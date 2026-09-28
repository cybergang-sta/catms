<?php

declare(strict_types=1);

namespace App\Core\Exception;

/**
 * 429 RATE_LIMITED — the caller exhausted a bucket.
 *
 * The bucket name and limit are returned, and `Retry-After` is set, so a
 * well-behaved client can back off correctly instead of hammering. The limit
 * itself is disclosed on purpose: the login and register limits exist to slow
 * credential stuffing, not to be a secret an attacker has to guess.
 *
 * Raising a limit is allowed (see `docs/IMPLEMENTATION.md` §9 — a shared NAT
 * during UAT is a real case) but must come with a rationale in the pull
 * request. Disabling the limiter is a security-review finding.
 */
final class RateLimitException extends CatmsException
{
    public function __construct(
        private readonly string $bucket,
        private readonly int $limit,
        private readonly int $retryAfterSeconds,
    ) {
        parent::__construct(
            'RATE_LIMITED',
            'Too many requests. Please retry later.',
            429,
            [
                'bucket'   => $bucket,
                'limit'    => $limit,
                'retry_after_seconds' => $retryAfterSeconds,
            ],
            ['Retry-After' => (string) $retryAfterSeconds],
        );
    }

    public function bucket(): string
    {
        return $this->bucket;
    }

    public function limit(): int
    {
        return $this->limit;
    }

    public function retryAfterSeconds(): int
    {
        return $this->retryAfterSeconds;
    }
}
