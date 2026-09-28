<?php

declare(strict_types=1);

namespace App\Domain\Service;

use App\Core\Database;
use App\Core\Exception\ConflictException;
use App\Core\Exception\NotFoundException;
use App\Core\Exception\UnauthorizedException;
use App\Core\Exception\ValidationException;
use App\Core\Identity;
use App\Core\Logger;
use App\Core\PasswordPolicy;
use App\Core\SecurityEventRecorder;
use App\Domain\Entity\User;
use App\Domain\Repository\RefreshTokenRepository;
use App\Domain\Repository\UserRepository;
use PDOException;

/**
 * Accounts: registration, profile, password reset, and user administration.
 *
 * A password hash is read only through UserRepository. This service never
 * selects `password_hash` itself, and it never puts one in a response.
 */
final class AccountService
{
    private const USER_COLUMNS = 'u.id, u.role_id, r.name AS role, u.email, u.first_name, u.last_name, '
        . 'u.department_id, u.phone, u.student_index, u.staff_id, u.status, '
        . 'u.must_change_password, u.email_verified_at, u.last_login_at, u.created_at';

    public function __construct(
        private readonly Database $database,
        private readonly Logger $logger,
        private readonly UserRepository $users,
        private readonly RefreshTokenRepository $refreshTokens,
        private readonly PasswordPolicy $passwords,
        private readonly SecurityEventRecorder $securityEvents,
        private readonly AuditTrail $audit,
        private readonly bool $local,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function register(array $input, ?string $ip): array
    {
        $email = strtolower(trim((string) $input['email']));
        $role = (string) $input['role'];
        $password = (string) $input['password'];
        $name = trim((string) $input['first_name'] . ' ' . (string) $input['last_name']);

        $this->passwords->assertAcceptable($password, $email, $name);

        if ($role === 'student' && trim((string) ($input['student_index'] ?? '')) === '') {
            throw new ValidationException([
                'student_index' => ['A student index is required for a student account.'],
            ]);
        }

        if ($this->users->existsWithEmail($email)) {
            throw new ConflictException('An account with those details already exists.');
        }

        $departmentId = $this->defaultDepartment();
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => $this->passwords->cost()]);

        try {
            $id = $this->database->insert('users', [
                'role_id'           => $this->roleId($role),
                'department_id'     => $departmentId,
                'first_name'        => trim((string) $input['first_name']),
                'last_name'         => trim((string) $input['last_name']),
                'email'             => $email,
                'phone'             => $this->blankToNull($input['phone'] ?? null),
                'student_index'     => $role === 'student' ? trim((string) $input['student_index']) : null,
                'password_hash'     => $hash,
                'status'            => User::STATUS_PENDING,
                'must_change_password' => 0,
            ]);
        } catch (PDOException $exception) {
            if ($this->isDuplicate($exception)) {
                throw new ConflictException('An account with those details already exists.');
            }

            throw $exception;
        }

        $this->securityEvents->record($id, SecurityEventRecorder::ACCOUNT_REGISTERED, 'info', $ip, [
            'role' => $role,
        ]);
        $this->logger->info('Account registered and awaiting verification.', ['user_id' => $id]);

        return [
            'id'                => $id,
            'email'             => $email,
            'status'            => User::STATUS_PENDING,
            'email_verified_at' => null,
        ];
    }

    /**
     * Identical response whether or not the address exists.
     */
    public function forgotPassword(string $email, ?string $ip): void
    {
        $email = strtolower(trim($email));
        $user = $this->users->findByEmail($email);
        // A missing account still pays for a hash so the response time does not
        // tell the caller which addresses are registered.
        $token = bin2hex(random_bytes(32));
        if ($user === null) {
            password_hash($token, PASSWORD_BCRYPT, ['cost' => $this->passwords->cost()]);

            return;
        }

        $this->database->insert('password_reset_tokens', [
            'user_id'    => $user->id(),
            'token_hash' => hash('sha256', $token),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600),
        ]);
        $this->database->insert('notification_outbox', [
            'channel'      => 'email',
            'recipient_id' => $user->id(),
            'payload'      => json_encode([
                'type'  => 'password.reset',
                'token' => $token,
            ], JSON_THROW_ON_ERROR),
            'status'       => 'pending',
        ]);

        if ($this->local) {
            $this->logger->info('Password reset token issued.', [
                'user_id' => $user->id(),
                'token'   => $token,
            ]);
        }

        $this->securityEvents->record($user->id(), SecurityEventRecorder::PASSWORD_RESET, 'info', $ip, []);
    }

    public function resetPassword(string $token, string $password, ?string $ip): void
    {
        $this->passwords->assertAcceptable($password);
        $row = $this->database->selectOne(
            'SELECT `id`, `user_id` FROM `password_reset_tokens`
             WHERE `token_hash` = :hash AND `used_at` IS NULL AND `expires_at` > UTC_TIMESTAMP()',
            ['hash' => hash('sha256', $token)],
        );

        if ($row === null) {
            $this->securityEvents->record(null, SecurityEventRecorder::PASSWORD_RESET_FAILED, 'warning', $ip, []);

            throw new ValidationException([
                'token' => ['This reset link is not valid.'],
            ]);
        }

        $userId = (int) $row['user_id'];
        $user = $this->users->findById($userId);
        if ($user !== null) {
            $this->passwords->assertAcceptable($password, $user->email(), $user->displayName());
        }

        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => $this->passwords->cost()]);
        $this->database->transaction(function () use ($row, $userId, $hash): void {
            $this->users->setPassword($userId, $hash, false);
            $this->database->execute(
                'UPDATE `password_reset_tokens` SET `used_at` = UTC_TIMESTAMP() WHERE `id` = :id',
                ['id' => (int) $row['id']],
            );
            $this->database->execute(
                'UPDATE `users` u
                 JOIN `roles` ro ON ro.id = u.role_id
                 SET u.email_verified_at = COALESCE(u.email_verified_at, UTC_TIMESTAMP()),
                     u.status = CASE
                         WHEN u.status = :pending AND ro.name <> \'admin\' THEN :active
                         ELSE u.status
                     END
                 WHERE u.id = :user',
                ['user' => $userId, 'pending' => User::STATUS_PENDING, 'active' => User::STATUS_ACTIVE],
            );
        });
        $this->refreshTokens->revokeAllForUser($userId);
        $this->securityEvents->record($userId, SecurityEventRecorder::PASSWORD_RESET, 'info', $ip, [
            'completed' => true,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function profile(Identity $identity): array
    {
        $user = $this->requireUser($identity->userId());

        return $user->toArray();
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function updateProfile(Identity $identity, array $input): array
    {
        $user = $this->requireUser($identity->userId());
        $changes = $this->profileChanges($input);
        if ($changes !== []) {
            $this->database->update('users', $changes, ['id' => $user->id()]);
            $this->audit->record($identity, 'profile.updated', 'user', $user->id(), $user->toArray(), $changes);
        }

        return $this->requireUser($user->id())->toArray();
    }

    public function changePassword(Identity $identity, string $current, string $next, ?string $ip): void
    {
        $user = $this->requireUser($identity->userId());
        $hash = $this->users->passwordHashFor($user->id());
        if ($hash === null || !password_verify($current, $hash)) {
            throw new UnauthorizedException('The current password is not correct.');
        }

        $this->passwords->assertAcceptable($next, $user->email(), $user->displayName());
        $replacement = password_hash($next, PASSWORD_BCRYPT, ['cost' => $this->passwords->cost()]);
        $this->users->setPassword($user->id(), $replacement, false);
        $this->refreshTokens->revokeAllForUser($user->id());
        $this->securityEvents->record($user->id(), SecurityEventRecorder::PASSWORD_RESET, 'info', $ip, [
            'self_service' => true,
        ]);
        $this->audit->record($identity, 'profile.password_changed', 'user', $user->id());
    }

    /**
     * @param array<string, mixed>           $query
     * @param array{page: int, per_page: int, offset: int} $page
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function listUsers(Identity $identity, string $scope, array $query, array $page): array
    {
        $bindings = [];
        $where = 'u.deleted_at IS NULL';
        $where .= $this->departmentClause($identity, $scope, 'u.department_id', $bindings, false);

        $q = trim((string) ($query['q'] ?? ''));
        if ($q !== '') {
            $where .= ' AND (u.email LIKE :q OR u.first_name LIKE :q OR u.last_name LIKE :q
                         OR u.student_index LIKE :q OR u.staff_id LIKE :q)';
            $bindings['q'] = '%' . $q . '%';
        }
        if (isset($query['role']) && is_string($query['role']) && $query['role'] !== '') {
            $where .= ' AND r.name = :role';
            $bindings['role'] = $query['role'];
        }
        if (isset($query['status']) && is_string($query['status']) && $query['status'] !== '') {
            $where .= ' AND u.status = :status';
            $bindings['status'] = $query['status'];
        }

        $total = (int) $this->database->scalar(
            'SELECT COUNT(*) FROM `users` u JOIN `roles` r ON r.id = u.role_id WHERE ' . $where,
            $bindings,
        );
        $rows = $this->database->select(
            'SELECT ' . self::USER_COLUMNS . ' FROM `users` u JOIN `roles` r ON r.id = u.role_id
             WHERE ' . $where . ' ORDER BY u.last_name, u.first_name
             LIMIT ' . $page['per_page'] . ' OFFSET ' . $page['offset'],
            $bindings,
        );

        return [
            'items' => array_map(fn (array $row): array => $this->presentUser($row), $rows),
            'total' => $total,
        ];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function createUser(Identity $actor, array $input): array
    {
        $email = strtolower(trim((string) $input['email']));
        $role = (string) $input['role'];
        if ($this->users->existsWithEmail($email)) {
            throw new ConflictException('An account with those details already exists.');
        }

        $departmentId = $this->departmentForWrite(
            $actor,
            isset($input['department_id']) ? (int) $input['department_id'] : null,
        );
        $temporary = bin2hex(random_bytes(16));
        $hash = password_hash($temporary, PASSWORD_BCRYPT, ['cost' => $this->passwords->cost()]);

        try {
            $id = $this->database->insert('users', [
                'role_id'              => $this->roleId($role),
                'department_id'        => $departmentId,
                'first_name'           => trim((string) $input['first_name']),
                'last_name'            => trim((string) $input['last_name']),
                'email'                => $email,
                'phone'                => $this->blankToNull($input['phone'] ?? null),
                'student_index'        => $this->blankToNull($input['student_index'] ?? null),
                'staff_id'             => $this->blankToNull($input['staff_id'] ?? null),
                'password_hash'        => $hash,
                'status'               => User::STATUS_ACTIVE,
                'must_change_password' => 1,
                'email_verified_at'    => gmdate('Y-m-d H:i:s'),
            ]);
        } catch (PDOException $exception) {
            if ($this->isDuplicate($exception)) {
                throw new ConflictException('An account with those details already exists.');
            }

            throw $exception;
        }

        $created = $this->requireUser($id);
        $this->audit->record($actor, 'user.created', 'user', $id, null, $created->toArray());
        if ($this->local) {
            $this->logger->info('Temporary password issued for a new account. It is not returned by the API.', [
                'user_id' => $id,
            ]);
        }

        return $created->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    public function showUser(Identity $actor, string $scope, int $id): array
    {
        return $this->presentUser($this->visibleUserRow($actor, $scope, $id));
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function updateUser(Identity $actor, string $scope, int $id, array $input): array
    {
        $before = $this->visibleUserRow($actor, $scope, $id);
        $changes = $this->profileChanges($input);
        if (isset($input['status'])) {
            $changes['status'] = (string) $input['status'];
        }
        if (isset($input['staff_id'])) {
            $changes['staff_id'] = $this->blankToNull($input['staff_id']);
        }
        if (isset($input['student_index'])) {
            $changes['student_index'] = $this->blankToNull($input['student_index']);
        }
        if ($changes !== []) {
            $this->database->update('users', $changes, ['id' => $id]);
            $this->audit->record($actor, 'user.updated', 'user', $id, $this->presentUser($before), $changes);
        }

        return $this->presentUser($this->visibleUserRow($actor, $scope, $id));
    }

    public function archiveUser(Identity $actor, string $scope, int $id): void
    {
        if ($actor->userId() === $id) {
            throw new ConflictException('You cannot archive the account you are signed in with.');
        }

        $this->visibleUserRow($actor, $scope, $id);
        $this->database->execute(
            'UPDATE `users` SET `status` = :archived, `deleted_at` = UTC_TIMESTAMP(),
                    `email` = CONCAT(`email`, :suffix)
             WHERE `id` = :id AND `deleted_at` IS NULL',
            [
                'id'       => $id,
                'archived' => User::STATUS_ARCHIVED,
                'suffix'   => '#archived-' . $id,
            ],
        );
        $this->refreshTokens->revokeAllForUser($id);
        $this->audit->record($actor, 'user.archived', 'user', $id);
    }

    /**
     * @return array<string, mixed>
     */
    public function changeRole(Identity $actor, string $scope, int $id, string $role): array
    {
        $before = $this->visibleUserRow($actor, $scope, $id);
        $this->database->update('users', ['role_id' => $this->roleId($role)], ['id' => $id]);
        $after = $this->visibleUserRow($actor, $scope, $id);
        $this->audit->record($actor, 'user.role_changed', 'user', $id, [
            'role' => $before['role'],
        ], [
            'role' => $after['role'],
        ]);
        $this->refreshTokens->revokeAllForUser($id);

        return $this->presentUser($after);
    }

    /**
     * @return array<string, mixed>
     */
    public function lecturerLoad(Identity $actor, string $scope, int $id): array
    {
        $this->visibleUserRow($actor, $scope, $id);
        $bindings = ['lecturer' => $id];
        $where = 'lecturer_id = :lecturer';
        $where .= $this->departmentClause($actor, $scope, 'department_id', $bindings, false);
        $rows = $this->database->select(
            'SELECT * FROM `v_lecturer_load` WHERE ' . $where,
            $bindings,
        );

        return [
            'user_id' => $id,
            'load'    => $rows,
        ];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function profileChanges(array $input): array
    {
        $changes = [];
        foreach (['first_name', 'last_name', 'phone'] as $field) {
            if (array_key_exists($field, $input)) {
                $changes[$field] = $field === 'phone'
                    ? $this->blankToNull($input[$field])
                    : trim((string) $input[$field]);
            }
        }

        return $changes;
    }

    /**
     * @param array<string, mixed> $bindings
     */
    private function departmentClause(
        Identity $identity,
        string $scope,
        string $column,
        array &$bindings,
        bool $shared,
    ): string {
        if ($scope === 'any' || ($identity->isAdmin() && $identity->departmentId() === null)) {
            return '';
        }

        $departmentId = $identity->departmentId();
        if ($departmentId === null) {
            return '';
        }

        $bindings['scope_dept'] = $departmentId;
        if ($shared) {
            return ' AND (' . $column . ' = :scope_dept OR ' . $column . ' IS NULL)';
        }

        return ' AND ' . $column . ' = :scope_dept';
    }

    private function departmentForWrite(Identity $actor, ?int $requested): ?int
    {
        if ($actor->departmentId() !== null) {
            if ($requested !== null && $requested !== $actor->departmentId()) {
                throw new NotFoundException('Department', $requested);
            }

            return $actor->departmentId();
        }

        return $requested ?? $this->defaultDepartment();
    }

    private function defaultDepartment(): ?int
    {
        $id = $this->database->scalar('SELECT `id` FROM `departments` ORDER BY `id` LIMIT 1');

        return $id === null ? null : (int) $id;
    }

    private function roleId(string $name): int
    {
        $id = $this->database->scalar('SELECT `id` FROM `roles` WHERE `name` = :name', ['name' => $name]);
        if ($id === null) {
            throw new ValidationException(['role' => ['That role is not available.']]);
        }

        return (int) $id;
    }

    private function requireUser(int $id): User
    {
        $user = $this->users->findById($id);
        if ($user === null) {
            throw new NotFoundException('User', $id);
        }

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function visibleUserRow(Identity $actor, string $scope, int $id): array
    {
        $bindings = ['id' => $id];
        $where = 'u.id = :id AND u.deleted_at IS NULL';
        $where .= $this->departmentClause($actor, $scope, 'u.department_id', $bindings, false);
        $row = $this->database->selectOne(
            'SELECT ' . self::USER_COLUMNS . ' FROM `users` u JOIN `roles` r ON r.id = u.role_id WHERE ' . $where,
            $bindings,
        );
        if ($row === null) {
            throw new NotFoundException('User', $id);
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function presentUser(array $row): array
    {
        return User::fromRow($row)->toArray();
    }

    private function blankToNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    private function isDuplicate(PDOException $exception): bool
    {
        return ($exception->errorInfo[1] ?? null) === 1062;
    }
}
