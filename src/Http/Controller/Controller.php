<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Core\Config;
use App\Core\Container;
use App\Core\Database;
use App\Core\Identity;
use App\Core\Logger;
use App\Core\Rbac;
use App\Core\Request;
use App\Core\SecurityEventRecorder;
use App\Core\Validator;
use App\Domain\Repository\RefreshTokenRepository;
use App\Domain\Repository\UserRepository;
use RuntimeException;

/**
 * Base class for every HTTP controller.
 *
 * WHAT A CONTROLLER IS ALLOWED TO DO
 * Read the request, call one service, return a Response. It may not contain a
 * SQL statement, a business rule, or an `if` that decides what a user is allowed
 * to see — authorisation was already settled by the middleware, and business
 * rules belong in `App\Domain\Service`. `docs/IMPLEMENTATION.md` §8 makes this
 * the first step of "add a new endpoint", and it is the reason a controller can
 * be read in thirty seconds during a security review.
 *
 * WHY SERVICE LOCATORS RATHER THAN CONSTRUCTOR INJECTION EVERYWHERE
 * The container is injected once, here, and the concrete services are pulled
 * lazily. A controller therefore has one constructor argument instead of eight,
 * and a test can replace a service through the container without subclassing
 * anything. The cost is that a typo in a service name is a runtime error rather
 * than a compile error, which is why every accessor below asserts the type.
 */
abstract class Controller
{
    public function __construct(protected readonly Container $container)
    {
    }

    protected function config(): Config
    {
        return $this->service(Config::class);
    }

    protected function database(): Database
    {
        return $this->service(Database::class);
    }

    protected function logger(): Logger
    {
        return $this->service(Logger::class);
    }

    protected function validator(): Validator
    {
        return $this->service(Validator::class);
    }

    protected function rbac(): Rbac
    {
        return $this->service(Rbac::class);
    }

    protected function users(): UserRepository
    {
        return $this->service(UserRepository::class);
    }

    protected function refreshTokens(): RefreshTokenRepository
    {
        return $this->service(RefreshTokenRepository::class);
    }

    protected function securityEvents(): SecurityEventRecorder
    {
        return $this->service(SecurityEventRecorder::class);
    }

    /**
     * The authenticated caller.
     *
     * Throws rather than returning null: a controller action only runs after
     * `RequireAuthentication` has run, so an absent identity means the route
     * table and the middleware list disagree — a bug that should be loud, not a
     * null that turns into a null-pointer three frames later.
     */
    protected function identity(Request $request): Identity
    {
        $identity = $request->attribute('identity');
        if (!$identity instanceof Identity) {
            throw new RuntimeException(
                'This action requires an authenticated caller. Add RequireAuthentication to the route.'
            );
        }

        return $identity;
    }

    /**
     * The effective data scope for this request: `own`, `department` or `any`.
     * Repositories take this and apply it, so a controller cannot forget.
     */
    protected function scope(Request $request): string
    {
        $scope = $request->attribute('scope', 'own');

        return is_string($scope) ? $scope : 'own';
    }

    protected function param(Request $request, string $name): int
    {
        return $request->intAttribute('param.' . $name);
    }

    protected function stringParam(Request $request, string $name): string
    {
        $value = $request->attribute('param.' . $name);

        return is_string($value) ? $value : '';
    }

    /**
     * Pagination, clamped.
     *
     * The ceiling is the important part. `?per_page=100000` against a table with
     * a few hundred thousand allocations is a denial of service served from the
     * database, and it is a one-line mistake to make in a client.
     *
     * @return array{page: int, per_page: int, offset: int}
     */
    protected function pagination(Request $request, int $defaultPerPage = 25, int $maxPerPage = 100): array
    {
        $page = max(1, (int) ($request->queryInt('page', 1) ?? 1));
        $perPage = (int) ($request->queryInt('per_page', $defaultPerPage) ?? $defaultPerPage);
        $perPage = min($maxPerPage, max(1, $perPage));

        return [
            'page'     => $page,
            'per_page' => $perPage,
            'offset'   => ($page - 1) * $perPage,
        ];
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    protected function service(string $id): object
    {
        $service = $this->container->get($id);
        if (!is_object($service)) {
            throw new RuntimeException(sprintf('Service "%s" is not an object.', $id));
        }

        return $service;
    }
}
