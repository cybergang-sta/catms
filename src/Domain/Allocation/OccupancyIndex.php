<?php

declare(strict_types=1);

namespace App\Domain\Allocation;

use function count;

/**
 * Bookkeeping of what is already placed, so hard constraints can be evaluated in
 * near-constant time during candidate generation.
 *
 * This is an *index*, not a source of truth. The database unique index on
 * allocations (ADR-006) is the backstop; this structure exists purely for speed.
 *
 * Three lookups, all keyed for O(1) average access:
 *   - room+slot      → set of session ids
 *   - lecturer+slot  → set of session ids
 *   - cohort+slot    → set of session ids
 *   - lecturer+day   → count, for the daily load ceiling (HC-8)
 *
 * `exceptSessionId` lets the checker ask "would this be free if this session were
 * not already placed here?", which is what makes move evaluation correct.
 */
final class OccupancyIndex
{
    /** @var array<string, array<int, true>> */
    private array $roomSlot = [];

    /** @var array<string, array<int, true>> */
    private array $lecturerSlot = [];

    /** @var array<string, array<int, true>> */
    private array $cohortSlot = [];

    /** @var array<string, int> */
    private array $lecturerDay = [];

    /** @var array<int, Assignment> */
    private array $bySession = [];

    /**
     * sessionId => day_of_week of the slot it currently occupies.
     *
     * Kept so lecturerDayLoad() can tell "this session is in this day's bucket"
     * from "this session is in some other day's bucket" — a distinction that
     * matters when evaluating a move.
     *
     * @var array<int, int>
     */
    private array $placedDay = [];

    public function place(Assignment $assignment, SchedulingProblem $problem): void
    {
        $session = $problem->sessionById($assignment->sessionId());
        $slot = $problem->slotById($assignment->timeSlotId());

        if ($session === null || $slot === null) {
            return;
        }

        // A session occupies exactly one place. Re-placing it without an
        // unplace() first must move it, not count it twice against the day.
        $previous = $this->bySession[$assignment->sessionId()] ?? null;
        if ($previous !== null) {
            $this->unplace($previous, $problem);
        }

        $this->roomSlot[$this->rs($assignment->roomId(), $assignment->timeSlotId())][$assignment->sessionId()] = true;
        $key = $this->ls($session->lecturerId(), $assignment->timeSlotId());
        $this->lecturerSlot[$key][$assignment->sessionId()] = true;
        $this->cohortSlot[$this->cs($session->cohortId(), $assignment->timeSlotId())][$assignment->sessionId()] = true;
        $this->lecturerDay[$this->ld($session->lecturerId(), $slot->dayOfWeek())]
            = ($this->lecturerDay[$this->ld($session->lecturerId(), $slot->dayOfWeek())] ?? 0) + 1;

        $this->bySession[$assignment->sessionId()] = $assignment;
        $this->placedDay[$assignment->sessionId()] = $slot->dayOfWeek();
    }

    public function unplace(Assignment $assignment, SchedulingProblem $problem): void
    {
        $session = $problem->sessionById($assignment->sessionId());
        $slot = $problem->slotById($assignment->timeSlotId());

        if ($session === null || $slot === null) {
            return;
        }

        unset(
            $this->roomSlot[$this->rs($assignment->roomId(), $assignment->timeSlotId())][$assignment->sessionId()],
            $this->lecturerSlot[$this->ls($session->lecturerId(), $assignment->timeSlotId())][$assignment->sessionId()],
            $this->cohortSlot[$this->cs($session->cohortId(), $assignment->timeSlotId())][$assignment->sessionId()],
        );

        $dayKey = $this->ld($session->lecturerId(), $slot->dayOfWeek());
        if (isset($this->lecturerDay[$dayKey])) {
            $this->lecturerDay[$dayKey] = max(0, $this->lecturerDay[$dayKey] - 1);
        }

        unset($this->bySession[$assignment->sessionId()]);
        unset($this->placedDay[$assignment->sessionId()]);
    }

    public function roomSlotTaken(int $roomId, int $timeSlotId, ?int $exceptSessionId = null): bool
    {
        $bucket = $this->roomSlot[$this->rs($roomId, $timeSlotId)] ?? [];

        if ($exceptSessionId === null) {
            return $bucket !== [];
        }

        unset($bucket[$exceptSessionId]);

        return $bucket !== [];
    }

    public function lecturerSlotTaken(int $lecturerId, int $timeSlotId, ?int $exceptSessionId = null): bool
    {
        $bucket = $this->lecturerSlot[$this->ls($lecturerId, $timeSlotId)] ?? [];

        if ($exceptSessionId === null) {
            return $bucket !== [];
        }

        unset($bucket[$exceptSessionId]);

        return $bucket !== [];
    }

    public function cohortSlotTaken(int $cohortId, int $timeSlotId, ?int $exceptSessionId = null): bool
    {
        $bucket = $this->cohortSlot[$this->cs($cohortId, $timeSlotId)] ?? [];

        if ($exceptSessionId === null) {
            return $bucket !== [];
        }

        unset($bucket[$exceptSessionId]);

        return $bucket !== [];
    }

    public function lecturerDayLoad(int $lecturerId, int $dayOfWeek, ?int $exceptSessionId = null): int
    {
        $load = $this->lecturerDay[$this->ld($lecturerId, $dayOfWeek)] ?? 0;

        if ($exceptSessionId === null) {
            return $load;
        }

        // Only discount the excluded session when it is *this* session sitting in
        // *this* day bucket. A session placed on another day of the same week
        // contributes nothing here, and subtracting it anyway would under-count
        // the load and let the engine quietly breach the daily ceiling.
        if (($this->placedDay[$exceptSessionId] ?? null) === $dayOfWeek) {
            return max(0, $load - 1);
        }

        return $load;
    }

    public function assignmentFor(int $sessionId): ?Assignment
    {
        return $this->bySession[$sessionId] ?? null;
    }

    /** @return array<int, Assignment> */
    public function all(): array
    {
        return $this->bySession;
    }

    public function isPlaced(int $sessionId): bool
    {
        return isset($this->bySession[$sessionId]);
    }

    public function count(): int
    {
        return count($this->bySession);
    }

    private function rs(int $roomId, int $slotId): string
    {
        return $roomId . '|' . $slotId;
    }

    private function ls(int $lecturerId, int $slotId): string
    {
        return $lecturerId . '|' . $slotId;
    }

    private function cs(int $cohortId, int $slotId): string
    {
        return $cohortId . '|' . $slotId;
    }

    private function ld(int $lecturerId, int $dayOfWeek): string
    {
        return $lecturerId . '|' . $dayOfWeek;
    }
}
