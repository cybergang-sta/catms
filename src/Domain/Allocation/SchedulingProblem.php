<?php

declare(strict_types=1);

namespace App\Domain\Allocation;

use function count;

/**
 * The immutable input to one engine run: everything to be scheduled, every space
 * available, and the calendar that bounds them.
 *
 * This is the "port" through which data enters the domain. The engine has no
 * repository dependency, so the same code path serves an HTTP request, a CLI
 * batch job and a unit test.
 */
final class SchedulingProblem
{
    /** @var array<int, SessionRequest> */
    private array $sessions = [];

    /** @var array<int, Room> */
    private array $rooms = [];

    /** @var array<int, TimeSlot> */
    private array $slots = [];

    /** @var array<int, true> */
    private array $activeSlots = [];

    /** @var array<int, true> */
    private array $teachableSlots = [];

    /** @var array<string, true> "lecturerId|slotId" and "lecturerId|dayOfWeek" */
    private array $lecturerUnavailable = [];

    /** @var array<string, true> "roomId|slotId" */
    private array $blockedRooms = [];

    /** @var array<int, int> cohortId => enrolled count, for the waste term */
    private array $cohortSize = [];

    private CostWeights $weights;

    public function __construct(?CostWeights $weights = null)
    {
        $this->weights = $weights ?? new CostWeights();
    }

    /**
     * @param iterable<SessionRequest> $sessions
     */
    public function withSessions(iterable $sessions): self
    {
        foreach ($sessions as $session) {
            $this->sessions[$session->id()] = $session;
            $this->cohortSize[$session->cohortId()] = max(
                $this->cohortSize[$session->cohortId()] ?? 0,
                $session->enrolledCount(),
            );
        }

        return $this;
    }

    /**
     * @param iterable<Room> $rooms
     */
    public function withRooms(iterable $rooms): self
    {
        foreach ($rooms as $room) {
            $this->rooms[$room->id()] = $room;

            foreach ($room->unavailableSlotIds() as $slotId) {
                $this->blockedRooms[$room->id() . '|' . $slotId] = true;
            }
        }

        return $this;
    }

    /**
     * @param iterable<TimeSlot>             $slots
     * @param iterable<int>|null              $teachableSlotIds Null means every active slot is teachable
     */
    public function withSlots(iterable $slots, ?iterable $teachableSlotIds = null): self
    {
        foreach ($slots as $slot) {
            $this->slots[$slot->id()] = $slot;
            if (!$slot->isActive()) {
                continue;
            }

            $this->activeSlots[$slot->id()] = true;
        }

        if ($teachableSlotIds !== null) {
            $this->teachableSlots = [];
            foreach ($teachableSlotIds as $id) {
                $this->teachableSlots[$id] = true;
            }
        } else {
            $this->teachableSlots = $this->activeSlots;
        }

        return $this;
    }

    /**
     * Lecturer unavailability. Each entry is either a specific slot or a whole
     * day of the week.
     *
     * @param list<array{lecturer_id:int, slot_id?:int|null, day_of_week?:int|null}> $entries
     */
    public function withLecturerUnavailability(array $entries): self
    {
        foreach ($entries as $entry) {
            $lecturerId = $entry['lecturer_id'];
            if (isset($entry['slot_id'])) {
                $this->lecturerUnavailable[$lecturerId . '|' . $entry['slot_id']] = true;
            }
            if (!isset($entry['day_of_week'])) {
                continue;
            }

            $this->lecturerUnavailable[$lecturerId . ' ' . $entry['day_of_week']] = true;
        }

        return $this;
    }

    public function withWeights(CostWeights $weights): self
    {
        $this->weights = $weights;

        return $this;
    }

    public function weights(): CostWeights
    {
        return $this->weights;
    }

    /** @return array<int, SessionRequest> */
    public function sessions(): array
    {
        return $this->sessions;
    }

    /** @return array<int, Room> */
    public function rooms(): array
    {
        return $this->rooms;
    }

    /** @return array<int, TimeSlot> */
    public function slots(): array
    {
        return $this->slots;
    }

    public function sessionById(int $id): ?SessionRequest
    {
        return $this->sessions[$id] ?? null;
    }

    public function roomById(int $id): ?Room
    {
        return $this->rooms[$id] ?? null;
    }

    public function slotById(int $id): ?TimeSlot
    {
        return $this->slots[$id] ?? null;
    }

    public function cohortSize(int $cohortId): int
    {
        return $this->cohortSize[$cohortId] ?? 0;
    }

    /** HC-7 — the slot must lie inside a teaching window. */
    public function isTeachable(TimeSlot $slot): bool
    {
        return $slot->isActive() && isset($this->teachableSlots[$slot->id()]);
    }

    /** HC-8 */
    public function isLecturerUnavailable(int $lecturerId, TimeSlot $slot): bool
    {
        return isset($this->lecturerUnavailable[$lecturerId . '|' . $slot->id()])
            || isset($this->lecturerUnavailable[$lecturerId . ' ' . $slot->dayOfWeek()]);
    }

    /** HC-9 */
    public function isRoomBlocked(int $roomId, TimeSlot $slot): bool
    {
        return isset($this->blockedRooms[$roomId . '|' . $slot->id()]);
    }

    /** HC-10 — a shared room crosses department boundaries; a scoped one does not. */
    public function isRoomInScope(Room $room, SessionRequest $session): bool
    {
        if ($room->isShared() || $room->departmentId() === null) {
            return true;
        }

        return $room->departmentId() === $session->departmentId();
    }

    /**
     * Restrict the problem to a set of slots — used by incremental repair to
     * consider only the affected day (docs/ALLOCATION_ENGINE.md §7).
     *
     * @param list<int> $slotIds
     */
    public function restrictedToSlots(array $slotIds): self
    {
        $allowed = [];
        foreach ($slotIds as $id) {
            $allowed[$id] = true;
        }

        $clone = clone $this;
        $clone->teachableSlots = array_intersect_key($this->activeSlots, $allowed);

        return $clone;
    }

    /**
     * Keep only these sessions. Used when a repair must leave every other
     * class where it is.
     *
     * @param list<int> $sessionIds
     */
    public function onlySessions(array $sessionIds): self
    {
        $allowed = [];
        foreach ($sessionIds as $id) {
            $allowed[$id] = true;
        }

        $clone = clone $this;
        $clone->sessions = array_intersect_key($this->sessions, $allowed);

        return $clone;
    }

    public function totalSessions(): int
    {
        return count($this->sessions);
    }
}
