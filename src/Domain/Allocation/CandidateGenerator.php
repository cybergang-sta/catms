<?php

declare(strict_types=1);

namespace App\Domain\Allocation;

/**
 * Produces the feasible (slot, room) pairs for one session.
 *
 * This is the filter stage of the optimiser: everything it returns satisfies
 * HC-1 … HC-10, so nothing downstream ever has to reason about validity.
 *
 * COMPLETENESS IS THE WHOLE JOB
 * -----------------------------
 * The prefilter below is hand-inlined rather than delegated to ConstraintChecker
 * so that the cheap rejections happen before any object is allocated, but that
 * makes it a second implementation of the hard constraints — and a second
 * implementation is exactly how HC-8 (the daily lecturer load ceiling) went
 * missing here once already. It is asserted against ConstraintChecker by
 * CandidateGeneratorTest::test_prefilter_matches_constraint_checker, which walks
 * every slot/room pair and demands agreement. Do not add a constraint here
 * without adding it there, and let the test fail.
 *
 * Ordering is deterministic (slots by day then start time, rooms by id) so the
 * engine is reproducible even before the RNG is involved.
 */
final class CandidateGenerator
{
    public function __construct(
        private readonly ConstraintChecker $checker,
    ) {
    }

    /**
     * All feasible placements for $sessionId.
     *
     * @return list<Assignment>
     */
    public function for(
        int $sessionId,
        SchedulingProblem $problem,
        OccupancyIndex $occupancy,
    ): array {
        $session = $problem->sessionById($sessionId);
        if ($session === null) {
            return [];
        }

        $candidates = [];

        foreach ($this->usableSlots($problem) as $slot) {
            // Cheap rejections first: skip the room loop entirely when the
            // cohort, the lecturer or the calendar already rules this slot out.
            if ($occupancy->cohortSlotTaken($session->cohortId(), $slot->id(), $sessionId)) {
                continue;
            }

            if ($occupancy->lecturerSlotTaken($session->lecturerId(), $slot->id(), $sessionId)) {
                continue;
            }

            if ($problem->isLecturerUnavailable($session->lecturerId(), $slot)) {
                continue;
            }

            // HC-8, second half: the daily load ceiling. Depends on the lecturer
            // and the day, not on the room, so it belongs in the slot loop.
            if ($occupancy->lecturerDayLoad(
                $session->lecturerId(),
                $slot->dayOfWeek(),
                $sessionId,
            ) >= $this->checker->maxLecturerSessionsPerDay()) {
                continue;
            }

            foreach ($problem->rooms() as $room) {
                if (! $room->isServiceable()) {
                    continue;
                }

                if (! $room->fits($session->enrolledCount())) {
                    continue;
                }

                if (! $room->features()->satisfies($session->requiredFeatures())) {
                    continue;
                }

                if ($problem->isRoomBlocked($room->id(), $slot)) {
                    continue;
                }

                if (! $problem->isRoomInScope($room, $session)) {
                    continue;
                }

                if ($occupancy->roomSlotTaken($room->id(), $slot->id(), $sessionId)) {
                    continue;
                }

                $candidates[] = new Assignment($sessionId, $room->id(), $slot->id());
            }
        }

        return $candidates;
    }

    /**
     * How many feasible placements $sessionId has, for the fail-first ordering
     * of Phase 1.
     *
     * Delegates to for() on purpose. An earlier version re-implemented the
     * prefilter here to avoid materialising Assignment objects; that saved a
     * few allocations and cost a silent divergence in the hard constraints. One
     * implementation, tested against the checker, is worth more.
     */
    public function countFeasible(
        int $sessionId,
        SchedulingProblem $problem,
        OccupancyIndex $occupancy,
    ): int {
        return \count($this->for($sessionId, $problem, $occupancy));
    }

    /**
     * Slots that lie inside a teaching window, in a stable order.
     *
     * @return list<TimeSlot>
     */
    private function usableSlots(SchedulingProblem $problem): array
    {
        $slots = array_values($problem->slots());

        usort($slots, static function (TimeSlot $a, TimeSlot $b): int {
            return [$a->dayOfWeek(), $a->startTime()] <=> [$b->dayOfWeek(), $b->startTime()];
        });

        $teachable = array_filter(
            $slots,
            static fn (TimeSlot $s): bool => $problem->isTeachable($s),
        );

        return array_values($teachable);
    }
}
