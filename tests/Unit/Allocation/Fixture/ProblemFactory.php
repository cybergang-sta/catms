<?php

declare(strict_types=1);

namespace Tests\Unit\Allocation\Fixture;

use App\Domain\Allocation\Rng;

/**
 * Generates pseudo-random but *reproducible* scheduling problems.
 *
 * The property tests (no double booking, capacity) are the ones that earn their
 * keep, because they explore input shapes a hand-written fixture never thinks
 * of: three cohorts sharing one lecturer on a day with two rooms, a room with
 * 4 % missing a feature. A fixed set of examples proves only that those
 * examples work.
 *
 * Reproducibility comes from the seeded Rng, so a failure is always
 * re-runnable: the failing seed is printed in the test message and fed straight
 * back to the generator.
 */
final class ProblemFactory
{
    private Rng $rng;

    public function __construct(int $seed = 20260801)
    {
        $this->rng = new Rng($seed);
    }

    /**
     * A problem with a plausible shape: overlapping cohorts, a lecturer who
     * teaches more than one course, rooms of mixed size, some out of scope.
     *
     * @param int $sessionCount
     * @param int $roomCount
     * @param int $slotCount
     * @param int $cohortCount
     * @param int $lecturerCount
     */
    public function random(
        int $sessionCount = 12,
        int $roomCount = 6,
        int $slotCount = 10,
        int $cohortCount = 6,
        int $lecturerCount = 4,
    ): ProblemBuilder {
        $builder = new ProblemBuilder();

        // --- slots ---------------------------------------------------------
        $times = [
            ['08:00', '09:00'],
            ['09:00', '10:00'],
            ['10:00', '11:00'],
            ['11:00', '12:00'],
            ['13:00', '14:00'],
            ['14:00', '15:00'],
            ['15:00', '16:00'],
            ['16:00', '17:00'],
        ];

        for ($i = 0; $i < $slotCount; $i++) {
            $builder->slot(
                ($i % 5) + 1,
                $times[$i % 8][0],
                $times[$i % 8][1],
            );
        }

        // --- rooms ---------------------------------------------------------
        $features = ['projector', 'lab_bench', 'whiteboard'];
        for ($i = 0; $i < $roomCount; $i++) {
            $capacity = [15, 25, 40, 60, 90, 120, 200][$i % 7];

            $roomFeatures = [];
            if ($this->rng->chance(0.6)) {
                $roomFeatures[] = 'projector';
            }
            if ($this->rng->chance(0.25)) {
                $roomFeatures[] = 'lab_bench';
            }

            $builder->room(
                capacity: $capacity,
                features: $roomFeatures,
                building: 'B' . $this->rng->int(1, 3),
                status: $this->rng->chance(0.1) ? 'maintenance' : 'available',
                bookable: $this->rng->chance(0.9),
                // Most rooms are shared; a few are scoped to one department,
                // which is what makes HC-10 reachable.
                shared: $this->rng->chance(0.75),
            );
        }

        unset($features);

        // --- sessions ------------------------------------------------------
        for ($i = 0; $i < $sessionCount; $i++) {
            $required = [];
            if ($this->rng->chance(0.35)) {
                $required[] = 'projector';
            }
            if ($this->rng->chance(0.1)) {
                $required[] = 'lab_bench';
            }

            $builder->session(
                cohortId: $this->rng->int(1, $cohortCount),
                lecturerId: $this->rng->int(1, $lecturerCount),
                enrolledCount: $this->rng->int(5, 110),
                requiredFeatures: $required,
                preferredBuilding: $this->rng->chance(0.3) ? 'B' . $this->rng->int(1, 3) : null,
            );
        }

        // --- a few availability constraints --------------------------------
        for ($i = 0; $i < $lecturerCount; $i++) {
            if ($this->rng->chance(0.3)) {
                $builder->lecturerUnavailableDay($i + 1, $this->rng->int(1, 5));
            }
        }

        return $builder;
    }

    /**
     * A problem that is always solvable: one room per session-sized band, and
     * enough slots that HC-3 cannot bite. Used where the test cares about the
     * *quality* of a solution rather than its validity.
     */
    public function solvable(int $sessions = 8): ProblemBuilder
    {
        $builder = new ProblemBuilder();
        $builder->standardWeek(4);

        for ($i = 0; $i < $sessions; $i++) {
            $builder->room(capacity: 50, shared: true, building: 'Main');
        }

        for ($i = 0; $i < $sessions; $i++) {
            $builder->session(
                cohortId: $i + 1,
                lecturerId: $i + 1,
                enrolledCount: 40,
            );
        }

        return $builder;
    }

    public function rng(): Rng
    {
        return $this->rng;
    }
}
