<?php

declare(strict_types=1);

/**
 * CATMS — HTTP front controller.
 *
 * The single PHP file the web server is allowed to execute. Everything else in
 * the repository is either a class reached through the autoloader or a CLI
 * script; the document root is this directory, so nothing above it is web
 * reachable (see docker/nginx.conf, and .htaccess for Apache).
 *
 * RESPONSIBILITIES, AND NOTHING ELSE
 *   1. Find the autoloader. Works from a composer install, and falls back to a
 *      tiny PSR-4 loader so the application still boots before `composer install`
 *      has ever been run — useful when diagnosing a broken vendor directory.
 *   2. Boot configuration and refuse to serve if it is unsafe for the
 *      environment (Config::assertProductionSafe).
 *   3. Build the request, hand it to the kernel, send the response.
 *   4. Guarantee that something is always sent, even if boot itself failed.
 *
 * NO BUSINESS LOGIC BELOW THIS LINE. A controller that needs a database
 * connection asks the container for one; it does not open it here.
 */


use App\Core\App;
use App\Core\Exception\ConfigurationException;
use App\Core\Request;
use App\Core\Response;

$basePath = dirname(__DIR__);

// Failures before the autoloader exists cannot be logged through the
// application logger, so this one handler writes to the error log directly.
// It is reached only when boot itself failed, which means the reason is an
// operator problem — the client is told the service is unavailable and nothing
// more, and the detail has already gone to the log.
$emergency = static function (int $status, string $message): never {
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
    }

    // The envelope is identical to every other error the API returns
    // (docs/API.md §1), so a client never has to special-case a boot failure.
    echo json_encode([
        'data'  => null,
        'meta'  => (object) [],
        'error' => [
            'code'    => 'SERVICE_UNAVAILABLE',
            'message' => $message,
            'details' => (object) [],
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    exit;
};

// ─── 1. Autoloader ──────────────────────────────────────────────────────────
if (is_file($basePath . '/vendor/autoload.php')) {
    require $basePath . '/vendor/autoload.php';
} else {
    // Minimal PSR-4 fallback so the failure mode is "the application boots and
    // tells you what is wrong" rather than a blank 500 with a stack trace in a
    // log nobody is reading.
    spl_autoload_register(static function (string $class) use ($basePath): void {
        $prefixes = ['App\\' => $basePath . '/src/', 'Tests\\' => $basePath . '/tests/'];
        foreach ($prefixes as $prefix => $directory) {
            if (!str_starts_with($class, $prefix)) {
                continue;
            }

            $relative = substr($class, strlen($prefix));
            $file = $directory . str_replace('\\', '/', $relative) . '.php';
            if (is_file($file)) {
                require $file;
            }

            return;
        }
    });
}

// ─── 2. Boot ────────────────────────────────────────────────────────────────
try {
    $app = new App($basePath);
    $app->container()->typed(\App\Core\Config::class)->assertProductionSafe();
} catch (ConfigurationException $exception) {
    // A configuration failure is an operator problem, not a user problem, and it
    // must never be reported as one. The detail goes to the error log; the
    // client gets a bare 500 with the standard envelope, because a client that
    // learns "APP_KEY must be at least 32 characters" has learned about the
    // deployment.
    error_log('[catms] configuration rejected: ' . $exception->getMessage());
    $emergency(500, 'The service is not correctly configured.');
} catch (Throwable $exception) {
    error_log('[catms] boot failed: ' . $exception->getMessage());
    $emergency(500, 'The service is temporarily unavailable.');
}

// ─── 3. Request ─────────────────────────────────────────────────────────────
$response = null;

try {
    $response = $app->handle(Request::fromGlobals());
} catch (Throwable $exception) {
    // App::handle() is written to catch everything and route it through the
    // ExceptionHandler, so reaching this means a bug in the kernel itself.
    error_log('[catms] unhandled kernel failure: ' . $exception->getMessage());
    $response = Response::error('SERVICE_UNAVAILABLE', 'The service is temporarily unavailable.', 500);
}

// ─── 4. Send ────────────────────────────────────────────────────────────────
if (!$response instanceof Response) {
    $emergency(500, 'The service is temporarily unavailable.');
}

$response->send();
