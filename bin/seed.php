#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * `php bin/seed.php` — RBAC, reference data and demo users.
 *
 * Identical to `php bin/console seed`. See docs/DEPLOYMENT.md §6.2 for when to
 * run it and what it refuses to do: it will not seed outside `local` or
 * `staging` without `--i-know-what-i-am-doing`, and outside `local` the demo
 * accounts are created disabled with a random password (VULN-06).
 *
 * Usage:
 *   php bin/seed.php
 *   php bin/seed.php --reference-only     # no users, no demo data
 *   php bin/seed.php --i-know-what-i-am-doing
 *
 * Safe to re-run: every insert is an upsert keyed on its natural key, and the
 * whole seed is one transaction, so a failure part-way leaves the database as it
 * was rather than half-seeded.
 */


if (\PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "This script must be run from the command line.\n";
    exit(1);
}

$root = \dirname(__DIR__);

if (! is_file($root . '/vendor/autoload.php')) {
    fwrite(STDERR, "Dependencies are not installed. Run: composer install\n");
    exit(1);
}

require $root . '/vendor/autoload.php';

exit(App\Console\Launcher::main($argv, 'seed'));
