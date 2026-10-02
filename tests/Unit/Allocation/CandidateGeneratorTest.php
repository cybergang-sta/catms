<?php

declare(strict_types=1);

namespace Tests\Unit\Allocation;

use App\Domain\Allocation\Assignment;
use App\Domain\Allocation\CandidateGenerator;
use App\Domain\Allocation\ConstraintChecker;
use App\Domain\Allocation\OccupancyIndex;

/**
 * The candidate prefilter must be exactly equivalent to the constraint checker.
 *
 * CandidateGenerator hand-inlines the hard constraints instead of calling
 * ConstraintChecker, for speed on the hot path. That means two implementations
 * of the same predicate — and HC-8 (the daily lecturer load ceiling) was in fact
 * missing from the prefilter, so Phase 1 could commit a timetable that breached
 * it. No fixed fixture caught it, because the omission only shows up once a
 * lecturer has four sessions on a day and a fifth is moved onto it.
 *
 * This test closes that class of bug permanently: it walks every
 * (session, room, slot) combination of a problem that has an occupancy index
 * under load, and demands that the two implementations agree, both on the
 * accept and on the reject side. Adding a hard constraint to one place and not
 * the other now fails here instead of in production.
 */
final class CandidateGeneratorTest extends EngineTestCase
{
    public function testThePrefilterAgreesWithTheCheckerOnAnIdleProblem(): void
    {
        $this->assertPrefilterMatchesChecker($this->loadedProblem());
    }

    public function testThePrefilterAgreesWithTheCheckerOnAnEmptyProblem(): void
    {
        $builder = (new \Tests\Unit\Allocation\Fixture\ProblemBuilder())
            ->standardWeek(2)
            ->room(20, features: ['projector'], shared: true)
            ->room(50, features: ['projector', 'lab_bench'], shared: true)
            ->room(80, features: [], shared: false, departmentId: 7)
            ->session(1, 1, enrolledCount: 25, requiredFeatures: ['projector'])
            ->session(2, 2, enrolledCount: 60, requiredFeatures: ['lab_bench'])
            ->session(3, 3, enrolledCount: 45);

        $this->assertPrefilterMatchesChecker($builder->build());
    }

    /**
     * The specific regression: five cohorts, one lecturer, four rooms, one slot
     * a day. With a ceiling of four, the prefilter used to accept a fifth.
     */
    public function testThePrefilterEnforcesTheDailyLoadCeiling(): void
    {
        $builder = new \Tests\Unit\Allocation\Fixture\ProblemBuilder();
        $builder->standardWeek(1);

        for ($i = 0; $i < 4; $i++) {
            $builder->room(50, shared: true);
        }

        for ($i = 1; $i <= 5; $i++) {
            $builder->session($i, 1, enrolledCount: 30);
        }

        $problem = $builder->build();
        $checker = new ConstraintChecker(maxLecturerSessionsPerDay: 4);
        $generator = new CandidateGenerator($checker);
        $occupancy = new OccupancyIndex();

        $slotId = $builder->slotIds()[0];

        // Fill the day to the ceiling with the first four cohorts.
        foreach ([1, 2, 3, 4] as $sessionId) {
            $occupancy->place(new Assignment($sessionId, $sessionId, $slotId), $problem);
        }

        $candidates = $generator->for(5, $problem, $occupancy);
        $fullDay = $problem->slotById($slotId)?->dayOfWeek();

        self::assertSame(
            [],
            array_values(array_filter(
                $candidates,
                static fn (Assignment $a): bool => $problem->slotById($a->timeSlotId())?->dayOfWeek() === $fullDay,
            )),
            'A lecturer already at their daily ceiling must yield no candidates on that day.',
        );
        self::assertNotSame([], $candidates, 'The other days of the week are still open to the lecturer.');
    }

    public function testThePrefilterCountsAgreeWithTheCheckerToo(): void
    {
        $problem = $this->loadedProblem();
        $checker = new ConstraintChecker();
        $generator = new CandidateGenerator($checker);
        $occupancy = new OccupancyIndex();

        // Put a couple of placements down so the index is not empty.
        $first = $generator->for(1, $problem, $occupancy);
        if ($first !== []) {
            $occupancy->place($first[0], $problem);
        }

        foreach ($problem->sessions() as $session) {
            self::assertSame(
                \count($generator->for($session->id(), $problem, $occupancy)),
                $generator->countFeasible($session->id(), $problem, $occupancy),
                'countFeasible() delegates to for(); they must not disagree.',
            );
        }
    }

    public function testThePrefilterExcludesTheSessionsOwnOccupancyWhenEvaluatingAMove(): void
    {
        // This is what makes local search able to move a session at all: the
        // session's own current placement must not count against it, or every
        // session would appear to be in a permanently occupied slot and the
        // search could never do anything.
        $builder = (new \Tests\Unit\Allocation\Fixture\ProblemBuilder())
            ->standardWeek(2)
            ->room(50, shared: true)
            ->room(50, shared: true)
            ->session(1, 1, enrolledCount: 30);

        $problem = $builder->build();
        $checker = new ConstraintChecker();
        $generator = new CandidateGenerator($checker);
        $occupancy = new OccupancyIndex();

        $slotIds = $builder->slotIds();
        $occupancy->place(new Assignment(1, 1, $slotIds[0]), $problem);

        $candidates = $generator->for(1, $problem, $occupancy);
        $slots = array_map(static fn (Assignment $a): int => $a->timeSlotId(), $candidates);

        self::assertContains(
            $slotIds[0],
            $slots,
            'The session must still be able to consider the slot it already occupies.',
        );
    }

    public function testCountFeasibleReturnsZeroForAnUnknownSession(): void
    {
        $builder = (new \Tests\Unit\Allocation\Fixture\ProblemBuilder())
            ->standardWeek(1)
            ->room(50, shared: true)
            ->session(1, 1, enrolledCount: 30);

        $checker = new ConstraintChecker();
        $generator = new CandidateGenerator($checker);

        self::assertSame(0, $generator->countFeasible(9999, $builder->build(), new OccupancyIndex()));
        self::assertSame([], $generator->for(9999, $builder->build(), new OccupancyIndex()));
    }

    /**
     * A problem shaped to make every hard constraint reachable at once.
     */
    private function loadedProblem(): \App\Domain\Allocation\SchedulingProblem
    {
        $builder = new \Tests\Unit\Allocation\Fixture\ProblemBuilder();

        $builder->standardWeek(2)
            ->room(20, features: ['projector'], shared: true, building: 'A')
            ->room(50, features: ['projector', 'lab_bench'], shared: true, building: 'A')
            ->room(80, features: [], shared: false, departmentId: 7, building: 'B')
            ->room(40, status: 'maintenance', shared: true, building: 'B');

        $builder->session(1, 1, enrolledCount: 25, requiredFeatures: ['projector'])
            ->session(2, 2, enrolledCount: 60, requiredFeatures: ['lab_bench'])
            ->session(3, 3, enrolledCount: 45, departmentId: 7)
            ->session(1, 4, enrolledCount: 30, preferredBuilding: 'B')
            ->session(4, 1, enrolledCount: 15);

        $slotIds = $builder->slotIds();
        $builder->lecturerUnavailableSlot(2, $slotIds[0]);
        $builder->lecturerUnavailableDay(3, 2);

        // Block one room for one slot, to exercise HC-9.
        $builder->room(50, shared: true, unavailableSlotIds: [$slotIds[1]], id: 99);

        // Withhold the last slot from teaching, to exercise HC-7.
        $builder->teachableSlots(array_slice($slotIds, 0, -1));

        return $builder->build();
    }

    private function assertPrefilterMatchesChecker(\App\Domain\Allocation\SchedulingProblem $problem): void
    {
        $checker = new ConstraintChecker();
        $generator = new CandidateGenerator($checker);
        $occupancy = new OccupancyIndex();

        // Occupy a realistic share of the grid so the index is under load and
        // the exclusion path is actually exercised.
        foreach ($problem->sessions() as $index => $session) {
            $candidates = $generator->for($session->id(), $problem, $occupancy);
            if ($candidates !== []) {
                $occupancy->place($candidates[$index % \count($candidates)], $problem);
            }
        }

        foreach ($problem->sessions() as $session) {
            $accepted = [];

            foreach ($generator->for($session->id(), $problem, $occupancy) as $candidate) {
                $accepted[$candidate->roomId() . '|' . $candidate->timeSlotId()] = true;
            }

            foreach ($problem->rooms() as $room) {
                foreach ($problem->slots() as $slot) {
                    $key = $room->id() . '|' . $slot->id();

                    $checkerSays = $checker->check(
                        new Assignment($session->id(), $room->id(), $slot->id()),
                        $occupancy,
                        $problem,
                    ) === [];

                    self::assertSame(
                        $checkerSays,
                        isset($accepted[$key]),
                        sprintf(
                            'Prefilter and checker disagree for session %d, room %s, slot %d: '
                            . 'checker says %s, prefilter says %s.',
                            $session->id(),
                            $room->id(),
                            $slot->id(),
                            $checkerSays ? 'feasible' : 'infeasible',
                            isset($accepted[$key]) ? 'feasible' : 'infeasible',
                        ),
                    );
                }
            }
        }
    }
}
