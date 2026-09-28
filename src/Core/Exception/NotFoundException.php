<?php

declare(strict_types=1);

namespace App\Core\Exception;

/**
 * 404 NOT_FOUND — no such resource, *or* it exists but is outside the caller's
 * department.
 *
 * The two are the same response on purpose (NFR-SCALE-02, tenant isolation).
 * Returning 403 for "belongs to another department" would confirm the row
 * exists, which turns the id space into an enumeration oracle. Repositories
 * therefore translate an empty result into this exception and never into a
 * Forbidden one.
 */
final class NotFoundException extends CatmsException
{
    public function __construct(string $resource = 'Resource', ?int $id = null)
    {
        $details = $id === null ? [] : ['id' => $id];

        parent::__construct('NOT_FOUND', $resource . ' not found.', 404, $details);
    }
}
