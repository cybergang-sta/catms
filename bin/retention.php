#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Console\Launcher;

/**
 * `php bin/retention.php` — the only process allowed to DELETE.
 *
 * Identical to `php bin/console retention`, `php bin/console app:retention` and
 * `php bin/console purge`.
 *
 * Usage:
 *   php bin/retention.php --status         # what is past due; deletes nothing
 *   php bin/retention.php --dry-run        # the same, stated as a count
 *   php bin/retention.php                  # purge everything past due
 *   php bin/retention.php --only=security-events,rate-limit-buckets
 *
 * The windows are in docs/DATA_MODEL.md §12 and encoded in the command. The one
 * that matters: `audit_log` is kept for 2557 days (7 years) because Act 843 makes
 * the processing record the compliance evidence, and it is the one window that
 * requires the `catms_maint` database user. `--force` skips the grant check and
 * exists for a laptop, not for a server.
 *
 * Every run writes a `system.retention_purge` audit entry, including a run that
 * deleted nothing: a purge with no record is indistinguishable from a purge that
 * never ran, and that is exactly the question an auditor asks.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "This script must be run from the command line.\n";
    exit(1);
}

$root = dirname(__DIR__);

if (! is_file($root . '/vendor/autoload.php')) {
    fwrite(STDERR, "Dependencies are not installed. Run: composer install\n");
    exit(1);
}

require $root . '/vendor/autoload.php';

exit(Launcher::main($argv, 'retention'));
