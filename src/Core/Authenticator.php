<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Exception\UnauthorizedException;
use App\Domain\Entity\User;
use App\Domain\Repository\UserRepository;

/**
 * Turns a bearer token into an Identity, and an e-mail plus password into a pair
 * of tokens.
 *
 * TWO LOGIN PROPERTIES ENFORCED HERE, NOT IN THE CONTROLLER
 * Both come from `docs/SECURITY.md` §5, and both are easy to lose when the
 * check lives in a controller that somebody later refactors:
 *
 *  1. TIMING IS CONSTANT. The unknown-e-mail path still runs a bcrypt
 *     verification against a decoy hash. Without it, a request for an
 *     unregistered address returns in about a millisecond while a request for a
 *     registered one takes ~250 ms, and that difference is a reliable
 *     account-enumeration oracle.
 *  2. THE FAILURE MESSAGE IS IDENTICAL for a wrong password, an unknown address
 *     and a suspended account. Each of those distinctions is useful to an
 *     attacker and useless to a legitimate user, who needs to be told the same
 *     thing either way: try again or contact the administrator.
 *
 * PERMISSIONS ARE READ FROM THE DATABASE, NOT FROM THE TOKEN
 * A token that carried its own permission list could not be invalidated when a
 * role changed, and demoting an administrator is a requirement (FR-PROF-02), not
 * a nicety. The cost is one indexed lookup per request against two small tables.
 */
final class Authenticator
{
    /**
     * A syntactically valid bcrypt hash of a value nobody knows, used only to
     * equalise the timing of the unknown-e-mail path. `password_verify` still
     * performs the full key derivation and then returns false, which is the
     * entire point.
     */
    private const TIMING_DECOY_HASH = '$2y$12$abcdefghijklmnopqrstuvaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    /** Failed logins before the account is locked (NFR-SEC-01). */
    public const MAX_FAILED_LOGINS = 5;

    public const LOCK_MINUTES = 15;

    public function __construct(
        private readonly Jwt $jwt,
        private readonly UserRepository $users,
        private readonly SecurityEventRecorder $events,
        private readonly Logger $logger,
        private readonly int $bcryptCost = 12,
    ) {
    }

    /**
     * Verify an access token and load the caller's permissions.
     *
     * @throws UnauthorizedException
     */
    public function authenticate(?string $token): Identity
    {
        if ($token === null || $token === '') {
            throw new UnauthorizedException();
        }

        $claims = $this->jwt->verify($token);
        $userId = (int) ($claims['sub'] ?? 0);
        if ($userId < 1) {
            throw new UnauthorizedException();
        }

        $user = $this->users->findById($userId);
        if ($user === null || !$this->mayUseSession($user)) {
            // A valid signature for an account that has since been suspended or
            // archived is still refused. An access token cannot be revoked, so
            // this check is the only thing that stops a suspended administrator
            // for up to 15 minutes — which is why the lifetime is 15 minutes.
            throw new UnauthorizedException();
        }

        return new Identity(
            userId: $user->id(),
            role: $user->role(),
            permissions: $this->users->permissionsFor($user->id()),
            departmentId: $user->departmentId(),
            roles: [$user->role()],
            email: $user->email(),
            displayName: $user->displayName(),
        );
    }

    public function mayUseSession(User $user, ?string $now = null): bool
    {
        return $user->canAuthenticate($now);
    }

    /**
     * Password verification. Returns null on any failure rather than throwing, so
     * the caller produces one response shape for every kind of rejection and
     * cannot accidentally give a different answer for "no such user" and "wrong
     * password".
     */
    public function verifyPassword(
        string $email,
        string $password,
        ?string $ip,
        ?string $userAgent,
    ): ?User {
        $credentials = $this->users->credentialsForEmail($email);

        if ($credentials === null) {
            password_verify($password, self::TIMING_DECOY_HASH);
            $this->events->record(
                null,
                SecurityEventRecorder::LOGIN_FAILED,
                'warning',
                $ip,
                ['reason' => 'unknown_email', 'email' => self::maskEmail($email)],
                $userAgent,
            );

            return null;
        }

        /** @var User $user */
        $user = $credentials['user'];
        $hash = $credentials['password_hash'];

        if (!password_verify($password, $hash)) {
            $failures = $this->users->recordFailedLogin(
                $user->id(),
                self::MAX_FAILED_LOGINS,
                self::LOCK_MINUTES,
            );

            $this->events->record(
                $user->id(),
                SecurityEventRecorder::LOGIN_FAILED,
                'warning',
                $ip,
                ['reason' => 'bad_password', 'failures' => $failures],
                $userAgent,
            );

            if ($failures >= self::MAX_FAILED_LOGINS) {
                $this->events->record(
                    $user->id(),
                    SecurityEventRecorder::ACCOUNT_LOCKED,
                    'critical',
                    $ip,
                    ['failures' => $failures, 'locked_for_minutes' => self::LOCK_MINUTES],
                    $userAgent,
                );
            }

            return null;
        }

        if (!$this->mayUseSession($user)) {
            $this->events->record(
                $user->id(),
                SecurityEventRecorder::LOGIN_FAILED,
                'warning',
                $ip,
                ['reason' => $user->isLocked() ? 'account_locked' : 'status_' . $user->status()],
                $userAgent,
            );

            return null;
        }

        $this->users->recordSuccessfulLogin($user->id());
        $this->upgradeHashIfNeeded($user, $password, $hash);

        $this->events->record(
            $user->id(),
            SecurityEventRecorder::LOGIN_SUCCEEDED,
            'info',
            $ip,
            ['role' => $user->role()],
            $userAgent,
        );

        return $user;
    }

    /**
     * @return array{access_token: string, token_type: string, expires_in: int}
     */
    public function issueAccessToken(User $user, ?int $now = null): array
    {
        return [
            'access_token' => $this->jwt->issue(
                [
                    'sub'  => $user->id(),
                    'role' => $user->role(),
                    'dept' => $user->departmentId(),
                ],
                $now,
            ),
            'token_type' => 'Bearer',
            'expires_in' => $this->jwt->ttl(),
        ];
    }

    /**
     * Re-hash transparently when the configured cost factor has been raised.
     *
     * Without this, every hash keeps the cost it was created with and raising
     * `PASSWORD_BCRYPT_COST` protects nobody until each user next logs in — for
     * an infrequent lecturer that might be next semester.
     */
    private function upgradeHashIfNeeded(User $user, string $plainPassword, string $currentHash): void
    {
        if (!password_needs_rehash($currentHash, PASSWORD_BCRYPT, ['cost' => $this->bcryptCost])) {
            return;
        }

        $replacement = password_hash($plainPassword, PASSWORD_BCRYPT, ['cost' => $this->bcryptCost]);
        $this->users->setPassword($user->id(), $replacement, $user->mustChangePassword());

        $this->logger->info('Re-hashed a password at the current cost factor.', [
            'user_id' => $user->id(),
            'cost'    => $this->bcryptCost,
        ]);
    }

    /**
     * Reduce an address to something safe to log: enough to correlate repeated
     * attempts, not enough to build a contact list.
     */
    public static function maskEmail(string $email): string
    {
        $at = strpos($email, '@');
        if ($at === false || $at < 2) {
            return '***';
        }

        return substr($email, 0, 1) . str_repeat('*', $at - 1) . substr($email, $at);
    }
}
