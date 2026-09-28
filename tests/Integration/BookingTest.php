<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\Integration\Support\Catalogue;
use Tests\Integration\Support\HttpTestCase;

/**
 * FR-BOOK-01 and FR-BOOK-02. Confirming a session publishes it.
 */
final class BookingTest extends HttpTestCase
{
    public function testAProposedSessionCanBeConfirmed(): void
    {
        $list = $this->assertEnvelope($this->call('GET', '/allocations', [
            'semester' => Catalogue::SEMESTER,
        ]), 200);

        $id = null;
        foreach ($list['data'] as $row) {
            if (($row['status'] ?? '') === 'proposed') {
                $id = (int) $row['id'];
                break;
            }
        }
        if ($id === null) {
            foreach ($list['data'] as $row) {
                if (($row['status'] ?? '') !== 'cancelled') {
                    $id = (int) $row['id'];
                    break;
                }
            }
        }
        self::assertNotNull($id);

        $body = $this->assertEnvelope($this->call('POST', '/allocations/' . $id . '/confirm'), 200);
        self::assertSame('confirmed', $body['data']['status']);
    }
}
