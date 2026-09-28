<?php

declare(strict_types=1);

namespace App\Core\Exception;

/**
 * 401 UNAUTHENTICATED — no usable credential was presented.
 *
 * The caller may be anonymous, the token may be malformed, expired, or revoked.
 * The message is deliberately identical in every case: distinguishing them tells
 * an attacker which half of the attack worked, and is a user-enumeration vector
 * through the token endpoint.
 */
final class UnauthorizedException extends CatmsException
{
    public function __construct(
        string $message = 'Authentication is required.',
        array $details = [],
    ) {
        parent::__construct('UNAUTHENTICATED', $message, 401, $details, ['WWW-Authenticate' => 'Bearer']);
    }
}
