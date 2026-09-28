<?php

declare(strict_types=1);

namespace App\Console;

use InvalidArgumentException;

/**
 * Parsed command-line arguments and options.
 *
 * ACCEPTED FORMS
 *   `--name=value`   the documented form
 *   `--name value`   the same, because operators type it and a tool that
 *                    rejects it teaches them to stop reading `--help`
 *   `--name`         a flag, when nothing follows it or the next token is
 *                    another option
 *   `name`           a positional argument
 *   `--`             everything after it is positional, verbatim
 *
 * The `--name value` rule is inherently ambiguous, and the ambiguity is resolved
 * in favour of the operator: a bare `--name` followed by a word that is not an
 * option takes that word as its value. That is what makes
 * `app:maintenance --enable --message "Upgrading to 1.4.0"` (docs/DEPLOYMENT.md
 * §8.2) work without a second, `--message=` convention.
 *
 * Options are stored with the leading dashes removed and underscores intact, so
 * `--dry-run` is read with `boolOption('dry-run')` and `--repair-day` with
 * `option('repair-day')`. No normalisation: a flag whose name changes in the
 * documentation should fail loudly, not be found under a different name.
 */
final class Input
{
    /**
     * @param list<string>               $arguments Positional values, in order.
     * @param array<string, string|bool> $options
     */
    private function __construct(
        private readonly array $arguments,
        private readonly array $options,
    ) {
    }

    /**
     * @param list<string> $argv The full `$argv`, including the script name at
     *                           index 0.
     */
    public static function fromArgv(array $argv): self
    {
        array_shift($argv); // the script name

        $arguments = [];
        $options = [];
        $literal = false;

        $count = \count($argv);
        for ($i = 0; $i < $count; $i++) {
            $token = $argv[$i];

            if ($literal) {
                $arguments[] = $token;
                continue;
            }

            if ($token === '--') {
                $literal = true;
                continue;
            }

            if (!str_starts_with($token, '--')) {
                $arguments[] = $token;
                continue;
            }

            $body = substr($token, 2);
            $equals = strpos($body, '=');

            if ($equals !== false) {
                $options[self::key(substr($body, 0, $equals))] = substr($body, $equals + 1);
                continue;
            }

            $next = $argv[$i + 1] ?? null;
            if ($next !== null && $next !== '' && !str_starts_with($next, '-')) {
                $options[self::key($body)] = $next;
                $i++;
                continue;
            }

            $options[self::key($body)] = true;
        }

        return new self($arguments, $options);
    }

    /** @return list<string> */
    public function arguments(): array
    {
        return $this->arguments;
    }

    /**
     * The same input with the command name dropped.
     *
     * The Kernel resolves a command from `firstArgument()` and then hands the
     * command an input that still has that name at index 0, which is why this
     * exists. Without it every command that takes a positional would read the
     * command's own name as its first argument: `make:migration add_index` would
     * generate a migration called `add_index` on its first attempt, and
     * `worker drain` would report an unknown subcommand "worker". The rule is
     * one sentence long — index 0 belongs to the Kernel, the rest to the
     * command — which is easier to hold in your head than a second parsing
     * convention is to get right at twelve call sites.
     */
    public function shifted(): self
    {
        return new self(\array_slice($this->arguments, 1), $this->options);
    }

    /**
     * The nth positional argument, or null.
     */
    public function argument(int $position): ?string
    {
        return $this->arguments[$position] ?? null;
    }

    public function argumentOr(int $position, string $default): string
    {
        return $this->arguments[$position] ?? $default;
    }

    public function requiredArgument(int $position, string $name): string
    {
        $value = $this->arguments[$position] ?? null;
        if ($value === null || $value === '') {
            throw new InvalidArgumentException(sprintf('The <%s> argument is required.', $name));
        }

        return $value;
    }

    /** The first positional argument, used by single-purpose commands. */
    public function firstArgument(): ?string
    {
        return $this->argument(0);
    }

    /** @return array<string, string|bool> */
    public function options(): array
    {
        return $this->options;
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->options);
    }

    public function option(string $name, ?string $default = null): ?string
    {
        $value = $this->options[$name] ?? null;

        if ($value === null) {
            return $default;
        }

        if (is_bool($value)) {
            throw new InvalidArgumentException(sprintf(
                'The --%s option needs a value, e.g. --%s=…',
                $name,
                $name,
            ));
        }

        return $value;
    }

    /**
     * @throws InvalidArgumentException when absent or not a number, because
     *                                  silently defaulting a mistyped
     *                                  `--iterations=2o00` would run a
     *                                  different plan than the operator asked
     *                                  for and report it as the one they asked
     *                                  for.
     */
    public function intOption(string $name, int $default): int
    {
        $value = $this->option($name);
        if ($value === null) {
            return $default;
        }

        if (!preg_match('/^-?\d+$/', $value)) {
            throw new InvalidArgumentException(sprintf('The --%s option must be a whole number.', $name));
        }

        return (int) $value;
    }

    public function floatOption(string $name, float $default): float
    {
        $value = $this->option($name);
        if ($value === null) {
            return $default;
        }

        if (!is_numeric($value)) {
            throw new InvalidArgumentException(sprintf('The --%s option must be a number.', $name));
        }

        return (float) $value;
    }

    public function boolOption(string $name): bool
    {
        $value = $this->options[$name] ?? false;

        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * A repeated option, e.g. `--exclude-day=1 --exclude-day=3`. Returns the
     * last occurrence only, matching the single-value behaviour above; used
     * where repetition is not meaningful.
     *
     * @return list<int>
     */
    public function intListOption(string $name): array
    {
        $value = $this->option($name);
        if ($value === null) {
            return [];
        }

        $days = [];
        foreach (explode(',', $value) as $part) {
            $part = trim($part);
            if ($part !== '' && preg_match('/^-?\d+$/', $part) === 1) {
                $days[] = (int) $part;
            }
        }

        return $days;
    }

    /**
     * Options the command does not declare.
     *
     * A typo'd flag is otherwise invisible: the run happens, with the default,
     * and the output looks like the flag had worked. The first three documented
     * options are ignored so a command can accept a common set without
     * declaring it.
     *
     * @param list<string> $known
     *
     * @return list<string>
     */
    public function unknownOptions(array $known): array
    {
        $alwaysAllowed = ['help', 'verbose', 'quiet', 'no-interaction', 'ansi', 'no-ansi'];

        $unknown = [];
        foreach (array_keys($this->options) as $name) {
            if (!in_array($name, $known, true) && !in_array($name, $alwaysAllowed, true)) {
                $unknown[] = '--' . $name;
            }
        }

        sort($unknown);

        return $unknown;
    }

    private static function key(string $name): string
    {
        return trim($name);
    }
}
