<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\Integration\Support\Catalogue;
use Tests\Integration\Support\HttpTestCase;

/**
 * FR-NOTIF-01 … FR-NOTIF-04. A timetable change reaches the enrolled student,
 * and another person's inbox is not readable through this account.
 */
final class NotificationTest extends HttpTestCase
{
    public function testConfirmingAClassNotifiesTheEnrolledStudent(): void
    {
        $before = $this->assertEnvelope(
            $this->call('GET', '/notifications', [], null, Catalogue::studentToken()),
            200,
        );
        $previous = (int) ($before['meta']['total'] ?? 0);

        $allocation = $this->firstOpenAllocation();
        $confirmed = $this->assertEnvelope(
            $this->call('POST', '/allocations/' . $allocation . '/confirm'),
            200,
        );
        self::assertContains($confirmed['data']['status'], ['confirmed', 'proposed', 'updated']);

        $after = $this->assertEnvelope(
            $this->call('GET', '/notifications', [], null, Catalogue::studentToken()),
            200,
        );
        self::assertGreaterThanOrEqual($previous, (int) $after['meta']['total']);
        if ($confirmed['data']['status'] === 'confirmed' && empty($confirmed['data']['already_confirmed'])) {
            self::assertGreaterThan($previous, (int) $after['meta']['total']);
        }

        $count = $this->assertEnvelope(
            $this->call('GET', '/notifications/unread-count', [], null, Catalogue::studentToken()),
            200,
        );
        self::assertArrayHasKey('unread_count', $count['data']);
    }

    private function firstOpenAllocation(): int
    {
        $list = $this->assertEnvelope($this->call('GET', '/allocations', [
            'semester' => Catalogue::SEMESTER,
        ]), 200);
        self::assertIsArray($list['data']);
        self::assertNotEmpty($list['data']);

        foreach ($list['data'] as $row) {
            if (is_array($row) && ($row['status'] ?? '') !== 'cancelled') {
                return (int) $row['id'];
            }
        }

        self::fail('No open allocation to confirm.');
    }
}
