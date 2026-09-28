<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\Integration\Support\Catalogue;
use Tests\Integration\Support\HttpTestCase;

/**
 * FR-ROOM-01 … FR-ROOM-04.
 */
final class RoomAvailabilityTest extends HttpTestCase
{
    public function testTheCatalogueAndARoomsFreeSlotsAreReadable(): void
    {
        $rooms = $this->assertEnvelope($this->call('GET', '/rooms'), 200);
        self::assertNotEmpty($rooms['data']);
        self::assertArrayHasKey('capacity', $rooms['data'][0]);

        $availability = $this->assertEnvelope($this->call('GET', '/rooms/' . Catalogue::$roomId . '/availability', [
            'semester_id' => (string) Catalogue::$semesterId,
            'week_number' => '1',
        ]), 200);
        self::assertIsArray($availability['data']);
    }
}
