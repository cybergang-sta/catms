<?php

declare(strict_types=1);

namespace Tests\Integration\Support;

use App\Core\Response;
use PHPUnit\Framework\TestCase;

abstract class HttpTestCase extends TestCase
{
    protected function setUp(): void
    {
        Catalogue::app();
    }

    /**
     * @param array<string, scalar> $query
     * @param array<string, mixed>|null $body
     */
    protected function call(
        string $method,
        string $path,
        array $query = [],
        ?array $body = null,
        ?string $token = null,
    ): Response {
        return Catalogue::call($method, $path, $query, $body, $token ?? Catalogue::adminToken());
    }

    /**
     * @return array<string, mixed>
     */
    protected function assertEnvelope(Response $response, int $status): array
    {
        $decoded = $response->decoded();
        self::assertSame($status, $response->status(), $response->body());
        self::assertArrayHasKey('data', $decoded);
        self::assertArrayHasKey('error', $decoded);
        if ($status >= 200 && $status < 300) {
            self::assertNull($decoded['error']);
        }

        return $decoded;
    }
}
