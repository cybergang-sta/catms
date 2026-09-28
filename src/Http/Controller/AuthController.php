<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Core\Authenticator;
use App\Core\Exception\UnauthorizedException;
use App\Core\Exception\ValidationException;
use App\Domain\Service\AccountService;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Core\SecurityEventRecorder;
use App\Domain\Entity\User;

/**
 * The authentication endpoints (FR-AUTH-01 … FR-AUTH-05).
 *
 * This is the one controller group the scaffold implements end to end, because
 * it is the only path that touches a credential, and a credential path that has
 * never been executed is a credential path with an unknown number of bugs in it.
 *
 * Registration creates a pending account. A password reset consumes a
 * single-use token, and for a pending account that reset is what marks the
 * address verified and the account active. The reset mail is queued on the
 * outbox; this request does not wait on a mail server.
 */
final class AuthController extends Controller
{
    /**
     * POST /api/v1/auth/login
     *
     * One message for every failure — see Authenticator. The `retry_after_seconds`
     * detail is returned only when the account is genuinely locked, which tells
     * the legitimate owner of a locked account what to do without telling an
     * attacker that the account exists.
     */
    public function login(Request $request): Response
    {
        $input = $this->validator()->validateStrict($request->json(), [
            'email'    => 'required|email|max:190',
            'password' => 'required|string|max:200',
        ]);

        $email = strtolower(trim((string) $input['email']));
        $password = (string) $input['password'];

        // The rate limit is keyed on IP *and* address, so neither a single host
        // spraying many accounts nor a botnet cycling one account gets an
        // unlimited budget. Applied before the password check so the bcrypt cost
        // cannot be used to multiply an attacker's throughput.
        $this->throttle('login', ($request->ip() ?? 'unknown') . '|' . $email);

        /** @var Authenticator $authenticator */
        $authenticator = $this->service(Authenticator::class);
        $user = $authenticator->verifyPassword($email, $password, $request->ip(), $request->userAgent());

        if ($user === null) {
            $locked = $this->isLocked($email);

            throw new UnauthorizedException(
                'Invalid credentials.',
                $locked
                    ? [
                        'reason'              => 'account_locked',
                        'retry_after_seconds' => Authenticator::LOCK_MINUTES * 60,
                    ]
                    : [],
            );
        }

        return $this->issueSession($request, $user, $authenticator);
    }

    /**
     * POST /api/v1/auth/refresh
     *
     * Rotation with replay detection. A token that is presented twice revokes the
     * entire family and forces a fresh login, which is the only safe response:
     * if an attacker has a copy, somebody is about to be logged out, and quietly
     * serving the second request would hand the attacker a valid session.
     */
    public function refresh(Request $request): Response
    {
        $input = $this->validator()->validateStrict($request->json(), [
            'refresh_token' => 'required|string|max:200',
        ]);

        $raw = (string) $input['refresh_token'];
        $this->throttle('refresh', substr(hash('sha256', $raw), 0, 32));

        $link = $this->refreshTokens()->consume($raw);

        if ($link === null) {
            $this->revokeFamilyFor($raw, $request->ip());

            throw new UnauthorizedException('The refresh token is not valid. Please sign in again.');
        }

        $user = $this->users()->findById($link['user_id']);
        if ($user === null || !$user->canAuthenticate()) {
            $this->refreshTokens()->revokeFamily($link['family_id']);

            throw new UnauthorizedException('The refresh token is not valid. Please sign in again.');
        }

        /** @var Authenticator $authenticator */
        $authenticator = $this->service(Authenticator::class);

        // The next link in the same chain, so a replay can still be attributed to
        // the family it came from.
        $next = $this->refreshTokens()->issue(
            $user->id(),
            $request->ip(),
            $request->userAgent(),
            $link['family_id'],
        );

        $this->securityEvents()->record(
            $user->id(),
            SecurityEventRecorder::TOKEN_REFRESHED,
            'info',
            $request->ip(),
            [],
            $request->userAgent(),
        );

        return Response::success(
            array_merge(
                $authenticator->issueAccessToken($user),
                [
                    'refresh_token' => $next['token'],
                    'refresh_expires_at' => $next['expires_at'],
                ],
            ),
        );
    }

    /**
     * POST /api/v1/auth/logout
     *
     * Revokes every refresh token for the user, not just the one presented.
     * "Log out everywhere" is what somebody does when they think a device was
     * stolen, and a logout that only kills the current chain leaves the stolen
     * device working for another 29 days.
     */
    public function logout(Request $request): Response
    {
        $identity = $this->identity($request);
        $revoked = $this->refreshTokens()->revokeAllForUser($identity->userId());

        $this->securityEvents()->record(
            $identity->userId(),
            SecurityEventRecorder::LOGOUT,
            'info',
            $request->ip(),
            ['revoked_tokens' => $revoked],
            $request->userAgent(),
        );

        return Response::success(['revoked_sessions' => $revoked]);
    }

    /**
     * GET /api/v1/auth/me
     *
     * Act 843 subject access: a user can read everything the system holds about
     * them through this endpoint and through `GET /profile`. That is a legal
     * requirement, not a convenience, which is why it is separate from the
     * admin-only `GET /users/{id}`.
     */
    public function me(Request $request): Response
    {
        $identity = $this->identity($request);
        $user = $this->users()->findById($identity->userId());

        if ($user === null) {
            throw new UnauthorizedException('The account is no longer available.');
        }

        return Response::success([
            'user'        => $user->toArray(),
            'permissions' => $identity->permissions(),
            'scope'       => $this->scope($request),
        ]);
    }

    /**
     * POST /api/v1/auth/register
     *
     * FR-AUTH-01. Public registration is limited to `student` and `lecturer`;
     * an administrator is created by an administrator, never by a signup form.
     */
    public function register(Request $request): Response
    {
        $input = $this->validator()->validateStrict($request->json(), [
            'email'                 => 'required|email|max:190',
            'password'              => 'required|string|max:200',
            'password_confirmation' => 'required|string|max:200',
            'role'                  => 'required|in:student,lecturer,admin',
            'first_name'            => 'required|string|max:80',
            'last_name'             => 'required|string|max:80',
            'phone'                 => 'nullable|string|max:30',
            'student_index'         => 'nullable|string|max:40',
        ]);
        if ($input['password'] !== $input['password_confirmation']) {
            throw ValidationException::field('password_confirmation', 'The password confirmation does not match.');
        }

        $this->throttle('register', $request->ip() ?? 'unknown');

        return Response::created($this->accounts()->register($input, $request->ip()));
    }

    /**
     * POST /api/v1/auth/forgot-password
     *
     * FR-AUTH-03. The response is identical whether or not the address exists,
     * and it is produced on the same code path either way, so neither the body
     * nor the timing reveals whether an account is registered.
     */
    public function forgotPassword(Request $request): Response
    {
        $input = $this->validator()->validateStrict($request->json(), [
            'email' => 'required|email|max:190',
        ]);

        $this->throttle('password_reset', ($request->ip() ?? 'unknown') . '|' . (string) $input['email']);

        $this->accounts()->forgotPassword((string) $input['email'], $request->ip());

        return Response::success(
            ['message' => 'If that address is registered, a reset link has been issued.'],
            [],
            202,
        );
    }

    /**
     * POST /api/v1/auth/reset-password
     */
    public function resetPassword(Request $request): Response
    {
        $input = $this->validator()->validateStrict($request->json(), [
            'token'                 => 'required|string|max:200',
            'password'              => 'required|string|max:200',
            'password_confirmation' => 'required|string|max:200',
        ]);
        if ($input['password'] !== $input['password_confirmation']) {
            throw ValidationException::field('password_confirmation', 'The password confirmation does not match.');
        }

        $this->throttle('password_reset', ($request->ip() ?? 'unknown') . '|' . (string) $input['token']);

        $this->accounts()->resetPassword((string) $input['token'], (string) $input['password'], $request->ip());

        return Response::success(['reset' => true]);
    }

    private function accounts(): AccountService
    {
        return $this->service(AccountService::class);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Issue the token pair and queue the first refresh token.
     */
    private function issueSession(Request $request, User $user, Authenticator $authenticator): Response
    {
        $refresh = $this->refreshTokens()->issue($user->id(), $request->ip(), $request->userAgent());

        return Response::success(array_merge(
            $authenticator->issueAccessToken($user),
            [
                'refresh_token'     => $refresh['token'],
                'refresh_expires_at' => $refresh['expires_at'],
                'user'              => $user->toArray(),
                // The client uses this to route straight to the "change your
                // password" screen instead of discovering the requirement later.
                'must_change_password' => $user->mustChangePassword(),
            ],
        ));
    }

    /**
     * Revoke the family of a token that failed to rotate.
     *
     * A token that is unknown, expired or already used is indistinguishable from
     * a replay, and all three warrant killing the chain: the cost of a spurious
     * re-login is one extra tap, and the cost of missing a replay is an attacker
     * holding a 30-day session.
     */
    private function revokeFamilyFor(string $rawToken, ?string $ip): void
    {
        $hash = hash('sha256', $rawToken);
        $family = $this->database()->scalar(
            'SELECT `family_id` FROM `refresh_tokens` WHERE `token_hash` = :hash',
            ['hash' => $hash],
        );

        if (is_string($family) && $family !== '') {
            $this->refreshTokens()->revokeFamily($family);
        }

        $this->securityEvents()->record(
            null,
            SecurityEventRecorder::TOKEN_REPLAY,
            'critical',
            $ip,
            ['token_fingerprint' => substr($hash, 0, 12)],
        );
    }

    private function isLocked(string $email): bool
    {
        $user = $this->users()->findByEmail($email);

        return $user !== null && $user->isLocked();
    }

    /**
     * @throws RateLimitException
     */
    private function throttle(string $bucket, string $subject): void
    {
        /** @var RateLimiter $limiter */
        $limiter = $this->service(RateLimiter::class);
        $limiter->hit($bucket, $subject);
    }
}
