<?php

declare(strict_types=1);

namespace Tests\Unit\Allocation;

use App\Domain\Allocation\ConstraintChecker;
use App\Domain\Allocation\EngineOptions;
use App\Domain\Allocation\Exception\InfeasibleProblemException;
use App\Domain\Allocation\FixedClock;
use Tests\Unit\Allocation\Fixture\ProblemBuilder;

/**
 * The engine must fail loudly and informatively, never silently.
 *
 * An over-subscribed department is a normal operating condition and its correct
 * output is "these four sessions could not be placed, here is why". A
 * misconfigured calendar is an operator error and its correct output is an
 * exception. Conflating the two is how a real timetable ends up published as
 * empty because nobody noticed the teaching calendar had not been published yet.
 */
final class UnsolvableTest extends EngineTestCase
{
    public function testAnOverSubscribedDepartmentReportsEverySessionUnallocated(): void
    {
        // Five cohorts, two cohorts, one room, one slot. Three cannot be placed.
        $builder = (new ProblemBuilder())
            ->standardWeek(1)
            ->room(50, shared: true)
            ->session(1, 1, enrolledCount: 30)
            ->session(2, 2, enrolledCount: 30)
            ->session(3, 3, enrolledCount: 30);

        $result = $this->solve($builder, EngineOptions::greedyOnly(1));

        $this->assertResultIsSound($result);

        self::assertCount(1, $result->assignments);
        self::assertCount(2, $result->unallocated);
        self::assertSame(0.0, (float) $result->metrics['accuracy'] * 3 - 2, 'accuracy should be 1/3');
    }

    public function testACompletelyImpossibleProblemAllocatesNothingAndViolatesNothing(): void
    {
        // No room is serviceable. Nothing can be placed — and that is a clean
        // result, not an error: the hard constraints held throughout.
        $builder = (new ProblemBuilder())
            ->standardWeek(2)
            ->room(50, status: 'maintenance', shared: true)
            ->room(50, status: 'maintenance', shared: true)
            ->session(1, 1, enrolledCount: 30)
            ->session(2, 2, enrolledCount: 30);

        $result = $this->solve($builder, EngineOptions::greedyOnly(1));

        $this->assertResultIsSound($result);

        self::assertSame([], $result->assignments);
        self::assertCount(2, $result->unallocated);
        self::assertSame([], $result->violations);
        self::assertFalse($result->isPublishable(), 'An incomplete timetable must never be publishable.');
    }

    public function testEachUnallocatedSessionExplainsItselfWithACountAndAReason(): void
    {
        $builder = (new ProblemBuilder())
            ->standardWeek(1)
            ->room(20, shared: true) // too small for a 60-student cohort
            ->session(1, 1, enrolledCount: 60);

        $result = $this->solve($builder, EngineOptions::greedyOnly(1));

        self::assertCount(1, $result->unallocated);
        $entry = $result->unallocated[0];

        self::assertSame(ConstraintChecker::HC_CAPACITY, $entry->primaryConstraint());
        self::assertGreaterThan(0, $entry->consideredCombinations);
        self::assertNotSame('', $entry->summary);
        self::assertStringContainsString('60', $entry->summary, 'The summary should name the cohort size.');
    }

    public function testTheSummaryNamesTheMostCommonBlockingConstraint(): void
    {
        // One room that fits, but only one slot, already taken by another
        // cohort. Every remaining combination is eliminated by HC-3.
        $builder = (new ProblemBuilder())
            ->standardWeek(1)
            ->room(50, shared: true)
            ->session(1, 1, enrolledCount: 30)
            ->session(2, 2, enrolledCount: 30);

        $result = $this->solve($builder, EngineOptions::greedyOnly(1));

        self::assertCount(1, $result->unallocated);
        $entry = $result->unallocated[0];

        self::assertSame(ConstraintChecker::HC_COHORT_FREE, $entry->primaryConstraint());
    }

    public function testBlockingConstraintCountsAreOrderedByFrequency(): void
    {
        $builder = (new ProblemBuilder())
            ->standardWeek(1)
            ->room(20, shared: true)
            ->session(1, 1, enrolledCount: 60);

        $result = $this->solve($builder, EngineOptions::greedyOnly(1));
        $counts = $result->unallocated[0]->blockingConstraints;

        self::assertNotSame([], $counts);

        $sorted = $counts;
        arsort($sorted);
        self::assertSame(
            array_keys($sorted),
            array_keys($counts),
            'Constraints should be ordered from most to least blocking.',
        );
    }

    // --- structural problems: operator errors ----------------------------

    public function testStrictModeThrowsWhenThereAreNoSessions(): void
    {
        $builder = (new ProblemBuilder())->standardWeek(1);

        $this->expectException(InfeasibleProblemException::class);
        $this->expectExceptionMessage('no sessions');

        $this->solve($builder, EngineOptions::greedyOnly(1));
    }

    public function testNonStrictModeReportsAnEmptyProblemRatherThanThrowing(): void
    {
        // The admin UI calls the engine directly to preview a term that has not
        // been fully set up yet. It must get a report, not a 500.
        $builder = (new ProblemBuilder())->standardWeek(1);

        $result = $this->solve($builder, EngineOptions::greedyOnly(1));

        self::assertSame([], $result->assignments);
        self::assertSame([], $result->unallocated);
        self::assertSame([], $result->violations);
    }

    public function testStrictModeThrowsWhenNoSlotIsTeachable(): void
    {
        $builder = new ProblemBuilder();
        $builder->standardWeek(2)
            ->room(50, shared: true)
            ->session(1, 1, enrolledCount: 30);
        $builder->teachableSlots([]);

        $this->expectException(InfeasibleProblemException::class);
        $this->expectExceptionMessage('no teachable time slots');

        $this->solve($builder, $this->strictOptions());
    }

    public function testTheNoTeachableSlotsExceptionIsOnlyThrownInStrictMode(): void
    {
        $builder = new ProblemBuilder();
        $builder->standardWeek(2)
            ->room(50, shared: true)
            ->session(1, 1, enrolledCount: 30);
        $builder->teachableSlots([]);

        $result = $this->solve($builder, EngineOptions::greedyOnly(1));

        self::assertCount(1, $result->unallocated);
        self::assertSame(
            ConstraintChecker::HC_CALENDAR_WINDOW,
            $result->unallocated[0]->primaryConstraint(),
        );
    }

    public function testAStaleAllocationForADeletedSessionIsDroppedWithoutComment(): void
    {
        // The database can hold an allocation whose session was deleted between
        // runs. It must not be resurrected, and it must not be reported as a
        // conflict — there is nothing to conflict with.
        $builder = (new ProblemBuilder())
            ->standardWeek(1)
            ->room(50, shared: true)
            ->session(1, 1, enrolledCount: 30);

        $result = $this->engine()->solve(
            $builder->build(),
            [new \App\Domain\Allocation\Assignment(999, 1, 1)],
            EngineOptions::greedyOnly(1),
        );

        $this->assertResultIsSound($result);
        self::assertSame(1, $result->assignedCount());
        self::assertSame(0, $result->metrics['warm_start_rejected']);
    }

    private function strictOptions(): EngineOptions
    {
        return new EngineOptions(
            maxIterations: 0,
            strict: true,
            timeBudgetSeconds: 3600.0,
            clock: new FixedClock(0.0),
        );
    }
}
