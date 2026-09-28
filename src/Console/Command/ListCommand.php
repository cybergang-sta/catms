<?php

declare(strict_types=1);

namespace App\Console\Command;

use App\Console\Input;
use App\Console\Kernel;
use App\Console\Output;

/**
 * `bin/console list` — every command, its aliases and its description.
 *
 * The list is read from the Kernel's registry rather than kept here, so a new
 * command cannot be unreachable: the same array that dispatches also documents
 * itself. The `Kernel` already falls back to this command when no command is
 * named, so `php bin/console` with no arguments prints exactly this.
 */
final class ListCommand extends Command
{
    public function name(): string
    {
        return 'list';
    }

    public function description(): string
    {
        return 'List the available commands';
    }

    /** @return list<string> */
    public function aliases(): array
    {
        return ['help'];
    }

    /** @return list<string> */
    public function synopsis(): array
    {
        return [
            'php bin/console list',
            'php bin/console <command> --help',
        ];
    }

    public function run(Input $input, Output $output): int
    {
        $width = 0;
        foreach ($this->kernel->commands() as $command) {
            $width = max($width, mb_strlen($command->name()));
        }

        $output->title('CATMS console');

        $output->definitions([
            'environment' => $this->kernel->config()->environment(),
            'debug'       => $this->kernel->config()->isDebug() ? 'on' : 'off',
            'database'    => $this->kernel->config()->get('DB_DATABASE', '(not configured)'),
            'base path'   => $this->kernel->basePath(),
        ], 0);

        $output->line();
        $output->line('  Commands:');

        $rows = [];
        foreach ($this->kernel->commands() as $command) {
            $aliases = $command->aliases();
            $rows[] = [
                $command->name(),
                $command->description(),
                $aliases === [] ? '' : '(' . implode(', ', $aliases) . ')',
            ];
        }

        $output->table(['name', 'description', 'aliases'], $rows, 4);

        $output->line();
        $output->line(sprintf(
            '  Run %s for a command\'s own options.',
            $output->paint(Output::DIM, 'php bin/console <command> --help'),
        ));
        $output->line();

        return Kernel::SUCCESS;
    }
}
