<?php

declare(strict_types=1);

namespace App\Domain\Service;

use App\Core\Database;
use App\Core\Exception\NotFoundException;
use App\Core\Exception\ValidationException;
use App\Core\Identity;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Semesters, the weekly grid, and the days and people who are unavailable.
 */
final class CalendarService
{
    public function __construct(
        private readonly Database $database,
        private readonly AuditTrail $audit,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function semesters(Identity $identity, string $scope): array
    {
        $bindings = [];
        $where = '1 = 1' . $this->dept($identity, $scope, 'department_id', $bindings);
        if (!$identity->isAdmin()) {
            $where .= " AND `status` IN ('active','closed','archived')";
        }

        return $this->database->select(
            'SELECT * FROM `semesters` WHERE ' . $where . ' ORDER BY `start_date` DESC',
            $bindings,
        );
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function createSemester(Identity $actor, array $input): array
    {
        $departmentId = $actor->departmentId() ?? (int) ($input['department_id'] ?? 0);
        if ($departmentId < 1) {
            throw ValidationException::field('department_id', 'A department is required.');
        }
        $id = $this->database->insert('semesters', [
            'department_id'  => $departmentId,
            'name'           => trim((string) $input['name']),
            'academic_year'  => (string) $input['academic_year'],
            'start_date'     => (string) $input['start_date'],
            'end_date'       => (string) $input['end_date'],
            'teaching_start' => (string) ($input['teaching_start'] ?? $input['start_date']),
            'teaching_end'   => (string) ($input['teaching_end'] ?? $input['end_date']),
            'total_weeks'    => (int) ($input['total_weeks'] ?? 14),
            'status'         => (string) ($input['status'] ?? 'planning'),
        ]);
        $row = $this->semester($actor, 'department', $id);
        $this->audit->record($actor, 'semester.created', 'semester', $id, null, $row);

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    public function showSemester(Identity $identity, string $scope, int $id): array
    {
        return $this->semester($identity, $scope, $id);
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function updateSemester(Identity $actor, string $scope, int $id, array $input): array
    {
        $this->semester($actor, $scope, $id);
        $changes = [];
        $editable = [
            'name', 'academic_year', 'start_date', 'end_date',
            'teaching_start', 'teaching_end', 'total_weeks', 'status',
        ];
        foreach ($editable as $field) {
            if (array_key_exists($field, $input)) {
                $changes[$field] = $input[$field];
            }
        }
        if ($changes !== []) {
            $this->database->update('semesters', $changes, ['id' => $id]);
        }

        return $this->semester($actor, $scope, $id);
    }

    /**
     * @return array<string, mixed>
     */
    public function timeline(Identity $identity, string $scope, int $id): array
    {
        $semester = $this->semester($identity, $scope, $id);
        $exceptions = $this->exceptions($identity, $scope, $id);
        $byDate = [];
        foreach ($exceptions as $exception) {
            $byDate[(string) $exception['exception_date']] = $exception['label'];
        }

        $start = new DateTimeImmutable((string) $semester['teaching_start'], new DateTimeZone('UTC'));
        $weeks = [];
        $total = (int) $semester['total_weeks'];
        for ($number = 1; $number <= $total; $number++) {
            $weekStart = $start->modify('+' . (($number - 1) * 7) . ' days');
            $days = [];
            for ($offset = 0; $offset < 7; $offset++) {
                $date = $weekStart->modify('+' . $offset . ' days')->format('Y-m-d');
                $days[] = [
                    'date'  => $date,
                    'note'  => $byDate[$date] ?? null,
                ];
            }
            $weeks[] = [
                'week'  => $number,
                'start' => $weekStart->format('Y-m-d'),
                'end'   => $weekStart->modify('+6 days')->format('Y-m-d'),
                'days'  => $days,
            ];
        }

        return ['semester' => $semester, 'weeks' => $weeks];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function exceptions(Identity $identity, string $scope, int $semesterId): array
    {
        $this->semester($identity, $scope, $semesterId);

        return $this->database->select(
            'SELECT * FROM `calendar_exceptions` WHERE `semester_id` = :id ORDER BY `exception_date`',
            ['id' => $semesterId],
        );
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function addException(Identity $actor, string $scope, int $semesterId, array $input): array
    {
        $this->semester($actor, $scope, $semesterId);
        $id = $this->database->insert('calendar_exceptions', [
            'semester_id'    => $semesterId,
            'exception_date' => (string) $input['exception_date'],
            'type'           => (string) ($input['type'] ?? 'non_teaching'),
            'label'          => trim((string) $input['label']),
        ]);
        $row = $this->database->selectOne('SELECT * FROM `calendar_exceptions` WHERE `id` = :id', ['id' => $id]);
        $this->audit->record($actor, 'calendar.exception_added', 'semester', $semesterId, null, $row ?? []);

        return $row ?? [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function slots(Identity $identity): array
    {
        $bindings = [];
        $where = 'is_active = 1';
        if ($identity->departmentId() !== null) {
            $where .= ' AND (department_id = :dept OR department_id IS NULL)';
            $bindings['dept'] = $identity->departmentId();
        }

        return $this->database->select(
            'SELECT * FROM `time_slots` WHERE ' . $where . ' ORDER BY day_of_week, start_time',
            $bindings,
        );
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function createSlot(Identity $actor, array $input): array
    {
        $id = $this->database->insert('time_slots', [
            'department_id' => $actor->departmentId(),
            'label'         => trim((string) $input['label']),
            'day_of_week'   => (int) $input['day_of_week'],
            'start_time'    => (string) $input['start_time'],
            'end_time'      => (string) $input['end_time'],
            'sort_order'    => (int) ($input['sort_order'] ?? 0),
            'is_active'     => 1,
        ]);
        $row = $this->database->selectOne('SELECT * FROM `time_slots` WHERE `id` = :id', ['id' => $id]) ?? [];
        $this->audit->record($actor, 'slot.created', 'time_slot', $id, null, $row);

        return $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function lecturerAvailability(Identity $identity, ?int $semesterId): array
    {
        $bindings = [];
        $where = '1 = 1';
        if ($semesterId !== null) {
            $where .= ' AND a.semester_id = :semester';
            $bindings['semester'] = $semesterId;
        }
        if ($identity->hasRole('lecturer') && !$identity->isAdmin()) {
            $where .= ' AND a.lecturer_id = :lecturer';
            $bindings['lecturer'] = $identity->userId();
        }

        return $this->database->select(
            'SELECT a.* FROM `lecturer_availability` a WHERE ' . $where . ' ORDER BY a.id DESC',
            $bindings,
        );
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function storeLecturerAvailability(Identity $actor, array $input): array
    {
        $lecturerId = (int) ($input['lecturer_id'] ?? $actor->userId());
        if (!$actor->isAdmin()) {
            $lecturerId = $actor->userId();
        }
        $id = $this->database->insert('lecturer_availability', [
            'lecturer_id'  => $lecturerId,
            'semester_id'  => (int) $input['semester_id'],
            'day_of_week'  => $input['day_of_week'] ?? null,
            'time_slot_id' => $input['time_slot_id'] ?? null,
            'date'         => $input['date'] ?? null,
            'reason'       => $this->blank($input['reason'] ?? null),
        ]);

        return $this->database->selectOne(
            'SELECT * FROM `lecturer_availability` WHERE `id` = :id',
            ['id' => $id],
        ) ?? [];
    }

    public function deleteLecturerAvailability(Identity $actor, int $id): void
    {
        $row = $this->database->selectOne(
            'SELECT * FROM `lecturer_availability` WHERE `id` = :id',
            ['id' => $id],
        );
        if ($row === null) {
            throw new NotFoundException('Availability', $id);
        }
        if (!$actor->isAdmin() && (int) $row['lecturer_id'] !== $actor->userId()) {
            throw new NotFoundException('Availability', $id);
        }
        $this->database->delete('lecturer_availability', ['id' => $id]);
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function storeRoomUnavailability(Identity $actor, array $input): array
    {
        $id = $this->database->insert('room_unavailability', [
            'room_id'      => (int) $input['room_id'],
            'semester_id'  => (int) $input['semester_id'],
            'time_slot_id' => (int) $input['time_slot_id'],
            'date'         => $input['date'] ?? null,
            'reason'       => trim((string) $input['reason']),
        ]);
        $this->audit->record($actor, 'room.unavailable', 'room', (int) $input['room_id']);

        return $this->database->selectOne('SELECT * FROM `room_unavailability` WHERE `id` = :id', ['id' => $id]) ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    private function semester(Identity $identity, string $scope, int $id): array
    {
        $bindings = ['id' => $id];
        $where = 'id = :id' . $this->dept($identity, $scope, 'department_id', $bindings);
        $row = $this->database->selectOne('SELECT * FROM `semesters` WHERE ' . $where, $bindings);
        if ($row === null) {
            throw new NotFoundException('Semester', $id);
        }

        return $row;
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

    private function blank(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
