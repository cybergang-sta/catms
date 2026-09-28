<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\Integration\Support\Catalogue;
use Tests\Integration\Support\HttpTestCase;

/**
 * FR-PROF-01 … FR-PROF-04.
 */
final class ProfileTest extends HttpTestCase
{
    public function testTheCallerCanReadAndUpdateTheirOwnProfile(): void
    {
        $shown = $this->assertEnvelope(
            $this->call('GET', '/profile', [], null, Catalogue::studentToken()),
            200,
        );
        self::assertSame('student@utas.edu.gh', $shown['data']['email']);
        self::assertArrayNotHasKey('password_hash', $shown['data']);

        $updated = $this->assertEnvelope($this->call('PATCH', '/profile', [], [
            'phone' => '024-000-0000',
        ], Catalogue::studentToken()), 200);
        self::assertSame('024-000-0000', $updated['data']['phone']);
    }

    public function testAPasswordChangeWithAMismatchIsRejected(): void
    {
        $response = $this->call('PATCH', '/profile/password', [], [
            'current_password'      => 'Student@1234',
            'password'              => 'river-lantern-semester',
            'password_confirmation' => 'a-different-phrase',
        ], Catalogue::studentToken());

        self::assertSame(422, $response->status(), $response->body());
    }
}
