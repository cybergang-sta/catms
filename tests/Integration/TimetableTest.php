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

    public function testTheCsvExportMatchesTheWeekGrid(): void
    {
        $week = $this->assertEnvelope($this->call('GET', '/timetable', [
            'semester' => Catalogue::SEMESTER,
            'week'     => '1',
        ]), 200);

        $first = null;
        foreach ($week['data']['days'] as $day) {
            foreach ($day['entries'] ?? [] as $entry) {
                $first = ['day' => $day, 'entry' => $entry];
                break 2;
            }
        }
        self::assertNotNull($first);

        $response = $this->call('GET', '/timetable/' . Catalogue::$semesterId . '/export.csv', [
            'week' => '1',
        ]);
        self::assertSame(200, $response->status(), $response->body());
        self::assertStringContainsString('attachment;', $response->headers()['Content-Disposition'] ?? '');

        $lines = preg_split("/\r\n|\n/", trim($response->body())) ?: [];
        self::assertNotEmpty($lines);
        $header = str_getcsv($lines[0]);
        self::assertSame([
            'Week', 'Day', 'Date', 'Start', 'End', 'Course', 'Title', 'Cohort',
            'Lecturer', 'Room', 'Building', 'Capacity', 'Status', 'Source', 'Changed',
        ], $header);

        $row = str_getcsv($lines[1]);
        self::assertCount(count($header), $row);
        self::assertSame($first['entry']['course']['code'], $row[5]);
        self::assertSame($first['entry']['course']['title'], $row[6]);
        self::assertSame($first['entry']['cohort']['name'], $row[7]);
        self::assertSame($first['entry']['lecturer']['name'], $row[8]);
        self::assertSame($first['entry']['room']['code'], $row[9]);
        self::assertSame(substr((string) $first['entry']['slot']['start'], 0, 5), $row[3]);
    }
}
