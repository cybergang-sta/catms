<?php

declare(strict_types=1);

namespace Tests\Unit\Allocation;

use App\Domain\Allocation\Assignment;
use App\Domain\Allocation\ConstraintChecker;
use App\Domain\Allocation\OccupancyIndex;
use App\Domain\Allocation\Room;
use App\Domain\Allocation\RoomFeatures;
use App\Domain\Allocation\SchedulingProblem;
use App\Domain\Allocation\SessionRequest;

/**
 * HC-1 … HC-10, one test each.
 *
 * Each test builds the *smallest* problem that can exhibit the violation, puts
 * a known-good assignment in the occupancy index, and asserts that the specific
 * constraint fires and that the repaired version does not. Testing all ten in
 * one scenario would pass even if the codes were crossed, which is the exact
 * bug this file exists to prevent — the codes are persisted in
 * allocation_conflicts.constraint_code and read by the conflict report, so a
 * swapped code tells an administrator the wrong thing.
 */
final class HardConstraintTest extends EngineTestCase
{
    /**
     * A two-room, two-slot problem with one cohort and one lecturer.
     */
    private function base(): SchedulingProblem
    {
        return (new \Tests\Unit\Allocation\Fixture\ProblemBuilder())
            ->standardWeek(1)
            ->room(50, shared: true)
            ->room(50, shared: true)
            ->session(1, 1, enrolledCount: 30)
            ->build();
    }

    private function assertCode(
        SchedulingProblem $problem,
        OccupancyIndex $occupancy,
        Assignment $candidate,
        string $expected,
    ): void {
        $checker = new ConstraintChecker();
        $codes = array_map(
            static fn ($v): string => $v->code,
            $checker->check($candidate, $occupancy, $problem),
        );

        self::assertContains(
            $expected,
            $codes,
            sprintf(
                'Expected %s but the checker reported [%s] for session %d in room %d at slot %d.',
                $expected,
                implode(', ', $codes) ?: 'nothing',
                $candidate->sessionId(),
                $candidate->roomId(),
                $candidate->timeSlotId(),
            ),
        );
    }

    private function assertNoCode(
        SchedulingProblem $problem,
        OccupancyIndex $occupancy,
        Assignment $candidate,
        string $unexpected,
    ): void {
        $checker = new ConstraintChecker();
        $codes = array_map(
            static fn ($v): string => $v->code,
            $checker->check($candidate, $occupancy, $problem),
        );

        self::assertNotContains($unexpected, $codes);
    }

    // --- HC-1 -------------------------------------------------------------

    public function testHc1RejectsARoomAlreadyBookedInThatSlot(): void
    {
        $problem = $this->base();

        $occupancy = new OccupancyIndex();
        $occupancy->place(new Assignment(1, 1, 1), $problem);

        $this->assertCode($problem, $occupancy, new Assignment(1, 2, 1), ConstraintChecker::HC_ROOM_FREE);
    }

    public function testHc1AcceptsTheSameRoomInADifferentSlot(): void
    {
        $problem = $this->base();

        $occupancy = new OccupancyIndex();
        $occupancy->place(new Assignment(1, 1, 1), $problem);

        $this->assertNoCode($problem, $occupancy, new Assignment(1, 1, 2), ConstraintChecker::HC_ROOM_FREE);
    }

    // --- HC-2 -------------------------------------------------------------

    public function testHc2RejectsALecturerAlreadyTeachingInThatSlot(): void
    {
        $problem = (new \Tests\Unit\Allocation\Fixture\ProblemBuilder())
            ->standardWeek(1)
            ->room(50, shared: true)
            ->session(1, 1, enrolledCount: 30)
            ->session(2, 1, enrolledCount: 30) // same lecturer, different cohort
            ->build();

        $occupancy = new OccupancyIndex();
        $occupancy->place(new Assignment(1, 1, 1), $problem);

        $this->assertCode($problem, $occupancy, new Assignment(2, 1, 1), ConstraintChecker::HC_LECTURER_FREE);
    }

    // --- HC-3 -------------------------------------------------------------

    public function testHc3RejectsACohortAlreadyInClassInThatSlot(): void
    {
        $problem = (new \Tests\Unit\Allocation\Fixture\ProblemBuilder())
            ->standardWeek(1)
            ->room(50, shared: true)
            ->room(50, shared: true)
            ->session(1, 1, enrolledCount: 30)
            ->session(1, 2, enrolledCount: 30) // same cohort, different lecturer
            ->build();

        $occupancy = new OccupancyIndex();
        $occupancy->place(new Assignment(1, 1, 1), $problem);

        $this->assertCode($problem, $occupancy, new Assignment(2, 2, 1), ConstraintChecker::HC_COHORT_FREE);
    }

    public function testHc3AllowsACohortInTwoRoomsAcrossTheDay(): void
    {
        $problem = (new \Tests\Unit\Allocation\Fixture\ProblemBuilder())
            ->standardWeek(2)
            ->room(50, shared: true)
            ->room(50, shared: true)
            ->session(1, 1, enrolledCount: 30)
            ->session(1, 2, enrolledCount: 30)
            ->build();

        $occupancy = new OccupancyIndex();
        $occupancy->place(new Assignment(1, 1, 1), $problem);

        $this->assertNoCode($problem, $occupancy, new Assignment(2, 2, 2), ConstraintChecker::HC_COHORT_FREE);
    }

    // --- HC-4 -------------------------------------------------------------

    public function testHc4RejectsARoomTooSmallForTheCohort(): void
    {
        $problem = (new \Tests\Unit\Allocation\Fixture\ProblemBuilder())
            ->standardWeek(1)
            ->room(20, shared: true)
            ->session(1, 1, enrolledCount: 30)
            ->build();

        $this->assertCode($problem, new OccupancyIndex(), new Assignment(1, 1, 1), ConstraintChecker::HC_CAPACITY);
    }

    public function testHc4AcceptsAExactlyFittingRoom(): void
    {
        $problem = (new \Tests\Unit\Allocation\Fixture\ProblemBuilder())
            ->standardWeek(1)
            ->room(30, shared: true)
            ->session(1, 1, enrolledCount: 30)
            ->build();

        $this->assertNoCode($problem, new OccupancyIndex(), new Assignment(1, 1, 1), ConstraintChecker::HC_CAPACITY);
    }

    // --- HC-5 -------------------------------------------------------------

    public function testHc5RejectsARoomMissingAMandatoryFeature(): void
    {
        $problem = (new \Tests\Unit\Allocation\Fixture\ProblemBuilder())
            ->standardWeek(1)
            ->room(50, features: ['projector', 'whiteboard'], shared: true)
            ->session(1, 1, enrolledCount: 30, requiredFeatures: ['projector', 'lab_bench'])
            ->build();

        $this->assertCode($problem, new OccupancyIndex(), new Assignment(1, 1, 1), ConstraintChecker::HC_FEATURES);
    }

    public function testHc5FeatureComparisonIsCaseInsensitive(): void
    {
        $room = new Room(1, 'R1', 'Room 1', 'Main', 50, new RoomFeatures(['Projector']), 'available', true);
        $session = new SessionRequest(1, 1, 1, 1, 30, 60, new RoomFeatures(['projector']));

        self::assertTrue($room->features()->satisfies($session->requiredFeatures()));
    }

    // --- HC-6 -------------------------------------------------------------

    public function testHc6RejectsARoomUnderMaintenance(): void
    {
        $problem = (new \Tests\Unit\Allocation\Fixture\ProblemBuilder())
            ->standardWeek(1)
            ->room(50, status: 'maintenance', shared: true)
            ->session(1, 1, enrolledCount: 30)
            ->build();

        $this->assertCode(
            $problem,
            new OccupancyIndex(),
            new Assignment(1, 1, 1),
            ConstraintChecker::HC_ROOM_SERVICEABLE,
        );
    }

    public function testHc6RejectsAnUnbookableRoomEvenWhenAvailable(): void
    {
        $problem = (new \Tests\Unit\Allocation\Fixture\ProblemBuilder())
            ->standardWeek(1)
            ->room(50, status: 'available', bookable: false, shared: true)
            ->session(1, 1, enrolledCount: 30)
            ->build();

        $this->assertCode(
            $problem,
            new OccupancyIndex(),
            new Assignment(1, 1, 1),
            ConstraintChecker::HC_ROOM_SERVICEABLE,
        );
    }

    // --- HC-7 -------------------------------------------------------------

    public function testHc7RejectsASlotOutsideTheTeachingWindow(): void
    {
        $builder = new \Tests\Unit\Allocation\Fixture\ProblemBuilder();
        $builder->standardWeek(1)
            ->room(50, shared: true)
            ->session(1, 1, enrolledCount: 30);

        $slotIds = $builder->slotIds();
        $builder->teachableSlots([$slotIds[0]]);

        $problem = $builder->build();

        $this->assertCode(
            $problem,
            new OccupancyIndex(),
            new Assignment(1, 1, $slotIds[1]),
            ConstraintChecker::HC_CALENDAR_WINDOW,
        );
    }

    public function testHc7RejectsAnInactiveSlot(): void
    {
        $problem = (new \Tests\Unit\Allocation\Fixture\ProblemBuilder())
            ->slot(1, '08:00', '09:00', active: false)
            ->room(50, shared: true)
            ->session(1, 1, enrolledCount: 30)
            ->build();

        $this->assertCode(
            $problem,
            new OccupancyIndex(),
            new Assignment(1, 1, 1),
            ConstraintChecker::HC_CALENDAR_WINDOW,
        );
    }

    // --- HC-8 -------------------------------------------------------------

    public function testHc8RejectsAnUnavailableLecturerSlot(): void
    {
        $builder = new \Tests\Unit\Allocation\Fixture\ProblemBuilder();
        $builder->standardWeek(2)
            ->room(50, shared: true)
            ->room(50, shared: true)
            ->session(1, 1, enrolledCount: 30);

        $builder->lecturerUnavailableSlot(1, 2);
        $problem = $builder->build();

        $this->assertCode(
            $problem,
            new OccupancyIndex(),
            new Assignment(1, 1, 2),
            ConstraintChecker::HC_LECTURER_AVAILABLE,
        );
    }

    public function testHc8RejectsALecturerUnavailableForTheWholeDay(): void
    {
        $problem = (new \Tests\Unit\Allocation\Fixture\ProblemBuilder())
            ->standardWeek(2)
            ->room(50, shared: true)
            ->room(50, shared: true)
            ->session(1, 1, enrolledCount: 30)
            ->lecturerUnavailableDay(1, 1) // Monday
            ->build();

        $occupancy = new OccupancyIndex();

        $this->assertCode($problem, $occupancy, new Assignment(1, 1, 1), ConstraintChecker::HC_LECTURER_AVAILABLE);
        $this->assertNoCode($problem, $occupancy, new Assignment(1, 1, 2), ConstraintChecker::HC_LECTURER_AVAILABLE);
    }

    public function testHc8EnforcesTheDailyLoadCeiling(): void
    {
        $builder = new \Tests\Unit\Allocation\Fixture\ProblemBuilder();
        $builder->standardWeek(1)->room(50, shared: true);

        for ($i = 1; $i <= 5; $i++) {
            $builder->session($i, 1, enrolledCount: 30); // one lecturer, five cohorts
        }

        $problem = $builder->build();
        $slotId = $builder->slotIds()[0];

        $checker = new ConstraintChecker(maxLecturerSessionsPerDay: 4);
        $occupancy = new OccupancyIndex();

        for ($i = 1; $i <= 4; $i++) {
            $occupancy->place(new Assignment($i, 1, $slotId), $problem);
        }

        $codes = array_map(
            static fn ($v): string => $v->code,
            $checker->check(new Assignment(5, 1, $slotId), $occupancy, $problem),
        );

        self::assertContains(ConstraintChecker::HC_LECTURER_AVAILABLE, $codes);
    }

    public function testHc8TheLoadCeilingIsNotChargedForTheSessionBeingEvaluated(): void
    {
        // The fifth session is being *moved*, so its own occupancy of that day
        // must not count against the ceiling. Getting this wrong makes a full
        // lecturer look permanently unavailable and silently caps every
        // timetable at three sessions a day.
        $builder = new \Tests\Unit\Allocation\Fixture\ProblemBuilder();
        $builder->standardWeek(1)->room(50, shared: true);

        for ($i = 1; $i <= 5; $i++) {
            $builder->session($i, 1, enrolledCount: 30);
        }

        $problem = $builder->build();
        $checker = new ConstraintChecker(maxLecturerSessionsPerDay: 4);
        $occupancy = new OccupancyIndex();

        for ($i = 1; $i <= 5; $i++) {
            $occupancy->place(new Assignment($i, 1, 1), $problem);
        }

        self::assertSame(
            5,
            $occupancy->lecturerDayLoad(1, 1),
            'Sanity: the index should hold all five placements.',
        );

        self::assertSame(
            4,
            $occupancy->lecturerDayLoad(1, 1, exceptSessionId: 5),
            'Excluding the session under evaluation must discount exactly one.',
        );
    }

    public function testHc8ExcludingASessionOnAnotherDayMustNotDiscountTheLoad(): void
    {
        $builder = new \Tests\Unit\Allocation\Fixture\ProblemBuilder();
        $builder->standardWeek(2)->room(50, shared: true)->room(50, shared: true);

        $builder->session(1, 1, enrolledCount: 30);
        $builder->session(2, 1, enrolledCount: 30);
        $builder->session(3, 1, enrolledCount: 30);
        $builder->session(4, 1, enrolledCount: 30);
        $builder->session(5, 1, enrolledCount: 30);

        $problem = $builder->build();
        $occupancy = new OccupancyIndex();

        $slotIds = $builder->slotIds();
        $monday = $slotIds[0];   // day 1
        $tuesday = $slotIds[1];  // day 2

        // Four on Monday, and session 5 parked on Tuesday.
        for ($i = 1; $i <= 4; $i++) {
            $occupancy->place(new Assignment($i, 1, $monday), $problem);
        }
        $occupancy->place(new Assignment(5, 1, $tuesday), $problem);

        self::assertSame(
            4,
            $occupancy->lecturerDayLoad(1, 1),
        );

        self::assertSame(
            4,
            $occupancy->lecturerDayLoad(1, 1, exceptSessionId: 5),
            'Session 5 is on Tuesday, so it must not reduce the Monday load.',
        );
    }

    // --- HC-9 -------------------------------------------------------------

    public function testHc9RejectsARoomBlockedForMaintenanceInThatSlot(): void
    {
        $builder = new \Tests\Unit\Allocation\Fixture\ProblemBuilder();
        $builder->standardWeek(2)
            ->room(50, shared: true, unavailableSlotIds: [2])
            ->session(1, 1, enrolledCount: 30);

        $problem = $builder->build();

        $this->assertCode(
            $problem,
            new OccupancyIndex(),
            new Assignment(1, 1, 2),
            ConstraintChecker::HC_ROOM_NOT_BLOCKED,
        );
        $this->assertNoCode(
            $problem,
            new OccupancyIndex(),
            new Assignment(1, 1, 1),
            ConstraintChecker::HC_ROOM_NOT_BLOCKED,
        );
    }

    // --- HC-10 ------------------------------------------------------------

    public function testHc10RejectsARoomScopedToAnotherDepartment(): void
    {
        $problem = (new \Tests\Unit\Allocation\Fixture\ProblemBuilder())
            ->standardWeek(1)
            ->room(50, shared: false, departmentId: 2)
            ->session(1, 1, enrolledCount: 30, departmentId: 1)
            ->build();

        $this->assertCode(
            $problem,
            new OccupancyIndex(),
            new Assignment(1, 1, 1),
            ConstraintChecker::HC_DEPARTMENT_SCOPE,
        );
    }

    public function testHc10AllowsASharedRoomRegardlessOfDepartment(): void
    {
        $problem = (new \Tests\Unit\Allocation\Fixture\ProblemBuilder())
            ->standardWeek(1)
            ->room(50, shared: true, departmentId: 2)
            ->session(1, 1, enrolledCount: 30, departmentId: 1)
            ->build();

        $this->assertNoCode(
            $problem,
            new OccupancyIndex(),
            new Assignment(1, 1, 1),
            ConstraintChecker::HC_DEPARTMENT_SCOPE,
        );
    }

    public function testHc10AllowsARoomOwnedByTheSchedulingDepartment(): void
    {
        $problem = (new \Tests\Unit\Allocation\Fixture\ProblemBuilder())
            ->standardWeek(1)
            ->room(50, shared: false, departmentId: 1)
            ->session(1, 1, enrolledCount: 30, departmentId: 1)
            ->build();

        $this->assertNoCode(
            $problem,
            new OccupancyIndex(),
            new Assignment(1, 1, 1),
            ConstraintChecker::HC_DEPARTMENT_SCOPE,
        );
    }

    // --- cross-cutting ----------------------------------------------------

    public function testEveryViolationIsReportedNotJustTheFirst(): void
    {
        // A diagnostic that stops at the first failure makes the admin fix one
        // problem, re-run, and discover the next. FR-ALLOC-05 asks for a count
        // and a reason, which requires all of them.
        $problem = (new \Tests\Unit\Allocation\Fixture\ProblemBuilder())
            ->standardWeek(1)
            ->room(10, features: [], shared: false, departmentId: 99) // too small, no features, wrong department
            ->session(1, 1, enrolledCount: 30, requiredFeatures: ['lab_bench'], departmentId: 1)
            ->build();

        $codes = array_map(
            static fn ($v): string => $v->code,
            (new ConstraintChecker())->check(new Assignment(1, 1, 1), new OccupancyIndex(), $problem),
        );

        self::assertContains(ConstraintChecker::HC_CAPACITY, $codes);
        self::assertContains(ConstraintChecker::HC_FEATURES, $codes);
        self::assertContains(ConstraintChecker::HC_DEPARTMENT_SCOPE, $codes);
    }

    public function testAnAssignmentReferencingUnknownIdsIsMalformedNotSilentlyAccepted(): void
    {
        $problem = $this->base();
        $checker = new ConstraintChecker();

        $violations = $checker->check(new Assignment(999, 999, 999), new OccupancyIndex(), $problem);

        self::assertCount(1, $violations);
        self::assertSame('HC-MALFORMED', $violations[0]->code);
    }

    public function testConstraintCodesAreUniqueAndStable(): void
    {
        $codes = [
            ConstraintChecker::HC_ROOM_FREE,
            ConstraintChecker::HC_LECTURER_FREE,
            ConstraintChecker::HC_COHORT_FREE,
            ConstraintChecker::HC_CAPACITY,
            ConstraintChecker::HC_FEATURES,
            ConstraintChecker::HC_ROOM_SERVICEABLE,
            ConstraintChecker::HC_CALENDAR_WINDOW,
            ConstraintChecker::HC_LECTURER_AVAILABLE,
            ConstraintChecker::HC_ROOM_NOT_BLOCKED,
            ConstraintChecker::HC_DEPARTMENT_SCOPE,
        ];

        self::assertSame($codes, array_unique($codes), 'Constraint codes must be distinct.');
        self::assertSame(
            ['HC-1', 'HC-2', 'HC-3', 'HC-4', 'HC-5', 'HC-6', 'HC-7', 'HC-8', 'HC-9', 'HC-10'],
            $codes,
            'These strings are persisted in allocation_conflicts.constraint_code. '
            . 'Changing one is a breaking schema change, not a rename.',
        );
    }

    public function testIsFeasibleAgreesWithCheck(): void
    {
        // The two methods are separate implementations of the same predicate.
        // They are the only two gates a candidate passes, so a divergence in
        // either direction is a correctness bug.
        $builder = (new \Tests\Unit\Allocation\Fixture\ProblemBuilder())
            ->standardWeek(2)
            ->room(20, shared: true)
            ->room(50, features: ['projector'], shared: true)
            ->session(1, 1, enrolledCount: 30, requiredFeatures: ['projector']);

        $problem = $builder->build();
        $checker = new ConstraintChecker();
        $occupancy = new OccupancyIndex();

        foreach ($problem->slots() as $slot) {
            foreach ($problem->rooms() as $room) {
                foreach ($problem->sessions() as $session) {
                    $candidate = new Assignment($session->id(), $room->id(), $slot->id());

                    $fromCheck = $checker->check($candidate, $occupancy, $problem) === [];
                    $fromPredicate = $checker->isFeasible($candidate, $occupancy, $problem);

                    self::assertSame(
                        $fromCheck,
                        $fromPredicate,
                        sprintf(
                            'isFeasible() disagreed with check() for session %d, room %d, slot %d.',
                            $session->id(),
                            $room->id(),
                            $slot->id(),
                        ),
                    );
                }
            }
        }
    }
}
