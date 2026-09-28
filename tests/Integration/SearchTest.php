<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\Integration\Support\HttpTestCase;

/**
 * FR-SEARCH-01 and FR-SEARCH-02.
 */
final class SearchTest extends HttpTestCase
{
    public function testRoomsCanBeFilteredByCapacity(): void
    {
        $body = $this->assertEnvelope($this->call('GET', '/search/rooms', [
            'min_capacity' => '30',
        ]), 200);

        self::assertIsArray($body['data']);
        self::assertNotEmpty($body['data']);
        foreach ($body['data'] as $room) {
            self::assertGreaterThanOrEqual(30, (int) $room['capacity']);
        }
    }

    public function testSchedulesCanBeFoundByCourseCode(): void
    {
        $body = $this->assertEnvelope($this->call('GET', '/search/schedules', [
            'q' => 'CS101',
        ]), 200);

        self::assertIsArray($body['data']);
        self::assertNotEmpty($body['data']);
    }

    public function testAnEmptyScheduleQueryIsRejected(): void
    {
        $body = $this->assertEnvelope($this->call('GET', '/search/schedules'), 422);
        self::assertSame('VALIDATION_FAILED', $body['error']['code']);
    }
}
