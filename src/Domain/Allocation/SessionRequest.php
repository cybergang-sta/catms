<?php

declare(strict_types=1);

namespace App\Domain\Allocation;

/**
 * One cohort's requirement to be scheduled: which course, which lecturer, how
 * many students, how long, and what the room must provide.
 *
 * A course with meetings_per_week = 2 produces two SessionRequest instances,
 * distinguished by `sequence`. Each is scheduled independently, but HC-3 keeps
 * the cohort from being in two places at once.
 */
final class SessionRequest
{
    public function __construct(
        private readonly int $id,
        private readonly int $cohortId,
        private readonly int $courseId,
        private readonly int $lecturerId,
        private readonly int $enrolledCount,
        private readonly int $durationMinutes,
        private readonly RoomFeatures $requiredFeatures,
        private readonly int $sequence = 0,
        private readonly ?string $preferredBuilding = null,
        private readonly ?int $departmentId = null,
        private readonly string $label = '',
    ) {
    }

    public function id(): int
    {
        return $this->id;
    }

    public function cohortId(): int
    {
        return $this->cohortId;
    }

    public function courseId(): int
    {
        return $this->courseId;
    }

    public function lecturerId(): int
    {
        return $this->lecturerId;
    }

    public function enrolledCount(): int
    {
        return $this->enrolledCount;
    }

    public function durationMinutes(): int
    {
        return $this->durationMinutes;
    }

    public function requiredFeatures(): RoomFeatures
    {
        return $this->requiredFeatures;
    }

    /** Which sitting of the week this is: 0, 1, 2 … */
    public function sequence(): int
    {
        return $this->sequence;
    }

    public function preferredBuilding(): ?string
    {
        return $this->preferredBuilding;
    }

    public function departmentId(): ?int
    {
        return $this->departmentId;
    }

    public function label(): string
    {
        return $this->label;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id'                 => $this->id,
            'cohort_id'          => $this->cohortId,
            'course_id'          => $this->courseId,
            'lecturer_id'        => $this->lecturerId,
            'enrolled_count'     => $this->enrolledCount,
            'duration_minutes'   => $this->durationMinutes,
            'required_features'  => $this->requiredFeatures->toArray(),
            'sequence'           => $this->sequence,
            'preferred_building' => $this->preferredBuilding,
        ];
    }
}
