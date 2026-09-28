<?php

declare(strict_types=1);

namespace App\Domain\Allocation;

/**
 * A teachable space.
 *
 * Immutable value object. Constructed from a repository row or a test fixture;
 * the engine never queries the database itself (ADR-001).
 */
final class Room
{
    /**
     * @param list<int> $unavailableSlotIds Slots explicitly blocked for this room (HC-9)
     */
    public function __construct(
        private readonly int $id,
        private readonly string $code,
        private readonly string $name,
        private readonly string $building,
        private readonly int $capacity,
        private readonly RoomFeatures $features,
        private readonly string $status = 'available',
        private readonly bool $bookable = true,
        private readonly ?int $departmentId = null,
        private readonly bool $shared = false,
        private readonly array $unavailableSlotIds = [],
        private readonly ?string $roomType = null,
    ) {
    }

    public function id(): int
    {
        return $this->id;
    }

    public function code(): string
    {
        return $this->code;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function building(): string
    {
        return $this->building;
    }

    public function capacity(): int
    {
        return $this->capacity;
    }

    public function features(): RoomFeatures
    {
        return $this->features;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function isServiceable(): bool
    {
        return $this->status === 'available' && $this->bookable;
    }

    public function isBookable(): bool
    {
        return $this->bookable;
    }

    public function departmentId(): ?int
    {
        return $this->departmentId;
    }

    /** A shared room is bookable by any department (HC-10). */
    public function isShared(): bool
    {
        return $this->shared;
    }

    public function roomType(): ?string
    {
        return $this->roomType;
    }

    /** @return list<int> */
    public function unavailableSlotIds(): array
    {
        return $this->unavailableSlotIds;
    }

    /**
     * Capacity band, used by the equity term to compare utilisation between
     * rooms of comparable size (docs/ALLOCATION_ENGINE.md §4).
     */
    public function capacityBand(): string
    {
        return match (true) {
            $this->capacity <= 20 => 'xs',
            $this->capacity <= 50 => 's',
            $this->capacity <= 100 => 'm',
            $this->capacity <= 200 => 'l',
            default => 'xl',
        };
    }

    public function fits(int $headcount): bool
    {
        return $this->capacity >= $headcount;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id'         => $this->id,
            'code'       => $this->code,
            'name'       => $this->name,
            'building'   => $this->building,
            'capacity'   => $this->capacity,
            'features'   => $this->features->toArray(),
            'status'     => $this->status,
            'bookable'   => $this->bookable,
            'department' => $this->departmentId,
        ];
    }
}
