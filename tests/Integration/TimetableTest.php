<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\Integration\Support\Catalogue;
use Tests\Integration\Support\HttpTestCase;

/**
 * FR-TIME-01 and FR-TIME-03 … FR-TIME-05. Week, day and semester views.
 */
final class TimetableTest extends HttpTestCase
{
    public function testTheWeekViewListsPlacedSessions(): void
    {
        $body = $this->assertEnvelope($this->call('GET', '/timetable', [
            'semester' => Catalogue::SEMESTER,
            'week'     => '1',
        ]), 200);

        $data = $body['data'];
        self::assertIsArray($data);
        self::assertGreaterThan(0, $data['summary']['sessions']);
        self::assertNotEmpty($data['days']);
    }

    public function testTheDayAndSemesterViewsAreReadable(): void
    {
        $week = $this->assertEnvelope($this->call('GET', '/timetable', [
            'semester' => Catalogue::SEMESTER,
            'week'     => '1',
        ]), 200);
        $date = $week['data']['days'][0]['date'] ?? null;
        self::assertIsString($date);

        $day = $this->assertEnvelope($this->call('GET', '/timetable/day', [
            'semester' => Catalogue::SEMESTER,
            'date'     => $date,
        ]), 200);
        self::assertArrayHasKey('entries', $day['data']);

        $term = $this->assertEnvelope($this->call('GET', '/timetable/semester', [
            'semester' => Catalogue::SEMESTER,
        ]), 200);
        self::assertNotEmpty($term['data']['weeks']);
    }

    public function testAStudentCanReadThePublishedWeek(): void
    {
        $body = $this->assertEnvelope($this->call('GET', '/timetable', [
            'semester' => Catalogue::SEMESTER,
            'week'     => '1',
        ], null, Catalogue::studentToken()), 200);

        self::assertGreaterThan(0, $body['data']['summary']['sessions']);
    }
}
