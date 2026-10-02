<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Exception\RateLimitException;

/**
 * Fixed-window rate limiting, backed by MySQL so every worker sees the same count.
 *
 * WHY THE DATABASE AND NOT APCu
 * A per-process counter is wrong the moment there is more than one PHP-FPM
 * worker, and the login limit is the one control standing between the system and
 * credential stuffing. A shared table makes the limit real across the pool
 * (`docs/SECURITY.md` §7, NFR-SEC-05).
 *
 * WHY FIXED WINDOW AND NOT SLIDING
 * A fixed window is one upsert. A sliding window needs either a ring of buckets
 * or a Lua script, and buys a bounded burst at the boundary. For login attempts
 * that trade is not worth the operational cost, and the alternative of raising
 * the limit to compensate is exactly the failure mode the security review
 * watches for.
 *
 * The two low-frequency buckets (password_reset, generate) are *not* hot paths,
 * so a table row per attempt is entirely affordable.
 */
final class RateLimiter
{
    /**
     * @var array<string, array{limit: int, window: int}>
     */
    public const BUCKETS = [
        'login'          => ['limit' => 5,   'window' => 60],
        'password_reset' => ['limit' => 3,   'window' => 3600],
        'refresh'        => ['limit' => 30,  'window' => 60],
        'search'         => ['limit' => 60,  'window' => 60],
        'write'          => ['limit' => 120, 'window' => 60],
        'register'       => ['limit' => 3,   'window' => 3600],
        'generate'       => ['limit' => 10,  'window' => 3600],
    ];

    public function __construct(private readonly Database $database)
    {
    }

    /**
     * Register a hit and refuse the request when the bucket is full.
     *
     * The counter is incremented *before* the limit is compared, so a rejected
     * attempt still counts. Incrementing afterwards would let an attacker keep
     * trying passwords on the fifth rejected request forever.
     *
     * @throws RateLimitException
     */
    public function hit(string $bucket, string $subject): void
    {
        if (!isset(self::BUCKETS[$bucket])) {
            // An unknown bucket means a typo in config/routes.php. Failing closed
            // on a typo is correct: an unthrottled endpoint is a security hole,
            // and a 500 here is visible on the first request after the mistake.
            throw new RateLimitException($bucket, 0, 60);
        }

        $limit = self::BUCKETS[$bucket]['limit'];
        $window = self::BUCKETS[$bucket]['window'];
        $now = time();
        $windowStart = $now - ($now % $window);
        $key = self::key($bucket, $subject);

        $hits = $this->database->transaction(function (Database $database) use ($key, $windowStart): int {
            $database->execute(
                'INSERT INTO `rate_limit_buckets` (`bucket_key`, `window_start`, `hits`)
                 VALUES (:bucket_key, FROM_UNIXTIME(:window_start), 1)
                 ON DUPLICATE KEY UPDATE `hits` = `hits` + 1',
                [
                    'bucket_key'   => $key,
                    'window_start' => $windowStart,
                ],
            );

            $value = $database->scalar(
                'SELECT `hits` FROM `rate_limit_buckets`
                 WHERE `bucket_key` = :bucket_key AND `window_start` = FROM_UNIXTIME(:window_start)',
                [
                    'bucket_key'   => $key,
                    'window_start' => $windowStart,
                ],
            );

            return (int) $value;
        });

        if ($hits > $limit) {
            $retryAfter = max(1, ($windowStart + $window) - $now);

            throw new RateLimitException($bucket, $limit, $retryAfter);
        }
    }

    /**
     * Current count and remaining allowance, for the `X-RateLimit-*` headers.
     *
     * @return array{limit: int, remaining: int, reset: int}
     */
    public function status(string $bucket, string $subject): array
    {
        if (!isset(self::BUCKETS[$bucket])) {
            return ['limit' => 0, 'remaining' => 0, 'reset' => 0];
        }

        $limit = self::BUCKETS[$bucket]['limit'];
        $window = self::BUCKETS[$bucket]['window'];
        $now = time();
        $windowStart = $now - ($now % $window);

        $hits = (int) $this->database->scalar(
            'SELECT `hits` FROM `rate_limit_buckets`
             WHERE `bucket_key` = :bucket_key AND `window_start` = FROM_UNIXTIME(:window_start)',
            ['bucket_key' => self::key($bucket, $subject), 'window_start' => $windowStart],
        );

        return [
            'limit'     => $limit,
            'remaining' => max(0, $limit - $hits),
            'reset'     => ($windowStart + $window) - $now,
        ];
    }

    /**
     * Delete buckets whose window has closed. Called by `bin/console
     * rate-limit:sweep`, not per request: a sweep on the hot path would add a
     * write to every request to keep a table tidy.
     */
    public function sweep(): int
    {
        return $this->database->execute(
            'DELETE FROM `rate_limit_buckets` WHERE `window_start` < FROM_UNIXTIME(:cutoff)',
            ['cutoff' => time() - 7200],
        );
    }

    /**
     * The key is namespaced by bucket and by subject, and the e-mail is hashed
     * rather than stored: the table would otherwise accumulate every address
     * anyone ever tried, which is a personal-data retention problem created by
     * a security control.
     */
    private static function key(string $bucket, string $subject): string
    {
        return $bucket . ':' . substr(hash('sha256', strtolower($subject)), 0, 32);
    }
}
