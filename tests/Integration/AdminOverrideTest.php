<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\Integration\Support\HttpTestCase;

/**
 * FR-ALLOC-04 and FR-TIME-02. An override records a reason. Without one the
 * change is refused.
 */
final class AdminOverrideTest extends HttpTestCase
{
    public function testAnOverrideWithoutAReasonIsRejected(): void
    {
        $id = $this->allocationId();
        $response = $this->call('PATCH', '/allocations/' . $id, [], []);

        $body = $this->assertEnvelope($response, 422);
        self::assertSame('VALIDATION_FAILED', $body['error']['code']);
    }

    public function testAnOverrideWithAReasonIsRecorded(): void
    {
        $id = $this->allocationId();
        $body = $this->assertEnvelope($this->call('PATCH', '/allocations/' . $id, [], [
            'reason' => 'Faculty meeting moved this class.',
        ]), 200);

        self::assertSame('updated', $body['data']['status']);
        self::assertSame('override', $body['data']['source']);
        self::assertSame('Faculty meeting moved this class.', $body['data']['override_reason']);
    }

    private function allocationId(): int
    {
        $list = $this->assertEnvelope($this->call('GET', '/allocations'), 200);
        self::assertNotEmpty($list['data']);

        return (int) $list['data'][0]['id'];
    }

    private function roomIdOf(int $id): int
    {
        $row = $this->assertEnvelope($this->call('GET', '/allocations/' . $id), 200);

        return (int) $row['data']['room_id'];
    }
}
