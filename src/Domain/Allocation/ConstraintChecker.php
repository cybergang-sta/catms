<?php

declare(strict_types=1);

namespace App\Domain\Allocation;

/**
 * Hard constraints HC-1 … HC-10 (docs/ALLOCATION_ENGINE.md §3).
 *
 * A candidate that fails any of these is DISCARDED, never scored. Feasibility is
 * therefore binary, and the reported accuracy can never be inflated by
 * invalid placements.
 *
 * The checker is stateless: all occupancy information arrives as the
 * OccupancyIndex argument, which keeps it trivially unit-testable.
 */
final class ConstraintChecker
{
    /**
     * Stable, machine-readable constraint codes. Persisted in
     * allocation_conflicts.constraint_code, so these strings are part of the
     * contract — do not rename them without a migration.
     */
    public const HC_ROOM_FREE          = 'HC-1';
    public const HC_LECTURER_FREE      = 'HC-2';
    public const HC_COHORT_FREE        = 'HC-3';
    public const HC_CAPACITY           = 'HC-4';
    public const HC_FEATURES           = 'HC-5';
    public const HC_ROOM_SERVICEABLE   = 'HC-6';
    public const HC_CALENDAR_WINDOW    = 'HC-7';
    public const HC_LECTURER_AVAILABLE = 'HC-8';
    public const HC_ROOM_NOT_BLOCKED   = 'HC-9';
    public const HC_DEPARTMENT_SCOPE   = 'HC-10';

    public function __construct(
        private readonly int $maxLecturerSessionsPerDay = 4,
    ) {
    }

    /**
     * The HC-8 daily load ceiling. Exposed so the candidate prefilter can apply
     * the identical limit rather than hard-coding a second copy of it.
     */
    public function maxLecturerSessionsPerDay(): int
    {
        return $this->maxLecturerSessionsPerDay;
    }

    /**
     * Full check of a proposed assignment.
     *
     * Returns an empty array when the assignment is feasible, otherwise a list
     * of Violation objects describing every constraint that failed — all of them,
     * not just the first, so the conflict report is actually diagnostic.
     *
     * @return list<Violation>
     */
    public function check(
        Assignment $candidate,
        OccupancyIndex $occupancy,
        SchedulingProblem $problem,
    ): array {
        $violations = [];

        $room = $problem->roomById($candidate->roomId());
        $session = $problem->sessionById($candidate->sessionId());
        $slot = $problem->slotById($candidate->timeSlotId());

        if ($room === null || $session === null || $slot === null) {
            return [Violation::malformed($candidate)];
        }

        // HC-1 — the room must be free in this slot.
        if ($occupancy->roomSlotTaken($room->id(), $slot->id(), $candidate->sessionId())) {
            $violations[] = Violation::of(
                self::HC_ROOM_FREE,
                sprintf('Room %s is already allocated in %s.', $room->code(), $slot->label()),
                $candidate,
            );
        }

        // HC-2 — the lecturer must be free in this slot.
        if ($occupancy->lecturerSlotTaken(
            $session->lecturerId(),
            $slot->id(),
            $candidate->sessionId(),
        )) {
            $violations[] = Violation::of(
                self::HC_LECTURER_FREE,
                sprintf(
                    'Lecturer #%d is already scheduled in %s.',
                    $session->lecturerId(),
                    $slot->label(),
                ),
                $candidate,
            );
        }

        // HC-3 — the cohort must be free in this slot.
        if ($occupancy->cohortSlotTaken(
            $session->cohortId(),
            $slot->id(),
            $candidate->sessionId(),
        )) {
            $violations[] = Violation::of(
                self::HC_COHORT_FREE,
                sprintf('Cohort #%d already has a class in %s.', $session->cohortId(), $slot->label()),
                $candidate,
            );
        }

        // HC-4 — capacity must be sufficient. Never a penalty: an overflow is
        // an invalid timetable, not a worse one.
        if ($room->capacity() < $session->enrolledCount()) {
            $violations[] = Violation::of(
                self::HC_CAPACITY,
                sprintf(
                    'Room %s seats %d but %d students are enrolled (short by %d).',
                    $room->code(),
                    $room->capacity(),
                    $session->enrolledCount(),
                    $session->enrolledCount() - $room->capacity(),
                ),
                $candidate,
            );
        }

        // HC-5 — the room must provide every mandatory feature.
        $missing = $room->features()->missingFrom($session->requiredFeatures());
        if ($missing !== []) {
            $violations[] = Violation::of(
                self::HC_FEATURES,
                sprintf('Room %s lacks: %s.', $room->code(), implode(', ', $missing)),
                $candidate,
            );
        }

        // HC-6 — only serviceable, bookable rooms may be used.
        if (! $room->isServiceable()) {
            $violations[] = Violation::of(
                self::HC_ROOM_SERVICEABLE,
                sprintf('Room %s is %s.', $room->code(), $room->status()),
                $candidate,
            );
        }

        // HC-7 — the slot must lie inside a teaching window.
        if (! $problem->isTeachable($slot)) {
            $violations[] = Violation::of(
                self::HC_CALENDAR_WINDOW,
                sprintf('%s falls outside the teaching calendar.', $slot->label()),
                $candidate,
            );
        }

        // HC-8 — lecturer availability, including the daily load ceiling.
        if ($problem->isLecturerUnavailable($session->lecturerId(), $slot)) {
            $violations[] = Violation::of(
                self::HC_LECTURER_AVAILABLE,
                sprintf('Lecturer #%d is unavailable in %s.', $session->lecturerId(), $slot->label()),
                $candidate,
            );
        }

        if ($occupancy->lecturerDayLoad(
            $session->lecturerId(),
            $slot->dayOfWeek(),
            $candidate->sessionId(),
        ) >= $this->maxLecturerSessionsPerDay) {
            $violations[] = Violation::of(
                self::HC_LECTURER_AVAILABLE,
                sprintf(
                    'Lecturer #%d already has the maximum of %d sessions that day.',
                    $session->lecturerId(),
                    $this->maxLecturerSessionsPerDay,
                ),
                $candidate,
            );
        }

        // HC-9 — explicit per-slot room block, e.g. maintenance window.
        if ($problem->isRoomBlocked($room->id(), $slot)) {
            $violations[] = Violation::of(
                self::HC_ROOM_NOT_BLOCKED,
                sprintf('Room %s is blocked in %s.', $room->code(), $slot->label()),
                $candidate,
            );
        }

        // HC-10 — department scope: rooms may be shared, never silently crossed.
        if (! $problem->isRoomInScope($room, $session)) {
            $violations[] = Violation::of(
                self::HC_DEPARTMENT_SCOPE,
                sprintf(
                    'Room %s is outside the scope of the department scheduling this cohort.',
                    $room->code(),
                ),
                $candidate,
            );
        }

        return $violations;
    }

    /**
     * Convenience predicate. Avoids allocating a Violation list on the hot
     * candidate-generation path.
     */
    public function isFeasible(
        Assignment $candidate,
        OccupancyIndex $occupancy,
        SchedulingProblem $problem,
    ): bool {
        $room = $problem->roomById($candidate->roomId());
        $session = $problem->sessionById($candidate->sessionId());
        $slot = $problem->slotById($candidate->timeSlotId());

        if ($room === null || $session === null || $slot === null) {
            return false;
        }

        return ! $occupancy->roomSlotTaken($room->id(), $slot->id(), $candidate->sessionId())
            && ! $occupancy->lecturerSlotTaken($session->lecturerId(), $slot->id(), $candidate->sessionId())
            && ! $occupancy->cohortSlotTaken($session->cohortId(), $slot->id(), $candidate->sessionId())
            && $room->capacity() >= $session->enrolledCount()
            && $room->features()->satisfies($session->requiredFeatures())
            && $room->isServiceable()
            && $problem->isTeachable($slot)
            && ! $problem->isLecturerUnavailable($session->lecturerId(), $slot)
            && $occupancy->lecturerDayLoad($session->lecturerId(), $slot->dayOfWeek(), $candidate->sessionId())
                < $this->maxLecturerSessionsPerDay
            && ! $problem->isRoomBlocked($room->id(), $slot)
            && $problem->isRoomInScope($room, $session);
    }
}
