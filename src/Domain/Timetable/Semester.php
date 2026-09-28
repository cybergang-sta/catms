<?php

declare(strict_types=1);

namespace App\Domain\Timetable;

use App\Core\Exception\NotFoundException;

/**
 * A semester as the read side needs it, including the derived week arithmetic.
 *
 * A value object rather than a row so that callers cannot read `start_date`
 * without also being able to ask "what week is this date in". The two facts are
 * always needed together, and a caller that has to remember to join them is a
 * caller that will eventually forget.
 */
final class Semester
{
    public function __construct(
        public readonly int $id,
        public readonly int $departmentId,
        public readonly string $name,
        public readonly string $academicYear,
        public readonly string $startDate,
        public readonly string $endDate,
        public readonly string $teachingStart,
        public readonly string $teachingEnd,
        public readonly int $totalWeeks,
        public readonly string $status,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (int) $row['department_id'],
            (string) $row['name'],
            (string) $row['academic_year'],
            (string) $row['start_date'],
            (string) $row['end_date'],
            (string) $row['teaching_start'],
            (string) $row['teaching_end'],
            (int) $row['total_weeks'],
            (string) $row['status'],
        );
    }

    /**
     * A `planning` semester is a draft timetable, not a published one. A student
     * must not see it: they would plan a term around a grid an administrator can
     * still regenerate. Admins can, because generating it is their job.
     */
    public function isPublished(): bool
    {
        return $this->status === 'active' || $this->status === 'closed' || $this->status === 'archived';
    }

    public function weekFor(string $date): TeachingWeek
    {
        return TeachingWeek::fromDate($date, $this->startDate, $this->totalWeeks);
    }

    public function week(int $number): TeachingWeek
    {
        return TeachingWeek::fromNumber($number, $this->startDate, $this->totalWeeks);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'             => $this->id,
            'department_id'  => $this->departmentId,
            'name'           => $this->name,
            'academic_year'  => $this->academicYear,
            'start_date'     => $this->startDate,
            'end_date'       => $this->endDate,
            'teaching_start' => $this->teachingStart,
            'teaching_end'   => $this->teachingEnd,
            'total_weeks'    => $this->totalWeeks,
            'status'         => $this->status,
        ];
    }

    public function notFound(): NotFoundException
    {
        return new NotFoundException('Semester', $this->id);
    }
}
