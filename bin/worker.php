#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * `php bin/worker.php <subcommand>` — the outbox and the nightly jobs.
 *
 * Identical to `php bin/console worker <subcommand>`. The `bin/worker.php`
 * spelling is what docs/DEPLOYMENT.md §10 schedules, because a `cron` line and a
 * Kubernetes `CronJob` spec read better with one word in them than two.
 *
 * Usage:
 *   php bin/worker.php start --once        # one pass, then exit (the CronJob form)
 *   php bin/worker.php drain --limit=500   # send up to 500 queued notifications
 *   php bin/worker.php rollup --date=2026-09-01
 *   php bin/worker.php prune               # drop revoked refresh tokens
 *   php bin/worker.php sweep               # drop expired rate-limit buckets
 *   php bin/worker.php rollover            # close a semester, start the next
 *   php bin/worker.php status --json
 *
 * `start` is the only looping subcommand; every other one runs once and exits.
 * The one rule to remember: a failing message is retried on a 30 s × 2^n
 * backoff and only becomes a dead letter at `max_attempts`, so a temporarily
 * broken SMTP server does not lose anyone's room-change notification.
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

exit(App\Console\Launcher::main($argv, 'worker'));
