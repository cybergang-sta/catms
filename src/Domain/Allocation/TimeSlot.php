<?php

declare(strict_types=1);

namespace App\Domain\Allocation;

/**
 * A recurring weekly position on the timetable grid.
 *
 * day_of_week follows ISO-8601: 1 = Monday … 7 = Sunday.
 */
final class TimeSlot
{
    public function __construct(
        private readonly int $id,
        private readonly int $dayOfWeek,
        private readonly string $startTime,
        private readonly string $endTime,
        private readonly string $label = '',
        private readonly bool $active = true,
    ) {
    }

    public function id(): int
    {
        return $this->id;
    }

    public function dayOfWeek(): int
    {
        return $this->dayOfWeek;
    }

    public function startTime(): string
    {
        return $this->startTime;
    }

    public function endTime(): string
    {
        return $this->endTime;
    }

    public function label(): string
    {
        return $this->label !== ''
            ? $this->label
            : $this->dayName() . ' ' . substr($this->startTime, 0, 5) . '–' . substr($this->endTime, 0, 5);
    }

    public function dayName(): string
    {
        return match ($this->dayOfWeek) {
            1 => 'Monday',
            2 => 'Tuesday',
            3 => 'Wednesday',
            4 => 'Thursday',
            5 => 'Friday',
            6 => 'Saturday',
            7 => 'Sunday',
            default => 'Day ' . $this->dayOfWeek,
        };
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id'          => $this->id,
            'day_of_week' => $this->dayOfWeek,
            'day'         => $this->dayName(),
            'start_time'  => $this->startTime,
            'end_time'    => $this->endTime,
            'label'       => $this->label(),
        ];
    }
}
