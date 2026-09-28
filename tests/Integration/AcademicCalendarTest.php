<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\Integration\Support\Catalogue;
use Tests\Integration\Support\HttpTestCase;

/**
 * FR-CAL-01 … FR-CAL-04.
 */
final class AcademicCalendarTest extends HttpTestCase
{
    public function testTheActiveSemesterAndItsTimelineAreReadable(): void
    {
        $list = $this->assertEnvelope($this->call('GET', '/semesters'), 200);
        $match = null;
        foreach ($list['data'] as $semester) {
            if (($semester['name'] ?? '') === Catalogue::SEMESTER) {
                $match = $semester;
                break;
            }
        }
        self::assertIsArray($match);
        self::assertSame('active', $match['status']);

        $timeline = $this->assertEnvelope(
            $this->call('GET', '/semesters/' . $match['id'] . '/timeline'),
            200,
        );
        self::assertArrayHasKey('weeks', $timeline['data']);
    }
}
