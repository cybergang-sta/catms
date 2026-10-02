<?php

declare(strict_types=1);

namespace App\Console;

use RuntimeException;

/**
 * Console output.
 *
 * TWO STREAMS, ONE PURPOSE EACH
 * Everything a person reads goes to stdout. Everything a script or a CI job
 * parses goes to stdout too, but progress and diagnostics go to stderr, so
 * `php bin/generate-timetable.php --json | jq` works without the log noise
 * ending up in a JSON document.
 *
 * COLOUR IS OPT-IN AND DETECTABLE
 * ANSI escapes are emitted only when the stream is a TTY and neither `NO_COLOR`
 * nor `--no-ansi` says otherwise. A colourised line pasted into a ticket is
 * unreadable, and CI log files are full of them, so this is decided by
 * inspecting the stream rather than by trusting the caller.
 */
final class Output
{
    public const RESET = "\033[0m";
    public const BOLD = "\033[1m";
    public const DIM = "\033[2m";
    public const RED = "\033[31m";
    public const GREEN = "\033[32m";
    public const YELLOW = "\033[33m";
    public const BLUE = "\033[34m";
    public const CYAN = "\033[36m";

    /**
     * @param resource $stdout
     * @param resource $stderr
     */
    public function __construct(
        private $stdout,
        private $stderr,
        private readonly bool $decorated = false,
    ) {
    }

    /**
     * The real terminal streams, coloured only when writing to a terminal.
     */
    public static function standard(bool $forceColour = false): self
    {
        $forced = getenv('FORCE_COLOR');
        $disabled = getenv('NO_COLOR');

        $decorated = $forceColour
            || (is_string($forced) && $forced !== '' && $forced !== '0')
            || (($disabled === false || $disabled === '') && stream_isatty(STDOUT));

        return new self(STDOUT, STDERR, $decorated);
    }

    /**
     * Somewhere to throw output away. Used by commands that only care about
     * their exit code, and by tests.
     */
    public static function silent(): self
    {
        $sink = fopen('php://memory', 'wb');

        if ($sink === false) {
            throw new RuntimeException('Cannot open an in-memory stream.');
        }

        return new self($sink, $sink, false);
    }

    public function isDecorated(): bool
    {
        return $this->decorated;
    }

    public function write(string $text): void
    {
        fwrite($this->stdout, $text);
    }

    public function line(string $text = ''): void
    {
        fwrite($this->stdout, $text . "\n");
    }

    public function error(string $text = ''): void
    {
        fwrite($this->stderr, $this->paint(self::RED, $text) . "\n");
    }

    public function success(string $text): void
    {
        $this->line($this->paint(self::GREEN, '  ok  ' . $text));
    }

    public function warn(string $text): void
    {
        $this->line($this->paint(self::YELLOW, ' warn ' . $text));
    }

    public function failure(string $text): void
    {
        $this->line($this->paint(self::RED, ' fail ' . $text));
    }

    public function info(string $text): void
    {
        $this->line($this->paint(self::BLUE, '  ..  ' . $text));
    }

    public function step(string $text): void
    {
        $this->line($this->paint(self::DIM, '       ' . $text));
    }

    public function title(string $text): void
    {
        $this->line();
        $this->line($this->paint(self::BOLD, $text));
        $this->line($this->paint(self::DIM, str_repeat('-', max(8, min(72, mb_strlen($text))))));
    }

    /**
     * A two-column list, aligned. Used for every command's summary so the shape
     * of the output is recognisable at a glance.
     *
     * @param array<string, string|int|float|bool|null> $pairs
     */
    public function definitions(array $pairs, int $indent = 2): void
    {
        if ($pairs === []) {
            return;
        }

        $width = 0;
        foreach (array_keys($pairs) as $label) {
            $width = max($width, mb_strlen((string) $label));
        }

        foreach ($pairs as $label => $value) {
            $this->line(sprintf(
                '%s%s  %s',
                str_repeat(' ', $indent),
                str_pad((string) $label, $width),
                $this->stringify($value),
            ));
        }
    }

    /**
     * A left-aligned table. Deliberately plain: no box drawing, because the
     * output is read in a terminal, in a CI log and in a pull-request comment,
     * and box characters are mangled by at least one of those.
     *
     * @param list<string>              $headers
     * @param list<list<string|int>>     $rows
     */
    public function table(array $headers, array $rows, int $indent = 2): void
    {
        $widths = array_map(static fn (string $h): int => mb_strlen($h), $headers);

        foreach ($rows as $row) {
            foreach (array_values($row) as $index => $cell) {
                $widths[$index] = max($widths[$index] ?? 0, mb_strlen((string) $cell));
            }
        }

        $pad = str_repeat(' ', $indent);
        $header = [];
        foreach ($headers as $index => $head) {
            $header[] = str_pad($head, $widths[$index] ?? mb_strlen($head));
        }
        $this->line($pad . $this->paint(self::BOLD, rtrim(implode('  ', $header))));
        $this->line($pad . $this->paint(self::DIM, implode('  ', array_map(
            static fn (int $w): string => str_repeat('-', $w),
            $widths,
        ))));

        foreach ($rows as $row) {
            $cells = [];
            foreach (array_values($row) as $index => $cell) {
                $cells[] = str_pad((string) $cell, $widths[$index] ?? 0);
            }
            $this->line($pad . rtrim(implode('  ', $cells)));
        }
    }

    /**
     * Machine-readable output. Nothing else is written to stdout in `--json`
     * mode, which is the whole point of the flag.
     */
    public function json(mixed $payload): void
    {
        $encoded = json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR,
        );

        $this->line($encoded === false ? '{"error":"payload was not encodable"}' : $encoded);
    }

    public function paint(string $colour, string $text): string
    {
        return $this->decorated
            ? $colour . $text . self::RESET
            : $text;
    }

    private function stringify(string|int|float|bool|null $value): string
    {
        return match (true) {
            $value === null => $this->paint(self::DIM, '-'),
            is_bool($value)  => $value ? 'yes' : 'no',
            is_float($value) => rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.'),
            default          => (string) $value,
        };
    }
}
