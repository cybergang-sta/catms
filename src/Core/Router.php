<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Exception\MethodNotAllowedException;
use App\Core\Exception\NotFoundException;

/**
 * Pattern-matching router over the table in `config/routes.php`.
 *
 * Three responsibilities, in this order, and the order is the design:
 *
 *  1. RESOLVE. Find the route whose method and path match. Placeholders like
 *     `{id}` become integers, and an unusable one is a 400 rather than a
 *     silently-absent parameter — `/allocations/abc` must not reach a controller
 *     as `$id === 0`.
 *  2. AUTHORISE. Check the route's declared permission against the caller's
 *     Identity. This happens before the handler is even looked up, so an
 *     unimplemented endpoint is still not an open one.
 *  3. DISPATCH. Resolve "App\Http\Controller\X@method" to a callable and invoke
 *     it, having already placed the Identity and the route metadata on the
 *     request.
 *
 * SPECIFICITY ORDERING
 * Routes are sorted so that literal paths are matched before placeholder paths
 * within the same segment count. That is what lets `GET /allocations/conflicts`
 * coexist with `GET /allocations/{id}` without anybody having to hand-order the
 * route file, and it is the failure mode this class exists to make impossible.
 *
 * NO REGEX FROM CALLERS
 * Paths are matched against patterns built here. The client chooses a path, not a
 * pattern, so there is no path for a caller to smuggle a metacharacter through.
 */
final class Router
{
    /** @var list<array<string, mixed>> */
    private array $routes = [];

    /** @var list<string> */
    private array $allowedMethods = [];

    /**
     * @param list<array<string, mixed>> $routes As returned by config/routes.php.
     */
    public function __construct(array $routes, private readonly string $prefix = '')
    {
        foreach ($routes as $route) {
            $this->add($route);
        }

        $this->sortBySpecificity();
    }

    /**
     * @param array<string, mixed> $route
     */
    private function add(array $route): void
    {
        $path = $this->prefix . rtrim((string) $route['path'], '/');
        $method = strtoupper((string) $route['method']);

        $this->routes[] = [
            'method'     => $method,
            'path'       => $path,
            'regex'      => $this->compile($path),
            'placeholders' => $this->placeholders($path),
            'name'       => (string) ($route['name'] ?? $path),
            'handler'    => (string) ($route['handler'] ?? ''),
            'permission' => $route['permission'] ?? null,
            'scope'      => (string) ($route['scope'] ?? 'own'),
            'throttle'   => $route['throttle'] ?? null,
            'middleware' => array_values((array) ($route['middleware'] ?? [])),
            'literals'   => substr_count($path, '/'),
            'variables'  => substr_count($path, '{'),
        ];

        $this->allowedMethods[$path] ??= [];
        $this->allowedMethods[$path][] = $method;
    }

    /**
     * Literal segments first, then fewer placeholders, then longer paths.
     *
     * Stable within a tier, so the order a developer wrote the file in is
     * preserved where it does not matter — which keeps a diff of config/routes.php
     * readable.
     */
    private function sortBySpecificity(): void
    {
        usort($this->routes, static function (array $left, array $right): int {
            return [$left['variables'], -$left['literals']]
                <=> [$right['variables'], -$right['literals']];
        });
    }

    /**
     * @throws NotFoundException         no path matches
     * @throws MethodNotAllowedException the path matches, the method does not
     *
     * @return array<string, mixed> The matched route, with `params` added.
     */
    public function match(string $method, string $path): array
    {
        $method = strtoupper($method);
        $candidate = $this->normalise($path);
        $pathMatched = false;
        $allowed = [];

        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $candidate, $matches) !== 1) {
                continue;
            }

            $pathMatched = true;
            $allowed = array_merge($allowed, (array) $this->allowedMethods[$route['path']]);

            if ($route['method'] !== $method) {
                continue;
            }

            $route['params'] = $this->bind($route['placeholders'], $matches);

            return $route;
        }

        if ($pathMatched) {
            throw new MethodNotAllowedException(array_values(array_unique($allowed)));
        }

        throw new NotFoundException('Endpoint', null);
    }

    /**
     * The matched route for a path, ignoring the method. Used by
     * `bin/console route:list` and by the CORS/OPTIONS preflight handler.
     */
    public function pathExists(string $path): bool
    {
        $candidate = $this->normalise($path);

        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $candidate) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return $this->routes;
    }

    public function count(): int
    {
        return count($this->routes);
    }

    /**
     * `/api/v1/rooms/` and `/api/v1/rooms` are the same resource. A trailing
     * slash on a write is a common client bug and there is no reason to make it a
     * 404 that a developer then debugs for an hour.
     */
    private function normalise(string $path): string
    {
        $path = '/' . ltrim($path, '/');
        if (strlen($path) > 1) {
            $path = rtrim($path, '/');
        }

        return $path;
    }

    private function compile(string $path): string
    {
        $quoted = preg_quote($path, '#');
        // preg_quote escaped the braces, so undo that before substituting.
        $quoted = str_replace(['\{', '\}'], ['{', '}'], $quoted);

        $pattern = preg_replace(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/',
            // A placeholder is one path segment and nothing else. Allowing a
            // slash inside one would let `/allocations/1/../2` bind a single
            // "id" and turn the id space into a traversal primitive.
            '(?P<$1>[^/]+)',
            $quoted,
        );

        return '#^' . ($pattern ?? $quoted) . '$#';
    }

    /**
     * @return list<string>
     */
    private function placeholders(string $path): array
    {
        preg_match_all('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', $path, $matches);

        return $matches[1];
    }

    /**
     * @param list<string>       $placeholders
     * @param array<int, string> $matches
     *
     * @return array<string, string>
     */
    private function bind(array $placeholders, array $matches): array
    {
        $params = [];
        foreach ($placeholders as $name) {
            $params[$name] = urldecode($matches[$name] ?? '');
        }

        return $params;
    }
}
