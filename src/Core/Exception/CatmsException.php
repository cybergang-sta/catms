<?php

declare(strict_types=1);

namespace App\Core\Exception;

use RuntimeException;
use Throwable;

/**
 * Base class for every exception the application converts into a documented API
 * error response.
 *
 * Why a hierarchy rather than a switch on exception class: the HTTP status, the
 * stable `error.code` string and the optional `error.details` payload travel
 * together. Throwing `new ForbiddenException('allocation:override')` is
 * impossible to get wrong in a way that leaks an internal message, because the
 * message a client sees is derived from the class, not from whatever text the
 * thrower happened to write.
 *
 * The `error.code` values are part of the public contract (`docs/API.md` §4).
 * Renaming one is a breaking API change.
 */
class CatmsException extends RuntimeException
{
    /**
     * @param array<string, mixed> $details Machine-readable context. Must never
     *                                    contain personal data or secrets.
     * @param array<string, string> $headers Extra response headers, e.g. Retry-After.
     */
    public function __construct(
        private readonly string $errorCode,
        string $message,
        private readonly int $status = 500,
        private readonly array $details = [],
        private readonly array $headers = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function status(): int
    {
        return $this->status;
    }

    /**
     * @return array<string, mixed>
     */
    public function details(): array
    {
        return $this->details;
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * The message safe to return to a client. Overridden by anything that must
     * not disclose internals; the default already suppresses the exception
     * class and file, which is the part that leaks file paths.
     */
    public function publicMessage(): string
    {
        return $this->getMessage();
    }
}
