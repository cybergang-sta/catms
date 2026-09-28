#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * `php bin/migrate.php` — schema migrations, with the command name implied.
 *
 * Identical to `php bin/console migrate`. Exists so a deployment runbook and the
 * `if ! php bin/migrate.php; then rollback; fi` guard in docs/DEPLOYMENT.md §7.3
 * are one word, and so a mistyped command name is a missing file rather than an
 * exit 127 from a shell script.
 *
 * Usage:
 *   php bin/migrate.php                    # apply every pending migration
 *   php bin/migrate.php --status           # what is applied, what is pending
 *   php bin/migrate.php --pretend          # print the SQL, execute nothing
 *   php bin/migrate.php --rollback         # undo the last batch
 *   php bin/migrate.php --rollback --steps=2
 *   php bin/migrate.php --json             # machine-readable, for a deploy gate
 *
 * There is deliberately no `--fresh`. Rebuilding a database is `docker compose
 * down -v && php bin/migrate.php`, and a flag that drops every table should have
 * to be typed in full where someone is watching.
 *
 * Exit code 0 on success, 1 on failure, 2 on a usage error. Any of them can be
 * a rollback trigger, which is why the codes are Kernel constants and not
 * whatever happened to come out of PDO.
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

exit(App\Console\Launcher::main($argv, 'migrate'));
