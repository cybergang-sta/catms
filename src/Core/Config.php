<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Exception\ConfigurationException;

/**
 * Typed access to configuration, loaded from the environment and `.env`.
 *
 * PRECEDENCE (highest first)
 *   1. Real environment variables. A container orchestrator injects these and
 *      they must not be shadowed by a file baked into the image.
 *   2. The `.env` file in the project root, if present.
 *   3. The `default` argument passed at the call site.
 *
 * `.env` IS A DEVELOPMENT CONVENIENCE
 * It is gitignored, and `Dockerfile` does not copy it into the image. If a
 * deployment ever ships one, the real environment still wins, so the failure is
 * silent rather than a hard outage — which is exactly why `.env.example` exists
 * and the DEPLOYMENT.md checklist makes secrets a release blocker.
 *
 * PRODUCTION GUARD
 * `assertProductionSafe()` is called once during boot. Each rule below is a
 * documented release blocker in `docs/SECURITY.md` §14, not a stylistic
 * preference: a system that boots into production with debug output on, or with
 * the shipped demo password hashes still in the users table, has already failed
 * its security review.
 */
final class Config
{
    /** @var array<string, string> */
    private array $values;

    /**
     * @param array<string, string> $overrides Already-resolved values, typically
     *                                         the result of a Dotenv parse.
     */
    public function __construct(
        private readonly string $basePath,
        array $overrides = [],
    ) {
        $this->values = $overrides;
    }

    /**
     * Load `.env` from $basePath (or the project root) and overlay the real
     * environment. Called by the front controller, the console and every
     * `bin/` script, so there is exactly one answer to "what is configured".
     */
    public static function load(string $basePath): self
    {
        $config = new self($basePath, self::parseDotEnv($basePath . '/.env'));
        $config->applyEnvironment();

        return $config;
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $value = $this->values[$key] ?? $default;

        return $value === null ? null : (string) $value;
    }

    public function require(string $key): string
    {
        $value = $this->get($key);
        if ($value === null || $value === '') {
            throw new ConfigurationException(
                sprintf('Required configuration "%s" is missing.', $key)
            );
        }

        return $value;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->get($key);
        if ($value === null || $value === '') {
            return $default;
        }

        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->get($key);
        if ($value === null || !is_numeric($value)) {
            return $default;
        }

        return (int) $value;
    }

    public function float(string $key, float $default = 0.0): float
    {
        $value = $this->get($key);
        if ($value === null || !is_numeric($value)) {
            return $default;
        }

        return (float) $value;
    }

    public function has(string $key): bool
    {
        return isset($this->values[$key]) && $this->values[$key] !== '';
    }

    public function set(string $key, string $value): void
    {
        $this->values[$key] = $value;
    }

    public function environment(): string
    {
        return $this->get('APP_ENV', 'production') ?? 'production';
    }

    public function isProduction(): bool
    {
        return $this->environment() === 'production';
    }

    public function isDebug(): bool
    {
        return $this->bool('APP_DEBUG', false);
    }

    public function basePath(string $append = ''): string
    {
        return $this->basePath . ($append === '' ? '' : '/' . ltrim($append, '/'));
    }

    /**
     * Where runtime-writable state lives: logs, the maintenance flag, the cache.
     *
     * Defaults to `<base>/storage`, which is what the development checkout and
     * the container image both want. STORAGE_PATH exists for the packaged
     * install, where the application directory is read-only and only /var/www
     * is writable — a deploy that cannot write its log has no deploy.
     */
    public function storagePath(string $append = ''): string
    {
        $configured = $this->get('STORAGE_PATH');
        $root = ($configured === null || $configured === '')
            ? $this->basePath('storage')
            : rtrim($configured, '/');

        return $root . ($append === '' ? '' : '/' . ltrim($append, '/'));
    }

    public function apiPrefix(): string
    {
        return rtrim($this->get('API_PREFIX', '/api/v1') ?? '/api/v1', '/');
    }

    /**
     * The signing key for access tokens. Returns raw bytes; the caller decides
     * how to hash them. Never logged, never returned by an API.
     */
    public function appKey(): string
    {
        return $this->require('APP_KEY');
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return $this->values;
    }

    /**
     * Refuse to boot into production in a state the security review would reject.
     *
     * @throws ConfigurationException
     */
    public function assertProductionSafe(): void
    {
        if (!$this->isProduction()) {
            return;
        }

        $key = $this->get('APP_KEY', '') ?? '';
        if (strlen($key) < 32) {
            throw new ConfigurationException(
                'APP_KEY must be at least 32 characters in production. '
                . 'Generate one with: php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"'
            );
        }

        if ($this->isDebug()) {
            throw new ConfigurationException('APP_DEBUG must be false when APP_ENV=production.');
        }

        if (strtolower($this->get('LOG_LEVEL', 'warning') ?? 'warning') === 'debug') {
            throw new ConfigurationException('LOG_LEVEL must not be debug when APP_ENV=production.');
        }
    }

    /**
     * A minimal `.env` reader: KEY=VALUE per line, `#` comments, optional single
     * or double quotes. Deliberately not a general parser — a dotenv grammar
     * with variable interpolation is a class of bug with no upside here.
     *
     * @return array<string, string>
     */
    private static function parseDotEnv(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            return [];
        }

        $parsed = [];
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines === false ? [] : $lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $separator = strpos($line, '=');
            if ($separator === false) {
                continue;
            }

            $key = trim(substr($line, 0, $separator));
            $value = trim(substr($line, $separator + 1));

            if ($key === '') {
                continue;
            }

            $parsed[$key] = self::unquote($value);
        }

        return $parsed;
    }

    /**
     * Overlays the real process environment on top of the `.env` file.
     *
     * `getenv()` with no argument returns every variable in the environment
     * regardless of `variables_order`. Enumerating `$_ENV` instead would read as
     * empty on any host where `variables_order` omits `E` — which is the default
     * on several distributions — and the result would be that injected
     * DB_PASSWORD and APP_KEY were silently ignored while `.env` kept winning.
     * That failure is invisible until production.
     */
    private function applyEnvironment(): void
    {
        foreach (getenv() as $key => $value) {
            $this->values[$key] = $value;
        }

        // php-fpm and the built-in server surface some values only in $_SERVER.
        foreach (array_keys($_SERVER) as $key) {
            if (is_string($key) && str_starts_with($key, 'APP_') && is_string($_SERVER[$key])) {
                $this->values[$key] = $_SERVER[$key];
            }
        }
    }

    private static function unquote(string $value): string
    {
        $length = strlen($value);
        if ($length >= 2) {
            $first = $value[0];
            $last = $value[$length - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                return substr($value, 1, -1);
            }
        }

        return $value;
    }
}
