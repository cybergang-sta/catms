<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\Entity\User;

/**
 * Persistence contract for accounts.
 *
 * An interface in the domain, implemented in `App\Infrastructure`. That inversion
 * is the point: the auth flow, the allocation engine and the tests can all be
 * written against these methods without a database, which is what makes the
 * pure-domain rule (ADR-001) enforceable rather than aspirational.
 *
 * WHY `credentialsForEmail` IS SEPARATE FROM `findByEmail`
 * The password hash is deliberately absent from the `User` entity, so a hash
 * cannot reach a response or a log by accident. It still has to be readable
 * exactly once, in the login path, so that one method returns it beside the
 * entity and the Authenticator throws it away immediately afterwards. One
 * method that touches a credential, documented as such, is auditable in a way
 * that "the repository returns whatever the query selected" never is.
 */
interface UserRepository
{
    public function findById(int $userId): ?User;

    /**
     * Case-insensitive: the schema stores addresses lowercased, and the login
     * form must not reject `Kwame@utas.edu.gh` for having a capital K.
     */
    public function findByEmail(string $email): ?User;

    /**
     * The account plus its password hash, for the login path only.
     *
     * The key is `password_hash` and the value is a bcrypt string. Nothing else
     * in the codebase may call this.
     *
     * @return array{user: User, password_hash: string}|null
     */
    public function credentialsForEmail(string $email): ?array;

    /**
     * Permission names for this user, from `role_permissions`.
     *
     * @return list<string>
     */
    public function permissionsFor(int $userId): array;

    /**
     * The e-mail addresses of everyone who should be told about a change to a
     * cohort's timetable: the cohort's students, its lecturer, and the
     * department's administrators (FR-NOTIF-04).
     *
     * @return list<int>
     */
    public function notificationAudienceForCohort(int $cohortId): array;

    /**
     * Clear the lockout counter. Called only after a successful password check.
     *
     * The IP is deliberately not a parameter: the caller records it against
     * `security_events`, the append-only log that survives a user row being
     * archived. Writing an address into two places means two retention policies
     * to keep consistent.
     */
    public function recordSuccessfulLogin(int $userId): void;

    /**
     * Increment the counter and lock the account once it reaches the threshold.
     *
     * @return int The new failure count.
     */
    public function recordFailedLogin(int $userId, int $lockAfter = 5, int $lockMinutes = 15): int;

    public function existsWithEmail(string $email): bool;

    /**
     * Replace the stored hash. Used by password reset, by a password change, and
     * by the transparent cost-factor upgrade after a login.
     *
     * @param bool $mustChangePassword Force a change at next login (admin reset).
     */
    public function setPassword(int $userId, string $passwordHash, bool $mustChangePassword = false): void;

    /**
     * The stored bcrypt hash for a password change. Same rule as
     * `credentialsForEmail`: the hash never rides on the User entity.
     */
    public function passwordHashFor(int $userId): ?string;
}
