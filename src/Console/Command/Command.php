<?php

declare(strict_types=1);

namespace App\Console\Command;

use App\Console\Input;
use App\Console\Kernel;
use App\Console\Output;

/**
 * One console command.
 *
 * A command receives the `Kernel` rather than a pile of constructor arguments
 * because the set of services differs per command: `routes` needs no database,
 * `generate` needs five, and giving each one a bespoke constructor list would
 * mean a signature change every time a service is added. The Kernel's accessors
 * are narrow (`config()`, `database()`, `logger()`, `output()`) so the coupling
 * stays visible at each call site rather than hidden behind autowiring.
 *
 * `run()` returns the process exit code rather than calling `exit()`, so a
 * command is callable from a test.
 */
abstract class Command
{
    public function __construct(protected readonly Kernel $kernel)
    {
    }

    /** The name typed on the command line, e.g. `migrate`. */
    abstract public function name(): string;

    /** One line, shown in the command list. */
    abstract public function description(): string;

    abstract public function run(Input $input, Output $output): int;

    /**
     * Additional names for the same command. The deployment runbooks use
     * `app:verify-integrity` and `app:maintenance`; day-to-day use is
     * `verify-integrity` and `maintenance`. Both are the same code, and the
     * runbook spelling is the one that must keep working.
     *
     * @return list<string>
     */
    public function aliases(): array
    {
        return [];
    }

    /**
     * Usage lines, one per supported form.
     *
     * @return list<string>
     */
    public function synopsis(): array
    {
        return [sprintf('php bin/console %s', $this->name())];
    }

    /**
     * `flag => explanation`, shown by `--help`. Every option a command reads
     * must appear here: `Input::unknownOptions()` turns an unlisted option into
     * an error, which is the point.
     *
     * @return array<string, string>
     */
    public function options(): array
    {
        return [];
    }

    /** Extra notes printed after the options. */
    public function notes(): array
    {
        return [];
    }

    /**
     * Whether the command writes to the database. A `--dry-run` of a write
     * command says no, and the Kernel uses this to refuse to boot without a
     * connection for commands that only read configuration.
     */
    public function isReadOnly(): bool
    {
        return true;
    }

    /** A usage error: bad or missing arguments. Exit code 2. */
    protected function invalid(Output $output, string $message): int
    {
        $output->error($message);
        $this->printHelp($output);

        return Kernel::INVALID;
    }

    protected function printHelp(Output $output): void
    {
        $output->title(sprintf('%s — %s', $this->name(), $this->description()));

        $output->line('  Usage:');
        foreach ($this->synopsis() as $line) {
            $output->line('    ' . $line);
        }

        $aliases = $this->aliases();
        if ($aliases !== []) {
            $output->line();
            $output->line('  Aliases: ' . implode(', ', $aliases));
        }

        $options = $this->options();
        if ($options !== []) {
            $output->line();
            $output->line('  Options:');
            $output->definitions($options, 4);
        }

        $notes = $this->notes();
        if ($notes !== []) {
            $output->line();
            foreach ($notes as $note) {
                $output->line('  ' . $note);
            }
        }
    }
}
