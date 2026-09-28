<?php

declare(strict_types=1);

/**
 * Router for PHP's built-in development server.
 *
 *   composer serve          # php -S 0.0.0.0:8080 -t public public/router.php
 *
 * WHY THIS FILE IS NOT THE SAME AS public/index.php
 * The built-in server has no rewrite engine, so it hands *every* request to this
 * script and this script decides what to do with it. Production uses nginx
 * (docker/nginx.conf) or Apache (public/.htaccess), which do the rewriting in
 * configuration and reach public/index.php directly.
 *
 * THE RULES THAT MATTER
 *   1. A file that exists inside public/ is served as itself. This is what makes
 *      the service worker, the manifest and the PWA assets work at all.
 *   2. A path that resolves *outside* public/ is a 404, never execution.
 *      `realpath()` of a missing path is false — that is not an escape, it is
 *      an application route. Treating the two the same sent every API call to
 *      a JSON 404 before index.php ever ran.
 *   3. A GET with no file extension, outside `/api`, is the PWA shell
 *      (`index.html`). `/today` and `/week` are client routes, not endpoints.
 *   4. A path that resolves to a .php file is a 404, never execution. Without
 *      this, `php -S` happily serves and runs /src/Core/Config.php to anyone
 *      who asks, because it ignores the document root for router scripts.
 *
 * NOT FOR PRODUCTION. The built-in server is single-threaded and has no
 * timeouts, so one slow allocation blocks every other request.
 */


$publicRoot = __DIR__;
$basePath = dirname(__DIR__);
$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$path = is_string($path) ? rawurldecode($path) : '/';
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

$candidate = $publicRoot . $path;

// ── Reject traversal before touching the filesystem ─────────────────────────
// realpath() collapses `..` and resolves symlinks, so a comparison against the
// real document root catches `/../src/Core/Config.php`, `/public/../../.env`,
// and a symlink pointing outside the tree. Encoding tricks are already undone
// by rawurldecode above.
//
// A path that does not exist has no realpath. That is a route, not an escape:
// `/api/v1/health` and `/today` are not files, and both must reach the branch
// below rather than a 404 invented here.
$resolved = realpath($candidate);
$rootReal = realpath($publicRoot);

if ($rootReal === false || ($resolved !== false && !str_starts_with($resolved, $rootReal))) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'data'  => null,
        'meta'  => (object) [],
        'error' => ['code' => 'NOT_FOUND', 'message' => 'Not found.', 'details' => (object) []],
    ]);

    return true;
}

// ── Serve an existing file as itself ─────────────────────────────────────────
// Directories fall through to the front controller so that / and /admin/ reach
// index.php rather than producing a listing.
if (is_string($resolved) && is_file($resolved)) {
    if (str_ends_with($resolved, '.php')) {
        // A .php file inside public/ other than the front controller is a
        // mistake, not a route. There are none by design.
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'data'  => null,
            'meta'  => (object) [],
            'error' => ['code' => 'NOT_FOUND', 'message' => 'Not found.', 'details' => (object) []],
        ]);

        return true;
    }

    // Let the built-in server stream the file with the right content type.
    return false;
}

// ── PWA shell ────────────────────────────────────────────────────────────────
// `/`, `/today`, `/week` and the other client routes are not files and not API
// calls. Serving index.html here means the page paints even when the API
// process cannot boot, and a refresh on a client route stays inside the app.
$extension = pathinfo($path, PATHINFO_EXTENSION);
$isApi = $path === '/api' || str_starts_with($path, '/api/');
$isProbe = $path === '/health' || $path === '/metrics';
$shell = $publicRoot . '/index.html';

if (
    ($method === 'GET' || $method === 'HEAD')
    && !$isApi
    && !$isProbe
    && $extension === ''
    && is_file($shell)
) {
    header('Content-Type: text/html; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header('Cache-Control: no-cache');
    header(
        "Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; "
        . "img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; "
        . "base-uri 'self'; form-action 'self'"
    );
    readfile($shell);

    return true;
}

// ── Everything else is an application route ──────────────────────────────────
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $publicRoot . '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';

require $publicRoot . '/index.php';
