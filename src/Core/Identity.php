<?php

declare(strict_types=1);

namespace App\Core;

/**
 * The authenticated caller, as the application layer sees them.
 *
 * Immutable, and deliberately small. A controller receives this and not a
 * `users` row, which is what stops personal data from leaking into a response by
 * convenience. Loading the full profile is an explicit call to UserRepository
 * (Act 843 data minimisation, `docs/SECURITY.md` §6.3).
 *
 * `permissions` is resolved once at authentication and carried for the life of
 * the request. That is safe because a permission only ever changes through an
 * admin action, and the practical consequence — an account demoted mid-session
 * keeps its permissions until its 15-minute access token expires — is
 * documented in `docs/SECURITY.md` §5.
 */
final class Identity
{
    /**
     * @param list<string> $permissions
     * @param list<string> $roles
     */
    public function __construct(
        private readonly int $userId,
        private readonly string $role,
        private readonly array $permissions,
        private readonly ?int $departmentId = null,
        private readonly array $roles = [],
        private readonly string $email = '',
        private readonly string $displayName = '',
    ) {
    }

    public function userId(): int
    {
        return $this->userId;
    }

    public function role(): string
    {
        return $this->role;
    }

    /**
     * @return list<string>
     */
    public function permissions(): array
    {
        return $this->permissions;
    }

    /**
     * @return list<string>
     */
    public function roles(): array
    {
        return $this->roles;
    }

    public function hasRole(string $role): bool
    {
        return $this->role === $role || in_array($role, $this->roles, true);
    }

    public function isAdmin(): bool
    {
        return $this->hasRole('admin');
    }

    /**
     * @return int|null Null for a system-level administrator with no department.
     */
    public function departmentId(): ?int
    {
        return $this->departmentId;
    }

    public function email(): string
    {
        return $this->email;
    }

    public function displayName(): string
    {
        return $this->displayName;
    }

    public function can(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }

    /**
     * The public half of this object, for `/auth/me` and the audit log.
     *
     * No e-mail-adjacent fields beyond the address itself, and no token ever —
     * `password_hash` is not on this class and must never be added to it.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'user_id'       => $this->userId,
            'role'          => $this->role,
            'roles'         => $this->roles,
            'department_id' => $this->departmentId,
            'email'         => $this->email,
            'display_name'  => $this->displayName,
            'permissions'   => $this->permissions,
        ];
    }
}
