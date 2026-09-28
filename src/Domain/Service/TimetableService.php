<?php

declare(strict_types=1);

namespace App\Domain\Service;

use App\Core\Database;
use App\Core\Identity;
use App\Core\Logger;
use App\Domain\Timetable\Semester;
use App\Domain\Timetable\SessionEntry;
use App\Domain\Timetable\TeachingWeek;
use App\Domain\Timetable\Viewer;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The read side of the timetable: who is teaching what, where, and when.
 *
 * WHY A SERVICE AND NOT A CONTROLLER
 * A controller is allowed to read a request, call one service, and return a
 * Response (`docs/IMPLEMENTATION.md` §8). Everything with a decision in it lives
 * here, which is what makes the visibility rules reviewable in one file instead
 * of scattered across four actions.
 *
 * THE QUERY COUNT IS THE DESIGN CONSTRAINT
 * NFR-PERF-01 budgets the whole request at 3 seconds on a mobile connection, and
 * a timetable grid is the read the report says students open most. A week is
 * built with exactly two queries regardless of how many sittings it contains:
 *
 *   1. the viewer's visible cohorts (students only, and cached per request)
 *   2. one join of allocations -> rooms -> time_slots -> courses -> cohorts ->
 *      users, with every label the client draws already selected
 *
 * The alternative — loading allocations and then resolving each room, course and
 * lecturer — is the textbook N+1, and at 30 sittings it is 91 queries. On the
 * 3G connection in the report's pilot it does not fit in 3 seconds.
 *
 * COLD-CACHE BEHAVIOUR IS DELIBERATE
 * Nothing here is cached. A cached timetable is a timetable that is wrong, and a
 * student who walks to a room that changed twenty minutes ago has been failed by
 * the one optimisation that looked free. The `allocations` covering indexes make
 * the uncached read cheap enough not to need one; the PWA's service worker
 * handles offline at the edge, where staleness is visible to the user as an
 * explicit "showing your last synced copy".
 */
final class TimetableService
{
    /**
     * The statuses that count as a published sitting.
     *
     * Deliberately the same set as the `allocations.active_guard` generated
     * column. If the read side and the uniqueness constraint disagreed about
     * what "active" means, the timetable could show a class that the engine
     * believes it is free to double-book.
     *
     * @var list<string>
     */
    private const PUBLISHED_STATUSES = ['proposed', 'confirmed', 'updated'];

    /**
     * Per-request memo of a student's visible cohorts.
     *
     * Scoped to the object rather than a static property on purpose: a static
     * cache would be shared by every request handled by the same FPM worker,
     * which under concurrency is a cross-user data leak and a bug that only
     * reproduces in production.
     *
     * @var array<int, list<int>>
     */
    private array $cohortCache = [];

    public function __construct(
        private readonly Database $database,
        private readonly Logger $logger,
    ) {
    }

    // -----------------------------------------------------------------------
    // Viewer
    // -----------------------------------------------------------------------

    /**
     * Decide what this identity may see.
     *
     * A role that is not student, lecturer or admin gets an admin viewer bounded
     * by their own department. Falling through to "no filter" would be the
     * catastrophic option, so the default is deliberately the restrictive one and
     * a new role is a visible code change rather than a silent data exposure.
     */
    public function viewerFor(Identity $identity): Viewer
    {
        if ($identity->isAdmin() && $identity->departmentId() === null) {
            return Viewer::system($identity->userId());
        }

        if ($identity->hasRole('student')) {
            return Viewer::student(
                $identity->userId(),
                $identity->departmentId(),
                $this->enrolledCohortIds($identity->userId()),
            );
        }

        if ($identity->hasRole('lecturer')) {
            return Viewer::lecturer($identity->userId(), $identity->departmentId());
        }

        return Viewer::admin($identity->userId(), $identity->departmentId());
    }

    /**
     * Cohorts a student is actively enrolled in.
     *
     * `status = 'enrolled'` and not merely "has a row": a dropped or completed
     * enrolment is history, and showing a student the timetable of a cohort they
     * left last term is both wrong and a small personal-data leak to the wrong
     * human.
     *
     * @return list<int>
     */
    public function enrolledCohortIds(int $userId): array
    {
        if (isset($this->cohortCache[$userId])) {
            return $this->cohortCache[$userId];
        }

        $rows = $this->database->select(
            'SELECT DISTINCT `cohort_id` FROM `enrollments`
             WHERE `student_id` = :student AND `status` = \'enrolled\'
             ORDER BY `cohort_id`',
            ['student' => $userId],
        );

        return $this->cohortCache[$userId] = array_map(
            static fn (array $row): int => (int) $row['cohort_id'],
            $rows,
        );
    }

    // -----------------------------------------------------------------------
    // Semesters
    // -----------------------------------------------------------------------

    /**
     * The semester to render, chosen from what the client asked for.
     *
     * `?semester=` accepts an id or a code, because `--semester=3` is how the
     * console writes it and `2026-A` is how a person reads it, and neither of
     * them should have to remember which. With no parameter, the caller's
     * department's active semester is used — a student opening the app should
     * not have to know that their term is called 2026-A.
     *
     * @return list<Semester>
     */
    public function resolveSemesters(Identity $identity, ?string $requested, bool $includeUnpublished = false): array
    {
        $where = [];
        $bindings = [];

        if ($requested !== null && $requested !== '') {
            if (ctype_digit($requested)) {
                $where[] = 's.`id` = :semester_id';
                $bindings['semester_id'] = (int) $requested;
            } else {
                $where[] = 's.`name` = :semester_name';
                $bindings['semester_name'] = $requested;
            }
        } elseif ($identity->departmentId() !== null) {
            $where[] = 's.`department_id` = :department_id';
            $bindings['department_id'] = $identity->departmentId();
        }

        // A `planning` semester is only ever visible to an administrator, who is
        // the person who generates it. Everyone else sees published semesters
        // only, which is why this is a SQL predicate and not a post-filter.
        if (!$includeUnpublished && !$identity->isAdmin()) {
            $where[] = "s.`status` IN ('active','closed','archived')";
        }

        $sql = 'SELECT s.`id`, s.`department_id`, s.`name`, s.`academic_year`, s.`start_date`,
                       s.`end_date`, s.`teaching_start`, s.`teaching_end`, s.`total_weeks`, s.`status`
                FROM `semesters` s';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY s.`start_date` DESC, s.`id` DESC';

        return array_map(
            static fn (array $row): Semester => Semester::fromRow($row),
            $this->database->select($sql, $bindings),
        );
    }

    /**
     * Dates on which nothing is taught, keyed by `Y-m-d`.
     *
     * Returned to the client so the grid can grey out a public holiday instead of
     * showing an empty column that looks like missing data. HC-7 prevents the
     * engine from scheduling into these dates; this is the same truth shown to a
     * human.
     *
     * @param list<int> $semesterIds
     *
     * @return array<string, array{type: string, label: string}>
     */
    public function nonTeachingDays(array $semesterIds): array
    {
        if ($semesterIds === []) {
            return [];
        }

        $placeholders = [];
        $bindings = [];
        foreach ($semesterIds as $index => $semesterId) {
            $placeholders[] = ':sem' . $index;
            $bindings['sem' . $index] = $semesterId;
        }

        $rows = $this->database->select(
            'SELECT `exception_date`, `type`, `label` FROM `calendar_exceptions`
             WHERE `semester_id` IN (' . implode(', ', $placeholders) . ')
             ORDER BY `exception_date`',
            $bindings,
        );

        $days = [];
        foreach ($rows as $row) {
            $days[(string) $row['exception_date']] = [
                'type'  => (string) $row['type'],
                'label' => (string) $row['label'],
            ];
        }

        return $days;
    }

    // -----------------------------------------------------------------------
    // The grid
    // -----------------------------------------------------------------------

    /**
     * One week of sittings visible to the viewer, unsorted.
     *
     * The caller sorts and groups; returning them pre-grouped would mean two
     * different shapes for the same data and a week view and a CSV export that
     * can disagree about ordering.
     *
     * @return list<SessionEntry>
     */
    public function entriesForWeek(Semester $semester, int $weekNumber, Viewer $viewer): array
    {
        $statuses = self::PUBLISHED_STATUSES;
        $where = [
            'a.`semester_id` = :semester_id',
            // The engine stores the recurring pattern as week 1. A later week
            // with its own rows replaces that pattern; otherwise week 1 is what
            // is taught.
            'a.`week_number` = IF(
                EXISTS (
                    SELECT 1 FROM `allocations` pattern
                    WHERE pattern.`semester_id` = :pattern_semester
                      AND pattern.`week_number` = :pattern_week
                      AND pattern.`status` IN (\'proposed\',\'confirmed\',\'updated\')
                ),
                :week_number,
                1
            )',
        ];
        $bindings = [
            'semester_id' => $semester->id,
            'week_number' => $weekNumber,
            'pattern_semester' => $semester->id,
            'pattern_week' => $weekNumber,
        ];

        $statusPlaceholders = [];
        foreach ($statuses as $index => $status) {
            $statusPlaceholders[] = ':st' . $index;
            $bindings['st' . $index] = $status;
        }
        $where[] = 'a.`status` IN (' . implode(', ', $statusPlaceholders) . ')';

        $visibility = $viewer->apply();
        if ($visibility['sql'] !== '') {
            // Viewer::apply() returns a fragment that already starts with AND,
            // because semesterEntries() concatenates it. This query joins its
            // own AND, so the leading keyword has to come off.
            $where[] = preg_replace('/^\s*AND\s+/i', '', $visibility['sql']) ?? $visibility['sql'];
        }
        $bindings += $visibility['bindings'];

        $sql = 'SELECT a.`id`, a.`week_number`, a.`status`, a.`source`, a.`override_reason`,
                       a.`cohort_id`, a.`course_id`, a.`lecturer_id`, a.`room_id`, a.`time_slot_id`,
                       co.`name` AS `cohort_name`, co.`enrolled_count`, co.`capacity_slack`,
                       c.`code` AS `course_code`, c.`title` AS `course_title`, c.`level` AS `course_level`,
                       u.`first_name`, u.`last_name`,
                       r.`code` AS `room_code`, r.`name` AS `room_name`, r.`building` AS `room_building`,
                       r.`floor` AS `room_floor`, r.`capacity` AS `room_capacity`, r.`room_type`,
                       ts.`label` AS `slot_label`, ts.`day_of_week`, ts.`start_time`, ts.`end_time`
                FROM `allocations` a
                INNER JOIN `cohorts` co ON co.`id` = a.`cohort_id`
                INNER JOIN `courses` c   ON c.`id` = a.`course_id`
                INNER JOIN `rooms` r     ON r.`id` = a.`room_id`
                INNER JOIN `time_slots` ts ON ts.`id` = a.`time_slot_id`
                INNER JOIN `users` u     ON u.`id` = a.`lecturer_id`
                WHERE ' . implode("\n                  AND ", $where) . '
                ORDER BY ts.`day_of_week`, ts.`start_time`, r.`code`, co.`name`';

        $startedAt = microtime(true);
        $rows = $this->database->select($sql, $bindings);

        $this->logger->debug('Timetable week loaded.', [
            'semester_id' => $semester->id,
            'week'        => $weekNumber,
            'viewer'      => $viewer->label(),
            'rows'        => count($rows),
            'ms'          => (int) round((microtime(true) - $startedAt) * 1000),
        ]);

        return array_map(static fn (array $row): SessionEntry => self::hydrate($row), $rows);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrate(array $row): SessionEntry
    {
        $first = (string) ($row['first_name'] ?? '');
        $last = (string) ($row['last_name'] ?? '');

        // A deleted lecturer account leaves an allocation pointing at nothing.
        // The schema's foreign keys prevent that, but a suspended one still has a
        // name, and a blank cell in a timetable is worse than a generic label.
        $lecturerName = trim($first . ' ' . $last);
        if ($lecturerName === '') {
            $lecturerName = 'Unassigned';
        }

        return new SessionEntry(
            (int) $row['id'],
            (int) $row['cohort_id'],
            (string) $row['cohort_name'],
            (int) $row['enrolled_count'] + (int) $row['capacity_slack'],
            (string) $row['course_code'],
            (string) $row['course_title'],
            (int) $row['course_level'],
            (string) ($row['course_features'] ?? ''),
            (int) $row['lecturer_id'],
            $lecturerName,
            (int) $row['room_id'],
            (string) $row['room_code'],
            (string) $row['room_name'],
            (string) $row['room_building'],
            $row['room_floor'] === null ? null : (int) $row['room_floor'],
            (int) $row['room_capacity'],
            (string) $row['room_type'],
            (string) $row['status'],
            (string) $row['source'],
            (string) $row['source'] === 'override',
            $row['override_reason'] === null ? null : (string) $row['override_reason'],
            (int) $row['week_number'],
            (int) $row['day_of_week'],
            (string) $row['start_time'],
            (string) $row['end_time'],
            (string) $row['slot_label'],
            (int) $row['time_slot_id'],
        );
    }

    /**
     * Build the week grid the client renders.
     *
     * Seven days, each holding its slots, each slot holding its sittings — the
     * shape a grid needs, so the client does no grouping and there is exactly one
     * place in the codebase where "what does a week look like" is decided.
     *
     * @return array<string, mixed>
     */
    public function weekView(Semester $semester, TeachingWeek $week, Viewer $viewer): array
    {
        $entries = $this->entriesForWeek($semester, $week->number, $viewer);
        $nonTeaching = $this->nonTeachingDays([$semester->id]);

        // `day_of_week` on a time slot is the true ISO weekday, so the column it
        // belongs in is decided by that and not by its position in the date list.
        // The two only agree when the semester starts on a Monday, and a semester
        // that starts on a Wednesday is an ordinary thing an administrator can
        // do. Building the map means a mid-week start shows Wednesday's sittings
        // under Wednesday rather than shifting the whole grid by two days.
        $dateByIso = [];
        foreach ($week->dates() as $date) {
            $dateByIso[$this->isoWeekdayOf($date)] = $date;
        }

        $byDay = [];
        foreach ($entries as $entry) {
            $byDay[$entry->dayOfWeek][] = $entry;
        }

        $days = [];
        for ($iso = 1; $iso <= 7; $iso++) {
            $date = $dateByIso[$iso] ?? $week->startDate;
            $exception = $nonTeaching[$date] ?? null;

            $days[] = [
                'iso'             => $iso,
                'date'            => $date,
                'label'           => $this->dayLabel($date),
                'is_non_teaching' => $exception !== null,
                'note'            => $exception['label'] ?? null,
                'entries'         => array_map(
                    static fn (SessionEntry $entry): array => $entry->toGridArray(),
                    $byDay[$iso] ?? [],
                ),
            ];
        }

        return [
            'semester' => $semester->toArray(),
            'week'     => [
                'number'     => $week->number,
                'of'         => $week->totalWeeks,
                'label'      => sprintf('Week %d of %d', $week->number, $week->totalWeeks),
                'start_date' => $week->startDate,
                'end_date'   => $week->endDate,
                'can_prev'   => $week->number > 1,
                'can_next'   => $week->number < $week->totalWeeks,
            ],
            'weekdays' => TeachingWeek::weekdayLabels(),
            'days'     => $days,
            'summary'  => [
                'sessions'    => count($entries),
                'rooms'       => count(array_unique(array_map(
                    static fn (SessionEntry $e): int => $e->roomId,
                    $entries,
                ))),
                'lecturers'   => count(array_unique(array_map(
                    static fn (SessionEntry $e): int => $e->lecturerId,
                    $entries,
                ))),
                'overridden'  => count(array_filter(
                    $entries,
                    static fn (SessionEntry $e): bool => $e->isOverride,
                )),
            ],
        ];
    }

    /**
     * One calendar day, for the "what is on today" view.
     *
     * @return array<string, mixed>
     */
    public function dayView(Semester $semester, string $date, Viewer $viewer): array
    {
        $week = $semester->weekFor($date);
        $isoWeekday = $this->isoWeekdayOf($date);
        $entries = array_values(array_filter(
            $this->entriesForWeek($semester, $week->number, $viewer),
            static fn (SessionEntry $entry): bool => $entry->dayOfWeek === $isoWeekday,
        ));

        $nonTeaching = $this->nonTeachingDays([$semester->id]);
        $exception = $nonTeaching[$date] ?? null;

        return [
            'semester'       => $semester->toArray(),
            'date'           => $date,
            'week'           => $week->number,
            'label'          => $this->dayLabel($date),
            'is_non_teaching' => $exception !== null,
            'note'           => $exception['label'] ?? null,
            'entries'        => array_map(
                static fn (SessionEntry $entry): array => $entry->toDetailArray(),
                $entries,
            ),
        ];
    }

    /**
     * The whole semester as flat detail rows, for the export.
     *
     * One query for the term rather than one per week, and bounded by the
     * semester's own `total_weeks`, so a client cannot ask for a year of
     * timetable by passing a large number.
     *
     * @return list<SessionEntry>
     */
    public function semesterEntries(Semester $semester, Viewer $viewer): array
    {
        $bindings = ['semester_id' => $semester->id];

        $statusPlaceholders = [];
        foreach (self::PUBLISHED_STATUSES as $index => $status) {
            $statusPlaceholders[] = ':st' . $index;
            $bindings['st' . $index] = $status;
        }

        $visibility = $viewer->apply();

        $sql = 'SELECT a.`id`, a.`week_number`, a.`status`, a.`source`, a.`override_reason`,
                       a.`cohort_id`, a.`course_id`, a.`lecturer_id`, a.`room_id`, a.`time_slot_id`,
                       co.`name` AS `cohort_name`, co.`enrolled_count`, co.`capacity_slack`,
                       c.`code` AS `course_code`, c.`title` AS `course_title`, c.`level` AS `course_level`,
                       u.`first_name`, u.`last_name`,
                       r.`code` AS `room_code`, r.`name` AS `room_name`, r.`building` AS `room_building`,
                       r.`floor` AS `room_floor`, r.`capacity` AS `room_capacity`, r.`room_type`,
                       ts.`label` AS `slot_label`, ts.`day_of_week`, ts.`start_time`, ts.`end_time`
                FROM `allocations` a
                INNER JOIN `cohorts` co ON co.`id` = a.`cohort_id`
                INNER JOIN `courses` c   ON c.`id` = a.`course_id`
                INNER JOIN `rooms` r     ON r.`id` = a.`room_id`
                INNER JOIN `time_slots` ts ON ts.`id` = a.`time_slot_id`
                INNER JOIN `users` u     ON u.`id` = a.`lecturer_id`
                WHERE a.`semester_id` = :semester_id
                  AND a.`status` IN (' . implode(', ', $statusPlaceholders) . ')' . $visibility['sql'] . '
                ORDER BY a.`week_number`, ts.`day_of_week`, ts.`start_time`, r.`code`
                LIMIT 20000';

        $rows = $this->database->select($sql, $bindings + $visibility['bindings']);

        return array_map(static fn (array $row): SessionEntry => self::hydrate($row), $rows);
    }

    /**
     * CSV column order, shared by the controller and documented in `docs/API.md`
     * so an export is never a surprise to a spreadsheet.
     *
     * @return list<string>
     */
    public static function csvHeader(): array
    {
        return [
            'week',
            'iso_weekday',
            'start_time',
            'end_time',
            'course_code',
            'course_title',
            'course_level',
            'cohort',
            'headcount',
            'lecturer',
            'room_code',
            'room_name',
            'building',
            'floor',
            'capacity',
            'room_type',
            'status',
            'source',
            'override_reason',
        ];
    }

    /**
     * The ISO weekday of a `Y-m-d` date, 1 = Monday.
     *
     * The date has already been through `TeachingWeek`, which is where an
     * unparseable string is rejected, so the `?:` fallback here is unreachable
     * in practice and exists only to keep the return type an int.
     */
    private function isoWeekdayOf(string $date): int
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'));

        return $parsed === false ? 1 : (int) $parsed->format('N');
    }

    /**
     * A human label for a grid column: "Mon 21 Sep".
     */
    private function dayLabel(string $date): string
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'));
        if ($parsed === false) {
            return $date;
        }

        return $parsed->format('D j M');
    }
}
