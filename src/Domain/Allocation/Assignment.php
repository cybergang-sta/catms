<?php

declare(strict_types=1);

namespace App\Domain\Allocation;

/**
 * A tentative placement of one cohort's session into one room and one time slot.
 *
 * Immutable. The engine creates candidates freely and discards most of them, so
 * this must stay cheap and side-effect free.
 */
final class Assignment
{
    public function __construct(
        private readonly int $sessionId,
        private readonly int $roomId,
        private readonly int $timeSlotId,
        private float $cost = 0.0,
        /** @var array<string, float>|null */
        private ?array $breakdown = null,
        /** True when the assignment came from the existing timetable (warm start). */
        private readonly bool $fromExisting = false,
    ) {
    }

    public function sessionId(): int
    {
        return $this->sessionId;
    }

    public function roomId(): int
    {
        return $this->roomId;
    }

    public function timeSlotId(): int
    {
        return $this->timeSlotId;
    }

    public function cost(): float
    {
        return $this->cost;
    }

    /** @return array<string, float>|null */
    public function breakdown(): ?array
    {
        return $this->breakdown;
    }

    public function isFromExisting(): bool
    {
        return $this->fromExisting;
    }

    public function withCost(float $cost, ?array $breakdown = null): self
    {
        $clone = clone $this;
        $clone->cost = $cost;
        $clone->breakdown = $breakdown;

        return $clone;
    }

    /**
     * Same session, different room and/or slot. Used by the REASSIGN and MOVESLOT
     * neighbourhoods.
     */
    public function movedTo(int $roomId, int $timeSlotId): self
    {
        return new self($this->sessionId, $roomId, $timeSlotId, $this->cost, null, $this->fromExisting);
    }

    public function key(): string
    {
        return $this->sessionId . ':' . $this->roomId . ':' . $this->timeSlotId;
    }

    /** @return array{session_id:int, room_id:int, time_slot_id:int, cost:float} */
    public function toArray(): array
    {
        return [
            'session_id'    => $this->sessionId,
            'room_id'       => $this->roomId,
            'time_slot_id'  => $this->timeSlotId,
            'cost'          => round($this->cost, 4),
        ];
    }
}
