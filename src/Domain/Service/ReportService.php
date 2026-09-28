<?php

declare(strict_types=1);

namespace App\Domain\Service;

use App\Core\Database;
use App\Core\Exception\ValidationException;
use App\Core\Identity;

/**
 * Department reports. The three analytical views do the aggregation; this
 * service only scopes them and shapes the rows.
 */
final class ReportService
{
    public function __construct(private readonly Database $database)
    {
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return list<array<string, mixed>>
     */
    public function utilisation(Identity $identity, string $scope, array $query): array
    {
        if (!isset($query['week_number'])) {
            $rolled = $this->rollup($identity, $scope, $query);
            if ($rolled !== []) {
                return $rolled;
            }
        }

        [$where, $bindings] = $this->viewFilter($identity, $scope, $query, true);

        return $this->database->select(
            'SELECT * FROM `v_room_utilisation` WHERE ' . $where . ' ORDER BY `room_code`, `day_of_week`',
            $bindings,
        );
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return list<array<string, mixed>>
     */
    private function rollup(Identity $identity, string $scope, array $query): array
    {
        $bindings = [];
        $where = '1 = 1' . $this->dept($identity, $scope, 'd.department_id', $bindings);
        if (isset($query['semester_id']) && is_numeric($query['semester_id'])) {
            $where .= ' AND d.semester_id = :semester';
            $bindings['semester'] = (int) $query['semester_id'];
        }

        return $this->database->select(
            'SELECT r.code AS room_code, r.name AS room_name, d.stat_date, d.booked_minutes,
                    d.available_minutes, d.utilisation_pct, d.department_id, d.semester_id
             FROM `room_utilisation_daily` d
             JOIN `rooms` r ON r.id = d.room_id
             WHERE ' . $where . ' ORDER BY d.stat_date, r.code',
            $bindings,
        );
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return list<array<string, mixed>>
     */
    public function peakUsage(Identity $identity, string $scope, array $query): array
    {
        [$where, $bindings] = $this->viewFilter($identity, $scope, $query, false);

        return $this->database->select(
            'SELECT * FROM `v_peak_usage` WHERE ' . $where . ' ORDER BY `day_of_week`, `start_hour`',
            $bindings,
        );
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return list<array<string, mixed>>
     */
    public function conflicts(Identity $identity, string $scope, array $query): array
    {
        $bindings = [];
        $where = '1 = 1' . $this->dept($identity, $scope, 'department_id', $bindings);
        if (isset($query['semester_id']) && is_numeric($query['semester_id'])) {
            $where .= ' AND `semester_id` = :semester';
            $bindings['semester'] = (int) $query['semester_id'];
        }
        if (($query['open'] ?? '1') !== '0') {
            $where .= ' AND `resolved_at` IS NULL';
        }

        return $this->database->select(
            'SELECT * FROM `allocation_conflicts` WHERE ' . $where . ' ORDER BY `created_at` DESC LIMIT 500',
            $bindings,
        );
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return list<array<string, mixed>>
     */
    public function lecturerLoad(Identity $identity, string $scope, array $query): array
    {
        [$where, $bindings] = $this->viewFilter($identity, $scope, $query, false);

        return $this->database->select(
            'SELECT * FROM `v_lecturer_load` WHERE ' . $where . ' ORDER BY `session_count` DESC',
            $bindings,
        );
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return array{header: list<string>, rows: list<list<mixed>>}
     */
    public function export(Identity $identity, string $scope, string $kind, array $query): array
    {
        $rows = match ($kind) {
            'utilisation'   => $this->utilisation($identity, $scope, $query),
            'peak'          => $this->peakUsage($identity, $scope, $query),
            'conflicts'     => $this->conflicts($identity, $scope, $query),
            'lecturer-load' => $this->lecturerLoad($identity, $scope, $query),
            default         => throw ValidationException::field(
                'report',
                'Choose utilisation, peak, conflicts, or lecturer-load.',
            ),
        };

        if ($rows === []) {
            return ['header' => ['empty'], 'rows' => []];
        }

        $header = array_keys($rows[0]);
        $body = [];
        foreach ($rows as $row) {
            $line = [];
            foreach ($header as $column) {
                $value = $row[$column];
                $line[] = is_scalar($value) || $value === null ? $value : json_encode($value);
            }
            $body[] = $line;
        }

        return ['header' => $header, 'rows' => $body];
    }

    /**
     * @return array<string, int>
     */
    public function dashboard(Identity $identity, string $scope): array
    {
        $bindings = [];
        $userWhere = '`deleted_at` IS NULL AND `status` = \'active\'' . $this->dept($identity, $scope, 'department_id', $bindings);
        $allocBindings = [];
        $allocWhere = "`status` = 'proposed'" . $this->dept($identity, $scope, 'department_id', $allocBindings);
        $conflictBindings = [];
        $conflictWhere = '`resolved_at` IS NULL' . $this->dept($identity, $scope, 'department_id', $conflictBindings);
        $roomBindings = [];
        $roomWhere = "`status` = 'available'" . $this->dept($identity, $scope, 'department_id', $roomBindings, true);

        return [
            'active_users'         => (int) $this->database->scalar('SELECT COUNT(*) FROM `users` WHERE ' . $userWhere, $bindings),
            'proposed_allocations' => (int) $this->database->scalar('SELECT COUNT(*) FROM `allocations` WHERE ' . $allocWhere, $allocBindings),
            'open_conflicts'       => (int) $this->database->scalar('SELECT COUNT(*) FROM `allocation_conflicts` WHERE ' . $conflictWhere, $conflictBindings),
            'available_rooms'      => (int) $this->database->scalar('SELECT COUNT(*) FROM `rooms` WHERE ' . $roomWhere, $roomBindings),
        ];
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return list<array<string, mixed>>
     */
    public function heatMap(Identity $identity, string $scope, array $query): array
    {
        return $this->peakUsage($identity, $scope, $query);
    }

    /**
     * @param array<string, mixed> $query
     * @param array{page: int, per_page: int, offset: int} $page
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function audit(array $query, array $page): array
    {
        $bindings = [];
        $where = '1 = 1';
        if (isset($query['action']) && is_string($query['action']) && $query['action'] !== '') {
            $where .= ' AND `action` = :action';
            $bindings['action'] = $query['action'];
        }
        if (isset($query['entity_type']) && is_string($query['entity_type']) && $query['entity_type'] !== '') {
            $where .= ' AND `entity_type` = :entity_type';
            $bindings['entity_type'] = $query['entity_type'];
        }

        $total = (int) $this->database->scalar('SELECT COUNT(*) FROM `audit_log` WHERE ' . $where, $bindings);
        $rows = $this->database->select(
            'SELECT `id`, `actor_id`, `actor_role`, `action`, `entity_type`, `entity_id`,
                    `before_state`, `after_state`, `created_at`
             FROM `audit_log` WHERE ' . $where . ' ORDER BY `id` DESC
             LIMIT ' . $page['per_page'] . ' OFFSET ' . $page['offset'],
            $bindings,
        );

        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['actor_id'] = $row['actor_id'] === null ? null : (int) $row['actor_id'];
            $row['entity_id'] = $row['entity_id'] === null ? null : (int) $row['entity_id'];
            $row['before_state'] = $this->json($row['before_state']);
            $row['after_state'] = $this->json($row['after_state']);
        }
        unset($row);

        return ['items' => $rows, 'total' => $total];
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function viewFilter(Identity $identity, string $scope, array $query, bool $withWeek): array
    {
        $bindings = [];
        $where = '1 = 1' . $this->dept($identity, $scope, 'department_id', $bindings);
        if (isset($query['semester_id']) && is_numeric($query['semester_id'])) {
            $where .= ' AND `semester_id` = :semester';
            $bindings['semester'] = (int) $query['semester_id'];
        }
        if ($withWeek && isset($query['week_number']) && is_numeric($query['week_number'])) {
            $where .= ' AND `week_number` = :week';
            $bindings['week'] = (int) $query['week_number'];
        }

        return [$where, $bindings];
    }

    /**
     * @param array<string, mixed> $bindings
     */
    private function dept(Identity $identity, string $scope, string $column, array &$bindings, bool $includeShared = false): string
    {
        if ($scope === 'any' || ($identity->isAdmin() && $identity->departmentId() === null)) {
            return '';
        }
        if ($identity->departmentId() === null) {
            return '';
        }
        $bindings['scope_dept'] = $identity->departmentId();
        if ($includeShared) {
            return ' AND (`' . $column . '` = :scope_dept OR `' . $column . '` IS NULL)';
        }

        return ' AND `' . $column . '` = :scope_dept';
    }

    private function json(mixed $value): mixed
    {
        if (!is_string($value) || $value === '') {
            return $value;
        }
        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : $value;
    }
}
