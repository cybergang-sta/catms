<?php

declare(strict_types=1);

namespace App\Domain\Entity;

/**
 * An account, in the shape the application is allowed to know it.
 *
 * THE MOST IMPORTANT THING ABOUT THIS CLASS IS WHAT IS NOT ON IT
 * `password_hash` is deliberately absent. It is not private-and-hidden, it is
 * not there at all, so there is no code path — a `toArray()`, a log line, an
 * accidental `$user` interpolation into a response — by which a bcrypt hash can
 * reach a client or a file. Adding it back would be a security review finding,
 * and `docs/SECURITY.md` §5 lists "hash reachable from the entity" as VULN-05.
 *
 * `failed_login_count` and `locked_until` ARE here because the login flow needs
 * them to decide whether to lock, and because an administrator viewing a profile
 * legitimately needs to see that an account is locked. They are not secrets.
 *
 * Status is the account gate: only `active` may authenticate at all. `pending`
 * means e-mail unverified, `suspended` means an administrator disabled it, and
 * `archived` means the personal data has been pseudonymised for Act 843 erasure
 * while the historical allocations that referenced the account are preserved.
 */
final class User
{
    public const STATUS_PENDING   = 'pending';
    public const STATUS_ACTIVE    = 'active';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_ARCHIVED  = 'archived';

    public function __construct(
        private readonly int $id,
        private readonly int $roleId,
        private readonly string $role,
        private readonly string $email,
        private readonly string $firstName,
        private readonly string $lastName,
        private readonly ?int $departmentId = null,
        private readonly ?string $phone = null,
        private readonly ?string $studentIndex = null,
        private readonly ?string $staffId = null,
        private readonly string $status = self::STATUS_ACTIVE,
        private readonly bool $mustChangePassword = false,
        private readonly int $failedLoginCount = 0,
        private readonly ?string $lockedUntil = null,
        private readonly bool $emailVerified = true,
        private readonly ?string $lastLoginAt = null,
    ) {
    }

    /**
     * Build from a `users` JOIN `roles` row.
     *
     * Only the columns listed here are read. If someone adds a column to the
     * table, this method keeps ignoring it — which is the behaviour that makes
     * the "no password hash on the entity" rule hold as the schema grows.
     *
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) ($row['id'] ?? 0),
            roleId: (int) ($row['role_id'] ?? 0),
            role: (string) ($row['role'] ?? 'student'),
            email: (string) ($row['email'] ?? ''),
            firstName: (string) ($row['first_name'] ?? ''),
            lastName: (string) ($row['last_name'] ?? ''),
            departmentId: isset($row['department_id']) ? (int) $row['department_id'] : null,
            phone: isset($row['phone']) ? (string) $row['phone'] : null,
            studentIndex: isset($row['student_index']) ? (string) $row['student_index'] : null,
            staffId: isset($row['staff_id']) ? (string) $row['staff_id'] : null,
            status: (string) ($row['status'] ?? self::STATUS_ACTIVE),
            mustChangePassword: (bool) ($row['must_change_password'] ?? false),
            failedLoginCount: (int) ($row['failed_login_count'] ?? 0),
            lockedUntil: isset($row['locked_until']) ? (string) $row['locked_until'] : null,
            emailVerified: !empty($row['email_verified_at']),
            lastLoginAt: isset($row['last_login_at']) ? (string) $row['last_login_at'] : null,
        );
    }

    public function id(): int
    {
        return $this->id;
    }

    public function roleId(): int
    {
        return $this->roleId;
    }

    public function role(): string
    {
        return $this->role;
    }

    public function email(): string
    {
        return $this->email;
    }

    public function firstName(): string
    {
        return $this->firstName;
    }

    public function lastName(): string
    {
        return $this->lastName;
    }

    public function displayName(): string
    {
        return trim($this->firstName . ' ' . $this->lastName);
    }

    public function departmentId(): ?int
    {
        return $this->departmentId;
    }

    public function phone(): ?string
    {
        return $this->phone;
    }

    public function studentIndex(): ?string
    {
        return $this->studentIndex;
    }

    public function staffId(): ?string
    {
        return $this->staffId;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function mustChangePassword(): bool
    {
        return $this->mustChangePassword;
    }

    public function failedLoginCount(): int
    {
        return $this->failedLoginCount;
    }

    public function lockedUntil(): ?string
    {
        return $this->lockedUntil;
    }

    public function emailVerified(): bool
    {
        return $this->emailVerified;
    }

    public function lastLoginAt(): ?string
    {
        return $this->lastLoginAt;
    }

    /**
     * @param string|null $now Current time, so a test can pin the clock.
     */
    public function isLocked(?string $now = null): bool
    {
        if ($this->lockedUntil === null) {
            return false;
        }

        $now ??= gmdate('Y-m-d H:i:s');

        return $this->lockedUntil > $now;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * Whether this account may obtain a token at all.
     *
     * A suspended or archived account is refused with 403 while a pending one is
     * refused with 403 as well but a different message, because "verify your
     * e-mail" is actionable and "your account is suspended" tells an attacker the
     * address is registered.
     */
    public function canAuthenticate(?string $now = null): bool
    {
        return $this->isActive() && $this->emailVerified() && !$this->isLocked($now);
    }

    /**
     * The API representation. No e-mail unless asked for: `includeContact` is
     * false for a public listing and true only for the subject themselves or an
     * administrator, which is the data-minimisation rule in `docs/SECURITY.md` §6.3.
     *
     * @return array<string, mixed>
     */
    public function toArray(bool $includeContact = true): array
    {
        $data = [
            'user_id'      => $this->id,
            'role'         => $this->role,
            'first_name'   => $this->firstName,
            'last_name'    => $this->lastName,
            'display_name' => $this->displayName(),
            'status'       => $this->status,
        ];

        if ($this->departmentId !== null) {
            $data['department_id'] = $this->departmentId;
        }

        if ($includeContact) {
            $data['email'] = $this->email;
            if ($this->phone !== null) {
                $data['phone'] = $this->phone;
            }
        }

        if ($this->studentIndex !== null) {
            $data['student_index'] = $this->studentIndex;
        }

        return $data;
    }
}
