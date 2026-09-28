<?php

declare(strict_types=1);

namespace Tests\Unit\Allocation;

use App\Domain\Allocation\Assignment;
use App\Domain\Allocation\EngineOptions;
use App\Domain\Allocation\FixedClock;
use App\Domain\Allocation\SchedulingProblem;
use Tests\Unit\Allocation\Fixture\ProblemBuilder;
use Tests\Unit\Allocation\Fixture\ProblemFactory;

/**
 * Property test: no (room, slot) pair is ever used twice.
 *
 * This is the single most important assertion in the project. A double booking
 * is the failure the whole system exists to prevent, and it is exactly the kind
 * of bug that a hand-written fixture hides: the engine's own filter refuses to
 * produce one, so a fixed example always passes and only an interaction — three
 * cohorts, one lecturer, one room, a warm start that evicts a row — exposes it.
 *
 * Hence: randomised problems, randomised seeds, both cold and warm start, and
 * the invariant re-derived from the committed result rather than trusted from
 * anywhere inside the engine.
 *
 * ADR-006 adds a database unique index as a backstop, but the engine must not
 * need it: bin/generate-timetable.php writes results straight to a file, and a
 * bad allocation there would propagate silently.
 */
final class NoDoubleBookingTest extends EngineTestCase
{
    private const TRIALS = 150;

    public function testNoRoomIsEverBookedTwiceInTheSameSlot(): void
    {
        for ($trial = 0; $trial < self::TRIALS; $trial++) {
            $seed = 1000 + $trial;
            $factory = new ProblemFactory($seed);

            $builder = $factory->random(
                sessionCount: $factory->rng()->int(1, 20),
                roomCount: $factory->rng()->int(1, 8),
                slotCount: $factory->rng()->int(1, 12),
                cohortCount: $factory->rng()->int(1, 8),
                lecturerCount: $factory->rng()->int(1, 5),
            );

            $problem = $builder->build();
            $result = $this->engine()->solve(
                $problem,
                [],
                $this->options(maxIterations: 60, seed: $seed),
            );

            $this->assertResultIsSound($result);
            $this->assertNoDoubleBooking($result->assignments, $problem, $seed);
        }
    }

    /**
     * The same invariant must survive a warm start, which is the state the
     * engine is in for every incremental repair — and where the input already
     * contains a booking the engine has to work around.
     */
    public function testNoDoubleBookingSurvivesAWarmStart(): void
    {
        for ($trial = 0; $trial < self::TRIALS; $trial++) {
            $seed = 50000 + $trial;
            $factory = new ProblemFactory($seed);

            $builder = $factory->solvable(sessions: 6);
            $problem = $builder->build();

            // First pass produces a real timetable...
            $first = $this->engine()->solve($problem, [], $this->options(maxIterations: 0, seed: $seed));

            // ...which is then fed back in as the existing timetable, twice.
            $existing = array_values($first->assignments);
            $second = $this->engine()->solve($problem, $existing, $this->options(maxIterations: 60, seed: $seed));

            $this->assertResultIsSound($second);
            $this->assertNoDoubleBooking($second->assignments, $problem, $seed);
        }
    }

    /**
     * Deliberately hand the engine an infeasible existing timetable — two rows
     * in the same room and slot — and require that it repairs the clash rather
     * than propagating it.
     */
    public function testAnAlreadyConflictingWarmStartIsRepaired(): void
    {
        $builder = (new ProblemBuilder())
            ->standardWeek(1)
            ->room(50, shared: true)
            ->session(1, 1, enrolledCount: 30)
            ->session(2, 2, enrolledCount: 30);

        $problem = $builder->build();
        $slotId = $builder->slotIds()[0];

        $conflicting = [
            new Assignment(1, 1, $slotId),
            new Assignment(2, 1, $slotId), // same room, same slot
        ];

        $result = $this->engine()->solve(
            $problem,
            $conflicting,
            new EngineOptions(
                maxIterations: 0,
                timeBudgetSeconds: 3600.0,
                clock: new FixedClock(0.0),
            ),
        );

        $this->assertResultIsSound($result);
        $this->assertNoDoubleBooking($result->assignments, $problem, 0);

        // The clash must also be reported: the administrator needs to know their
        // published timetable contained a double booking.
        self::assertGreaterThan(
            0,
            $result->metrics['warm_start_rejected'] ?? 0,
            'The clashing warm-start row should have been evicted and reported.',
        );
    }

    /**
     * @param array<int, Assignment> $assignments
     */
    private function assertNoDoubleBooking(array $assignments, SchedulingProblem $problem, int $seed): void
    {
        $seen = [];

        foreach ($assignments as $assignment) {
            $key = $assignment->roomId() . '|' . $assignment->timeSlotId();

            self::assertArrayNotHasKey(
                $key,
                $seen,
                sprintf(
                    'Room %d is booked twice in slot %d (seed %d). '
                    . 'Sessions %d and %d. Violates HC-1.',
                    $assignment->roomId(),
                    $assignment->timeSlotId(),
                    $seed,
                    $seen[$key] ?? -1,
                    $assignment->sessionId(),
                ),
            );

            $seen[$key] = $assignment->sessionId();
        }

        // The same check for the other two resource axes. HC-2 and HC-3 are the
        // two a capacity-only test would miss entirely.
        $this->assertNoDoubleBookingOn($assignments, $problem, $seed, 'lecturer', 'HC-2');
        $this->assertNoDoubleBookingOn($assignments, $problem, $seed, 'cohort', 'HC-3');
    }

    /**
     * @param array<int, Assignment> $assignments
     */
    private function assertNoDoubleBookingOn(
        array $assignments,
        SchedulingProblem $problem,
        int $seed,
        string $axis,
        string $code,
    ): void {
        $seen = [];

        foreach ($assignments as $assignment) {
            $session = $problem->sessionById($assignment->sessionId());
            if ($session === null) {
                continue;
            }

            $key = ($axis === 'lecturer' ? $session->lecturerId() : $session->cohortId())
                . '|' . $assignment->timeSlotId();

            self::assertArrayNotHasKey(
                $key,
                $seen,
                sprintf(
                    'The %s of session %d is double-booked in slot %d (seed %d), '
                    . 'colliding with session %d. Violates %s.',
                    $axis,
                    $assignment->sessionId(),
                    $assignment->timeSlotId(),
                    $seed,
                    $seen[$key] ?? -1,
                    $code,
                ),
            );

            $seen[$key] = $assignment->sessionId();
        }
    }
}
