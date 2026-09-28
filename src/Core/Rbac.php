<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Exception\ForbiddenException;

/**
 * Authorisation. The single place a permission is checked.
 *
 * DEFAULT DENY, AND WHY IT IS ENFORCED HERE RATHER THAN IN A MIDDLEWARE SUBCLASS
 * The router already has the route's declared permission, so the check is one
 * method call in the dispatch path with no way to forget it. A per-controller
 * `assertCan()` is the pattern that reliably produces one forgotten check.
 *
 * NO ROLE INHERITANCE
 * `admin` is not a superset implemented in code — it is a row set in
 * `role_permissions`, and `config/rbac.php` lists its 25 permissions explicitly.
 * An inherited shortcut would mean the published matrix in `docs/SECURITY.md` §3
 * is a summary rather than the truth, and the security review depends on it
 * being the truth.
 *
 * SCOPE IS SEPARATE FROM PERMISSION
 * `can()` answers "may this role do this kind of thing at all?". It says nothing
 * about *which rows*. That is the repository's job, via the `scope` declared on
 * each route: `own` filters to the caller's own allocations, `department` to the
 * resolved department, `any` is admin-only. Keeping the two axes apart is what
 * stops "can allocate" from quietly becoming "can allocate in another
 * department" (NFR-SCALE-02, VULN-08).
 */
final class Rbac
{
    /**
     * @param array<string, array{group: string, description: string}> $permissions
     * @param array<string, list<string>>                            $rolePermissions role => permissions
     */
    public function __construct(
        private readonly array $permissions,
        private readonly array $rolePermissions,
    ) {
    }

    /**
     * Build from the contents of config/rbac.php.
     *
     * @param array{
     *     permissions: array<string, array{group: string, description: string}>,
     *     roles: array<string, array{label: string, description: string, permissions: list<string>}>
     * } $definition
     */
    public static function fromDefinition(array $definition): self
    {
        $rolePermissions = [];
        foreach ($definition['roles'] as $role => $config) {
            $rolePermissions[(string) $role] = array_values($config['permissions']);
        }

        return new self($definition['permissions'], $rolePermissions);
    }

    /**
     * @return list<string>
     */
    public function permissionsFor(string $role): array
    {
        return $this->rolePermissions[$role] ?? [];
    }

    /**
     * @return list<string> Every permission name, sorted, for seeding and docs.
     */
    public function allPermissions(): array
    {
        $names = array_keys($this->permissions);
        sort($names);

        return $names;
    }

    /**
     * @return list<string>
     */
    public function allRoles(): array
    {
        return array_keys($this->rolePermissions);
    }

    public function isKnownPermission(string $permission): bool
    {
        return isset($this->permissions[$permission]);
    }

    public function isKnownRole(string $role): bool
    {
        return isset($this->rolePermissions[$role]);
    }

    public function groupOf(string $permission): ?string
    {
        return $this->permissions[$permission]['group'] ?? null;
    }

    public function descriptionOf(string $permission): ?string
    {
        return $this->permissions[$permission]['description'] ?? null;
    }

    public function can(?Identity $identity, ?string $permission): bool
    {
        // A null permission means the route is public. A null identity on such a
        // route is fine; a null identity on a protected one never reaches here,
        // because the authentication middleware runs first.
        if ($permission === null) {
            return true;
        }

        if ($identity === null) {
            return false;
        }

        return $identity->can($permission);
    }

    /**
     * @throws ForbiddenException
     */
    public function assert(?Identity $identity, ?string $permission): void
    {
        if (!$this->can($identity, $permission)) {
            throw new ForbiddenException((string) $permission);
        }
    }

    /**
     * The `scope` a route declares, narrowed to what the identity may actually
     * reach. A `student` asking for `scope=any` gets `own`, never an error:
     * silently narrowing is safer than 403-ing a read the client legitimately
     * asked for, and the returned payload is still filtered by the repository.
     *
     * @return 'own'|'department'|'any'
     */
    public function effectiveScope(Identity $identity, string $scope): string
    {
        if ($scope === 'any' && !$identity->isAdmin()) {
            return $identity->departmentId() === null ? 'own' : 'department';
        }

        if ($scope === 'department' && $identity->departmentId() === null) {
            return $identity->isAdmin() ? 'any' : 'own';
        }

        return in_array($scope, ['own', 'department', 'any'], true) ? $scope : 'own';
    }
}
