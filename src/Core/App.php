<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Exception\NotFoundException;
use App\Core\Exception\NotImplementedException;
use App\Domain\Repository\RefreshTokenRepository;
use App\Domain\Repository\UserRepository;
use App\Domain\Service\AccountService;
use App\Domain\Service\AllocationService;
use App\Domain\Service\AuditTrail;
use App\Domain\Service\CalendarService;
use App\Domain\Service\CourseService;
use App\Domain\Service\NotificationService;
use App\Domain\Service\ReportService;
use App\Domain\Service\RoomService;
use App\Domain\Service\TimetableService;
use App\Http\Middleware\DisableWhenMaintenance;
use App\Http\Middleware\Middleware;
use App\Http\Middleware\RequireAuthentication;
use App\Infrastructure\Persistence\Mysql\MysqlRefreshTokenRepository;
use App\Infrastructure\Persistence\Mysql\MysqlUserRepository;
use ReflectionMethod;
use RuntimeException;
use Throwable;

/**
 * The application: wiring, request lifecycle, and nothing else.
 *
 * THE LIFECYCLE, IN ORDER
 *   1. Assign a request id. Every log line and every error response carries it,
 *      so a user reporting "it failed at 09:14" maps to exactly one log entry.
 *   2. Run the route's middleware (maintenance, authentication + authorisation).
 *   3. Resolve the handler. A route whose controller does not exist yet returns
 *      501 — but only *after* step 2, so an unimplemented endpoint is never an
 *      unauthenticated one.
 *   4. Call it. Controllers return a Response and never echo.
 *   5. Catch everything, including Throwable that is not an Exception, and turn
 *      it into the documented envelope.
 *
 * WHY THE WIRING IS HERE AND NOT IN A FRAMEWORK CONFIG
 * The whole dependency graph is below, in one readable list. That list is the
 * fastest possible answer to "what does a request actually touch", which is the
 * question a reviewer of a security change is asking.
 */
final class App
{
    /** Middleware short names accepted in config/routes.php. */
    private const MIDDLEWARE = [
        'RequireAuthentication'   => RequireAuthentication::class,
        'DisableWhenMaintenance'  => DisableWhenMaintenance::class,
    ];

    private Container $container;

    private Router $router;

    private ExceptionHandler $exceptionHandler;

    public function __construct(private readonly string $basePath)
    {
        $this->container = new Container();
        $this->registerCoreServices();
        $this->registerMiddleware();

        /** @var Router $router */
        $router = $this->container->get(Router::class);
        $this->router = $router;

        /** @var ExceptionHandler $handler */
        $handler = $this->container->get(ExceptionHandler::class);
        $this->exceptionHandler = $handler;
    }

    /**
     * Handle one request and return the response. The caller sends it.
     */
    public function handle(Request $request): Response
    {
        $requestId = $this->requestId();
        $request->setAttribute('request_id', $requestId);
        $request->setAttribute('started_at', microtime(true));
        $this->container->typed(AuditTrail::class)->remember($request->ip(), $request->userAgent());

        try {
            $route = $this->router->match($request->method(), $request->path());
        } catch (Throwable $exception) {
            // A browser opening `/`, `/today` or `/week` is not calling the API.
            // Those paths have no route on purpose: the document is the PWA shell,
            // and every data request goes to `/api/v1`. Probes stay JSON.
            if ($this->shouldServeShell($request, $exception)) {
                $shell = $this->shellDocument();
                if ($shell !== null) {
                    return $shell->withHeader('X-Request-Id', $requestId);
                }
            }

            // No route means no middleware, so an unauthenticated request to a
            // wrong URL gets a plain 404 rather than a 401. That is correct: the
            // client should fix the URL before it authenticates.
            return $this->exceptionHandler->render($exception, $requestId);
        }

        $request->setAttribute('route', $route);
        $request->setAttribute('route.permission', $route['permission']);
        $request->setAttribute('route.scope', $route['scope']);
        $request->setAttribute('route.name', $route['name']);
        foreach ((array) $route['params'] as $name => $value) {
            $request->setAttribute('param.' . $name, $value);
        }

        try {
            return $this->dispatch($request, $route, $requestId);
        } catch (Throwable $exception) {
            return $this->exceptionHandler->render($exception, $requestId);
        }
    }

    /**
     * True for a navigation the PWA should paint, false for the API and probes.
     */
    private function shouldServeShell(Request $request, Throwable $exception): bool
    {
        if (!$exception instanceof NotFoundException) {
            return false;
        }

        $method = $request->method();
        if ($method !== 'GET' && $method !== 'HEAD') {
            return false;
        }

        $path = $request->path();
        if ($path === '/health' || $path === '/metrics') {
            return false;
        }

        $prefix = $this->container->typed(Config::class)->apiPrefix();
        if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
            return false;
        }

        return pathinfo($path, PATHINFO_EXTENSION) === '';
    }

    /**
     * The installable client. Missing file means the API-only layout, and the
     * caller falls back to the JSON 404 rather than inventing a page.
     */
    private function shellDocument(): ?Response
    {
        $file = $this->basePath . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'index.html';
        if (!is_file($file)) {
            return null;
        }

        $html = file_get_contents($file);

        return is_string($html) ? Response::html($html) : null;
    }

    public function container(): Container
    {
        return $this->container;
    }

    public function router(): Router
    {
        return $this->router;
    }

    /**
     * @param array<string, mixed> $route
     */
    private function dispatch(Request $request, array $route, string $requestId): Response
    {
        // The handler is resolved INSIDE the terminal closure, not here. Resolving
        // it out here would throw NotImplementedException before a single
        // middleware has run, and an unimplemented route would then answer 501
        // to an anonymous caller instead of 401 — which both contradicts the
        // documented ordering and turns the route table into a map of which
        // endpoints exist for anyone who asks. Resolving it here also means the
        // reflection and the controller construction are only paid for by a
        // request that has already been authenticated and authorised.
        $startedAt = (float) $request->attribute('started_at', microtime(true));

        $terminal = function (Request $request) use ($route, $startedAt): Response {
            $handler = $this->resolveHandler($route);

            /** @var Response $response */
            $response = $handler($request);

            // NFR-PERF-01: every response carries its own server-side duration, so
            // a slow endpoint is visible without waiting for a slow client.
            $elapsed = (int) round((microtime(true) - $startedAt) * 1000);

            return $response->withHeader('X-Response-Time-Ms', (string) $elapsed);
        };

        $pipeline = array_reduce(
            $this->pipelineFor($route),
            function (callable $next, Middleware $middleware): callable {
                return fn (Request $request): Response => $middleware->process($request, $next);
            },
            $terminal,
        );

        $response = $pipeline($request);
        $response = $response->withHeader('X-Request-Id', $requestId);

        $throttle = $route['throttle'];
        if (is_string($throttle) && $throttle !== '') {
            $response = $this->withRateLimitHeaders($response, $throttle, $request);
        }

        return $response;
    }

    /**
     * Resolve "App\Http\Controller\X@method" to a callable.
     *
     * Called from the terminal closure, so this only runs for a request that has
     * already passed the whole middleware pipeline. The controller is still
     * constructed at most once per request: `resolveHandler` caches it in the
     * container, and an unimplemented route never reaches here twice.
     *
     * @param array<string, mixed> $route
     */
    private function resolveHandler(array $route): callable
    {
        $spec = (string) $route['handler'];
        [$class, $method] = array_pad(explode('@', $spec, 2), 2, '__invoke');

        if (!class_exists($class) || !method_exists($class, $method)) {
            // Documented in config/routes.php, not yet built. 501 rather than 404,
            // so an authorised client can tell "this does not exist" from "this is
            // not finished", and the security review can enumerate the gap with
            // `routes --missing`. Reached only after authentication, so it reveals
            // nothing to an anonymous caller.
            throw new NotImplementedException((string) $route['name']);
        }

        $reflection = new ReflectionMethod($class, $method);
        if ($reflection->getNumberOfRequiredParameters() > 1) {
            throw new RuntimeException(
                sprintf('Controller action %s::%s must take only the Request.', $class, $method)
            );
        }

        if (!$this->container->has($class)) {
            $this->container->instance($class, new $class($this->container));
        }

        $controller = $this->container->typed($class);

        return function (Request $request) use ($controller, $method): Response {
            $result = $controller->{$method}($request);

            if (!$result instanceof Response) {
                throw new RuntimeException(
                    sprintf('%s::%s must return a Response.', $controller::class, $method)
                );
            }

            return $result;
        };
    }

    /**
     * Build the middleware stack for a route, outermost first.
     *
     * Maintenance mode wraps authentication so that a write during a deploy is
     * refused with 503 rather than 401 — the caller is logged in, and telling
     * them otherwise sends them round the login loop during the deploy.
     *
     * @param array<string, mixed> $route
     *
     * @return list<Middleware>
     */
    private function pipelineFor(array $route): array
    {
        $names = array_merge(['DisableWhenMaintenance'], (array) $route['middleware']);

        $stack = [];
        foreach ($names as $name) {
            if (!isset(self::MIDDLEWARE[$name])) {
                throw new RuntimeException(
                    sprintf('Unknown middleware "%s" on route "%s".', $name, (string) $route['name'])
                );
            }

            /** @var Middleware $middleware */
            $middleware = $this->container->get(self::MIDDLEWARE[$name]);
            $stack[] = $middleware;
        }

        return $stack;
    }

    private function withRateLimitHeaders(Response $response, string $bucket, Request $request): Response
    {
        $identity = $request->attribute('identity');
        $subject = 'anonymous';
        if ($identity instanceof Identity) {
            $subject = 'user:' . $identity->userId();
        } elseif ($request->ip() !== null) {
            $subject = 'ip:' . $request->ip();
        }

        try {
            /** @var RateLimiter $limiter */
            $limiter = $this->container->get(RateLimiter::class);
            $status = $limiter->status($bucket, $subject);
        } catch (Throwable $exception) {
            // The headers are an observability nicety. A rate-limit table that is
            // briefly unavailable must not turn a successful read into a 500.
            $this->container->typed(Logger::class)->debug('Rate-limit status unavailable.', [
                'bucket' => $bucket,
                'reason' => $exception->getMessage(),
            ]);

            return $response;
        }

        return $response
            ->withHeader('X-RateLimit-Limit', (string) $status['limit'])
            ->withHeader('X-RateLimit-Remaining', (string) $status['remaining'])
            ->withHeader('X-RateLimit-Reset', (string) $status['reset']);
    }

    private function requestId(): string
    {
        $existing = $_SERVER['HTTP_X_REQUEST_ID'] ?? null;
        if (is_string($existing) && preg_match('/^[A-Za-z0-9._-]{8,64}$/', $existing) === 1) {
            return $existing;
        }

        return bin2hex(random_bytes(16));
    }

    // -----------------------------------------------------------------------
    // Wiring
    // -----------------------------------------------------------------------

    private function registerCoreServices(): void
    {
        $this->container->singleton(
            Config::class,
            fn (): Config => Config::load($this->basePath),
        );

        $this->container->singleton(
            Logger::class,
            function (Container $c): Logger {
                /** @var Config $config */
                $config = $c->get(Config::class);

                return new Logger(
                    $config->storagePath('logs'),
                    $config->get('LOG_LEVEL', Logger::WARNING) ?? Logger::WARNING,
                );
            },
        );

        $this->container->singleton(
            Database::class,
            fn (Container $c): Database => new Database(
                $c->typed(Config::class),
                $c->typed(Logger::class),
            ),
        );

        $this->container->singleton(
            Rbac::class,
            function (Container $c): Rbac {
                /** @var Config $config */
                $config = $c->get(Config::class);
                /** @var array{permissions: array<string, array{group: string, description: string}>, roles: array<string, array{label: string, description: string, permissions: list<string>}>} $definition */
                $definition = require $config->basePath('config/rbac.php');

                return Rbac::fromDefinition($definition);
            },
        );

        $this->container->singleton(
            Router::class,
            function (Container $c): Router {
                /** @var Config $config */
                $config = $c->get(Config::class);
                /** @var list<array<string, mixed>> $routes */
                $routes = require $config->basePath('config/routes.php');

                return new Router($routes, $config->apiPrefix());
            },
        );

        $this->container->singleton(
            ExceptionHandler::class,
            fn (Container $c): ExceptionHandler => new ExceptionHandler(
                $c->typed(Logger::class),
                $c->typed(Config::class)->isDebug(),
            ),
        );

        $this->container->singleton(
            Jwt::class,
            function (Container $c): Jwt {
                /** @var Config $config */
                $config = $c->get(Config::class);

                return new Jwt($config->appKey());
            },
        );

        $this->container->singleton(
            UserRepository::class,
            fn (Container $c): UserRepository => new MysqlUserRepository($c->typed(Database::class)),
        );

        $this->container->singleton(
            RefreshTokenRepository::class,
            fn (Container $c): RefreshTokenRepository => new MysqlRefreshTokenRepository($c->typed(Database::class)),
        );

        $this->container->singleton(
            SecurityEventRecorder::class,
            fn (Container $c): SecurityEventRecorder => new SecurityEventRecorder(
                $c->typed(Database::class),
                $c->typed(Logger::class),
            ),
        );

        $this->container->singleton(
            RateLimiter::class,
            fn (Container $c): RateLimiter => new RateLimiter($c->typed(Database::class)),
        );

        $this->container->singleton(
            Authenticator::class,
            function (Container $c): Authenticator {
                /** @var Config $config */
                $config = $c->get(Config::class);

                return new Authenticator(
                    $c->typed(Jwt::class),
                    $c->typed(UserRepository::class),
                    $c->typed(SecurityEventRecorder::class),
                    $c->typed(Logger::class),
                    $config->int('PASSWORD_BCRYPT_COST', 12),
                );
            },
        );

        $this->container->singleton(
            Validator::class,
            fn (): Validator => new Validator(),
        );

        $this->container->singleton(
            PasswordPolicy::class,
            function (Container $c): PasswordPolicy {
                /** @var Config $config */
                $config = $c->get(Config::class);

                return new PasswordPolicy($config->int('PASSWORD_BCRYPT_COST', 12));
            },
        );

        $this->registerDomainServices();
    }

    /**
     * Timetable reads take Database and Logger only. Every other service has a
     * wider constructor, so it is registered by name below.
     *
     * @var list<class-string>
     */
    private const DOMAIN_SERVICES = [
        TimetableService::class,
    ];

    private function registerDomainServices(): void
    {
        foreach (self::DOMAIN_SERVICES as $service) {
            $this->container->singleton(
                $service,
                fn (Container $c): object => new $service(
                    $c->typed(Database::class),
                    $c->typed(Logger::class),
                ),
            );
        }

        $this->container->singleton(
            AuditTrail::class,
            fn (Container $c): AuditTrail => new AuditTrail($c->typed(Database::class)),
        );

        $this->container->singleton(
            RoomService::class,
            fn (Container $c): RoomService => new RoomService(
                $c->typed(Database::class),
                $c->typed(AuditTrail::class),
            ),
        );

        $this->container->singleton(
            CourseService::class,
            fn (Container $c): CourseService => new CourseService(
                $c->typed(Database::class),
                $c->typed(AuditTrail::class),
            ),
        );

        $this->container->singleton(
            CalendarService::class,
            fn (Container $c): CalendarService => new CalendarService(
                $c->typed(Database::class),
                $c->typed(AuditTrail::class),
            ),
        );

        $this->container->singleton(
            ReportService::class,
            fn (Container $c): ReportService => new ReportService($c->typed(Database::class)),
        );

        $this->container->singleton(
            NotificationService::class,
            fn (Container $c): NotificationService => new NotificationService(
                $c->typed(Database::class),
                $c->typed(UserRepository::class),
            ),
        );

        $this->container->singleton(
            AccountService::class,
            function (Container $c): AccountService {
                /** @var Config $config */
                $config = $c->get(Config::class);

                return new AccountService(
                    $c->typed(Database::class),
                    $c->typed(Logger::class),
                    $c->typed(UserRepository::class),
                    $c->typed(RefreshTokenRepository::class),
                    $c->typed(PasswordPolicy::class),
                    $c->typed(SecurityEventRecorder::class),
                    $c->typed(AuditTrail::class),
                    !$config->isProduction(),
                );
            },
        );

        $this->container->singleton(
            AllocationService::class,
            fn (Container $c): AllocationService => new AllocationService(
                $c->typed(Database::class),
                $c->typed(Logger::class),
                $c->typed(Config::class),
                $c->typed(AuditTrail::class),
                $c->typed(NotificationService::class),
            ),
        );
    }

    private function registerMiddleware(): void
    {
        $this->container->singleton(
            RequireAuthentication::class,
            fn (Container $c): RequireAuthentication => new RequireAuthentication(
                $c->typed(Authenticator::class),
                $c->typed(Rbac::class),
            ),
        );

        $this->container->singleton(
            DisableWhenMaintenance::class,
            fn (Container $c): DisableWhenMaintenance => new DisableWhenMaintenance(
                $c->typed(Config::class)->storagePath('maintenance.flag'),
            ),
        );
    }
}
