#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Console\Launcher;

/**
 * `php bin/generate-timetable.php` — solve and publish a timetable.
 *
 * Identical to `php bin/console generate`. Named for the two words the
 * registrar's office actually says out loud, and for the cron line that
 * publishes a confirmed draft without a human at the keyboard.
 *
 * Usage:
 *   php bin/generate-timetable.php --semester=3 --department=1
 *   php bin/generate-timetable.php --semester=CSC-2026-1 --department=Computer\ Science
 *   php bin/generate-timetable.php --semester=3 --department=1 --dry-run
 *   php bin/generate-timetable.php --semester=3 --department=1 --repair-day=3
 *
 * Run a `--dry-run` first, always. It solves the same problem and writes
 * nothing — not even the `allocation_runs` row — so it is the only way to see
 * the accuracy a publish would produce without producing it.
 *
 * Fails, without publishing, when:
 *   - another run holds `GET_LOCK('catms_generate_<dept>_<sem>', 0)`; pass
 *     `--force` only if you are certain the holder is a zombie.
 *   - accuracy is below `ENGINE_ACCURACY_GATE` (0.90). The run is still
 *     recorded, because "we tried and it was not good enough" is the finding;
 *     pass `--force-accuracy` to publish anyway.
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

exit(Launcher::main($argv, 'generate'));
