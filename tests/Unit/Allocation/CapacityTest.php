<?php

declare(strict_types=1);

namespace Tests\Unit\Allocation;

use App\Domain\Allocation\SchedulingProblem;
use Tests\Unit\Allocation\Fixture\ProblemBuilder;
use Tests\Unit\Allocation\Fixture\ProblemFactory;

/**
 * Property test: no cohort is ever placed in a room that is too small.
 *
 * The companion to NoDoubleBookingTest. Capacity is the constraint most likely
 * to break on an edge case the fixtures miss — an enrolment of zero, a room
 * whose capacity changes mid-term, a cohort that grows between the problem
 * being built and the run starting.
 */
final class CapacityTest extends EngineTestCase
{
    private const TRIALS = 150;

    public function testNoCohortIsEverPlacedInATooSmallRoom(): void
    {
        for ($trial = 0; $trial < self::TRIALS; $trial++) {
            $seed = 2000 + $trial;
            $factory = new ProblemFactory($seed);

            $builder = $factory->random(
                sessionCount: $factory->rng()->int(1, 20),
                roomCount: $factory->rng()->int(1, 8),
                slotCount: $factory->rng()->int(1, 12),
                cohortCount: $factory->rng()->int(1, 8),
                lecturerCount: $factory->rng()->int(1, 5),
            );

            $problem = $builder->build();
            $result = $this->engine()->solve($problem, [], $this->options(maxIterations: 60, seed: $seed));

            $this->assertResultIsSound($result);
            $this->assertCapacityRespected($result->assignments, $problem, $seed);
        }
    }

    public function testACohortLargerThanEveryRoomIsReportedNotForcedIn(): void
    {
        $builder = (new ProblemBuilder())
            ->standardWeek(2)
            ->room(20, shared: true)
            ->room(30, shared: true)
            ->session(1, 1, enrolledCount: 500);

        $result = $this->solve($builder, $this->greedyOptions());

        $this->assertResultIsSound($result);

        self::assertSame([], $result->assignments, 'A 500-student cohort must not be placed in a 30-seat room.');
        self::assertCount(1, $result->unallocated);

        // FR-ALLOC-05: the reason must name capacity, not just "failed".
        $unallocated = $result->unallocated[0];
        self::assertSame('HC-4', $unallocated->primaryConstraint());
        self::assertArrayHasKey('HC-4', $unallocated->blockingConstraints);
    }

    public function testARoomExactlyAtCapacityIsAccepted(): void
    {
        $builder = (new ProblemBuilder())
            ->standardWeek(1)
            ->room(100, shared: true)
            ->session(1, 1, enrolledCount: 100);

        $result = $this->solve($builder, $this->greedyOptions());

        self::assertCount(1, $result->assignments);
        self::assertSame([], $result->unallocated);
    }

    public function testARoomOneSeatShortIsRejected(): void
    {
        $builder = (new ProblemBuilder())
            ->standardWeek(1)
            ->room(99, shared: true)
            ->session(1, 1, enrolledCount: 100);

        $result = $this->solve($builder, $this->greedyOptions());

        self::assertSame([], $result->assignments);
        self::assertCount(1, $result->unallocated);
    }

    /**
     * @param array<int, \App\Domain\Allocation\Assignment> $assignments
     */
    private function assertCapacityRespected(array $assignments, SchedulingProblem $problem, int $seed): void
    {
        foreach ($assignments as $assignment) {
            $session = $problem->sessionById($assignment->sessionId());
            $room = $problem->roomById($assignment->roomId());

            self::assertNotNull($session, "Session {$assignment->sessionId()} is not in the problem (seed $seed).");
            self::assertNotNull($room, "Room {$assignment->roomId()} is not in the problem (seed $seed).");

            self::assertGreaterThanOrEqual(
                $session->enrolledCount(),
                $room->capacity(),
                sprintf(
                    'Session %d enrols %d students but was placed in room %d, which seats %d (seed %d).',
                    $session->id(),
                    $session->enrolledCount(),
                    $room->id(),
                    $room->capacity(),
                    $seed,
                ),
            );
        }
    }
}
