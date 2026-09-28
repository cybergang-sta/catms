<?php

declare(strict_types=1);

namespace App\Core;

/**
 * An HTTP response, constructed but not yet sent.
 *
 * Keeping construction separate from `send()` is what makes controllers
 * testable: a test asserts on the envelope without capturing output, and nothing
 * in `src/` other than this class is allowed to call `header()` or `echo`.
 *
 * ENVELOPE
 * `docs/API.md` §1: every JSON response has exactly `data`, `meta` and `error`.
 * `error` is null on success. Building it in one place is what stops the codebase
 * from drifting into five different error shapes.
 */
final class Response
{
    /** @var array<string, string> */
    private array $headers = [];

    /**
     * @param mixed                $data
     * @param array<string, mixed> $meta
     */
    private function __construct(
        private readonly int $status,
        private readonly mixed $data = null,
        private readonly array $meta = [],
        private readonly ?string $errorCode = null,
        private readonly ?string $errorMessage = null,
        private readonly array $errorDetails = [],
        private readonly string $rawBody = '',
        private readonly string $contentType = 'application/json; charset=utf-8',
    ) {
    }

    /**
     * @param mixed                $data
     * @param array<string, mixed> $meta
     */
    public static function success(mixed $data = null, array $meta = [], int $status = 200): self
    {
        return new self($status, $data, $meta);
    }

    /**
     * 201, for a resource that was created.
     *
     * @param mixed                $data
     * @param array<string, mixed> $meta
     */
    public static function created(mixed $data, array $meta = []): self
    {
        return new self(201, $data, $meta);
    }

    /**
     * 202, for work that has been accepted but not done. An asynchronous
     * generation run returns this with a `run_id` to poll.
     *
     * @param array<string, mixed> $meta
     */
    public static function accepted(array $meta = []): self
    {
        return new self(202, null, $meta);
    }

    public static function noContent(): self
    {
        return new self(204);
    }

    /**
     * @param array<string, mixed> $details
     * @param array<string, mixed> $meta
     */
    public static function error(
        int $status,
        string $code,
        string $message,
        array $details = [],
        array $meta = [],
    ): self {
        return new self($status, null, $meta, $code, $message, $details);
    }

    /**
     * A CSV attachment. Used by the timetable and report exports (FR-REPORT-03).
     *
     * @param list<string>         $header
     * @param list<list<mixed>>    $rows
     */
    public static function csv(string $filename, array $header, array $rows): self
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return self::error(500, 'INTERNAL_ERROR', 'Could not build the export.');
        }

        fputcsv($handle, $header);
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }

        rewind($handle);
        $body = stream_get_contents($handle);
        fclose($handle);

        $response = new self(200, null, [], null, null, [], (string) $body, 'text/csv; charset=utf-8');

        return $response
            ->withHeader('Content-Disposition', 'attachment; filename="' . self::safeFilename($filename) . '"');
    }

    /**
     * The PWA document. Not the JSON envelope: a browser navigation to `/`
     * or `/today` receives this, and the client then talks to `/api/v1`.
     *
     * The policy matches `docs/SECURITY.md`: the shell may only load itself.
     * Inline styles and remote fonts are deliberately not allowed, so the
     * stylesheet and the script live under `public/assets`.
     */
    public static function html(string $document): self
    {
        return (new self(
            200,
            null,
            [],
            null,
            null,
            [],
            $document,
            'text/html; charset=utf-8',
        ))->withHeaders([
            'Cache-Control' => 'no-cache',
            'Content-Security-Policy' => "default-src 'self'; script-src 'self'; style-src 'self'; "
                . "img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; "
                . "base-uri 'self'; form-action 'self'",
        ]);
    }

    public function withHeader(string $name, string $value): self
    {
        $clone = clone $this;
        $clone->headers[$name] = $value;

        return $clone;
    }

    /**
     * @param array<string, string> $headers
     */
    public function withHeaders(array $headers): self
    {
        $clone = clone $this;
        foreach ($headers as $name => $value) {
            $clone->headers[$name] = $value;
        }

        return $clone;
    }

    public function status(): int
    {
        return $this->status;
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * The body exactly as it will go over the wire, as a string.
     *
     * JSON encoding uses JSON_THROW_ON_ERROR rather than returning false, because
     * a silently empty body on an encoding failure is the worst possible
     * outcome: a 200 that contains nothing.
     */
    public function body(): string
    {
        if ($this->rawBody !== '') {
            return $this->rawBody;
        }

        if ($this->status === 204) {
            return '';
        }

        $envelope = [
            'data'  => $this->data,
            'meta'  => (object) $this->meta,
            'error' => $this->errorCode === null
                ? null
                : [
                    'code'    => $this->errorCode,
                    'message' => (string) $this->errorMessage,
                    'details' => (object) $this->errorDetails,
                ],
        ];

        return json_encode(
            $envelope,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function decoded(): array
    {
        $decoded = json_decode($this->body(), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Emit status, headers and body.
     *
     * A 204 suppresses Content-Type and Cache-Control, because RFC 9110 §15.3.5
     * requires it to carry no content at all; browsers mis-handle the alternative.
     */
    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            header('X-Content-Type-Options: nosniff');
            header('X-Frame-Options: DENY');
            header('Referrer-Policy: no-referrer');

            if ($this->status === 204) {
                header_remove('Content-Type');
                header_remove('Content-Length');
            } else {
                header('Content-Type: ' . $this->contentType);
                header('Content-Length: ' . (string) strlen($this->body()));
                header('Cache-Control: no-store');
            }

            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value);
            }
        }

        echo $this->body();
    }

    /**
     * Strip anything from a filename that could break out of the header value.
     */
    private static function safeFilename(string $filename): string
    {
        $clean = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) ?? 'export.csv';

        return $clean === '' ? 'export.csv' : $clean;
    }
}
