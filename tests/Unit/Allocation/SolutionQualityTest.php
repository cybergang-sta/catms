<?php

declare(strict_types=1);

namespace Tests\Unit\Allocation;

use App\Domain\Allocation\Assignment;
use App\Domain\Allocation\CostFunction;
use App\Domain\Allocation\CostWeights;
use App\Domain\Allocation\EngineOptions;
use App\Domain\Allocation\SchedulingProblem;
use Tests\Unit\Allocation\Fixture\ProblemBuilder;
use Tests\Unit\Allocation\Fixture\ProblemFactory;

/**
 * The tests for the quality of a solution, as distinct from its validity.
 *
 * Everything in HardConstraintTest, NoDoubleBookingTest and CapacityTest would
 * pass with a solver that always picks the cheapest room it can find and never
 * improves. These are the tests that say whether the thing is actually good:
 * OBJ-3 (better use of teaching space) and BR-06 (do not churn rooms students
 * have just learned).
 */
final class SolutionQualityTest extends EngineTestCase
{
    public function testASmallCohortIsNotPutInAnOverlargeRoomWhileASmallerOneIsFree(): void
    {
        // 5 students, two rooms both free in the same slot: one seats 20, one
        // seats 200. The waste term must prefer the 20.
        $builder = (new ProblemBuilder())
            ->standardWeek(1)
            ->room(200, shared: true)
            ->room(20, shared: true)
            ->session(1, 1, enrolledCount: 5);

        $result = $this->solve($builder, EngineOptions::greedyOnly(1));

        self::assertCount(1, $result->assignments);
        $assignment = $result->orderedAssignments()[0];
        $room = $builder->build()->roomById($assignment->roomId());

        self::assertNotNull($room);
        self::assertSame(20, $room->capacity(), 'The 5-student cohort should take the 20-seat room.');
    }

    public function testTheWasteTermDrivesSeatUtilisationAboveTheRoomAverage(): void
    {
        // Warm start: a 5-student cohort already sits in a 200-seat room, and a
        // 150-student cohort sits in a 160-seat one. A 10-seat room would suit
        // the small cohort far better, which the churn term resists — so this
        // is a genuine trade-off, resolved by the weights. Phase 0 adopts every
        // feasible warm-start row, so only the local search can make the move.
        $builder = (new ProblemBuilder())
            ->standardWeek(1)
            ->room(200, shared: true)
            ->room(160, shared: true)
            ->room(10, shared: true)
            ->session(1, 1, enrolledCount: 5)
            ->session(2, 2, enrolledCount: 150);

        $problem = $builder->build();
        $slotId = $builder->slotIds()[0];
        $roomIds = $builder->roomIds();

        $existing = [
            new Assignment(1, $roomIds[0], $slotId), // 5 students, 200 seats
            new Assignment(2, $roomIds[1], $slotId), // 150 students, 160 seats
        ];

        $utilisationFirst = $this->engine(weights: CostWeights::utilisationFirst())
            ->solve($problem, $existing, $this->options(maxIterations: 200));

        $stabilityFirst = $this->engine(weights: CostWeights::stabilityFirst())
            ->solve($problem, $existing, $this->options(maxIterations: 200));

        // Both must stay valid whatever the weights.
        $this->assertResultIsSound($utilisationFirst);
        $this->assertResultIsSound($stabilityFirst);

        $before = $this->seatUtilisation($existing, $problem);

        $after = $this->seatUtilisation($utilisationFirst->assignments, $problem);
        $afterStable = $this->seatUtilisation($stabilityFirst->assignments, $problem);

        self::assertGreaterThan(
            $before,
            $after,
            'Utilisation-first weighting should improve on the warm-start arrangement.',
        );

        self::assertLessThanOrEqual(
            $after,
            $afterStable,
            'Stability-first weighting should not beat utilisation-first on utilisation alone.',
        );
    }

    public function testAStableWarmStartIsNotChurnedForAMarginalGain(): void
    {
        // The arrangement is already good. An engine that re-shuffles for a
        // fraction of a penalty point would make the tool unusable in practice.
        $builder = (new ProblemBuilder())
            ->standardWeek(1)
            ->room(50, shared: true)
            ->session(1, 1, enrolledCount: 50);

        $problem = $builder->build();
        $existing = [
            new Assignment(1, $builder->roomIds()[0], $builder->slotIds()[0]),
        ];

        $result = $this->engine()->solve($problem, $existing, EngineOptions::greedyOnly(1));

        $assignment = $result->orderedAssignments()[0];

        self::assertSame(
            $existing[0]->roomId(),
            $assignment->roomId(),
            'An already-optimal room should be kept.',
        );
        self::assertSame($existing[0]->timeSlotId(), $assignment->timeSlotId());
    }

    public function testACohortsSessionsAreKeptInOneRoomWhereTheWeightsAllow(): void
    {
        // Fragmentation: a cohort taught twice a week in two different rooms has
        // to find both. Two rooms, three cohorts, two slots a day.
        $builder = (new ProblemBuilder())
            ->standardWeek(1)
            ->room(50, shared: true, building: 'A')
            ->room(50, shared: true, building: 'B')
            ->session(1, 1, enrolledCount: 50, sequence: 0)
            ->session(1, 2, enrolledCount: 50, sequence: 1)
            ->session(2, 3, enrolledCount: 50, sequence: 0)
            ->session(2, 4, enrolledCount: 50, sequence: 1)
            ->session(3, 5, enrolledCount: 50, sequence: 0);

        $result = $this->solve($builder, EngineOptions::greedyOnly(1));
        $this->assertResultIsSound($result);

        $roomsByCohort = [];

        foreach ($result->assignments as $assignment) {
            $roomsByCohort[$assignment->sessionId() <= 2 ? 1 : 2][$assignment->roomId()] = true;
        }

        // Cohort 1 and cohort 2 each have two sittings; only two rooms exist, so
        // the fragmentation term should still stop one cohort being split when a
        // valid alternative exists.
        $cohort = $roomsByCohort[1] ?? [];
        self::assertCount(
            1,
            $cohort,
            'Cohort 1 should be kept in a single room for the week.',
        );
    }

    public function testABuildingPreferenceIsHonouredWhenItCostsNothingElse(): void
    {
        $builder = (new ProblemBuilder())
            ->standardWeek(1)
            ->room(50, shared: true, building: 'Annex')
            ->room(50, shared: true, building: 'Main')
            ->session(1, 1, enrolledCount: 50, preferredBuilding: 'Main');

        $result = $this->solve($builder, EngineOptions::greedyOnly(1));

        $assignment = $result->orderedAssignments()[0];
        $room = $builder->build()->roomById($assignment->roomId());

        self::assertNotNull($room);
        self::assertSame('Main', $room->building());
    }

    public function testAPreferenceIsOverriddenWhenTheRequestedBuildingHasNoRoom(): void
    {
        $builder = (new ProblemBuilder())
            ->standardWeek(1)
            ->room(50, shared: true, building: 'Annex')
            ->session(1, 1, enrolledCount: 50, preferredBuilding: 'Main');

        $result = $this->solve($builder, EngineOptions::greedyOnly(1));

        self::assertCount(1, $result->assignments, 'A preference must never make a session unplaceable.');
    }

    public function testTheChurnTermPenalisesADifferentRoomFromThePriorTimetable(): void
    {
        $problem = (new ProblemBuilder())
            ->standardWeek(1)
            ->room(50, shared: true, building: 'A')
            ->room(50, shared: true, building: 'A')
            ->session(1, 1, enrolledCount: 50)
            ->build();

        $slotId = 1;
        $cost = new CostFunction(
            CostWeights::stabilityFirst(),
        );

        $withPrior = $cost->withContext([], [], [1 => 1], []);
        $without = $cost->withContext([], [], [], []);

        $sameRoom = $withPrior->evaluate(new Assignment(1, 1, $slotId), $problem);
        $otherRoom = $withPrior->evaluate(new Assignment(1, 2, $slotId), $problem);

        self::assertSame(0.0, $sameRoom->raw['churn']);
        self::assertSame(1.0, $otherRoom->raw['churn']);
        self::assertLessThan($otherRoom->total, $sameRoom->total);

        // With no prior timetable the term must be inert, or the very first
        // generation would be scored against a timetable that does not exist.
        $fresh = $without->evaluate(new Assignment(1, 2, $slotId), $problem);
        self::assertSame(0.0, $fresh->raw['churn']);
    }

    public function testTheFragmentationLedgerIsReversible(): void
    {
        // forgetUse() has to remove exactly one reference. If it removed the room
        // outright, a cohort with two sessions in the same room would appear to
        // be spread across two rooms the moment one of them moved.
        $cost = new CostFunction(CostWeights::balanced());

        $cost->noteUse(1, 10, 7);
        $cost->noteUse(1, 11, 7);
        self::assertSame(1, $cost->distinctRoomsFor(7));

        $cost->forgetUse(1, 10, 7);
        self::assertSame(1, $cost->distinctRoomsFor(7), 'One room is still in use.');

        $cost->forgetUse(1, 11, 7);
        self::assertSame(0, $cost->distinctRoomsFor(7));

        // Forgetting something never recorded must be a no-op, not an error:
        // the search calls it on paths where a move was skipped.
        $cost->forgetUse(99, 1, 7);
        self::assertSame(0, $cost->distinctRoomsFor(7));
    }

    public function testTheEquityTermIsInertWithoutPriorUtilisation(): void
    {
        // On a cold start there is nothing to be fair about. An invented
        // baseline would only add noise to the first generation.
        $problem = (new ProblemBuilder())
            ->standardWeek(1)
            ->room(50, shared: true)
            ->session(1, 1, enrolledCount: 30)
            ->build();

        $cost = (new CostFunction(CostWeights::balanced()))
            ->withContext([], [], [], [], 20);

        $breakdown = $cost->evaluate(new Assignment(1, 1, 1), $problem);

        self::assertSame(0.0, $breakdown->raw['equity']);
    }

    public function testLocalSearchNeverIncreasesTheTotalPenalty(): void
    {
        $problem = (new ProblemFactory(4242))
            ->random(sessionCount: 20, roomCount: 5, slotCount: 8)
            ->build();

        $greedy = $this->engine()->solve($problem, [], $this->greedyOptions());
        $searched = $this->engine()->solve($problem, [], $this->options(maxIterations: 400, seed: 9));

        $this->assertResultIsSound($greedy);
        $this->assertResultIsSound($searched);

        self::assertGreaterThanOrEqual(
            $greedy->metrics['assigned_sessions'],
            $searched->metrics['assigned_sessions'],
            'Local search must never place fewer sessions than the greedy construction.',
        );

        self::assertLessThanOrEqual(
            (float) $greedy->metrics['total_penalty'] + 1e-9,
            (float) $searched->metrics['total_penalty'],
            'Local search must not leave a higher total penalty than it started from.',
        );
    }

    /**
     * @param array<int, \App\Domain\Allocation\Assignment> $assignments
     */
    private function seatUtilisation(array $assignments, SchedulingProblem $problem): float
    {
        $used = 0;
        $provided = 0;

        foreach ($assignments as $assignment) {
            $session = $problem->sessionById($assignment->sessionId());
            $room = $problem->roomById($assignment->roomId());

            if ($session === null || $room === null) {
                continue;
            }

            $used += $session->enrolledCount();
            $provided += $room->capacity();
        }

        return $provided > 0
            ? $used / $provided
            : 0.0;
    }
}
