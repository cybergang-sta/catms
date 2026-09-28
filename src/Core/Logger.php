<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Line-oriented JSON logger writing to `storage/logs/`.
 *
 * WHY PLAIN FILES
 * The deployment target is a single Docker Compose stack, not an ELK cluster.
 * JSON lines means `docker compose logs -f app` and any future shipper both work
 * without changing the format, and one line per event means a partial write
 * during a crash costs one record, not the file.
 *
 * THE REDACTION RULE
 * `redact()` is the reason this class is not `error_log()`. Personal data is
 * logged on a schedule (Act 843 storage limitation) and a password or a token in
 * a log line is a credential leak that survives every later "we rotated the
 * secret" conversation. Redaction happens here, once, rather than relying on
 * every call site to remember.
 */
final class Logger
{
    public const DEBUG = 'debug';
    public const INFO = 'info';
    public const WARNING = 'warning';
    public const ERROR = 'error';

    private const SEVERITY_ORDER = [
        self::DEBUG   => 0,
        self::INFO    => 1,
        self::WARNING => 2,
        self::ERROR   => 3,
    ];

    /** Keys whose values must never reach disk, at any nesting depth. */
    private const REDACTED_KEYS = [
        'password',
        'password_hash',
        'new_password',
        'current_password',
        'token',
        'access_token',
        'refresh_token',
        'token_hash',
        'authorization',
        'app_key',
        'secret',
        'db_password',
        'mail_password',
        'smtp_password',
    ];

    private mixed $handle = null;

    public function __construct(
        private readonly string $logDirectory,
        private readonly string $minimumLevel = self::WARNING,
        private readonly string $channel = 'app',
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function debug(string $message, array $context = []): void
    {
        $this->log(self::DEBUG, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function info(string $message, array $context = []): void
    {
        $this->log(self::INFO, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function warning(string $message, array $context = []): void
    {
        $this->log(self::WARNING, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function error(string $message, array $context = []): void
    {
        $this->log(self::ERROR, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function log(string $level, string $message, array $context = []): void
    {
        if ((self::SEVERITY_ORDER[$level] ?? 0) < (self::SEVERITY_ORDER[$this->minimumLevel] ?? 2)) {
            return;
        }

        $record = [
            'ts'      => gmdate('Y-m-d\TH:i:s\Z'),
            'level'   => $level,
            'channel' => $this->channel,
            'message' => $message,
            'context' => self::redact($context),
        ];

        $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if ($line === false) {
            $line = json_encode([
                'ts'      => gmdate('Y-m-d\TH:i:s\Z'),
                'level'   => self::ERROR,
                'message' => $message,
                'context' => ['note' => 'context was not encodable'],
            ]) ?: '{"level":"error","message":"log encoding failed"}';
        }

        $this->write($line);
    }

    /**
     * Replace sensitive values with a marker. Recursive, because a leaked token
     * is just as bad inside `context.user` as it is at the top level.
     *
     * @param mixed $value
     *
     * @return mixed
     */
    public static function redact(mixed $value): mixed
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) {
                if (is_string($key) && in_array(strtolower($key), self::REDACTED_KEYS, true)) {
                    $out[$key] = '[redacted]';
                    continue;
                }
                $out[$key] = self::redact($item);
            }

            return $out;
        }

        if (is_object($value)) {
            return self::redact(get_object_vars($value));
        }

        return $value;
    }

    public function close(): void
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }

        $this->handle = null;
    }

    private function write(string $line): void
    {
        $handle = $this->handle();
        if ($handle === null) {
            // Logging must never be the reason a request fails. If the log
            // directory is unwritable the line goes to stderr and the request
            // continues; a monitoring alert on stderr volume covers the case.
            error_log($line);

            return;
        }

        fwrite($handle, $line . "\n");
    }

    /**
     * @return resource|null
     */
    private function handle(): mixed
    {
        if (is_resource($this->handle)) {
            return $this->handle;
        }

        if (!is_dir($this->logDirectory) && !@mkdir($this->logDirectory, 0o775, true) && !is_dir($this->logDirectory)) {
            return null;
        }

        if (!is_writable($this->logDirectory)) {
            return null;
        }

        $path = sprintf('%s/%s-%s.log', $this->logDirectory, $this->channel, gmdate('Y-m-d'));
        $handle = @fopen($path, 'ab');
        if ($handle === false) {
            return null;
        }

        return $this->handle = $handle;
    }
}
