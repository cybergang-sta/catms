<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Exception\MalformedRequestException;

/**
 * An immutable view of the incoming HTTP request.
 *
 * Built from superglobals in the front controller and from explicit arrays in
 * tests, so a controller or a service can be exercised without a web server.
 * The only place in `src/` permitted to read `$_SERVER`, `$_GET`, `$_POST` or
 * `php://input` is `Request::fromGlobals()` — which is exactly what makes
 * `src/Domain` provably free of HTTP.
 *
 * @psalm-type Bag = array<string, mixed>
 */
final class Request
{
    /**
     * @param array<string, mixed>  $query
     * @param array<string, string> $headers Lower-cased header names.
     * @param array<string, string> $attributes Filled in by the router; the only
     *                                         mutable part, and only the router
     *                                         may write to it.
     * @param array<string, mixed>|null $body Decoded JSON body, decoded on first access.
     */
    public function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly array $query = [],
        private readonly array $headers = [],
        private readonly string $rawBody = '',
        private array $attributes = [],
        private readonly ?string $remoteAddress = null,
        private readonly string $userAgent = '',
    ) {
    }

    /**
     * Lazily decoded JSON body. Null until `json()` is called, so a GET request
     * that never touches the body pays nothing for it.
     *
     * @var array<string, mixed>|null
     */
    private ?array $body = null;

    /**
     * @return self
     */
    public static function fromGlobals(): self
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);
        $path = is_string($path) ? $path : '/';

        $raw = file_get_contents('php://input');
        $body = $raw === false ? '' : $raw;

        return new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            $path === '' ? '/' : $path,
            $_GET ?? [],
            self::readHeaders($_SERVER),
            $body,
            [],
            self::remoteAddress($_SERVER),
            (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
        );
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * A single query parameter, or $default when absent.
     */
    public function query(string $key, ?string $default = null): ?string
    {
        $value = $this->query[$key] ?? null;

        return is_scalar($value) ? (string) $value : $default;
    }

    /**
     * @return array<string, mixed>
     */
    public function queryAll(): array
    {
        return $this->query;
    }

    public function queryInt(string $key, ?int $default = null): ?int
    {
        $value = $this->query($key);
        if ($value === null || $value === '' || !is_numeric($value)) {
            return $default;
        }

        return (int) $value;
    }

    public function queryBool(string $key, bool $default = false): bool
    {
        $value = $this->query($key);
        if ($value === null) {
            return $default;
        }

        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    /**
     * The access token from the Authorization header.
     *
     * Returns null rather than throwing, so a public route does not have to
     * distinguish "no header" from "bad header" — the authentication middleware
     * produces the single 401 the client sees either way.
     */
    public function bearerToken(): ?string
    {
        $header = $this->header('authorization');
        if ($header === null) {
            return null;
        }

        if (!preg_match('/^Bearer\s+(\S+)$/i', trim($header), $matches)) {
            return null;
        }

        return $matches[1];
    }

    /**
     * The decoded JSON body.
     *
     * @return array<string, mixed>
     * @throws MalformedRequestException when the body is present but not a JSON object.
     */
    public function json(): array
    {
        if ($this->body !== null) {
            return $this->body;
        }

        if (trim($this->rawBody) === '') {
            return $this->body = [];
        }

        $contentType = strtolower($this->header('content-type', '') ?? '');
        if ($contentType !== '' && !str_contains($contentType, 'json')) {
            throw new MalformedRequestException('Request body must be application/json.');
        }

        $decoded = json_decode($this->rawBody, true);
        if (!is_array($decoded)) {
            throw new MalformedRequestException('Request body is not a valid JSON object.');
        }

        return $this->body = $decoded;
    }

    public function rawBody(): string
    {
        return $this->rawBody;
    }

    /**
     * Idempotency key for a retried write. `docs/API.md` §1 requires one
     * wherever a retry could duplicate work.
     */
    public function idempotencyKey(): ?string
    {
        $key = $this->header('idempotency-key');
        if ($key === null || trim($key) === '') {
            return null;
        }

        return substr(trim($key), 0, 100);
    }

    /**
     * The caller's IP, already validated by trusted-proxy configuration.
     *
     * X-Forwarded-For is only consulted for keys in TRUSTED_PROXIES. Honouring it
     * unconditionally would let any client bypass both the login rate limit and
     * the audit trail by setting a header (docs/SECURITY.md §5, VULN-03).
     */
    public function ip(): ?string
    {
        return $this->remoteAddress;
    }

    public function userAgent(): string
    {
        return substr($this->userAgent, 0, 255);
    }

    public function isSecure(): bool
    {
        return strtolower($this->header('x-forwarded-proto', '') ?? '') === 'https';
    }

    // -----------------------------------------------------------------------
    // Router-owned attributes
    // -----------------------------------------------------------------------

    public function attribute(string $name, mixed $default = null): mixed
    {
        return $this->attributes[$name] ?? $default;
    }

    public function setAttribute(string $name, mixed $value): void
    {
        $this->attributes[$name] = $value;
    }

    /**
     * @return array<string, mixed>
     */
    public function attributes(): array
    {
        return $this->attributes;
    }

    /**
     * A path placeholder captured by the router, cast to int.
     *
     * @throws MalformedRequestException when the segment is not a positive integer.
     */
    public function intAttribute(string $name): int
    {
        $value = $this->attributes[$name] ?? null;
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^[1-9][0-9]{0,17}$/', $value) === 1) {
            return (int) $value;
        }

        throw new MalformedRequestException(
            sprintf('Path segment "%s" must be a positive integer.', $name)
        );
    }

    /**
     * @param array<string, mixed>  $server
     *
     * @return array<string, string>
     */
    private static function readHeaders(array $server): array
    {
        $headers = [];
        foreach ($server as $key => $value) {
            if (!is_string($key) || !is_scalar($value)) {
                continue;
            }

            if (str_starts_with($key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = (string) $value;
            }
        }

        // These two are not prefixed with HTTP_ in every SAPI.
        foreach (['CONTENT_TYPE' => 'content-type', 'CONTENT_LENGTH' => 'content-length'] as $key => $name) {
            if (isset($server[$key]) && is_scalar($server[$key])) {
                $headers[$name] = (string) $server[$key];
            }
        }

        return $headers;
    }

    /**
     * @param array<string, mixed> $server
     */
    private static function remoteAddress(array $server): ?string
    {
        $remote = $server['REMOTE_ADDR'] ?? null;
        if (!is_string($remote) || $remote === '') {
            return null;
        }

        return filter_var($remote, FILTER_VALIDATE_IP) === false ? null : $remote;
    }
}
