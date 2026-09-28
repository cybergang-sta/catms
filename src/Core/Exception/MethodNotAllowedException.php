<?php

declare(strict_types=1);

namespace App\Core\Exception;

/**
 * 405 METHOD_NOT_ALLOWED — the path exists, the verb does not.
 *
 * Carries the allowed verbs in `details.allowed` and in the `Allow` header, both
 * of which RFC 9110 §15.5.6 requires. A separate code from NOT_FOUND because
 * the two mean different things operationally: a 404 is usually a wrong client
 * URL, a 405 is usually a wrong client verb.
 */
final class MethodNotAllowedException extends CatmsException
{
    /**
     * @param list<string> $allowed
     */
    public function __construct(array $allowed)
    {
        $allowed = array_values(array_unique($allowed));
        sort($allowed);

        parent::__construct(
            'METHOD_NOT_ALLOWED',
            'This endpoint does not support that HTTP method.',
            405,
            ['allowed' => $allowed],
            ['Allow' => implode(', ', $allowed)],
        );
    }
}
