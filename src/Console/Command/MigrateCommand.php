<?php

declare(strict_types=1);

namespace App\Console\Command;

use App\Console\Input;
use App\Console\Kernel;
use App\Console\Output;
use App\Infrastructure\Persistence\Migration\Migrator;
use Throwable;

/**
 * `bin/console migrate` — apply, inspect and reverse schema migrations.
 *
 * FORWARD-ONLY IN PRODUCTION, WITH A `down` PATH SHIPPED (NFR-MAINT-05)
 * Ground rule 8 in `docs/IMPLEMENTATION.md` §1: the `down` path ships in the
 * same release as the `up` path, because writing it during an incident is the
 * worst possible time. It is documented as the recovery route for a bad
 * migration (`docs/DEPLOYMENT.md` §8.3) and is *not* the normal way to undo a
 * deploy — a released migration is usually backward-compatible, so redeploying
 * the previous tag is both faster and safer.
 *
 * `rollback` reverses by BATCH, not by file. A deploy applies several files as
 * one unit, and reversing only the last one leaves the schema at a state nobody
 * wrote.
 */
final class MigrateCommand extends Command
{
    public function name(): string
    {
        return 'migrate';
    }

    public function description(): string
    {
        return 'Apply, inspect or reverse database migrations';
    }

    /** @return list<string> */
    public function aliases(): array
    {
        return ['app:migrate', 'migrations'];
    }

    /** @return list<string> */
    public function synopsis(): array
    {
        return [
            'php bin/console migrate',
            'php bin/console migrate --status',
            'php bin/console migrate --rollback --steps=1',
            'php bin/console migrate --path=dbs/other --pretend',
        ];
    }

    /** @return array<string, string> */
    public function options(): array
    {
        return [
            'status'    => 'List what has run and what has not. Writes nothing',
            'rollback'  => 'Reverse the most recent batch instead of applying',
            'steps'     => 'With --rollback: how many batches to reverse. 0 means all',
            'path'      => 'Directory to read migrations from (default db/migrations)',
            'pretend'   => 'Print what would run, including the SQL, and change nothing',
            'force'     => 'Apply pending migrations even when one is out of order',
            'json'      => 'Machine-readable output; nothing else is written to stdout',
        ];
    }

    /** @return list<string> */
    public function notes(): array
    {
        return [
            'Migrations are named YYYY_MM_DD_HHMMSS_name.php and applied in filename order.',
            'A migration that has been applied is never edited. Add a new one instead (ground rule 6).',
            'MySQL commits DDL implicitly, so a failed DDL statement leaves the schema partly changed.',
            'Read --status before re-running after a crash: an unrecorded table is expected, not a surprise.',
        ];
    }

    public function run(Input $input, Output $output): int
    {
        $unknown = $input->unknownOptions(['status', 'rollback', 'steps', 'path', 'pretend', 'force', 'json']);
        if ($unknown !== []) {
            return $this->invalid($output, 'Unknown option: ' . implode(', ', $unknown));
        }

        $path = $this->migrationsPath($input->option('path'));
        $migrator = $this->migrator($path);
        $asJson = $input->boolOption('json');

        try {
            if ($input->boolOption('status')) {
                return $this->reportStatus($migrator, $path, $output, $asJson);
            }

            if ($input->boolOption('rollback')) {
                return $this->rollback($migrator, $input, $output, $asJson);
            }

            return $this->apply($migrator, $input, $output, $asJson);
        } catch (Throwable $exception) {
            // Migrations fail in a way an operator has to read, not a stack
            // trace. The statement that failed is already in the message.
            $output->failure($exception->getMessage());
            $this->kernel->logger()->error('Migration failed.', [
                'exception' => $exception::class,
                'message'   => $exception->getMessage(),
            ]);

            if ($this->kernel->config()->isDebug()) {
                $output->line($exception->getTraceAsString());
            }

            return Kernel::FAILURE;
        }
    }

    // -----------------------------------------------------------------------
    // Modes
    // -----------------------------------------------------------------------

    private function apply(Migrator $migrator, Input $input, Output $output, bool $asJson): int
    {
        $pretend = $input->boolOption('pretend');
        $status = $migrator->status();
        $pending = $status['pending'];

        if ($asJson) {
            $output->json([
                'mode'    => $pretend ? 'pretend' : 'apply',
                'pending' => $pending,
                'applied' => $pretend ? [] : $migrator->migrate(),
            ]);

            return Kernel::SUCCESS;
        }

        $output->title($pretend ? 'Migrations (pretend)' : 'Migrations');

        if ($pending === []) {
            $output->success('Nothing to do. The schema is up to date.');

            return Kernel::SUCCESS;
        }

        $force = $input->boolOption('force');

        if ($status['out_of_order'] !== [] && !$force) {
            $output->failure(sprintf(
                '%d pending migration(s) sort before an applied one:',
                \count($status['out_of_order']),
            ));
            foreach ($status['out_of_order'] as $version) {
                $output->line('    ' . $version);
            }
            $output->line();
            $output->line('  A migration was dated in the past. Applying it now would put it after');
            $output->line('  something that already depends on it. Rename the file to today\'s date,');
            $output->line('  or re-run with --force if you are certain.');
            $output->line();

            return Kernel::FAILURE;
        }

        $output->line(sprintf('  %d migration(s) to apply:', \count($pending)));
        $output->line();

        if ($pretend) {
            foreach ($pending as $version) {
                $output->line('    would apply  ' . $version);
            }
            $output->line();
            $output->line('  --pretend changed nothing.');

            return Kernel::SUCCESS;
        }

        $applied = $migrator->migrate(function (string $version, string $stage) use ($output): void {
            if ($version === '') {
                $output->info('Nothing to apply.');

                return;
            }

            $output->line(sprintf('    %s %s', $stage === 'reversing' ? 'reversing ' : 'applying  ', $version));
        });

        $output->line();
        $output->success(sprintf('Applied %d migration(s).', \count($applied)));

        return Kernel::SUCCESS;
    }

    private function rollback(Migrator $migrator, Input $input, Output $output, bool $asJson): int
    {
        $steps = $input->intOption('steps', 1);
        if ($steps < 0) {
            return $this->invalid($output, '--steps must not be negative.');
        }

        if ($steps === 0 && $this->kernel->config()->isProduction() && !$input->boolOption('force')) {
            // Reversing everything in production is the "the deploy went wrong
            // and I do not know which file" case. It is allowed, but it has to
            // be typed, because the undo is not something a habit should do.
            return $this->invalid(
                $output,
                'Reversing every batch in production needs --force. Narrow it with --steps=N once you know which batch.'
            );
        }

        $reversed = $migrator->rollback($steps);

        if ($asJson) {
            $output->json(['mode' => 'rollback', 'steps' => $steps, 'reversed' => $reversed]);

            return Kernel::SUCCESS;
        }

        if ($reversed === []) {
            $output->success('Nothing to reverse.');

            return Kernel::SUCCESS;
        }

        $output->title('Migrations reversed');
        foreach ($reversed as $version) {
            $output->line('    ' . $version);
        }
        $output->line();
        $output->warn('Reversed ' . \count($reversed) . ' migration(s). A migration is not deleted — '
            . 're-running `migrate` will apply it again.');

        return Kernel::SUCCESS;
    }

    private function reportStatus(Migrator $migrator, string $path, Output $output, bool $asJson): int
    {
        $status = $migrator->status();

        if ($asJson) {
            $output->json($status);

            // A pending migration is an unfinished deploy, which is what
            // /health's `migrations` check reports as `unhealthy`. The exit code
            // is what makes that check scriptable.
            return $status['pending'] === [] ? Kernel::SUCCESS : Kernel::FAILURE;
        }

        $output->title('Migration status');

        $output->definitions([
            'migrations dir' => $path,
            'applied'        => \count($status['applied']),
            'pending'        => \count($status['pending']),
            'out of order'   => \count($status['out_of_order']),
        ], 0);

        if ($status['applied'] !== []) {
            $output->line();
            $output->line('  Applied:');
            $rows = [];
            foreach ($status['applied'] as $row) {
                $rows[] = [$row['version'], (string) $row['batch'], $row['applied_at']];
            }
            $output->table(['version', 'batch', 'applied at (UTC)'], $rows, 4);
        }

        if ($status['pending'] !== []) {
            $output->line();
            $output->line('  Pending:');
            foreach ($status['pending'] as $version) {
                $output->line('    ' . $version);
            }
        }

        $output->line();

        if ($status['pending'] === []) {
            $output->success('The schema is up to date.');

            return Kernel::SUCCESS;
        }

        $output->warn(sprintf(
            '%d migration(s) pending — a deploy probably skipped this step.',
            \count($status['pending']),
        ));

        return Kernel::FAILURE;
    }

    // -----------------------------------------------------------------------
    // Wiring
    // -----------------------------------------------------------------------

    private function migrator(string $path): Migrator
    {
        return new Migrator(
            $this->kernel->database(),
            $this->kernel->logger(),
            $path,
        );
    }

    private function migrationsPath(?string $override): string
    {
        $override = $override === null ? '' : trim($override);

        if ($override === '') {
            return $this->kernel->basePath('db/migrations');
        }

        if (str_starts_with($override, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $override) === 1) {
            return $override;
        }

        return $this->kernel->basePath($override);
    }
}
