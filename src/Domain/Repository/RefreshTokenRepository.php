<?php

declare(strict_types=1);

namespace App\Domain\Repository;

/**
 * Rotating refresh tokens.
 *
 * WHY THESE ARE NOT JWTs
 * An access token is self-contained and therefore unrevocable, which is why it
 * lasts 15 minutes. A refresh token lives for 30 days, so it must be revocable,
 * which means the server has to be able to look it up. Storing a JWT would mean
 * either a 30-day window in which a stolen token cannot be cut off, or putting
 * the token in the database anyway — at which point the signature is doing no
 * work. So: opaque random bytes, stored as a SHA-256 hash.
 *
 * ONLY THE HASH IS STORED
 * A database dump therefore contains no usable refresh token. That is the single
 * most important property of this table (NFR-SEC-01).
 *
 * SINGLE USE, WITH FAMILY REVOCATION
 * `consume()` marks a row used in the same statement that reads it, so two
 * concurrent requests with the same token cannot both succeed. Presenting an
 * already-used token revokes the entire `family_id` — a rotation chain — because
 * the only reason to replay one is that somebody copied it, and a copy means the
 * attacker and the legitimate user are now racing. The family dies so only one of
 * them keeps access.
 */
interface RefreshTokenRepository
{
    /**
     * Mint a token and return the raw value. The raw value exists only in this
     * response; nothing can retrieve it again.
     *
     * @return array{token: string, family_id: string, expires_at: string}
     */
    public function issue(int $userId, ?string $ipAddress, ?string $userAgent, ?string $familyId = null): array;

    /**
     * Validate and burn a token, starting the next link in the chain.
     *
     * @return array{user_id: int, family_id: string}|null Null when the token is
     *                                                    unknown, expired, revoked or
     *                                                    already used.
     */
    public function consume(string $rawToken): ?array;

    /**
     * Revoke every token in a family. Called when a used token is replayed.
     */
    public function revokeFamily(string $familyId): int;

    /**
     * Revoke every token for a user. Called on logout, on password change, and
     * by an administrator suspending an account.
     */
    public function revokeAllForUser(int $userId): int;

    /**
     * Delete rows that expired more than 30 days ago (Act 843 storage limitation).
     */
    public function purgeExpired(int $graceDays = 30): int;
}
