<?php

declare(strict_types=1);

namespace App\Console;

use function dirname;

/**
 * The one place a `bin/` script turns `$argv` into an exit code.
 *
 * WHY THIS EXISTS
 * Five entry points (`bin/migrate.php`, `bin/seed.php`, `bin/generate-timetable.php`,
 * `bin/worker.php`, `bin/retention.php`) exist because a deployment runbook and
 * a `cron` line are the wrong place to type a two-word command name. Each one is
 * a different command wearing one name, which is why they are thin: the real work
 * is in `App\Console\Command\*` and the wiring is in `App\Console\Kernel`, and a
 * file in `bin/` that grows logic is a file with no test.
 *
 * THE $argv TRICK
 * `$argv[0]` is the script and `$argv[1]` is the first thing the operator typed.
 * `bin/worker.php rollup` was typed as "rollup", so the command name has to be
 * *inserted* at position 1 — ahead of the operator's own positional — for
 * `Kernel` to resolve it and for `Input::shifted()` to hand the command the
 * arguments it expects. `array_splice($argv, 1, 0, [$command])` is that
 * insertion, and it leaves everything after it alone, so `--` and options keep
 * working: `bin/worker.php --json drain` and `bin/console worker --json drain`
 * parse identically.
 *
 * WHAT A WRAPPER STILL HAS TO DO ITSELF
 * The "not the web server" guard and `require vendor/autoload.php`, because
 * neither can be deferred to a class that cannot be loaded yet. Those six lines
 * are duplicated in each `bin/` file on purpose: they are the boot sequence, and
 * a wrapper that skipped them would look fine right up until someone pointed a
 * misconfigured virtual host at `bin/`.
 *
 * EXIT CODES
 * Whatever `Kernel::run()` returns, unchanged. 0 success, 1 failure, 2 invalid
 * usage, 127 unknown command. The wrappers add nothing, so
 * `php bin/console worker drain` and `php bin/worker.php drain` fail identically
 * and a runbook can mix the two spellings without surprises.
 */
final class Launcher
{
    /**
     * Not instantiable: this is a namespace for one static call.
     */
    private function __construct()
    {
    }

    /**
     * @param list<string> $argv     The full `$argv`, script name at index 0.
     * @param string|null  $command  The command this script stands for, or null
     *                               for the general `bin/console`.
     * @param string|null  $basePath Overrides the detected project root. Only the
     *                               test suite passes this.
     */
    public static function main(array $argv, ?string $command = null, ?string $basePath = null): int
    {
        $root = $basePath ?? dirname(__DIR__, 2);

        // The wrapper has already required the autoloader; this catches the
        // callers that have not, so the failure is a sentence rather than a
        // "Class not found" fatal with a stack trace on a deploy machine.
        if (! class_exists(Kernel::class)) {
            fwrite(STDERR, sprintf(
                "Dependencies are not installed. Run:\n\n    composer install\n\nThen re-run this command.\n",
            ));

            return 1;
        }

        if ($command !== null) {
            array_splice($argv, 1, 0, [$command]);
        }

        return (new Kernel($root, array_values($argv)))->run();
    }
}
