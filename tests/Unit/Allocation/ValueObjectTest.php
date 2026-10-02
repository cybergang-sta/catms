<?php

declare(strict_types=1);

namespace Tests\Unit\Allocation;

use App\Domain\Allocation\Assignment;
use App\Domain\Allocation\CostBreakdown;
use App\Domain\Allocation\EngineOptions;
use App\Domain\Allocation\FixedClock;
use App\Domain\Allocation\OccupancyIndex;
use App\Domain\Allocation\Room;
use App\Domain\Allocation\RoomFeatures;
use App\Domain\Allocation\SchedulingProblem;
use App\Domain\Allocation\TimeSlot;
use App\Domain\Allocation\UnallocatedSession;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Allocation\Fixture\ProblemBuilder;

/**
 * The value objects and the occupancy index.
 *
 * These carry no logic worth defending on their own, but each one sits on a
 * boundary where a mistake is silent: OccupancyIndex is the engine's only
 * source of truth during a run, and RoomFeatures is the sole implementation of
 * BR-05. A bug in either does not throw — it produces a plausible-looking
 * timetable.
 */
final class ValueObjectTest extends TestCase
{
    // --- OccupancyIndex ---------------------------------------------------

    private function problem(): SchedulingProblem
    {
        return (new ProblemBuilder())
            ->standardWeek(2)
            ->room(50, shared: true)
            ->session(1, 1, enrolledCount: 30)
            ->session(2, 2, enrolledCount: 30)
            ->build();
    }

    public function testPlaceThenUnplaceRestoresTheIndexExactly(): void
    {
        $problem = $this->problem();
        $index = new OccupancyIndex();
        $assignment = new Assignment(1, 1, 1);

        self::assertFalse($index->roomSlotTaken(1, 1));

        $index->place($assignment, $problem);
        self::assertTrue($index->roomSlotTaken(1, 1));
        self::assertTrue($index->lecturerSlotTaken(1, 1));
        self::assertTrue($index->cohortSlotTaken(1, 1));
        self::assertSame(1, $index->count());

        $index->unplace($assignment, $problem);
        self::assertFalse($index->roomSlotTaken(1, 1));
        self::assertFalse($index->lecturerSlotTaken(1, 1));
        self::assertFalse($index->cohortSlotTaken(1, 1));
        self::assertSame(0, $index->count());
    }

    public function testUnplacingSomethingThatWasNeverPlacedIsANoOp(): void
    {
        $problem = $this->problem();
        $index = new OccupancyIndex();

        $index->unplace(new Assignment(1, 1, 1), $problem);

        self::assertSame(0, $index->count());
        self::assertFalse($index->roomSlotTaken(1, 1));
    }

    public function testTheExclusionParameterIgnoresOnlyTheNamedSession(): void
    {
        $problem = $this->problem();
        $index = new OccupancyIndex();

        $index->place(new Assignment(1, 1, 1), $problem);
        $index->place(new Assignment(2, 1, 1), $problem); // different cohort AND lecturer, same room

        self::assertTrue($index->roomSlotTaken(1, 1));
        self::assertTrue($index->roomSlotTaken(1, 1, exceptSessionId: 1));
        self::assertTrue($index->roomSlotTaken(1, 1, exceptSessionId: 99));

        $index->unplace(new Assignment(2, 1, 1), $problem);
        self::assertFalse($index->roomSlotTaken(1, 1, exceptSessionId: 1));
    }

    public function testPlacingTheSameSessionTwiceDoesNotDoubleCountTheDayLoad(): void
    {
        // place() is called on every move after an unplace(), but a defensive
        // caller might place twice. The day ceiling must not drift.
        $problem = $this->problem();
        $index = new OccupancyIndex();

        $index->place(new Assignment(1, 1, 1), $problem);
        $index->place(new Assignment(1, 1, 1), $problem);

        self::assertSame(1, $index->lecturerDayLoad(1, 1));
    }

    public function testIndexIgnoresAssignmentsForUnknownEntities(): void
    {
        $problem = $this->problem();
        $index = new OccupancyIndex();

        $index->place(new Assignment(999, 999, 999), $problem);
        $index->unplace(new Assignment(999, 999, 999), $problem);

        self::assertSame(0, $index->count());
    }

    public function testAssignmentForReturnsThePlacedRow(): void
    {
        $problem = $this->problem();
        $index = new OccupancyIndex();
        $assignment = new Assignment(1, 1, 1);

        self::assertNull($index->assignmentFor(1));

        $index->place($assignment, $problem);
        self::assertSame($assignment, $index->assignmentFor(1));
        self::assertTrue($index->isPlaced(1));
    }

    // --- RoomFeatures -----------------------------------------------------

    public function testFeaturesAreCaseInsensitiveAndTrimmed(): void
    {
        $features = new RoomFeatures(['  Projector ', 'WHITEBOARD']);

        self::assertTrue($features->has('projector'));
        self::assertTrue($features->has('  WHITEBOARD '));
        self::assertFalse($features->has('lab_bench'));
    }

    public function testAnEmptyFeatureSetSatisfiesAnyRequirement(): void
    {
        self::assertTrue((new RoomFeatures())->satisfies(new RoomFeatures()));
    }

    public function testMissingFromReportsTheDisplayFormNotTheNormalisedOne(): void
    {
        // The message goes into allocation_conflicts.detail and is read by an
        // administrator, so it must say "Projector", not "projector".
        $room = new RoomFeatures(['whiteboard']);
        $missing = $room->missingFrom(new RoomFeatures(['Projector', 'Lab Bench']));

        self::assertSame(['Lab Bench', 'Projector'], $missing);
        self::assertCount(1, $room, 'Blank codes must not be counted.');
    }

    public function testDuplicateFeaturesCollapse(): void
    {
        self::assertCount(1, new RoomFeatures(['projector', 'PROJECTOR', ' projector ']));
    }

    public function testItSerialisesAsAList(): void
    {
        $features = new RoomFeatures(['projector', 'whiteboard']);

        self::assertSame(['projector', 'whiteboard'], $features->toArray());
        self::assertSame('["projector","whiteboard"]', json_encode($features));
    }

    // --- Room -------------------------------------------------------------

    public function testCapacityBandsAreContiguousAndOrdered(): void
    {
        $band = static fn (int $capacity): string => (new Room(
            1,
            'R',
            'R',
            'Main',
            $capacity,
            new RoomFeatures(),
        ))->capacityBand();

        self::assertSame('xs', $band(1));
        self::assertSame('xs', $band(20));
        self::assertSame('s', $band(21));
        self::assertSame('s', $band(50));
        self::assertSame('m', $band(51));
        self::assertSame('m', $band(100));
        self::assertSame('l', $band(101));
        self::assertSame('l', $band(200));
        self::assertSame('xl', $band(201));
        self::assertSame('xl', $band(1000));
    }

    public function testServiceableRequiresBothAvailabilityAndBookability(): void
    {
        $available = new Room(1, 'R', 'R', 'Main', 30, new RoomFeatures(), 'available', true);
        $unbookable = new Room(1, 'R', 'R', 'Main', 30, new RoomFeatures(), 'available', false);
        $maintenance = new Room(1, 'R', 'R', 'Main', 30, new RoomFeatures(), 'maintenance', true);

        self::assertTrue($available->isServiceable());
        self::assertFalse($unbookable->isServiceable());
        self::assertFalse($maintenance->isServiceable());
    }

    // --- TimeSlot ---------------------------------------------------------

    public function testASlotWithoutAnExplicitLabelBuildsAReadableOne(): void
    {
        $slot = new TimeSlot(1, 3, '14:00:00', '15:00:00');

        self::assertSame('Wednesday 14:00–15:00', $slot->label());
    }

    public function testAnExplicitLabelWins(): void
    {
        $slot = new TimeSlot(1, 3, '14:00:00', '15:00:00', 'Afternoon A');

        self::assertSame('Afternoon A', $slot->label());
    }

    public function testDayNamesFollowIso8601(): void
    {
        self::assertSame('Monday', (new TimeSlot(1, 1, '08:00', '09:00'))->dayName());
        self::assertSame('Sunday', (new TimeSlot(1, 7, '08:00', '09:00'))->dayName());
        self::assertSame('Day 0', (new TimeSlot(1, 0, '08:00', '09:00'))->dayName());
    }

    // --- CostBreakdown ----------------------------------------------------

    public function testTheDominantTermIsTheLargestNonZeroContribution(): void
    {
        $breakdown = new CostBreakdown(1.0, ['waste' => 0.9, 'churn' => 0.05, 'movement' => 0.0]);

        self::assertSame('waste', $breakdown->dominantTerm());
    }

    public function testAnAllZeroBreakdownHasNoDominantTerm(): void
    {
        self::assertNull((new CostBreakdown(0.0, ['waste' => 0.0, 'churn' => 0.0]))->dominantTerm());
        self::assertNull((new CostBreakdown(0.0))->dominantTerm());
    }

    public function testExplainOmitsTermsThatContributedNothing(): void
    {
        $breakdown = new CostBreakdown(0.5, ['waste' => 0.5, 'churn' => 0.0]);
        $lines = $breakdown->explain();

        self::assertCount(1, $lines);
        self::assertStringContainsString('leaves seats empty', $lines[0]);
    }

    // --- UnallocatedSession -----------------------------------------------

    public function testThePrimaryConstraintIsTheMostFrequent(): void
    {
        $entry = new UnallocatedSession(1, 2, 3, 40, ['HC-1' => 12, 'HC-4' => 25, 'HC-3' => 3], 'summary');

        self::assertSame('HC-4', $entry->primaryConstraint());
    }

    public function testThePrimaryConstraintIsNullWhenNothingBlocked(): void
    {
        self::assertNull((new UnallocatedSession(1, 2, 3, 0, [], 'summary'))->primaryConstraint());
    }

    // --- EngineOptions ----------------------------------------------------

    public function testOptionsRejectNonsensicalValues(): void
    {
        $clock = new FixedClock(0.0);

        foreach ([
            ['maxIterations' => -1],
            ['stallLimit' => 0],
            ['maxNeighbours' => 0],
            ['timeBudgetSeconds' => 0.0],
        ] as $overrides) {
            try {
                new EngineOptions(...$overrides, clock: $clock);
                self::fail('Expected InvalidArgumentException for ' . json_encode($overrides));
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testTheBudgetIsMeasuredFromTheStartInstantNotTheCallTime(): void
    {
        $clock = new FixedClock(10.0);

        $options = new EngineOptions(timeBudgetSeconds: 5.0, clock: $clock);

        self::assertSame(15.0, $options->budgetExpiresAt($options->clock->now()));
        self::assertSame(15.0, $options->budgetExpiresAt($options->clock->now()));

        // Once time moves on, the deadline for a *fixed* start does not.
        $clock->advance(100.0);
        self::assertSame(15.0, $options->budgetExpiresAt(10.0));
    }

    public function testFixedClockOnlyMovesWhenToldTo(): void
    {
        $clock = new FixedClock(1.5);

        self::assertSame(1.5, $clock->now());
        self::assertSame(1.5, $clock->now(), 'Reading the clock must not advance it.');
        self::assertSame(1500, $clock->nowMs());

        $clock->advance(0.25);
        self::assertSame(1.75, $clock->now());

        $clock->set(0.0);
        self::assertSame(0.0, $clock->now());
    }
}
