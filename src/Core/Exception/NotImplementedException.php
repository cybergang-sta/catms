<?php

declare(strict_types=1);

namespace App\Core\Exception;

/**
 * 501 NOT_IMPLEMENTED — the route exists in `config/routes.php` but its
 * controller action has not been written yet.
 *
 * This class exists so the scaffold can ship the *complete* documented route
 * table, with permissions attached, before every controller is built. The
 * alternative — omitting unwritten routes — would make the route table
 * incomplete in exactly the way that hides work, and would let a route be
 * shipped later with no permission.
 *
 * It is also the reason permission checking runs before handler resolution:
 * an unimplemented route still refuses an unauthenticated caller, so a
 * half-finished endpoint can never become an open one by accident.
 */
final class NotImplementedException extends CatmsException
{
    public function __construct(string $routeName)
    {
        parent::__construct(
            'NOT_IMPLEMENTED',
            'This endpoint is not implemented yet.',
            501,
            ['route' => $routeName],
        );
    }
}
