<?php

declare(strict_types=1);

namespace App\Core\Exception;

/**
 * 400 MALFORMED_REQUEST — the body or a query parameter could not be understood.
 *
 * Distinct from ValidationException on purpose: a body that is not JSON, or a
 * `per_page=abc`, is a client bug and a different diagnostic from "email is not
 * an e-mail address". Retrying a 400 unchanged will never succeed.
 */
final class MalformedRequestException extends CatmsException
{
    public function __construct(string $message = 'The request could not be parsed.', array $details = [])
    {
        parent::__construct('MALFORMED_REQUEST', $message, 400, $details);
    }
}
