<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\Integration\Support\Catalogue;
use Tests\Integration\Support\HttpTestCase;

/**
 * NFR-PERF-01 and NFR-PERF-03, measured on this process against the test
 * database. The figures in the requirements table are targets. The assertions
 * are the budgets, and the failure message records the time that was measured.
 */
final class PerformanceMeasurementTest extends HttpTestCase
{
    public function testTheWeekViewRespondsWithinOneSecond(): void
    {
        $started = hrtime(true);
        $response = $this->call('GET', '/timetable', [
            'semester' => Catalogue::SEMESTER,
            'week'     => '1',
        ]);
        $seconds = (hrtime(true) - $started) / 1_000_000_000;

        self::assertSame(200, $response->status(), $response->body());
        self::assertLessThan(1.0, $seconds, sprintf('GET /timetable took %.3f s.', $seconds));
    }

    public function testAnAllocationChangeReturnsWithinThreeSeconds(): void
    {
        $list = $this->assertEnvelope($this->call('GET', '/allocations'), 200);
        $id = (int) $list['data'][0]['id'];

        $started = hrtime(true);
        $response = $this->call('PATCH', '/allocations/' . $id, [], [
            'reason' => 'Measured override for the response-time budget.',
        ]);
        $seconds = (hrtime(true) - $started) / 1_000_000_000;

        self::assertSame(200, $response->status(), $response->body());
        self::assertLessThan(3.0, $seconds, sprintf('PATCH /allocations/%d took %.3f s.', $id, $seconds));
    }
}
