<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Mysql;

use App\Core\Database;
use App\Core\Logger;
use App\Domain\Allocation\Assignment;
use App\Domain\Allocation\CostWeights;
use App\Domain\Allocation\Room;
use App\Domain\Allocation\RoomFeatures;
use App\Domain\Allocation\SchedulingProblem;
use App\Domain\Allocation\SessionRequest;
use App\Domain\Allocation\TimeSlot;
use RuntimeException;

/**
 * Builds a `SchedulingProblem` out of the relational schema.
 *
 * THIS IS THE ADAPTER THAT MAKES ADR-001 HONEST
 * The engine receives value objects and nothing else. Everything that has to be
 * known about SQL — the joins, the synthetic session ids, the fact that a
 * recurring weekly timetable cannot represent a one-off date — is contained in
 * this class, so the constraint logic stays testable without a database.
 *
 * SYNTHETIC SESSION IDS
 * There is no `sessions` table, because a session is a pair of facts (a cohort
 * meets a course N times a week), not an entity an administrator maintains. The
 * engine nevertheless needs an integer to key a session by, so this loader
 * derives one: `cohort_id * 10 + sequence`. It is stable across runs, which is
 * what makes the warm start matchable, and `courses.meetings_per_week` is CHECK
 * constrained to 1..7, so ten is more than enough room for the sequence.
 *
 * WHAT A WEEKLY GRID CANNOT EXPRESS
 * Three pieces of reference data are date-specific rather than weekly:
 *  - `calendar_exceptions` marks individual non-teaching dates. A recurring
 *    timetable has no row for "the Tuesday of week 6", so a single bad date is
 *    handled by cancelling that week's `allocations` row, not by the engine. A
 *    weekday is only removed from the teachable set when *every* occurrence of
 *    it in the semester is a non-teaching day.
 *  - `lecturer_availability.date` is a one-off absence. Reported, not applied.
 *  - `room_unavailability.date` is a one-off block. Reported, not applied.
 *  In each case the alternative — ignoring the column entirely — would leave an
 *  administrator who entered it believing it had taken effect, so each is
 *  surfaced as a warning instead.
 */
final class SchedulingProblemLoader
{
    public function __construct(
        private readonly Database $database,
        private readonly Logger $logger,
    ) {
    }

    /**
     * @param list<int> $excludedDays ISO-8601 days of the week (1 = Monday … 7 =
     *                                Sunday) to remove from the teachable set
     *                                regardless of what the data says. The
     *                                operator escape hatch for a semester whose
     *                                partial calendar cannot express the
     *                                teaching pattern.
     */
    public function load(
        int $departmentId,
        int $semesterId,
        ?CostWeights $weights = null,
        array $excludedDays = [],
    ): LoadedProblem {
        $semester = $this->semester($departmentId, $semesterId);
        $warnings = [];

        $problem = new SchedulingProblem($weights);

        $slots = $this->slots($departmentId);
        $excluded = $this->nonTeachingDays($semester, $excludedDays, $warnings);
        $teachable = [];
        foreach ($slots as $slot) {
            if ($slot->isActive() && !in_array($slot->dayOfWeek(), $excluded, true)) {
                $teachable[] = $slot->id();
            }
        }
        $problem->withSlots($slots, $teachable);

        $rooms = $this->rooms($departmentId, (string) $semester['teaching_start'], (string) $semester['teaching_end']);
        $blocked = $this->roomBlocks($semesterId, $warnings);
        $problem->withRooms($this->attachBlocks($rooms, $blocked));

        $cohorts = $this->cohorts($departmentId, $semesterId, $warnings);
        $requiredFeatures = $this->courseFeatureRequirements($departmentId, $semesterId);
        $lecturers = $this->lecturers($departmentId, $semesterId);

        $sessions = [];
        foreach ($cohorts as $cohort) {
            $required = $requiredFeatures[(int) $cohort['course_id']] ?? [];
            $lecturerId = $lecturers[(int) $cohort['cohort_id']] ?? $lecturers[(int) $cohort['course_id']] ?? null;

            if ($lecturerId === null) {
                $warnings[] = sprintf(
                    'Cohort #%d (%s) has no lecturer assignment and no course default, so it was not scheduled. '
                    . 'Add a `lecturer_course_assignments` row or set `courses.default_lecturer_id`.',
                    (int) $cohort['cohort_id'],
                    (string) $cohort['cohort_name'],
                );
                continue;
            }

            $headcount = (int) $cohort['enrolled_count'] + (int) $cohort['capacity_slack'];
            $meetings = max(1, min(7, (int) $cohort['meetings_per_week']));

            for ($sequence = 0; $sequence < $meetings; $sequence++) {
                $sessions[] = new SessionRequest(
                    id: $this->sessionId((int) $cohort['cohort_id'], $sequence),
                    cohortId: (int) $cohort['cohort_id'],
                    courseId: (int) $cohort['course_id'],
                    lecturerId: $lecturerId,
                    enrolledCount: $headcount,
                    durationMinutes: (int) $cohort['duration_minutes'],
                    requiredFeatures: new RoomFeatures($required),
                    sequence: $sequence,
                    preferredBuilding: $this->nullableString($cohort['preferred_building']),
                    departmentId: (int) $cohort['department_id'],
                    label: sprintf(
                        '%s sitting %d/%d — %s',
                        (string) $cohort['course_code'],
                        $sequence + 1,
                        $meetings,
                        (string) $cohort['course_title'],
                    ),
                );
            }
        }

        $problem->withSessions($sessions);
        $problem->withLecturerUnavailability($this->lecturerUnavailability($semesterId, $warnings));

        $existing = $this->existingAssignments($departmentId, $semesterId, $cohorts, $warnings);

        if ($problem->totalSessions() === 0) {
            $warnings[] = 'No sessions to schedule. Every cohort in this semester is inactive, '
                . 'has no lecturer, or has no students enrolled.';
        }

        if ($teachable === []) {
            $warnings[] = 'No teachable time slots. Either the department has no active time grid, '
                . 'or every slot falls on an excluded day.';
        }

        if ($warnings !== []) {
            // A nightly run's warnings go to a log nobody reads; an operator
            // running the command sees them on stdout. Both are needed, because
            // accuracy that quietly excludes a cohort is a data problem that only
            // surfaces as a complaint months later.
            $this->logger->warning('Scheduling data loaded with warnings.', [
                'department_id' => $departmentId,
                'semester_id'   => $semesterId,
                'warnings'      => $warnings,
            ]);
        }

        return new LoadedProblem(
            problem: $problem,
            existingAssignments: $existing,
            departmentId: $departmentId,
            semesterId: $semesterId,
            semesterName: (string) $semester['name'],
            teachingStart: (string) $semester['teaching_start'],
            teachingEnd: (string) $semester['teaching_end'],
            totalWeeks: (int) $semester['total_weeks'],
            warnings: $warnings,
            counts: [
                'cohorts'          => \count($cohorts),
                'sessions'         => \count($sessions),
                'rooms'            => \count($rooms),
                'blocked_rooms'    => \count($blocked),
                'slots'            => \count($slots),
                'teachable_slots'  => \count($teachable),
                'warm_start_rows'  => \count($existing),
            ],
        );
    }

    /**
     * `cohort_id * 10 + sequence`.
     *
     * `courses.meetings_per_week` is CHECK-constrained to 1..7, so a sequence of
     * 7 plus the base is the largest value and ten never carries into the
     * cohort's own range. The overflow guard is there because a corrupted
     * auto-increment value must produce a loud failure, not a session id that
     * silently collides with another cohort's.
     */
    public function sessionId(int $cohortId, int $sequence): int
    {
        if ($cohortId < 1 || $cohortId > intdiv(PHP_INT_MAX, 10)) {
            throw new RuntimeException(
                sprintf('Cohort id %d is out of range for a derived session id.', $cohortId)
            );
        }

        return ($cohortId * 10) + $sequence;
    }

    // -----------------------------------------------------------------------
    // Calendar
    // -----------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function semester(int $departmentId, int $semesterId): array
    {
        $row = $this->database->selectOne(
            'SELECT id, department_id, name, teaching_start, teaching_end, total_weeks, status
             FROM `semesters`
             WHERE id = :id AND department_id = :department',
            ['id' => $semesterId, 'department' => $departmentId],
        );

        if ($row === null) {
            throw new RuntimeException(sprintf(
                'Semester #%d does not exist in department #%d. Check the --semester and --department arguments.',
                $semesterId,
                $departmentId,
            ));
        }

        return $row;
    }

    /**
     * The weekly grid: the department's own slots plus the institution-wide ones
     * (`department_id IS NULL`).
     *
     * Inactive slots are loaded too rather than filtered out in SQL. The engine
     * already understands `TimeSlot::$active`, and a slot that has been switched
     * off should disappear from the teachable set while still being visible in
     * the diagnostics of whatever can no longer be placed.
     *
     * @return list<TimeSlot>
     */
    private function slots(int $departmentId): array
    {
        $rows = $this->database->select(
            'SELECT id, day_of_week, start_time, end_time, label, is_active
             FROM `time_slots`
             WHERE department_id = :department OR department_id IS NULL',
            ['department' => $departmentId],
        );

        $slots = [];
        foreach ($rows as $row) {
            $slots[] = new TimeSlot(
                id: (int) $row['id'],
                dayOfWeek: (int) $row['day_of_week'],
                startTime: (string) $row['start_time'],
                endTime: (string) $row['end_time'],
                label: (string) $row['label'],
                active: (int) $row['is_active'] === 1,
            );
        }

        usort(
            $slots,
            static fn (TimeSlot $a, TimeSlot $b): int => [$a->dayOfWeek(), $a->startTime()]
                <=> [$b->dayOfWeek(), $b->startTime()],
        );

        return $slots;
    }

    /**
     * Days of the week on which nothing may be taught, plus the operator's
     * explicit exclusions.
     *
     * A weekday is only removed when every one of its occurrences inside the
     * semester is a non-teaching date. Anything weaker — "there is a holiday
     * some time this semester" — would silently delete a teaching day for the
     * whole term, which is a much worse failure than the one it prevents.
     *
     * @param array<string, mixed> $semester
     * @param list<int>            $excludedDays
     * @param list<string>         $warnings
     *
     * @return list<int>
     */
    private function nonTeachingDays(array $semester, array $excludedDays, array &$warnings): array
    {
        $weeks = max(1, (int) $semester['total_weeks']);

        $rows = $this->database->select(
            'SELECT WEEKDAY(exception_date) AS dow, COUNT(*) AS occurrences
             FROM `calendar_exceptions`
             WHERE semester_id = :semester
               AND type IN (\'holiday\', \'break\', \'non_teaching\')
               AND exception_date BETWEEN :from_date AND :to_date
             GROUP BY dow',
            [
                'semester'  => (int) $semester['id'],
                'from_date' => (string) $semester['teaching_start'],
                'to_date'   => (string) $semester['teaching_end'],
            ],
        );

        $excluded = [];
        $partialDays = 0;

        foreach ($rows as $row) {
            $isoDay = (int) $row['dow'] + 1; // MySQL WEEKDAY(): 0 = Monday
            $occurrences = (int) $row['occurrences'];

            if ($occurrences >= $weeks) {
                $excluded[] = $isoDay;
                $warnings[] = sprintf(
                    'Day %d is excluded from teaching: all %d of its occurrences in %s are non-teaching days.',
                    $isoDay,
                    $occurrences,
                    (string) $semester['name'],
                );
                continue;
            }

            $partialDays++;
        }

        if ($partialDays > 0) {
            // Worth saying out loud: an administrator who entered "mid-semester
            // break" has been obeyed in the sense that the run is unaffected, and
            // not in the sense they might assume. Silence here would let a
            // published timetable contradict the calendar in front of it.
            $warnings[] = sprintf(
                '%d day(s) in %s have non-teaching dates but are still taught in at least one week, so they '
                . 'remain in the timetable. A recurring weekly grid cannot represent a single exception: cancel '
                . 'the affected week\'s allocation row, or -- for a whole term of exceptions -- exclude the day.',
                $partialDays,
                (string) $semester['name'],
            );
        }

        foreach ($excludedDays as $day) {
            if ($day >= 1 && $day <= 7 && !in_array($day, $excluded, true)) {
                $excluded[] = $day;
                $warnings[] = sprintf('Day %d was excluded by the operator (--exclude-day).', $day);
            }
        }

        sort($excluded);

        return $excluded;
    }

    // -----------------------------------------------------------------------
    // Rooms
    // -----------------------------------------------------------------------

    /**
     * @return list<Room>
     */
    private function rooms(int $departmentId, string $teachingStart, string $teachingEnd): array
    {
        $rows = $this->database->select(
            'SELECT r.id, r.code, r.name, r.building, r.capacity, r.status, r.is_bookable,
                    r.department_id, r.room_type,
                    COALESCE(GROUP_CONCAT(DISTINCT rf.code ORDER BY rf.code SEPARATOR \'\\n\'), \'\') AS features
             FROM `rooms` r
             LEFT JOIN `room_feature_map` rfm
                    ON rfm.room_id = r.id
                   AND (rfm.valid_from IS NULL OR rfm.valid_from <= :valid_from)
                   AND (rfm.valid_to   IS NULL OR rfm.valid_to   >= :valid_to)
             LEFT JOIN `room_features` rf ON rf.id = rfm.feature_id
             WHERE r.department_id = :department OR r.department_id IS NULL
             GROUP BY r.id, r.code, r.name, r.building, r.capacity, r.status,
                      r.is_bookable, r.department_id, r.room_type
             ORDER BY r.id',
            [
                'department'  => $departmentId,
                'valid_from'  => $teachingStart,
                'valid_to'    => $teachingEnd,
            ],
        );

        $rooms = [];
        foreach ($rows as $row) {
            $features = trim((string) $row['features']);
            $departmentOfRoom = $row['department_id'] === null ? null : (int) $row['department_id'];

            $rooms[] = new Room(
                id: (int) $row['id'],
                code: (string) $row['code'],
                name: (string) $row['name'],
                building: (string) $row['building'],
                capacity: (int) $row['capacity'],
                features: new RoomFeatures($features === '' ? [] : explode("\n", $features)),
                status: (string) $row['status'],
                bookable: (int) $row['is_bookable'] === 1,
                departmentId: $departmentOfRoom,
                // A room with no department is the institution's to share, which is
                // the same rule HC-10 applies. Modelled as a column because the
                // engine asks "may anyone book this?" without needing a session.
                shared: $departmentOfRoom === null,
                unavailableSlotIds: [],
                roomType: $this->nullableString($row['room_type']),
            );
        }

        return $rooms;
    }

    /**
     * Recurring room blocks, keyed by room id.
     *
     * @param list<string> $warnings
     *
     * @return array<int, list<int>>
     */
    private function roomBlocks(int $semesterId, array &$warnings): array
    {
        $rows = $this->database->select(
            'SELECT ru.room_id, ru.time_slot_id
             FROM `room_unavailability` ru
             WHERE ru.semester_id = :semester AND ru.date IS NULL',
            ['semester' => $semesterId],
        );

        $blocked = [];
        foreach ($rows as $row) {
            $roomId = (int) $row['room_id'];
            $blocked[$roomId] ??= [];
            $blocked[$roomId][] = (int) $row['time_slot_id'];
        }

        $oneOffs = (int) $this->database->scalar(
            'SELECT COUNT(*) FROM `room_unavailability` WHERE semester_id = :semester AND date IS NOT NULL',
            ['semester' => $semesterId],
        );

        if ($oneOffs > 0) {
            $warnings[] = sprintf(
                '%d room unavailability row(s) are dated to a single day and were not applied. '
                . 'A recurring timetable has no per-date row; cancel that week\'s allocation instead.',
                $oneOffs,
            );
        }

        return $blocked;
    }

    /**
     * @param list<Room>            $rooms
     * @param array<int, list<int>> $blocked
     *
     * @return list<Room>
     */
    private function attachBlocks(array $rooms, array $blocked): array
    {
        if ($blocked === []) {
            return $rooms;
        }

        $withBlocks = [];
        foreach ($rooms as $room) {
            $withBlocks[] = new Room(
                id: $room->id(),
                code: $room->code(),
                name: $room->name(),
                building: $room->building(),
                capacity: $room->capacity(),
                features: $room->features(),
                status: $room->status(),
                bookable: $room->isBookable(),
                departmentId: $room->departmentId(),
                shared: $room->isShared(),
                unavailableSlotIds: $blocked[$room->id()] ?? [],
                roomType: $room->roomType(),
            );
        }

        return $withBlocks;
    }

    // -----------------------------------------------------------------------
    // Cohorts, courses, lecturers
    // -----------------------------------------------------------------------

    /**
     * Cohorts in this semester with their course, the number of sittings, and
     * both the stored headcount and the real enrolment count.
     *
     * @param list<string> $warnings
     *
     * @return list<array<string, mixed>>
     */
    private function cohorts(int $departmentId, int $semesterId, array &$warnings): array
    {
        $rows = $this->database->select(
            'SELECT c.id AS cohort_id, c.name AS cohort_name, c.department_id, c.course_id,
                    c.enrolled_count, c.capacity_slack,
                    co.code AS course_code, co.title AS course_title,
                    co.meetings_per_week, co.duration_minutes, co.preferred_building,
                    (SELECT COUNT(*) FROM `enrollments` e
                      WHERE e.cohort_id = c.id AND e.status = \'enrolled\') AS enrolled_rows
             FROM `cohorts` c
             JOIN `courses` co ON co.id = c.course_id
             WHERE c.semester_id = :semester
               AND c.department_id = :department
               AND co.is_active = 1
             ORDER BY c.id',
            ['semester' => $semesterId, 'department' => $departmentId],
        );

        $cohorts = [];
        foreach ($rows as $row) {
            $stored = (int) $row['enrolled_count'];
            $actual = (int) $row['enrolled_rows'];

            // A stale headcount is a data bug, and it is the one that silently
            // books a 30-seat room for a 90-student class. The larger of the two
            // is used so a stale count can only ever cost a too-large room, never
            // produce an overflowing one.
            if ($stored !== $actual) {
                $warnings[] = sprintf(
                    'Cohort #%d (%s) records enrolled_count = %d but has %d enrolled student row(s); '
                    . 'the larger figure was used. Re-run the enrolment sync.',
                    (int) $row['cohort_id'],
                    (string) $row['cohort_name'],
                    $stored,
                    $actual,
                );
            }

            if ($stored === 0 && $actual === 0) {
                $warnings[] = sprintf(
                    'Cohort #%d (%s) has no students and was not scheduled.',
                    (int) $row['cohort_id'],
                    (string) $row['cohort_name'],
                );
                continue;
            }

            $row['enrolled_count'] = max($stored, $actual);
            $cohorts[] = $row;
        }

        return $cohorts;
    }

    /**
     * Mandatory feature requirements per course (HC-5).
     *
     * `course_feature_requirements.mandatory = 0` is deliberately excluded: the
     * schema describes it as "preferred only, downgraded to a soft term", and
     * the cost function has no generic feature-preference term to downgrade it
     * into. Treating it as a hard requirement would be a stricter timetable than
     * the specification asks for; treating it as absent loses the administrator's
     * stated preference. It is therefore not consulted, and this is the place to
     * revisit when that term is added to `CostFunction`.
     *
     * @return array<int, list<string>>
     */
    private function courseFeatureRequirements(int $departmentId, int $semesterId): array
    {
        $rows = $this->database->select(
            'SELECT cfr.course_id,
                    COALESCE(GROUP_CONCAT(DISTINCT rf.code ORDER BY rf.code SEPARATOR \'\\n\'), \'\') AS codes
             FROM `course_feature_requirements` cfr
             JOIN `room_features` rf ON rf.id = cfr.feature_id
             JOIN `cohorts` c       ON c.course_id = cfr.course_id
             WHERE cfr.mandatory = 1
               AND c.semester_id = :semester
               AND c.department_id = :department
             GROUP BY cfr.course_id',
            ['semester' => $semesterId, 'department' => $departmentId],
        );

        $requirements = [];
        foreach ($rows as $row) {
            $codes = trim((string) $row['codes']);
            $requirements[(int) $row['course_id']] = $codes === '' ? [] : explode("\n", $codes);
        }

        return $requirements;
    }

    /**
     * Lecturer per cohort, falling back to the course default.
     *
     * Returns a map keyed by BOTH cohort id and course id so the caller can look
     * up `$map[$cohortId] ?? $map[$courseId]` — a cohort-specific assignment
     * always wins over a course-level default, which is what
     * `lecturer_course_assignments.is_primary` is for.
     *
     * @return array<int, int>
     */
    private function lecturers(int $departmentId, int $semesterId): array
    {
        $map = [];

        $courseLevel = $this->database->select(
            'SELECT lca.course_id, lca.lecturer_id
             FROM `lecturer_course_assignments` lca
             JOIN `cohorts` c ON c.course_id = lca.course_id
             WHERE c.semester_id = :semester
               AND c.department_id = :department
               AND lca.cohort_id IS NULL
             ORDER BY lca.is_primary DESC, lca.id',
            ['semester' => $semesterId, 'department' => $departmentId],
        );

        foreach ($courseLevel as $row) {
            $map[(int) $row['course_id']] = (int) $row['lecturer_id'];
        }

        $cohortLevel = $this->database->select(
            'SELECT lca.cohort_id, lca.lecturer_id
             FROM `lecturer_course_assignments` lca
             JOIN `cohorts` c ON c.id = lca.cohort_id
             WHERE c.semester_id = :semester
               AND c.department_id = :department
             ORDER BY lca.is_primary DESC, lca.id',
            ['semester' => $semesterId, 'department' => $departmentId],
        );

        foreach ($cohortLevel as $row) {
            // First writer wins because the query is ordered is_primary DESC, so
            // a second, non-primary lecturer for the same cohort does not
            // silently take over the teaching load.
            $map[(int) $row['cohort_id']] ??= (int) $row['lecturer_id'];
        }

        return $map;
    }

    /**
     * HC-8. A row with a `day_of_week` and no `time_slot_id` blocks the whole
     * day; a row with a `time_slot_id` blocks that slot on every day it appears.
     * Both are expressible on a weekly grid. A row with only a `date` is not.
     *
     * @param list<string> $warnings
     *
     * @return list<array{lecturer_id:int, slot_id?:int|null, day_of_week?:int|null}>
     */
    private function lecturerUnavailability(int $semesterId, array &$warnings): array
    {
        $rows = $this->database->select(
            'SELECT la.lecturer_id, la.day_of_week, la.time_slot_id, la.date
             FROM `lecturer_availability` la
             WHERE la.semester_id = :semester
             ORDER BY la.id',
            ['semester' => $semesterId],
        );

        $entries = [];
        $oneOffs = 0;

        foreach ($rows as $row) {
            $slotId = $row['time_slot_id'] === null ? null : (int) $row['time_slot_id'];
            $dayOfWeek = $row['day_of_week'] === null ? null : (int) $row['day_of_week'];

            if ($row['date'] !== null && $slotId === null && $dayOfWeek === null) {
                $oneOffs++;
                continue;
            }

            $entry = ['lecturer_id' => (int) $row['lecturer_id']];
            if ($slotId !== null) {
                $entry['slot_id'] = $slotId;
            }
            if ($dayOfWeek !== null) {
                $entry['day_of_week'] = $dayOfWeek;
            }

            $entries[] = $entry;
        }

        if ($oneOffs > 0) {
            $warnings[] = sprintf(
                '%d lecturer availability row(s) are dated to a single day and were not applied. '
                . 'Cancel the affected week\'s allocation instead, or record a recurring block.',
                $oneOffs,
            );
        }

        return $entries;
    }

    // -----------------------------------------------------------------------
    // Warm start
    // -----------------------------------------------------------------------

    /**
     * The current week-1 timetable, mapped onto the same synthetic session ids
     * the new problem uses.
     *
     * MATCHING
     * A course with two meetings a week produces two allocation rows that share
     * a cohort and a course and differ only in slot, and `allocations` has no
     * `sequence` column. The rows are therefore ordered by `time_slot_id` and
     * matched to sequences in order, which is arbitrary but *deterministic* —
     * and determinism is what matters, because a warm start that reordered
     * itself between runs would make the churn term meaningless.
     *
     * @param list<array<string, mixed>> $cohorts
     * @param list<string>               $warnings
     *
     * @return list<Assignment>
     */
    private function existingAssignments(
        int $departmentId,
        int $semesterId,
        array $cohorts,
        array &$warnings,
    ): array {
        $rows = $this->database->select(
            'SELECT a.cohort_id, a.course_id, a.room_id, a.time_slot_id
             FROM `allocations` a
             WHERE a.department_id = :department
               AND a.semester_id = :semester
               AND a.week_number = 1
               AND a.status IN (\'proposed\', \'confirmed\', \'updated\')
             ORDER BY a.cohort_id, a.course_id, a.time_slot_id, a.id',
            ['department' => $departmentId, 'semester' => $semesterId],
        );

        $meetings = [];
        foreach ($cohorts as $cohort) {
            $meetings[(int) $cohort['cohort_id']] = max(1, min(7, (int) $cohort['meetings_per_week']));
        }

        $byCohort = [];
        foreach ($rows as $row) {
            $cohortId = (int) $row['cohort_id'];
            if (!isset($meetings[$cohortId])) {
                continue; // the cohort is no longer schedulable this run
            }

            $byCohort[$cohortId][] = $row;
        }

        $assignments = [];
        $orphans = 0;

        foreach ($byCohort as $cohortId => $cohortRows) {
            $expected = $meetings[$cohortId];

            foreach ($cohortRows as $index => $row) {
                if ($index >= $expected) {
                    $orphans++;
                    continue; // more rows than sittings: the extra is stale
                }

                $assignments[] = new Assignment(
                    sessionId: $this->sessionId($cohortId, $index),
                    roomId: (int) $row['room_id'],
                    timeSlotId: (int) $row['time_slot_id'],
                    cost: 0.0,
                    breakdown: null,
                    fromExisting: true,
                );
            }
        }

        if ($orphans > 0) {
            $warnings[] = sprintf(
                '%d existing allocation row(s) have no matching sitting — the course now meets fewer times '
                . 'a week than it did. They are not carried into this run; the previous timetable is superseded '
                . 'only if this run is applied.',
                $orphans,
            );
        }

        return $assignments;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
