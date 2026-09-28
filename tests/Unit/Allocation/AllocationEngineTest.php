<?php

declare(strict_types=1);

namespace Tests\Unit\Allocation;

use Tests\Unit\Allocation\Fixture\ProblemBuilder;

/**
 * The class named by the traceability matrix for FR-ALLOC-01 and FR-ALLOC-03.
 *
 * The property suite around this class already covers double booking, capacity,
 * repair and determinism. This test is the small, named entry point: one
 * feasible session is placed, and the result stays inside the hard constraints.
 */
final class AllocationEngineTest extends EngineTestCase
{
    public function testAFeasibleSessionIsPlacedWithoutAHardViolation(): void
    {
        $result = $this->solve(
            (new ProblemBuilder())
                ->standardWeek(1)
                ->room(40, shared: true)
                ->session(1, 1, enrolledCount: 30),
        );

        $this->assertResultIsSound($result);
        self::assertSame(1, $result->assignedCount());
        self::assertSame(0, $result->unallocatedCount());
        self::assertGreaterThanOrEqual(0.90, (float) $result->metrics['accuracy']);
        self::assertTrue($result->isPublishable());
    }
}
