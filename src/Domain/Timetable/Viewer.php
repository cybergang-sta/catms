<?php

declare(strict_types=1);

namespace App\Domain\Timetable;

/**
 * The set of allocations one caller is allowed to see, expressed once.
 *
 * A VIEWER, NOT A FILTER STRING
 * The obvious implementation of "only show a student their own timetable" is to
 * interpolate a role name into a `WHERE` clause. That is how a timetable endpoint
 * ends up showing a student somebody else's classes: the filter is written
 * correctly in nine places and missed in the tenth, and the miss is a data leak
 * that looks exactly like a working endpoint.
 *
 * So visibility is decided here, in one place, and every query in
 * `TimetableService` is built by the same {@see self::apply()} method with the
 * same fragment and the same bindings. A query that forgets to call it does not
 * silently return too much — it returns nothing, because {@see TimetableService}
 * treats an unset viewer as a closed door.
 *
 * THE RULES
 *   student  — the cohorts they are actively enrolled in
 *   lecturer — the sittings they are allocated to teach
 *   admin    — the whole of their department
 *   any      — a system administrator with no department: everything
 *
 * `any` is only reachable by an identity whose `departmentId()` is null, which
 * the seeder and the admin console are the only things that create.
 */
final class Viewer
{
    /**
     * @param list<int> $cohortIds  Non-empty only for a student; empty for every
     *                              other role, and empty for a student who is not
     *                              enrolled in anything yet.
     * @param int|null  $lecturerId Non-null only for a lecturer.
     */
    private function __construct(
        public readonly string $role,
        public readonly int $userId,
        public readonly ?int $departmentId,
        public readonly array $cohortIds = [],
        public readonly ?int $lecturerId = null,
    ) {
    }

    public static function student(int $userId, ?int $departmentId, array $cohortIds): self
    {
        return new self('student', $userId, $departmentId, array_values(array_unique($cohortIds)), null);
    }

    public static function lecturer(int $userId, ?int $departmentId): self
    {
        return new self('lecturer', $userId, $departmentId, [], $userId);
    }

    public static function admin(int $userId, ?int $departmentId): self
    {
        return new self('admin', $userId, $departmentId, [], null);
    }

    /**
     * A system administrator with no department: sees every department.
     */
    public static function system(int $userId): self
    {
        return new self('any', $userId, null, [], null);
    }

    /**
     * True when this viewer can see every department's timetable.
     */
    public function seesEverything(): bool
    {
        return $this->role === 'any';
    }

    /**
     * The `WHERE` fragment and its bindings, appended to an allocation query.
     *
     * `a` is the required alias for `allocations` in every query that uses this.
     * A student with no enrolments gets a fragment that matches nothing rather
     * than one that is omitted — "I am enrolled in nothing" and "do not filter"
     * must not look the same to a query builder.
     *
     * @return array{sql: string, bindings: array<string, int>}
     */
    public function apply(): array
    {
        if ($this->seesEverything()) {
            return ['sql' => '', 'bindings' => []];
        }

        if ($this->role === 'student') {
            if ($this->cohortIds === []) {
                return ['sql' => ' AND 1 = 0', 'bindings' => []];
            }

            $placeholders = [];
            $bindings = [];
            foreach ($this->cohortIds as $index => $cohortId) {
                $name = 'cohort' . $index;
                $placeholders[] = ':' . $name;
                $bindings[$name] = $cohortId;
            }

            return [
                'sql'      => ' AND a.`cohort_id` IN (' . implode(', ', $placeholders) . ')',
                'bindings' => $bindings,
            ];
        }

        if ($this->role === 'lecturer' && $this->lecturerId !== null) {
            return [
                'sql'      => ' AND a.`lecturer_id` = :viewer_lecturer',
                'bindings' => ['viewer_lecturer' => $this->lecturerId],
            ];
        }

        // An admin with a department is bounded by it. An admin with no
        // department is `any` and returned above, so reaching here with a null
        // department is a case that must not widen the result set.
        if ($this->departmentId !== null) {
            return [
                'sql'      => ' AND a.`department_id` = :viewer_department',
                'bindings' => ['viewer_department' => $this->departmentId],
            ];
        }

        return ['sql' => ' AND 1 = 0', 'bindings' => []];
    }

    /**
     * The scope label echoed back in `meta`, so a client can tell what it was
     * given without inferring it from the row count.
     */
    public function label(): string
    {
        return match ($this->role) {
            'student' => 'own:cohort',
            'lecturer' => 'own:lecturer',
            'admin'   => 'department',
            default   => 'any',
        };
    }
}
