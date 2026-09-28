<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Mysql;

use App\Core\Database;
use App\Domain\Repository\RefreshTokenRepository;

/**
 * MySQL implementation of RefreshTokenRepository.
 */
final class MysqlRefreshTokenRepository implements RefreshTokenRepository
{
    private const LIFETIME_DAYS = 30;

    public function __construct(private readonly Database $database)
    {
    }

    public function issue(int $userId, ?string $ipAddress, ?string $userAgent, ?string $familyId = null): array
    {
        $raw = $this->generateToken();
        $family = $familyId ?? $this->newUuid();
        $expiresAt = gmdate('Y-m-d H:i:s', time() + (self::LIFETIME_DAYS * 86400));

        $this->database->insert('refresh_tokens', [
            'user_id'    => $userId,
            'family_id'  => $family,
            'token_hash' => self::hash($raw),
            'user_agent' => $userAgent === null ? null : substr($userAgent, 0, 255),
            'ip_address' => self::packIp($ipAddress),
            'expires_at' => $expiresAt,
        ]);

        return ['token' => $raw, 'family_id' => $family, 'expires_at' => $expiresAt];
    }

    public function consume(string $rawToken): ?array
    {
        $hash = self::hash($rawToken);

        // The claim and the read are two statements, but in this order and with
        // a unique key between them: the UPDATE is conditional on `used_at IS
        // NULL`, so of two concurrent requests presenting the same token exactly
        // one sees a changed row. SELECT-then-UPDATE, or an UPDATE followed by a
        // re-check of a condition, would let both through and defeat the entire
        // replay defence.
        $claimed = $this->database->execute(
            'UPDATE `refresh_tokens`
             SET `used_at` = UTC_TIMESTAMP()
             WHERE `token_hash` = :hash
               AND `used_at` IS NULL
               AND `revoked_at` IS NULL
               AND `expires_at` > UTC_TIMESTAMP()',
            ['hash' => $hash],
        );

        if ($claimed !== 1) {
            return null;
        }

        $link = $this->database->selectOne(
            'SELECT `user_id`, `family_id` FROM `refresh_tokens` WHERE `token_hash` = :hash',
            ['hash' => $hash],
        );

        if ($link === null) {
            return null;
        }

        return [
            'user_id'   => (int) $link['user_id'],
            'family_id' => (string) $link['family_id'],
        ];
    }

    public function revokeFamily(string $familyId): int
    {
        return $this->database->execute(
            'UPDATE `refresh_tokens`
             SET `revoked_at` = UTC_TIMESTAMP()
             WHERE `family_id` = :family AND `revoked_at` IS NULL',
            ['family' => $familyId],
        );
    }

    public function revokeAllForUser(int $userId): int
    {
        return $this->database->execute(
            'UPDATE `refresh_tokens`
             SET `revoked_at` = UTC_TIMESTAMP()
             WHERE `user_id` = :user_id AND `revoked_at` IS NULL',
            ['user_id' => $userId],
        );
    }

    public function purgeExpired(int $graceDays = 30): int
    {
        return $this->database->execute(
            'DELETE FROM `refresh_tokens`
             WHERE `expires_at` < FROM_UNIXTIME(:cutoff)',
            ['cutoff' => time() - ($graceDays * 86400)],
        );
    }

    /**
     * 32 bytes of CSPRNG output, base64url-encoded. `random_bytes` throws rather
     * than degrading, which is the correct behaviour for a credential.
     */
    private function generateToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function newUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }

    private static function hash(string $raw): string
    {
        return hash('sha256', $raw);
    }

    /**
     * inet_pton/inet_ntop rather than a VARCHAR column, so one column holds both
     * address families. Returns null for an unparseable address instead of
     * storing a truncated string.
     */
    private static function packIp(?string $ip): ?string
    {
        if ($ip === null || $ip === '') {
            return null;
        }

        $packed = @inet_pton($ip);

        return $packed === false ? null : $packed;
    }
}
