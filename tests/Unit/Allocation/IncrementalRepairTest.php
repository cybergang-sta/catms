<?php

declare(strict_types=1);

namespace Tests\Unit\Allocation;

use App\Domain\Allocation\EngineOptions;
use Tests\Unit\Allocation\Fixture\ProblemBuilder;

/**
 * Incremental repair: re-run the engine when something changes mid-semester,
 * without disturbing the rest of the timetable.
 *
 * This is the behaviour that decides whether the tool is usable after week one.
 * A full regeneration on every change would be simpler to build and unusable in
 * practice — every lecturer and every student would get a notification for a
 * change that did not affect them (FR-NOTIF-02), and the churn cost term would
 * be meaningless.
 *
 * The guarantee under test: a change to one cohort affects that cohort's
 * sessions and nothing else.
 */
final class IncrementalRepairTest extends EngineTestCase
{
    public function testAnEnrolmentIncreaseEvictsOnlyTheAffectedSession(): void
    {
        // Two cohorts, each in their own room, on separate days. Cohort 1 grows
        // past its room's capacity; cohort 2 must not move.
        $before = (new ProblemBuilder())
            ->standardWeek(1)
            ->room(40, shared: true)
            ->room(60, shared: true)
            ->session(1, 1, enrolledCount: 30)
            ->session(2, 2, enrolledCount: 50);

        $firstRun = $this->solve($before, EngineOptions::greedyOnly(1));
        $this->assertResultIsSound($firstRun);
        self::assertCount(2, $firstRun->assignments);

        $beforeAssignments = [];
        foreach ($firstRun->assignments as $sessionId => $assignment) {
            $beforeAssignments[$sessionId] = $assignment;
        }

        // Enrolment rises 30 -> 50, which no longer fits the 40-seat room.
        $after = (new ProblemBuilder())
            ->standardWeek(1)
            ->room(40, shared: true)
            ->room(60, shared: true)
            ->session(1, 1, enrolledCount: 50)
            ->session(2, 2, enrolledCount: 50);

        $repair = $this->engine()->solve(
            $after->build(),
            array_values($firstRun->assignments),
            EngineOptions::greedyOnly(1),
        );

        $this->assertResultIsSound($repair);
        self::assertCount(2, $repair->assignments, 'Both sessions should still fit somewhere.');

        // The unaffected session must be byte-identical.
        self::assertSame(
            $beforeAssignments[2]->roomId(),
            $repair->assignments[2]->roomId(),
            'The unrelated cohort must not be moved by an incremental repair.',
        );
        self::assertSame(
            $beforeAssignments[2]->timeSlotId(),
            $repair->assignments[2]->timeSlotId(),
        );

        // The affected one must have moved to a room that fits.
        $problem = $after->build();
        $moved = $repair->assignments[1];
        $room = $problem->roomById($moved->roomId());

        self::assertNotNull($room);
        self::assertGreaterThanOrEqual(50, $room->capacity());
    }

    public function testAGrowthThatFitsTheSameRoomChangesNothing(): void
    {
        $before = (new ProblemBuilder())
            ->standardWeek(1)
            ->room(60, shared: true)
            ->room(60, shared: true)
            ->session(1, 1, enrolledCount: 30);

        $firstRun = $this->solve($before, EngineOptions::greedyOnly(1));

        $after = (new ProblemBuilder())
            ->standardWeek(1)
            ->room(60, shared: true)
            ->room(60, shared: true)
            ->session(1, 1, enrolledCount: 55); // still fits

        $repair = $this->engine()->solve(
            $after->build(),
            array_values($firstRun->assignments),
            EngineOptions::greedyOnly(1),
        );

        self::assertSame(
            $firstRun->assignments[1]->roomId(),
            $repair->assignments[1]->roomId(),
        );
        self::assertSame(
            $firstRun->assignments[1]->timeSlotId(),
            $repair->assignments[1]->timeSlotId(),
        );
        self::assertSame(0, $repair->metrics['warm_start_rejected']);
    }

    public function testARoomGoingIntoMaintenanceRelocatesOnlyItsBookings(): void
    {
        $slots = (new ProblemBuilder())->standardWeek(2)->room(50, shared: true)->room(50, shared: true);

        $problem = $slots->session(1, 1, enrolledCount: 50)
            ->session(2, 2, enrolledCount: 50)
            ->build();

        $firstRun = $this->engine()->solve($problem, [], EngineOptions::greedyOnly(1));
        self::assertCount(2, $firstRun->assignments);

        // Room 1 is closed for maintenance.
        $degraded = (new ProblemBuilder())
            ->standardWeek(2)
            ->room(50, shared: true, status: 'maintenance')
            ->room(50, shared: true)
            ->session(1, 1, enrolledCount: 50)
            ->session(2, 2, enrolledCount: 50);

        $repair = $this->engine()->solve(
            $degraded->build(),
            array_values($firstRun->assignments),
            EngineOptions::greedyOnly(1),
        );

        $this->assertResultIsSound($repair);
        self::assertCount(2, $repair->assignments);

        foreach ($repair->assignments as $assignment) {
            self::assertNotSame(
                1,
                $assignment->roomId(),
                'Nothing may remain booked in a room that is under maintenance.',
            );
        }
    }

    public function testALecturerBecomingUnavailableRelocatesOnlyTheirSessions(): void
    {
        $before = (new ProblemBuilder())
            ->standardWeek(1)
            ->room(50, shared: true)
            ->room(50, shared: true)
            ->session(1, 1, enrolledCount: 30)
            ->session(2, 2, enrolledCount: 30);

        $firstRun = $this->solve($before, EngineOptions::greedyOnly(1));
        self::assertCount(2, $firstRun->assignments);

        $otherRoom = null;
        $otherSlot = null;
        foreach ($firstRun->assignments as $sessionId => $assignment) {
            if ($sessionId === 2) {
                $otherRoom = $assignment->roomId();
                $otherSlot = $assignment->timeSlotId();
            }
        }
        self::assertNotNull($otherRoom);

        // Lecturer 1 is now unavailable on every day.
        $after = (new ProblemBuilder())
            ->standardWeek(1)
            ->room(50, shared: true)
            ->room(50, shared: true)
            ->session(1, 1, enrolledCount: 30)
            ->session(2, 2, enrolledCount: 30)
            ->lecturerUnavailableDay(1, 1)
            ->lecturerUnavailableDay(1, 2)
            ->lecturerUnavailableDay(1, 3)
            ->lecturerUnavailableDay(1, 4)
            ->lecturerUnavailableDay(1, 5);

        $repair = $this->engine()->solve(
            $after->build(),
            array_values($firstRun->assignments),
            EngineOptions::greedyOnly(1),
        );

        $this->assertResultIsSound($repair);

        // Lecturer 1's session must be gone from the timetable, with a reason.
        self::assertArrayNotHasKey(1, $repair->assignments);
        self::assertCount(1, $repair->unallocated);
        self::assertSame(
            'HC-8',
            $repair->unallocated[0]->primaryConstraint(),
        );

        // Lecturer 2's session is untouched.
        self::assertSame($otherRoom, $repair->assignments[2]->roomId());
        self::assertSame($otherSlot, $repair->assignments[2]->timeSlotId());
    }

    public function testRepairingFromACompletelyEmptyTimetableStillWorks(): void
    {
        $builder = (new ProblemBuilder())
            ->standardWeek(1)
            ->room(50, shared: true)
            ->session(1, 1, enrolledCount: 30)
            ->session(2, 2, enrolledCount: 30);

        $result = $this->engine()->solve($builder->build(), [], EngineOptions::greedyOnly(1));

        self::assertCount(2, $result->assignments);
    }

    public function testARepairNeverTouchesASessionWhoseRoomIsUnchangedAndStillFits(): void
    {
        // The "byte-identical" claim, checked over a handful of independent
        // sessions rather than one lucky pair.
        $before = new ProblemBuilder();
        $before->standardWeek(1);
        for ($i = 0; $i < 4; $i++) {
            $before->room(50, shared: true);
        }
        for ($i = 1; $i <= 4; $i++) {
            $before->session($i, $i, enrolledCount: 40);
        }

        $firstRun = $this->engine()->solve($before->build(), [], EngineOptions::greedyOnly(1));
        self::assertCount(4, $firstRun->assignments);

        // Cohort 1 grows; cohorts 2-4 are untouched.
        $after = new ProblemBuilder();
        $after->standardWeek(1);
        for ($i = 0; $i < 4; $i++) {
            $after->room(50, shared: true);
        }
        $after->session(1, 1, enrolledCount: 50);
        for ($i = 2; $i <= 4; $i++) {
            $after->session($i, $i, enrolledCount: 40);
        }

        $repair = $this->engine()->solve(
            $after->build(),
            array_values($firstRun->assignments),
            EngineOptions::greedyOnly(1),
        );

        $this->assertResultIsSound($repair);

        for ($sessionId = 2; $sessionId <= 4; $sessionId++) {
            self::assertSame(
                $firstRun->assignments[$sessionId]->key(),
                $repair->assignments[$sessionId]->key(),
                "Session {$sessionId} was affected by a change to session 1.",
            );
        }
    }
}
