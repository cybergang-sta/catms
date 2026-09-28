<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Request;
use App\Core\Response;

/**
 * A request filter.
 *
 * Middleware is for cross-cutting concerns that must run for a *set* of routes,
 * declared in `config/routes.php` rather than inside a controller: authentication,
 * maintenance mode, CORS. Business rules are not middleware — they are services.
 *
 * A middleware may short-circuit by returning a Response without calling $next
 * (that is how an unauthenticated request gets its 401), or it may inspect and
 * enrich the request and pass it on.
 */
interface Middleware
{
    /**
     * @param callable(Request): Response $next
     */
    public function process(Request $request, callable $next): Response;
}
