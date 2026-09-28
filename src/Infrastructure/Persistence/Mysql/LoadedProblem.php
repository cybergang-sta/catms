<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Mysql;

use App\Domain\Allocation\Assignment;
use App\Domain\Allocation\SchedulingProblem;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Everything one engine run needs from the database, already resolved.
 *
 * Carries the two things a `SchedulingProblem` deliberately does not: the
 * current timetable (the warm start) and the calendar facts the writer needs to
 * turn a placement into a dated row.
 */
final class LoadedProblem
{
    /**
     * @param list<Assignment>       $existingAssignments Week-1 rows of the
     *                                                   current timetable,
     *                                                   already mapped onto
     *                                                   synthetic session ids.
     * @param list<string>           $warnings            Data problems found
     *                                                   while loading. Never
     *                                                   swallowed: a cohort
     *                                                   with no lecturer is a
     *                                                   data bug, and hiding it
     *                                                   would show up only as
     *                                                   an inexplicable drop
     *                                                   in accuracy.
     * @param array<string, int>     $counts              Row counts per
     *                                                   source table, for the
     *                                                   command's report.
     */
    public function __construct(
        public readonly SchedulingProblem $problem,
        public readonly array $existingAssignments,
        public readonly int $departmentId,
        public readonly int $semesterId,
        public readonly string $semesterName,
        public readonly string $teachingStart,
        public readonly string $teachingEnd,
        public readonly int $totalWeeks,
        public readonly array $warnings = [],
        public readonly array $counts = [],
    ) {
    }

    /**
     * The Monday that teaching week $weekNumber begins on.
     *
     * `semesters.teaching_start` is whatever day the department chose to open
     * teaching, which is often a Wednesday. Allocating against the raw date
     * would put week 3's row on a different weekday from week 1's, and the
     * timetable would move by a day every fortnight. Anchoring to the Monday of
     * the containing week is what makes `week_number` mean something.
     */
    public function weekStart(int $weekNumber = 1): string
    {
        $start = new DateTimeImmutable($this->teachingStart, new DateTimeZone('UTC'));
        $daysSinceMonday = (int) $start->format('N') - 1;
        $monday = $start->modify(sprintf('-%d days', $daysSinceMonday));
        $target = $monday->modify(sprintf('+%d days', 7 * max(0, $weekNumber - 1)));

        return $target->format('Y-m-d');
    }

    public function hasWarnings(): bool
    {
        return $this->warnings !== [];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'department_id'      => $this->departmentId,
            'semester_id'        => $this->semesterId,
            'semester'           => $this->semesterName,
            'teaching_start'     => $this->teachingStart,
            'teaching_end'       => $this->teachingEnd,
            'total_weeks'        => $this->totalWeeks,
            'week_1_monday'      => $this->weekStart(1),
            'sessions'           => $this->problem->totalSessions(),
            'rooms'              => \count($this->problem->rooms()),
            'slots'              => \count($this->problem->slots()),
            'warm_start_rows'    => \count($this->existingAssignments),
            'counts'             => $this->counts,
            'warnings'           => $this->warnings,
        ];
    }
}
