<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\Integration\Support\Catalogue;
use Tests\Integration\Support\HttpTestCase;

/**
 * FR-ADMIN-01 … FR-ADMIN-07. The department summary and the account list.
 */
final class DashboardTest extends HttpTestCase
{
    public function testTheDashboardCountsLiveRecords(): void
    {
        $body = $this->assertEnvelope($this->call('GET', '/dashboard'), 200);
        self::assertGreaterThan(0, $body['data']['active_users']);
        self::assertGreaterThan(0, $body['data']['available_rooms']);
        self::assertArrayHasKey('proposed_allocations', $body['data']);
        self::assertArrayHasKey('open_conflicts', $body['data']);

        $heat = $this->assertEnvelope($this->call('GET', '/dashboard/heat-map'), 200);
        self::assertIsArray($heat['data']);

        $users = $this->assertEnvelope($this->call('GET', '/users'), 200);
        self::assertGreaterThanOrEqual(3, (int) $users['meta']['total']);
    }

    public function testAStudentCannotOpenTheDashboard(): void
    {
        $response = $this->call('GET', '/dashboard', [], null, Catalogue::studentToken());
        self::assertSame(403, $response->status(), $response->body());
    }
}
