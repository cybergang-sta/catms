<?php

declare(strict_types=1);

namespace App\Core\Exception;

/**
 * 409 CONFLICT — the request is valid but the resource is not in a state that
 * allows it.
 *
 * Also the home of `STALE_WRITE`: a PATCH that carries an `If-Match` tag no
 * longer matching means two administrators edited the same allocation, and
 * silently overwriting the first edit is how a published timetable becomes wrong
 * without anybody being told. Same status, different machine-readable code.
 */
final class ConflictException extends CatmsException
{
    public const STALE_WRITE = 'STALE_WRITE';

    public function __construct(
        string $message = 'The resource is not in a state that allows this change.',
        string $errorCode = 'CONFLICT',
        array $details = [],
    ) {
        parent::__construct($errorCode, $message, 409, $details);
    }
}
