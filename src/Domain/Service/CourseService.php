<?php

declare(strict_types=1);

namespace App\Domain\Service;

use App\Core\Database;
use App\Core\Exception\ConflictException;
use App\Core\Exception\NotFoundException;
use App\Core\Identity;
use PDOException;

/**
 * Courses, the cohorts that take them, and who is enrolled.
 */
final class CourseService
{
    public function __construct(
        private readonly Database $database,
        private readonly AuditTrail $audit,
    ) {
    }

    /**
     * @param array<string, mixed> $query
     * @param array{page: int, per_page: int, offset: int} $page
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function list(Identity $identity, string $scope, array $query, array $page): array
    {
        $bindings = [];
        $where = '1 = 1' . $this->dept($identity, $scope, 'c.department_id', $bindings);
        $q = trim((string) ($query['q'] ?? ''));
        if ($q !== '') {
            $where .= ' AND (c.code LIKE :q OR c.title LIKE :q)';
            $bindings['q'] = '%' . $q . '%';
        }

        $total = (int) $this->database->scalar('SELECT COUNT(*) FROM `courses` c WHERE ' . $where, $bindings);
        $rows = $this->database->select(
            'SELECT c.* FROM `courses` c WHERE ' . $where . ' ORDER BY c.code
             LIMIT ' . $page['per_page'] . ' OFFSET ' . $page['offset'],
            $bindings,
        );

        return ['items' => array_map(fn (array $row): array => $this->presentCourse($row), $rows), 'total' => $total];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function create(Identity $actor, array $input): array
    {
        $departmentId = $this->departmentForWrite($actor, (int) ($input['department_id'] ?? 0));
        try {
            $id = $this->database->insert('courses', [
                'department_id'       => $departmentId,
                'code'                => strtoupper(trim((string) $input['code'])),
                'title'               => trim((string) $input['title']),
                'description'         => $this->blank($input['description'] ?? null),
                'credit_hours'        => $input['credit_hours'] ?? 3,
                'meetings_per_week'   => (int) ($input['meetings_per_week'] ?? 1),
                'duration_minutes'    => (int) ($input['duration_minutes'] ?? 120),
                'level'               => (int) ($input['level'] ?? 100),
                'default_lecturer_id' => $input['default_lecturer_id'] ?? null,
                'preferred_building'  => $this->blank($input['preferred_building'] ?? null),
                'is_active'           => 1,
            ]);
            $this->syncFeatures($id, $this->featureCodes($input['features'] ?? []));
        } catch (PDOException $exception) {
            if (($exception->errorInfo[1] ?? null) === 1062) {
                throw new ConflictException('A course with that code already exists in the department.');
            }

            throw $exception;
        }

        $course = $this->presentCourse($this->courseRow($actor, 'department', $id));
        $this->audit->record($actor, 'course.created', 'course', $id, null, $course);

        return $course;
    }

    /**
     * @return array<string, mixed>
     */
    public function show(Identity $identity, string $scope, int $id): array
    {
        return $this->presentCourse($this->courseRow($identity, $scope, $id));
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function update(Identity $actor, string $scope, int $id, array $input): array
    {
        $before = $this->presentCourse($this->courseRow($actor, $scope, $id));
        $changes = [];
        $editable = [
            'title', 'description', 'credit_hours', 'meetings_per_week', 'duration_minutes', 'level',
            'preferred_building', 'default_lecturer_id', 'is_active', 'code',
        ];
        foreach ($editable as $field) {
            if (array_key_exists($field, $input)) {
                $changes[$field] = $input[$field];
            }
        }
        if (isset($changes['code'])) {
            $changes['code'] = strtoupper(trim((string) $changes['code']));
        }
        if (isset($changes['is_active'])) {
            $changes['is_active'] = !empty($changes['is_active']) ? 1 : 0;
        }
        if ($changes !== []) {
            $this->database->update('courses', $changes, ['id' => $id]);
        }
        if (array_key_exists('features', $input)) {
            $this->syncFeatures($id, $this->featureCodes($input['features']));
        }
        $after = $this->presentCourse($this->courseRow($actor, $scope, $id));
        $this->audit->record($actor, 'course.updated', 'course', $id, $before, $after);

        return $after;
    }

    public function delete(Identity $actor, string $scope, int $id): void
    {
        $this->courseRow($actor, $scope, $id);
        $cohorts = (int) $this->database->scalar(
            'SELECT COUNT(*) FROM `cohorts` WHERE `course_id` = :id',
            ['id' => $id],
        );
        if ($cohorts > 0) {
            throw new ConflictException('This course still has cohorts. Remove those before deleting it.');
        }
        $this->database->delete('courses', ['id' => $id]);
        $this->audit->record($actor, 'course.deleted', 'course', $id);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function cohorts(Identity $identity, string $scope, int $courseId): array
    {
        $this->courseRow($identity, $scope, $courseId);
        $rows = $this->database->select(
            'SELECT * FROM `cohorts` WHERE `course_id` = :id ORDER BY `name`',
            ['id' => $courseId],
        );

        return array_map(fn (array $row): array => $this->presentCohort($row), $rows);
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function createCohort(Identity $actor, array $input): array
    {
        $course = $this->courseRow($actor, 'department', (int) $input['course_id']);
        try {
            $id = $this->database->insert('cohorts', [
                'department_id'  => (int) $course['department_id'],
                'course_id'      => (int) $course['id'],
                'semester_id'    => (int) $input['semester_id'],
                'name'           => trim((string) $input['name']),
                'enrolled_count' => 0,
                'capacity_slack' => (int) ($input['capacity_slack'] ?? 0),
            ]);
            if (isset($input['lecturer_id'])) {
                $this->database->insert('lecturer_course_assignments', [
                    'lecturer_id' => (int) $input['lecturer_id'],
                    'course_id'   => (int) $course['id'],
                    'cohort_id'   => $id,
                    'is_primary'  => 1,
                ]);
            }
        } catch (PDOException $exception) {
            if (($exception->errorInfo[1] ?? null) === 1062) {
                throw new ConflictException('A cohort with that name already exists this semester.');
            }

            throw $exception;
        }
        $cohort = $this->presentCohort($this->cohortRow($actor, 'department', $id));
        $this->audit->record($actor, 'cohort.created', 'cohort', $id, null, $cohort);

        return $cohort;
    }

    /**
     * @return array<string, mixed>
     */
    public function showCohort(Identity $identity, string $scope, int $id): array
    {
        return $this->presentCohort($this->cohortRow($identity, $scope, $id));
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function updateCohort(Identity $actor, string $scope, int $id, array $input): array
    {
        $this->cohortRow($actor, $scope, $id);
        $changes = [];
        foreach (['name', 'capacity_slack'] as $field) {
            if (array_key_exists($field, $input)) {
                $changes[$field] = $input[$field];
            }
        }
        if ($changes !== []) {
            $this->database->update('cohorts', $changes, ['id' => $id]);
        }

        return $this->presentCohort($this->cohortRow($actor, $scope, $id));
    }

    /**
     * @param list<int> $studentIds
     */
    public function enrol(Identity $actor, string $scope, int $cohortId, array $studentIds): int
    {
        $this->cohortRow($actor, $scope, $cohortId);
        $added = 0;
        $this->database->transaction(function () use ($cohortId, $studentIds, &$added): void {
            foreach ($studentIds as $studentId) {
                $existing = $this->database->selectOne(
                    'SELECT `status` FROM `enrollments` WHERE `cohort_id` = :cohort AND `student_id` = :student',
                    ['cohort' => $cohortId, 'student' => $studentId],
                );
                if ($existing === null) {
                    $this->database->insert('enrollments', [
                        'cohort_id'  => $cohortId,
                        'student_id' => $studentId,
                        'status'     => 'enrolled',
                    ]);
                    $added++;
                } elseif ($existing['status'] !== 'enrolled') {
                    $this->database->update('enrollments', ['status' => 'enrolled'], [
                        'cohort_id'  => $cohortId,
                        'student_id' => $studentId,
                    ]);
                    $added++;
                }
            }
            $this->recount($cohortId);
        });
        $this->audit->record($actor, 'cohort.enrolled', 'cohort', $cohortId, null, ['students' => $studentIds]);

        return $added;
    }

    public function unenrol(Identity $actor, string $scope, int $cohortId, int $userId): void
    {
        $this->cohortRow($actor, $scope, $cohortId);
        $this->database->transaction(function () use ($cohortId, $userId): void {
            $this->database->execute(
                'UPDATE `enrollments` SET `status` = \'dropped\''
                . ' WHERE `cohort_id` = :cohort AND `student_id` = :student',
                ['cohort' => $cohortId, 'student' => $userId],
            );
            $this->recount($cohortId);
        });
        $this->audit->record($actor, 'cohort.unenrolled', 'cohort', $cohortId, null, ['student_id' => $userId]);
    }

    private function recount(int $cohortId): void
    {
        $count = (int) $this->database->scalar(
            'SELECT COUNT(*) FROM `enrollments` WHERE `cohort_id` = :id AND `status` = \'enrolled\'',
            ['id' => $cohortId],
        );
        $this->database->update('cohorts', ['enrolled_count' => $count], ['id' => $cohortId]);
    }

    /**
     * @return array<string, mixed>
     */
    private function courseRow(Identity $identity, string $scope, int $id): array
    {
        $bindings = ['id' => $id];
        $where = 'c.id = :id' . $this->dept($identity, $scope, 'c.department_id', $bindings);
        $row = $this->database->selectOne('SELECT c.* FROM `courses` c WHERE ' . $where, $bindings);
        if ($row === null) {
            throw new NotFoundException('Course', $id);
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function cohortRow(Identity $identity, string $scope, int $id): array
    {
        $bindings = ['id' => $id];
        $where = 'id = :id' . $this->dept($identity, $scope, 'department_id', $bindings);
        $row = $this->database->selectOne('SELECT * FROM `cohorts` WHERE ' . $where, $bindings);
        if ($row === null) {
            throw new NotFoundException('Cohort', $id);
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function presentCourse(array $row): array
    {
        return [
            'id'                  => (int) $row['id'],
            'department_id'       => (int) $row['department_id'],
            'code'                => $row['code'],
            'title'               => $row['title'],
            'description'         => $row['description'],
            'credit_hours'        => $row['credit_hours'],
            'meetings_per_week'   => (int) $row['meetings_per_week'],
            'duration_minutes'    => (int) $row['duration_minutes'],
            'level'               => (int) $row['level'],
            'default_lecturer_id' => $row['default_lecturer_id'] === null ? null : (int) $row['default_lecturer_id'],
            'preferred_building'  => $row['preferred_building'],
            'is_active'           => (bool) $row['is_active'],
            'features'            => $this->featureCodesFor((int) $row['id']),
        ];
    }

    /**
     * @return list<string>
     */
    private function featureCodes(mixed $features): array
    {
        if (!is_array($features)) {
            return [];
        }
        $codes = [];
        foreach ($features as $feature) {
            if (is_string($feature) && trim($feature) !== '') {
                $codes[] = trim($feature);
            }
        }

        return $codes;
    }

    /**
     * @param list<string> $codes
     */
    private function syncFeatures(int $courseId, array $codes): void
    {
        $this->database->execute(
            'DELETE FROM `course_feature_requirements` WHERE `course_id` = :id',
            ['id' => $courseId],
        );
        foreach ($codes as $code) {
            $featureId = $this->database->scalar(
                'SELECT `id` FROM `room_features` WHERE `code` = :code',
                ['code' => $code],
            );
            if ($featureId === null) {
                continue;
            }
            $this->database->insert('course_feature_requirements', [
                'course_id'  => $courseId,
                'feature_id' => (int) $featureId,
                'mandatory'  => 1,
            ]);
        }
    }

    /**
     * @return list<string>
     */
    private function featureCodesFor(int $courseId): array
    {
        $rows = $this->database->select(
            'SELECT f.code FROM `course_feature_requirements` m
             JOIN `room_features` f ON f.id = m.feature_id
             WHERE m.course_id = :id ORDER BY f.code',
            ['id' => $courseId],
        );

        return array_map(static fn (array $row): string => (string) $row['code'], $rows);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function presentCohort(array $row): array
    {
        return [
            'id'             => (int) $row['id'],
            'department_id'  => (int) $row['department_id'],
            'course_id'      => (int) $row['course_id'],
            'semester_id'    => (int) $row['semester_id'],
            'name'           => $row['name'],
            'enrolled_count' => (int) $row['enrolled_count'],
            'capacity_slack' => (int) $row['capacity_slack'],
        ];
    }

    /**
     * @param array<string, mixed> $bindings
     */
    private function dept(Identity $identity, string $scope, string $column, array &$bindings): string
    {
        if ($scope === 'any' || ($identity->isAdmin() && $identity->departmentId() === null)) {
            return '';
        }
        if ($identity->departmentId() === null) {
            return '';
        }
        $bindings['scope_dept'] = $identity->departmentId();

        return ' AND ' . $column . ' = :scope_dept';
    }

    private function departmentForWrite(Identity $actor, int $requested): int
    {
        if ($actor->departmentId() !== null) {
            return $actor->departmentId();
        }
        if ($requested > 0) {
            return $requested;
        }
        $id = $this->database->scalar('SELECT `id` FROM `departments` ORDER BY `id` LIMIT 1');
        if ($id === null) {
            throw new NotFoundException('Department');
        }

        return (int) $id;
    }

    private function blank(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
