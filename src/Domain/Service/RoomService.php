<?php

declare(strict_types=1);

namespace App\Domain\Service;

use App\Core\Database;
use App\Core\Exception\ConflictException;
use App\Core\Exception\NotFoundException;
use App\Core\Identity;
use PDOException;

/**
 * Classrooms: the catalogue, live availability, and side-by-side comparison.
 */
final class RoomService
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
        [$where, $bindings] = $this->filters($identity, $scope, $query);
        $total = (int) $this->database->scalar('SELECT COUNT(*) FROM `rooms` r WHERE ' . $where, $bindings);
        $rows = $this->database->select(
            'SELECT r.* FROM `rooms` r WHERE ' . $where . ' ORDER BY r.building, r.code
             LIMIT ' . $page['per_page'] . ' OFFSET ' . $page['offset'],
            $bindings,
        );

        return ['items' => array_map(fn (array $row): array => $this->present($row), $rows), 'total' => $total];
    }

    /**
     * @param array<string, mixed> $input
     * @param list<string>         $features
     *
     * @return array<string, mixed>
     */
    public function create(Identity $actor, array $input, array $features): array
    {
        $departmentId = $this->departmentForWrite(
            $actor,
            isset($input['department_id']) ? (int) $input['department_id'] : null,
        );
        try {
            $id = $this->database->transaction(function () use ($input, $departmentId, $features): int {
                $id = $this->database->insert('rooms', [
                    'department_id' => $departmentId,
                    'code'          => strtoupper(trim((string) $input['code'])),
                    'name'          => trim((string) $input['name']),
                    'building'      => trim((string) $input['building']),
                    'floor'         => $input['floor'] ?? null,
                    'capacity'      => (int) $input['capacity'],
                    'room_type'     => (string) ($input['room_type'] ?? 'lecture'),
                    'status'        => (string) ($input['status'] ?? 'available'),
                    'is_bookable'   => !empty($input['is_bookable']) || !isset($input['is_bookable']) ? 1 : 0,
                    'notes'         => $this->blank($input['notes'] ?? null),
                ]);
                $this->syncFeatures($id, $features);

                return $id;
            });
        } catch (PDOException $exception) {
            if (($exception->errorInfo[1] ?? null) === 1062) {
                throw new ConflictException('A room with that code already exists.');
            }

            throw $exception;
        }

        $room = $this->present($this->row($actor, 'department', $id));
        $this->audit->record($actor, 'room.created', 'room', $id, null, $room);

        return $room;
    }

    /**
     * @return array<string, mixed>
     */
    public function show(Identity $identity, string $scope, int $id): array
    {
        return $this->present($this->row($identity, $scope, $id));
    }

    /**
     * @param array<string, mixed> $input
     * @param list<string>|null    $features
     *
     * @return array<string, mixed>
     */
    public function update(Identity $actor, string $scope, int $id, array $input, ?array $features): array
    {
        $before = $this->present($this->row($actor, $scope, $id));
        $changes = [];
        foreach (['name', 'building', 'floor', 'capacity', 'room_type', 'status', 'notes'] as $field) {
            if (array_key_exists($field, $input)) {
                $changes[$field] = $field === 'notes' ? $this->blank($input[$field]) : $input[$field];
            }
        }
        if (array_key_exists('is_bookable', $input)) {
            $changes['is_bookable'] = !empty($input['is_bookable']) ? 1 : 0;
        }
        if (array_key_exists('code', $input)) {
            $changes['code'] = strtoupper(trim((string) $input['code']));
        }

        $this->database->transaction(function () use ($id, $changes, $features): void {
            if ($changes !== []) {
                $this->database->update('rooms', $changes, ['id' => $id]);
            }
            if ($features !== null) {
                $this->syncFeatures($id, $features);
            }
        });

        $after = $this->present($this->row($actor, $scope, $id));
        $this->audit->record($actor, 'room.updated', 'room', $id, $before, $after);

        return $after;
    }

    public function delete(Identity $actor, string $scope, int $id): void
    {
        $this->row($actor, $scope, $id);
        $used = (int) $this->database->scalar(
            'SELECT COUNT(*) FROM `allocations` WHERE `room_id` = :id',
            ['id' => $id],
        );
        if ($used > 0) {
            throw new ConflictException(
                'This room is still referenced by the timetable, including cancelled sessions.'
                . ' Keep it, or mark it out of service.',
            );
        }

        $this->database->delete('rooms', ['id' => $id]);
        $this->audit->record($actor, 'room.deleted', 'room', $id);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function availability(Identity $identity, string $scope, int $id, int $semesterId, int $week): array
    {
        $this->row($identity, $scope, $id);
        $rows = $this->database->select(
            'SELECT ts.id, ts.label, ts.day_of_week, ts.start_time, ts.end_time,
                    a.id AS allocation_id, a.status
             FROM `time_slots` ts
             LEFT JOIN `allocations` a
               ON a.time_slot_id = ts.id AND a.room_id = :room AND a.semester_id = :semester
              AND a.week_number = :week AND a.status IN (\'proposed\',\'confirmed\',\'updated\')
             WHERE ts.is_active = 1
             ORDER BY ts.day_of_week, ts.start_time',
            ['room' => $id, 'semester' => $semesterId, 'week' => $week],
        );

        return array_map(static function (array $row): array {
            return [
                'time_slot_id' => (int) $row['id'],
                'label'        => $row['label'],
                'day_of_week'  => (int) $row['day_of_week'],
                'start_time'   => $row['start_time'],
                'end_time'     => $row['end_time'],
                'is_available' => $row['allocation_id'] === null,
                'allocation_id' => $row['allocation_id'] === null ? null : (int) $row['allocation_id'],
            ];
        }, $rows);
    }

    /**
     * @param list<int> $ids
     *
     * @return list<array<string, mixed>>
     */
    public function compare(Identity $identity, string $scope, array $ids, ?int $semesterId, ?int $week): array
    {
        $rooms = [];
        foreach ($ids as $id) {
            $room = $this->present($this->row($identity, $scope, $id));
            if ($semesterId !== null && $week !== null) {
                $free = 0;
                foreach ($this->availability($identity, $scope, $id, $semesterId, $week) as $slot) {
                    if ($slot['is_available']) {
                        $free++;
                    }
                }
                $room['free_slots'] = $free;
            }
            $rooms[] = $room;
        }

        return $rooms;
    }

    /**
     * @param list<string> $codes
     */
    private function syncFeatures(int $roomId, array $codes): void
    {
        $this->database->execute('DELETE FROM `room_feature_map` WHERE `room_id` = :id', ['id' => $roomId]);
        foreach ($codes as $code) {
            $featureId = $this->database->scalar(
                'SELECT `id` FROM `room_features` WHERE `code` = :code',
                ['code' => $code],
            );
            if ($featureId === null) {
                continue;
            }
            $this->database->insert('room_feature_map', [
                'room_id'    => $roomId,
                'feature_id' => (int) $featureId,
            ]);
        }
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function filters(Identity $identity, string $scope, array $query): array
    {
        $bindings = [];
        $where = '1 = 1';
        $where .= $this->departmentClause($identity, $scope, 'r.department_id', $bindings);
        $q = trim((string) ($query['q'] ?? ''));
        if ($q !== '') {
            $where .= ' AND (r.code LIKE :q OR r.name LIKE :q OR r.building LIKE :q)';
            $bindings['q'] = '%' . $q . '%';
        }
        $filters = [
            'building' => 'r.building',
            'room_type' => 'r.room_type',
            'status' => 'r.status',
        ];
        foreach ($filters as $key => $column) {
            if (isset($query[$key]) && is_string($query[$key]) && $query[$key] !== '') {
                $where .= ' AND ' . $column . ' = :' . $key;
                $bindings[$key] = $query[$key];
            }
        }
        if (isset($query['min_capacity']) && is_numeric($query['min_capacity'])) {
            $where .= ' AND r.capacity >= :min_capacity';
            $bindings['min_capacity'] = (int) $query['min_capacity'];
        }
        if (isset($query['max_capacity']) && is_numeric($query['max_capacity'])) {
            $where .= ' AND r.capacity <= :max_capacity';
            $bindings['max_capacity'] = (int) $query['max_capacity'];
        }
        if (isset($query['features']) && is_string($query['features']) && trim($query['features']) !== '') {
            $codes = array_values(array_filter(array_map('trim', explode(',', $query['features']))));
            foreach ($codes as $index => $code) {
                $key = 'feat_' . $index;
                $where .= ' AND EXISTS (
                    SELECT 1 FROM `room_feature_map` m
                    JOIN `room_features` f ON f.id = m.feature_id
                    WHERE m.room_id = r.id AND f.code = :' . $key . '
                )';
                $bindings[$key] = $code;
            }
        }

        return [$where, $bindings];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Identity $identity, string $scope, int $id): array
    {
        $bindings = ['id' => $id];
        $where = 'r.id = :id' . $this->departmentClause($identity, $scope, 'r.department_id', $bindings);
        $row = $this->database->selectOne('SELECT r.* FROM `rooms` r WHERE ' . $where, $bindings);
        if ($row === null) {
            throw new NotFoundException('Room', $id);
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function present(array $row): array
    {
        $features = $this->database->select(
            'SELECT f.code FROM `room_feature_map` m JOIN `room_features` f ON f.id = m.feature_id
             WHERE m.room_id = :id ORDER BY f.code',
            ['id' => (int) $row['id']],
        );

        return [
            'id'            => (int) $row['id'],
            'department_id' => $row['department_id'] === null ? null : (int) $row['department_id'],
            'code'          => $row['code'],
            'name'          => $row['name'],
            'building'      => $row['building'],
            'floor'         => $row['floor'] === null ? null : (int) $row['floor'],
            'capacity'      => (int) $row['capacity'],
            'room_type'     => $row['room_type'],
            'status'        => $row['status'],
            'is_bookable'   => (bool) $row['is_bookable'],
            'notes'         => $row['notes'],
            'features'      => array_map(static fn (array $feature): string => (string) $feature['code'], $features),
        ];
    }

    /**
     * @param array<string, mixed> $bindings
     */
    private function departmentClause(Identity $identity, string $scope, string $column, array &$bindings): string
    {
        if ($scope === 'any' || ($identity->isAdmin() && $identity->departmentId() === null)) {
            return '';
        }
        if ($identity->departmentId() === null) {
            return '';
        }

        $bindings['scope_dept'] = $identity->departmentId();

        return ' AND (' . $column . ' = :scope_dept OR ' . $column . ' IS NULL)';
    }

    private function departmentForWrite(Identity $actor, ?int $requested): ?int
    {
        if ($actor->departmentId() !== null) {
            return $actor->departmentId();
        }

        return $requested;
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
