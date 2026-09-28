<?php

declare(strict_types=1);

namespace App\Console\Command;

use App\Console\Input;
use App\Console\Kernel;
use App\Console\Output;
use RuntimeException;

/**
 * `bin/console maintenance` — the read-only window used around a deploy.
 *
 * WRITES A FILE, NOT A ROW
 * The flag is `storage/maintenance.flag`, and the file's contents are the
 * message shown to whoever tries to write during the window. That is the whole
 * design: enabling maintenance mode has to work when the database is down,
 * because the most common reason to enable it is that a deploy is about to
 * migrate something and a write arriving mid-migration is exactly the
 * failure being avoided. A flag in the database could not be set in that
 * situation, and a deploy that cannot be paused is a deploy that is a
 * gamble.
 *
 * READS ARE STILL SERVED
 * `DisableWhenMaintenance` only refuses non-GET methods. Taking the timetable
 * offline for the four minutes a deploy needs would be a worse outcome than
 * the deploy.
 */
final class MaintenanceCommand extends Command
{
    public function name(): string
    {
        return 'maintenance';
    }

    public function description(): string
    {
        return 'Enable or disable maintenance mode (writes are refused, reads are not)';
    }

    /** @return list<string> */
    public function aliases(): array
    {
        return ['app:maintenance', 'down'];
    }

    /** @return list<string> */
    public function synopsis(): array
    {
        return [
            'php bin/console maintenance',
            'php bin/console maintenance --enable --message "Upgrading to 1.4.0"',
            'php bin/console maintenance --disable',
        ];
    }

    /** @return array<string, string> */
    public function options(): array
    {
        return [
            'enable'  => 'Refuse writes. Creates storage/maintenance.flag',
            'disable' => 'Serve writes again. Removes the flag',
            'message' => 'Notice shown in the 503 body while the window is open',
            'json'    => 'Report the current state as JSON; nothing else is written to stdout',
        ];
    }

    /** @return list<string> */
    public function notes(): array
    {
        return [
            'The flag is a file, not a database row, so it can be set when the database is down.',
            'A stale flag is a permanently read-only instance. If nothing is deploying, run --disable.',
            'Unlocking is not automatic on purpose: a window that closes on its own is a window nobody chose.',
        ];
    }

    public function run(Input $input, Output $output): int
    {
        $unknown = $input->unknownOptions(['enable', 'disable', 'message', 'json']);
        if ($unknown !== []) {
            return $this->invalid($output, 'Unknown option: ' . implode(', ', $unknown));
        }

        $flag = $this->flagPath();
        $down = is_file($flag);

        $enable = $input->boolOption('enable');
        $disable = $input->boolOption('disable');

        if ($enable && $disable) {
            return $this->invalid($output, '--enable and --disable contradict each other.');
        }

        if ($input->boolOption('json')) {
            $output->json(['enabled' => $down, 'flag' => $flag, 'message' => $this->message()]);

            return Kernel::SUCCESS;
        }

        if (!$enable && !$disable) {
            $output->title('Maintenance mode');
            $output->definitions([
                'state'   => $down ? 'ENABLED — writes refused' : 'disabled',
                'flag'    => $flag,
                'message' => $this->message(),
                'reads'   => 'still served (GET and HEAD are never refused)',
            ], 0);
            $output->line();

            return $down ? Kernel::FAILURE : Kernel::SUCCESS;
        }

        if ($disable) {
            if (!$down) {
                $output->warn('Maintenance mode was not enabled; nothing to do.');

                return Kernel::SUCCESS;
            }

            if (!@unlink($flag)) {
                throw new RuntimeException(sprintf('Could not remove %s. Check its permissions.', $flag));
            }

            $output->success('Maintenance mode disabled. Writes are served again.');

            return Kernel::SUCCESS;
        }

        if ($down) {
            $output->warn('Maintenance mode is already enabled.');
            $output->definitions(['flag' => $flag, 'message' => $this->message()], 0);

            return Kernel::SUCCESS;
        }

        $directory = \dirname($flag);
        if (!is_dir($directory) && !@mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Cannot create %s.', $directory));
        }

        $message = (string) $input->option('message', 'An update is being applied.');
        // The message becomes the body of a 503 shown to a signed-in user, so
        // it is written as plain text with no markup and no newlines: it must
        // not be able to inject a second line into the response.
        $message = trim(preg_replace('/\s+/', ' ', $message) ?? $message);

        if (@file_put_contents($flag, $message) === false) {
            throw new RuntimeException(sprintf('Could not write %s. Check its permissions.', $flag));
        }

        $output->success('Maintenance mode enabled. Writes are refused; reads continue.');
        $output->definitions(['flag' => $flag, 'message' => $message], 0);
        $output->line();

        return Kernel::SUCCESS;
    }

    private function flagPath(): string
    {
        return $this->kernel->config()->storagePath('maintenance.flag');
    }

    private function message(): ?string
    {
        $flag = $this->flagPath();
        if (!is_file($flag)) {
            return null;
        }

        $contents = @file_get_contents($flag);

        return is_string($contents) && trim($contents) !== '' ? trim($contents) : null;
    }
}
