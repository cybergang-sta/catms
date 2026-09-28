<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\Integration\Support\Catalogue;
use Tests\Integration\Support\HttpTestCase;

/**
 * FR-REPORT-01 … FR-REPORT-03.
 */
final class ReportTest extends HttpTestCase
{
    public function testUtilisationConflictsAndLecturerLoadAreReadable(): void
    {
        $utilisation = $this->assertEnvelope($this->call('GET', '/reports/utilisation', [
            'semester' => Catalogue::SEMESTER,
        ]), 200);
        self::assertIsArray($utilisation['data']);

        $conflicts = $this->assertEnvelope($this->call('GET', '/reports/conflicts'), 200);
        self::assertIsArray($conflicts['data']);

        $load = $this->assertEnvelope($this->call('GET', '/reports/lecturer-load', [
            'semester' => Catalogue::SEMESTER,
        ]), 200);
        self::assertIsArray($load['data']);
    }
}
