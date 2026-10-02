<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Authenticator;
use App\Core\Rbac;
use App\Core\Request;
use App\Core\Response;

/**
 * Authenticates the caller, then authorises the matched route.
 *
 * BOTH CHECKS LIVE IN ONE MIDDLEWARE, DELIBERATELY
 * The route table already carries the permission, so the two happen at the same
 * point in the pipeline with the same inputs. Splitting them means an endpoint
 * can be added to `config/routes.php` with `permission: null` by accident and end
 * up both unauthenticated and unauthorised without anybody noticing until a
 * reviewer asks why `/users` is missing from the 401 test.
 *
 * THE IDENTITY GOES ON THE REQUEST, NEVER ON A GLOBAL
 * That is what lets the same kernel serve two requests in one process without the
 * second one seeing the first one's user, and it is why a controller can be tested
 * by constructing a Request with an `identity` attribute rather than by reaching
 * into a container.
 */
final class RequireAuthentication implements Middleware
{
    public function __construct(
        private readonly Authenticator $authenticator,
        private readonly Rbac $rbac,
    ) {
    }

    public function process(Request $request, callable $next): Response
    {
        // Throws UnauthorizedException, which ExceptionHandler renders as 401.
        $identity = $this->authenticator->authenticate($request->bearerToken());
        $this->rbac->assert($identity, $this->permissionOf($request));

        $request->setAttribute('identity', $identity);
        $request->setAttribute('scope', $this->rbac->effectiveScope($identity, $this->scopeOf($request)));

        return $next($request);
    }

    private function permissionOf(Request $request): ?string
    {
        $value = $request->attribute('route.permission');

        return is_string($value)
            ? $value
            : null;
    }

    private function scopeOf(Request $request): string
    {
        $value = $request->attribute('route.scope');

        return is_string($value)
            ? $value
            : 'own';
    }
}
