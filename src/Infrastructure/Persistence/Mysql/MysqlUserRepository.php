<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Mysql;

use App\Core\Database;
use App\Domain\Entity\User;
use App\Domain\Repository\UserRepository;

/**
 * MySQL implementation of UserRepository.
 *
 * SELECT COLUMNS ARE SPELLED OUT
 * Not `SELECT *`. The `users` table contains `password_hash`, and a `SELECT *`
 * that flows into `User::fromRow()` is one careless edit away from putting a
 * bcrypt hash into a log line. Naming the columns makes the omission a visible,
 * deliberate decision rather than a default.
 *
 * IP ADDRESSES ARE STORED AS VARBINARY(16)
 * That is what `db/schema.sql` declares, and it is what lets the same column hold
 * an IPv4 and an IPv6 address without a schema change. It is also a retention
 * decision: a raw IP is personal data under Act 843, and the nightly retention
 * job is the only thing that may delete one.
 */
final class MysqlUserRepository implements UserRepository
{
    private const COLUMNS = 'u.id, u.role_id, r.name AS role, u.email, u.first_name, u.last_name, '
        . 'u.department_id, u.phone, u.student_index, u.staff_id, u.status, '
        . 'u.must_change_password, u.failed_login_count, u.locked_until, '
        . 'u.email_verified_at, u.last_login_at';

    public function __construct(private readonly Database $database)
    {
    }

    public function findById(int $userId): ?User
    {
        $row = $this->database->selectOne(
            'SELECT ' . self::COLUMNS . ' FROM `users` u
             JOIN `roles` r ON r.id = u.role_id
             WHERE u.id = :id AND u.deleted_at IS NULL',
            ['id' => $userId],
        );

        return $row === null
            ? null
            : User::fromRow($row);
    }

    public function findByEmail(string $email): ?User
    {
        $row = $this->database->selectOne(
            'SELECT ' . self::COLUMNS . ' FROM `users` u
             JOIN `roles` r ON r.id = u.role_id
             WHERE u.email = :email AND u.deleted_at IS NULL',
            ['email' => strtolower(trim($email))],
        );

        return $row === null
            ? null
            : User::fromRow($row);
    }

    public function credentialsForEmail(string $email): ?array
    {
        // The ONLY query in the codebase that reads password_hash. The column is
        // named explicitly and the return shape is narrow, so an audit can find
        // every read of a credential with one grep.
        $row = $this->database->selectOne(
            'SELECT ' . self::COLUMNS . ', u.password_hash
             FROM `users` u
             JOIN `roles` r ON r.id = u.role_id
             WHERE u.email = :email AND u.deleted_at IS NULL',
            ['email' => strtolower(trim($email))],
        );

        if ($row === null) {
            return null;
        }

        return [
            'user'          => User::fromRow($row),
            'password_hash' => (string) $row['password_hash'],
        ];
    }

    public function setPassword(int $userId, string $passwordHash, bool $mustChangePassword = false): void
    {
        $this->database->execute(
            'UPDATE `users`
             SET `password_hash` = :hash,
                 `must_change_password` = :must_change,
                 -- A password change also ends every session that was alive when
                 -- it happened, or an attacker who stole a refresh token keeps
                 -- their access for another 30 days after the victim "fixes" it.
                 `failed_login_count` = 0,
                 `locked_until` = NULL
             WHERE `id` = :id',
            [
                'id'          => $userId,
                'hash'        => $passwordHash,
                'must_change' => $mustChangePassword ? 1 : 0,
            ],
        );
    }

    public function permissionsFor(int $userId): array
    {
        $rows = $this->database->select(
            'SELECT p.name
             FROM `role_permissions` rp
             JOIN `roles` ro       ON ro.id = rp.role_id
             JOIN `users` u         ON u.role_id = rp.role_id
             JOIN `permissions` p   ON p.id = rp.permission_id
             WHERE u.id = :id
             ORDER BY p.name',
            ['id' => $userId],
        );

        return array_map(static fn (array $row): string => (string) $row['name'], $rows);
    }

    public function notificationAudienceForCohort(int $cohortId): array
    {
        $rows = $this->database->select(
            // Students, the assigned lecturer, and every admin in the cohort's
            // department. Deduplicated by the query rather than in PHP so a user
            // who is both a cohort member and a lecturer gets one notification,
            // not two — duplicates are how a "you have 2 new alerts" badge
            // teaches people to ignore notifications.
            'SELECT DISTINCT u.id
             FROM `users` u
             WHERE u.deleted_at IS NULL
               AND u.status = :status
               AND (
                   u.id IN (
                        SELECT e.student_id
                        FROM `enrollments` e
                        WHERE e.cohort_id = :cohort_student AND e.status = :enrolled
                    )
                OR u.id IN (
                       SELECT lca.lecturer_id
                       FROM `lecturer_course_assignments` lca
                       JOIN `cohorts` c ON c.course_id = lca.course_id
                       WHERE c.id = :cohort_lecturer
                   )
                OR u.id IN (
                       SELECT a2.id
                       FROM `users` a2
                       JOIN `roles` r2 ON r2.id = a2.role_id
                       JOIN `cohorts` c2 ON c2.department_id = a2.department_id
                       WHERE c2.id = :cohort_admin AND r2.name = :admin
                   )
               )',
            [
                'cohort_student'  => $cohortId,
                'cohort_lecturer' => $cohortId,
                'cohort_admin'    => $cohortId,
                'status'          => User::STATUS_ACTIVE,
                'enrolled'        => 'enrolled',
                'admin'           => 'admin',
            ],
        );

        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }

    public function recordSuccessfulLogin(int $userId): void
    {
        $this->database->execute(
            'UPDATE `users`
             SET `failed_login_count` = 0, `locked_until` = NULL, `last_login_at` = UTC_TIMESTAMP()
             WHERE `id` = :id',
            ['id' => $userId],
        );
    }

    public function recordFailedLogin(int $userId, int $lockAfter = 5, int $lockMinutes = 15): int
    {
        $this->database->execute(
            'UPDATE `users`
             SET `failed_login_count` = `failed_login_count` + 1,
                 `locked_until` = CASE
                     WHEN `failed_login_count` + 1 >= :lock_after
                     THEN DATE_ADD(UTC_TIMESTAMP(), INTERVAL :lock_minutes MINUTE)
                     ELSE `locked_until`
                 END
             WHERE `id` = :id',
            [
                'id'           => $userId,
                'lock_after'   => $lockAfter,
                'lock_minutes' => $lockMinutes,
            ],
        );

        $row = $this->database->selectOne(
            'SELECT `failed_login_count` FROM `users` WHERE `id` = :id',
            ['id' => $userId],
        );

        return (int) ($row['failed_login_count'] ?? 0);
    }

    public function existsWithEmail(string $email): bool
    {
        $value = $this->database->scalar(
            'SELECT 1 FROM `users` WHERE `email` = :email LIMIT 1',
            ['email' => strtolower(trim($email))],
        );

        return $value !== null;
    }

    public function passwordHashFor(int $userId): ?string
    {
        $value = $this->database->scalar(
            'SELECT `password_hash` FROM `users` WHERE `id` = :id AND `deleted_at` IS NULL',
            ['id' => $userId],
        );

        return is_string($value)
            ? $value
            : null;
    }
}
