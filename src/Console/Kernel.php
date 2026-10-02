<?php

declare(strict_types=1);

namespace App\Console;

use App\Console\Command\Command;
use App\Console\Command\GenerateTimetableCommand;
use App\Console\Command\ListCommand;
use App\Console\Command\MaintenanceCommand;
use App\Console\Command\MakeControllerCommand;
use App\Console\Command\MakeMigrationCommand;
use App\Console\Command\MigrateCommand;
use App\Console\Command\RetentionCommand;
use App\Console\Command\RouteListCommand;
use App\Console\Command\SeedCommand;
use App\Console\Command\SmokeCommand;
use App\Console\Command\VerifyIntegrityCommand;
use App\Console\Command\WorkerCommand;
use App\Core\Config;
use App\Core\Database;
use App\Core\Logger;
use App\Core\WeightProfile;
use Throwable;

/**
 * The console front controller.
 *
 * BOOTS
 * `Config::load()` and the logger, both lazily, so `php bin/console list` works
 * on a machine with no database and no `.env`. The PDO connection is created on
 * first use, which means a command that turns out not to need one (listing
 * routes, checking configuration) never pays for it and never fails because the
 * database is down.
 *
 * WHY THE WIRING IS HERE AND NOT IN config/console.php
 * The same argument as `App\Core\App::registerCoreServices()`: the whole list is
 * visible on one screen, so "what can this thing do" and "what does it touch" are
 * answerable without a framework, a cache directory and a code generator.
 *
 * EXIT CODES
 * 0 success · 1 failure · 2 invalid usage · 127 unknown command. They are
 * constants rather than bare numbers because a deployment script greps for them
 * (`if ! php bin/migrate.php; then …`) and a magic number in a shell is invisible.
 */
final class Kernel
{
    public const SUCCESS = 0;
    public const FAILURE = 1;
    public const INVALID = 2;
    public const UNKNOWN = 127;

    /**
     * name => command class. Aliases are listed in the command itself, so this
     * map stays one line per command.
     */
    private const COMMANDS = [
        'list'             => ListCommand::class,
        'migrate'          => MigrateCommand::class,
        'seed'             => SeedCommand::class,
        'generate'         => GenerateTimetableCommand::class,
        'worker'           => WorkerCommand::class,
        'retention'        => RetentionCommand::class,
        'maintenance'      => MaintenanceCommand::class,
        'routes'           => RouteListCommand::class,
        'verify-integrity' => VerifyIntegrityCommand::class,
        'smoke'            => SmokeCommand::class,
        'make:migration'   => MakeMigrationCommand::class,
        'make:controller'  => MakeControllerCommand::class,
    ];

    private ?Config $config = null;

    private ?Logger $logger = null;

    private ?Database $database = null;

    private ?Output $output = null;

    private ?Input $input = null;

    private ?WeightProfile $weightProfiles = null;

    /**
     * @param list<string> $argv Full `$argv`, script name included.
     */
    public function __construct(
        private readonly string $basePath,
        private readonly array $argv,
        private readonly ?Output $injectedOutput = null,
    ) {
    }

    public function run(): int
    {
        $input = Input::fromArgv($this->argv);
        $output = $this->output();

        $this->input = $input;

        $requested = $input->firstArgument();
        $wantsHelp = $input->boolOption('help') || $input->boolOption('h');

        if ($requested === null || $requested === '' || $wantsHelp) {
            if ($requested === null || $requested === '') {
                (new ListCommand($this))->run($input, $output);

                return self::SUCCESS;
            }

            $command = $this->resolve($requested);
            if ($command === null) {
                return $this->unknown($requested, $output);
            }

            // Render help and stop. Calling run() with an empty Input would let
            // the command body continue and then fail on its own required
            // arguments, printing usage and an error for one keystroke.
            $command->help($output);

            return self::SUCCESS;
        }

        $command = $this->resolve($requested);
        if ($command === null) {
            return $this->unknown($requested, $output);
        }

        try {
            // shifted(): the command name is the Kernel's, not the command's.
            // `worker drain` hands the worker a single positional, "drain".
            $code = $command->run($input->shifted(), $output);
        } catch (Throwable $exception) {
            return $this->reportThrowable($exception, $command->name(), $output);
        }

        $this->logger()->close();

        return $code;
    }

    // -----------------------------------------------------------------------
    // Services
    // -----------------------------------------------------------------------

    public function config(): Config
    {
        return $this->config ??= Config::load($this->basePath);
    }

    public function logger(): Logger
    {
        if ($this->logger instanceof Logger) {
            return $this->logger;
        }

        $config = $this->config();

        return $this->logger = new Logger(
            $config->storagePath('logs'),
            $config->get('LOG_LEVEL', Logger::WARNING) ?? Logger::WARNING,
            'console',
        );
    }

    public function database(): Database
    {
        return $this->database ??= new Database($this->config(), $this->logger());
    }

    public function output(): Output
    {
        return $this->output ??= $this->injectedOutput ?? Output::standard();
    }

    public function input(): Input
    {
        return $this->input ??= Input::fromArgv($this->argv);
    }

    public function weightProfiles(): WeightProfile
    {
        return $this->weightProfiles ??= WeightProfile::fromFile(
            $this->config()->basePath('config/weights.php'),
        );
    }

    public function basePath(string $append = ''): string
    {
        return $this->config()->basePath($append);
    }

    // -----------------------------------------------------------------------
    // Registry
    // -----------------------------------------------------------------------

    /**
     * @return list<Command>
     */
    public function commands(): array
    {
        $commands = [];
        foreach (self::COMMANDS as $class) {
            $commands[] = new $class($this);
        }

        return $commands;
    }

    public function resolve(string $name): ?Command
    {
        foreach ($this->commands() as $command) {
            if ($command->name() === $name || in_array($name, $command->aliases(), true)) {
                return $command;
            }
        }

        return null;
    }

    // -----------------------------------------------------------------------
    // Failure reporting
    // -----------------------------------------------------------------------

    private function unknown(string $requested, Output $output): int
    {
        // The near-miss suggestion: `migrat` and `migrate` differ by one keystroke
        // and are indistinguishable in a CI log otherwise.
        $suggestion = null;
        $best = PHP_INT_MAX;
        foreach ($this->commands() as $command) {
            $names = array_merge([$command->name()], $command->aliases());
            foreach ($names as $candidate) {
                $distance = levenshtein($requested, $candidate);
                if ($distance >= $best) {
                    continue;
                }

                $best = $distance;
                $suggestion = $candidate;
            }
        }

        $output->error(sprintf('Unknown command "%s".', $requested));

        if ($suggestion !== null && $best <= 3) {
            $output->line(sprintf('  Did you mean "%s"?', $suggestion));
        }

        $output->line('  Run `php bin/console list` for the available commands.');
        $output->line();

        return self::UNKNOWN;
    }

    /**
     * One place that turns an exception into a message and an exit code.
     *
     * The exception is logged in full and the console is told only the headline.
     * A stack trace on stdout is useful once and corrosive thereafter: it trains
     * people to paste it into tickets without reading it, and a deployment
     * log full of traces hides the one line that matters.
     */
    private function reportThrowable(Throwable $exception, string $command, Output $output): int
    {
        $this->logger()->error('Console command failed.', [
            'command'   => $command,
            'exception' => $exception::class,
            'message'   => $exception->getMessage(),
            'file'      => $exception->getFile() . ':' . $exception->getLine(),
        ]);

        $output->line();
        $output->failure(sprintf('%s: %s', $command, $exception->getMessage()));

        if ($this->config()->isDebug()) {
            $output->line();
            $output->line($exception->getTraceAsString());
        } else {
            $output->line(
                '  The full error, including the trace, is in '
                . $this->config()->storagePath('logs')
                . '. Re-run with APP_DEBUG=true to see it here.',
            );
        }

        $output->line();

        return self::FAILURE;
    }
}
