<?php

declare(strict_types=1);

namespace App\Domain\Service;

use App\Core\Config;
use App\Core\Database;
use App\Core\Exception\ConflictException;
use App\Core\Exception\NotFoundException;
use App\Core\Exception\ValidationException;
use App\Core\Identity;
use App\Core\Logger;
use App\Core\WeightProfile;
use App\Domain\Allocation\AllocationEngine;
use App\Domain\Allocation\CandidateGenerator;
use App\Domain\Allocation\ConstraintChecker;
use App\Domain\Allocation\CostFunction;
use App\Domain\Allocation\EngineOptions;
use App\Domain\Allocation\Exception\InfeasibleProblemException;
use App\Domain\Allocation\SchedulingResult;
use App\Infrastructure\Persistence\Mysql\AllocationWriter;
use App\Infrastructure\Persistence\Mysql\LoadedProblem;
use App\Infrastructure\Persistence\Mysql\SchedulingProblemLoader;
use InvalidArgumentException;
use PDOException;
use RuntimeException;

/**
 * Published sessions, and the engine run that produces them.
 *
 * Generate and repair go through the same loader, engine and writer as
 * `bin/console generate`. A result below the accuracy gate, or one that still
 * contains a hard-constraint violation, is recorded as an advisory run and
 * does not replace the timetable.
 */
final class AllocationService
{
    private const ACTIVE = "('proposed','confirmed','updated')";

    public function __construct(
        private readonly Database $database,
        private readonly Logger $logger,
        private readonly Config $config,
        private readonly AuditTrail $audit,
        private readonly NotificationService $notifications,
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
        $total = (int) $this->database->scalar(
            'SELECT COUNT(*) ' . $this->fromSql() . ' WHERE ' . $where,
            $bindings,
        );
        $rows = $this->database->select(
            'SELECT a.*,
                c.name AS cohort_name, c.enrolled_count,
                co.code AS course_code, co.title AS course_title,
                u.first_name AS lecturer_first, u.last_name AS lecturer_last,
                r.code AS room_code, r.name AS room_name, r.building, r.capacity AS room_capacity,
                ts.label AS slot_label, ts.day_of_week, ts.start_time, ts.end_time '
            . $this->fromSql() . ' WHERE ' . $where . ' ORDER BY ts.day_of_week, ts.start_time, a.id
             LIMIT ' . $page['per_page'] . ' OFFSET ' . $page['offset'],
            $bindings,
        );

        return ['items' => array_map(fn (array $row): array => $this->present($row), $rows), 'total' => $total];
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
     *
     * @return array<string, mixed>
     */
    public function generate(Identity $actor, array $input): array
    {
        $departmentId = $this->departmentFor($actor, $input);
        $semesterId = (int) $input['semester_id'];
        $this->assertSemester($departmentId, $semesterId, !empty($input['apply']));

        $mode = (string) ($input['mode'] ?? 'full');
        if (!in_array($mode, ['full', 'repair'], true)) {
            throw ValidationException::field('mode', 'Mode must be full or repair.');
        }

        $seed = isset($input['seed']) ? (int) $input['seed'] : $this->config->int('ENGINE_SEED', 20260801);
        $iterations = isset($input['max_iterations'])
            ? (int) $input['max_iterations']
            : $this->config->int('ENGINE_MAX_ITERATIONS', 2000);
        $budget = isset($input['time_budget_seconds'])
            ? (float) $input['time_budget_seconds']
            : $this->config->float('ENGINE_TIME_BUDGET_SECONDS', 2.5);
        if ($iterations < 0) {
            throw ValidationException::field('max_iterations', 'Iterations cannot be negative.');
        }
        if ($budget <= 0 || $budget > 30) {
            throw ValidationException::field('time_budget_seconds', 'The time budget must be between 0 and 30 seconds.');
        }

        $profileName = (string) ($input['weight_profile'] ?? 'balanced');
        $profiles = WeightProfile::fromFile($this->config->basePath('config/weights.php'));
        try {
            $weights = $profiles->weights($profileName);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::field('weight_profile', $exception->getMessage());
        }

        $days = $this->days($input);
        $applyRequested = !empty($input['apply']);
        $gate = $this->config->float('ENGINE_ACCURACY_GATE', 0.90);
        $lockName = $applyRequested ? sprintf('catms_generate_%d_%d', $departmentId, $semesterId) : null;

        if ($lockName !== null && !$this->acquireLock($lockName)) {
            throw new ConflictException('Another generation is already running for this timetable.');
        }

        try {
            $loader = new SchedulingProblemLoader($this->database, $this->logger);
            $loaded = $loader->load($departmentId, $semesterId, $weights);
            if ($days !== []) {
                $loaded = $this->restrictToDays($loaded, $days);
            }

            $options = new EngineOptions(
                maxIterations: $iterations,
                stallLimit: 60,
                maxNeighbours: 40,
                randomSeed: $seed,
                timeBudgetSeconds: $budget,
                strict: !empty($input['strict']),
            );
            $checker = new ConstraintChecker($this->config->int('ENGINE_MAX_LECTURER_SESSIONS_PER_DAY', 4));
            $engine = new AllocationEngine($checker, new CandidateGenerator($checker), new CostFunction($weights));
            $warmStart = $mode === 'repair' ? $loaded->existingAssignments : [];
            $result = $engine->solve($loaded->problem, $warmStart, $options);

            $accuracy = (float) ($result->metrics['accuracy'] ?? 0.0);
            $belowGate = $accuracy < $gate && empty($input['force_accuracy']);
            $dayLimited = $days !== [];
            $slotLimit = $dayLimited ? $this->teachableSlotIds($loaded) : null;
            $willApply = $applyRequested && $result->violations === [] && !$belowGate;

            $writer = new AllocationWriter(
                $this->database,
                $this->logger,
                $this->config->get('ENGINE_VERSION', '1.0.0') ?? '1.0.0',
            );
            $metrics = [
                'weight_profile'       => $profileName,
                'weight_description'   => $profiles->description($profileName),
                'accuracy_gate'        => $gate,
                'accuracy_gate_passed' => $accuracy >= $gate,
                'days'                 => $days,
                'reason'               => $input['reason'] ?? null,
            ];

            try {
                $write = $willApply
                    ? $writer->apply($loaded, $result, $actor->userId(), $mode, 'proposed', $metrics, $slotLimit)
                    : $writer->recordAdvisory($loaded, $result, $actor->userId(), $mode, $metrics);
            } catch (RuntimeException $exception) {
                $write = $writer->recordAdvisory($loaded, $result, $actor->userId(), $mode, $metrics);
                $willApply = false;
                $this->logger->warning('Generation was recorded as advisory.', [
                    'reason' => $exception->getMessage(),
                ]);
            }

            $this->audit->record($actor, $willApply ? 'allocation.generated' : 'allocation.advisory', 'allocation_run', null, null, [
                'run_id'  => $write->runId,
                'applied' => $write->applied,
                'accuracy' => $write->accuracy,
            ]);
            if ($write->applied) {
                $this->notifyGenerated($loaded, $result);
            }

            return [
                'run'          => $this->runRow($write->runId, $actor, 'department'),
                'assignments'  => array_map(static fn ($assignment): array => $assignment->toArray(), $result->orderedAssignments()),
                'unallocated'  => array_map(static fn ($entry): array => $entry->toArray(), $result->unallocated),
                'violations'   => array_map(static fn ($violation): array => $violation->toArray(), $result->violations),
                'applied'      => $write->applied,
                'gate_blocked' => $applyRequested && $belowGate,
                'day_limited'  => $dayLimited,
            ];
        } catch (InfeasibleProblemException $exception) {
            throw $exception;
        } finally {
            if ($lockName !== null) {
                $this->releaseLock($lockName);
            }
        }
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function repair(Identity $actor, array $input): array
    {
        $input['mode'] = 'repair';

        return $this->generate($actor, $input);
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return list<array<string, mixed>>
     */
    public function conflicts(Identity $identity, string $scope, array $query): array
    {
        $bindings = [];
        $where = '1 = 1' . $this->departmentSql($identity, $scope, 'c.department_id', $bindings);
        if (isset($query['semester_id']) && is_numeric($query['semester_id'])) {
            $where .= ' AND c.semester_id = :semester';
            $bindings['semester'] = (int) $query['semester_id'];
        }
        if (($query['include_resolved'] ?? '') !== '1') {
            $where .= ' AND c.resolved_at IS NULL';
        }

        return $this->database->select(
            'SELECT c.* FROM `allocation_conflicts` c WHERE ' . $where . ' ORDER BY c.id DESC LIMIT 200',
            $bindings,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function resolveConflict(Identity $actor, string $scope, int $id, string $resolution): array
    {
        $bindings = ['id' => $id];
        $where = 'id = :id' . $this->departmentSql($actor, $scope, 'department_id', $bindings);
        $before = $this->database->selectOne('SELECT * FROM `allocation_conflicts` WHERE ' . $where, $bindings);
        if ($before === null) {
            throw new NotFoundException('Conflict', $id);
        }
        if ($before['resolved_at'] !== null) {
            return $before;
        }

        $this->database->execute(
            'UPDATE `allocation_conflicts` SET `resolved_at` = UTC_TIMESTAMP(), `resolved_by` = :actor WHERE `id` = :id',
            ['actor' => $actor->userId(), 'id' => $id],
        );
        $after = $this->database->selectOne('SELECT * FROM `allocation_conflicts` WHERE `id` = :id', ['id' => $id]) ?? [];
        $this->audit->record($actor, 'conflict.resolved', 'allocation_conflict', $id, null, [
            'resolution' => $resolution,
            'resolved_at' => $after['resolved_at'] ?? null,
        ]);

        return $after;
    }

    /**
     * @param array{page: int, per_page: int, offset: int} $page
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function runs(Identity $identity, string $scope, array $page): array
    {
        $bindings = [];
        $where = '1 = 1' . $this->departmentSql($identity, $scope, 'department_id', $bindings);
        $total = (int) $this->database->scalar('SELECT COUNT(*) FROM `allocation_runs` WHERE ' . $where, $bindings);
        $rows = $this->database->select(
            'SELECT * FROM `allocation_runs` WHERE ' . $where . ' ORDER BY `started_at` DESC
             LIMIT ' . $page['per_page'] . ' OFFSET ' . $page['offset'],
            $bindings,
        );

        return ['items' => array_map(fn (array $row): array => $this->presentRun($row), $rows), 'total' => $total];
    }

    /**
     * @return array<string, mixed>
     */
    public function run(Identity $identity, string $scope, string $runId): array
    {
        return $this->runRow($runId, $identity, $scope);
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function update(Identity $actor, string $scope, int $id, array $input): array
    {
        $before = $this->row($actor, $scope, $id);
        if ($before['status'] === 'cancelled') {
            throw new ConflictException('A cancelled session cannot be moved. Generate or create a new one.');
        }

        $roomId = isset($input['room_id']) ? (int) $input['room_id'] : (int) $before['room_id'];
        $slotId = isset($input['time_slot_id']) ? (int) $input['time_slot_id'] : (int) $before['time_slot_id'];
        $force = !empty($input['force']);
        $this->assertPlacement($before, $roomId, $slotId, $force);

        $changes = [
            'room_id'         => $roomId,
            'time_slot_id'    => $slotId,
            'status'          => 'updated',
            'source'          => 'override',
            'override_reason' => trim((string) $input['reason']),
            'overridden_by'   => $actor->userId(),
            'score_breakdown' => null,
        ];
        if ($roomId !== (int) $before['room_id']) {
            $changes['previous_room_id'] = (int) $before['room_id'];
        }

        try {
            $this->database->update('allocations', $changes, ['id' => $id]);
        } catch (PDOException $exception) {
            if (($exception->errorInfo[1] ?? null) === 1062) {
                throw new ConflictException('That room, lecturer, or cohort is already booked in this slot.');
            }

            throw $exception;
        }

        if ($force) {
            $this->database->insert('allocation_conflicts', [
                'run_id'          => $this->syntheticRunId(),
                'department_id'   => (int) $before['department_id'],
                'semester_id'     => (int) $before['semester_id'],
                'cohort_id'       => (int) $before['cohort_id'],
                'course_id'       => (int) $before['course_id'],
                'severity'        => 'warning',
                'constraint_code' => 'OVERRIDE',
                'message'         => 'An administrator forced this placement: ' . mb_substr(trim((string) $input['reason']), 0, 420),
            ]);
        }

        $after = $this->present($this->row($actor, $scope, $id));
        $this->audit->record($actor, 'allocation.override', 'allocation', $id, $this->present($before), $after);
        $this->notifications->fanOut(
            (int) $before['cohort_id'],
            'allocation.updated',
            'A class has moved',
            trim((string) $input['reason']),
            $id,
            'warning',
        );

        return $after;
    }

    /**
     * @return array<string, mixed>
     */
    public function confirm(Identity $actor, string $scope, int $id): array
    {
        $before = $this->row($actor, $scope, $id);
        if ($before['status'] === 'confirmed') {
            $body = $this->present($before);
            $body['already_confirmed'] = true;

            return $body;
        }
        if ($before['status'] === 'cancelled') {
            throw new ConflictException('A cancelled session cannot be confirmed.');
        }

        $this->database->update('allocations', ['status' => 'confirmed'], ['id' => $id]);
        $after = $this->present($this->row($actor, $scope, $id));
        $this->audit->record($actor, 'allocation.confirmed', 'allocation', $id, ['status' => $before['status']], $after);
        $this->notifications->fanOut(
            (int) $before['cohort_id'],
            'allocation.confirmed',
            'A class is confirmed',
            'Your session has been confirmed on the timetable.',
            $id,
        );

        return $after;
    }

    public function cancel(Identity $actor, string $scope, int $id, string $reason): void
    {
        $before = $this->row($actor, $scope, $id);
        if ($before['status'] === 'cancelled') {
            return;
        }

        $this->database->update('allocations', [
            'status'           => 'cancelled',
            'cancelled_reason' => $reason,
            'cancelled_at'     => gmdate('Y-m-d H:i:s'),
        ], ['id' => $id]);
        $this->audit->record($actor, 'allocation.cancelled', 'allocation', $id, ['status' => $before['status']], [
            'status' => 'cancelled',
            'reason' => $reason,
        ]);
        $this->notifications->fanOut(
            (int) $before['cohort_id'],
            'allocation.cancelled',
            'A class was cancelled',
            $reason,
            $id,
            'warning',
        );
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function reassign(Identity $actor, string $scope, int $id, array $input): array
    {
        $updated = $this->update($actor, $scope, $id, $input);
        if (empty($input['repair'])) {
            return ['allocation' => $updated, 'repair' => null];
        }

        $slot = $this->database->selectOne(
            'SELECT `day_of_week` FROM `time_slots` WHERE `id` = :id',
            ['id' => (int) $updated['time_slot']['id']],
        );
        $proposal = $this->repair($actor, [
            'semester_id' => (int) $updated['semester_id'],
            'apply'       => true,
            'reason'      => (string) $input['reason'],
            'scope'       => ['days' => [(int) ($slot['day_of_week'] ?? 1)]],
        ]);

        return ['allocation' => $updated, 'repair' => $proposal];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function showConflicts(Identity $identity, string $scope, int $id): array
    {
        $allocation = $this->row($identity, $scope, $id);

        return $this->database->select(
            'SELECT * FROM `allocation_conflicts`
             WHERE `cohort_id` = :cohort AND `course_id` = :course AND `semester_id` = :semester
             ORDER BY `id` DESC',
            [
                'cohort'   => (int) $allocation['cohort_id'],
                'course'   => (int) $allocation['course_id'],
                'semester' => (int) $allocation['semester_id'],
            ],
        );
    }

    /**
     * @param array<string, mixed> $query
     * @param array{page: int, per_page: int, offset: int} $page
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function search(Identity $identity, string $scope, array $query, array $page): array
    {
        $query['q'] = (string) ($query['q'] ?? '');

        return $this->list($identity, $scope, $query, $page);
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function filters(Identity $identity, string $scope, array $query): array
    {
        $bindings = [];
        $where = '1 = 1' . $this->viewerSql($identity, $scope, $bindings);
        if (($query['include_cancelled'] ?? '') !== '1' && ($query['include_cancelled'] ?? '') !== 'true') {
            $where .= ' AND a.status <> \'cancelled\'';
        }
        $q = trim((string) ($query['q'] ?? ''));
        if ($q !== '') {
            // Native prepares reject one named placeholder used more than once.
            $like = '%' . $q . '%';
            $columns = ['co.code', 'co.title', 'r.code', 'r.name', 'u.first_name', 'u.last_name', 'c.name'];
            $clauses = [];
            foreach ($columns as $index => $column) {
                $key = 'q' . $index;
                $clauses[] = $column . ' LIKE :' . $key;
                $bindings[$key] = $like;
            }
            $where .= ' AND (' . implode(' OR ', $clauses) . ')';
        }
        if (isset($query['day_of_week']) && is_numeric($query['day_of_week'])) {
            $where .= ' AND ts.day_of_week = :day_of_week';
            $bindings['day_of_week'] = (int) $query['day_of_week'];
        }
        foreach ([
            'semester_id' => 'a.semester_id',
            'cohort_id' => 'a.cohort_id',
            'course_id' => 'a.course_id',
            'lecturer_id' => 'a.lecturer_id',
            'room_id' => 'a.room_id',
            'time_slot_id' => 'a.time_slot_id',
            'week_number' => 'a.week_number',
        ] as $key => $column) {
            if (isset($query[$key]) && is_numeric($query[$key])) {
                $where .= ' AND ' . $column . ' = :' . $key;
                $bindings[$key] = (int) $query[$key];
            }
        }
        if (isset($query['status']) && is_string($query['status']) && $query['status'] !== '') {
            $where .= ' AND a.status = :status';
            $bindings['status'] = $query['status'];
        }
        if (isset($query['source']) && is_string($query['source']) && $query['source'] !== '') {
            $where .= ' AND a.source = :source';
            $bindings['source'] = $query['source'];
        }

        return [$where, $bindings];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Identity $identity, string $scope, int $id): array
    {
        $bindings = ['id' => $id];
        $where = 'a.id = :id' . $this->viewerSql($identity, $scope, $bindings);
        $row = $this->database->selectOne(
            'SELECT a.*,
                c.name AS cohort_name, c.enrolled_count,
                co.code AS course_code, co.title AS course_title,
                u.first_name AS lecturer_first, u.last_name AS lecturer_last,
                r.code AS room_code, r.name AS room_name, r.building, r.capacity AS room_capacity,
                ts.label AS slot_label, ts.day_of_week, ts.start_time, ts.end_time '
            . $this->fromSql() . ' WHERE ' . $where,
            $bindings,
        );
        if ($row === null) {
            throw new NotFoundException('Allocation', $id);
        }

        return $row;
    }

    private function fromSql(): string
    {
        return 'FROM `allocations` a
             JOIN `cohorts` c ON c.id = a.cohort_id
             JOIN `courses` co ON co.id = a.course_id
             JOIN `users` u ON u.id = a.lecturer_id
             JOIN `rooms` r ON r.id = a.room_id
             JOIN `time_slots` ts ON ts.id = a.time_slot_id';
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function present(array $row): array
    {
        $breakdown = $row['score_breakdown'] ?? null;
        if (is_string($breakdown) && $breakdown !== '') {
            $decoded = json_decode($breakdown, true);
            $breakdown = is_array($decoded) ? $decoded : null;
        }

        return [
            'id'               => (int) $row['id'],
            'semester_id'      => (int) $row['semester_id'],
            'department_id'    => (int) $row['department_id'],
            'week_number'      => (int) $row['week_number'],
            'effective_date'   => $row['effective_date'],
            'status'           => $row['status'],
            'source'           => $row['source'],
            'engine_score'     => $row['engine_score'] === null ? null : (float) $row['engine_score'],
            'score_breakdown'  => $breakdown,
            'override_reason'  => $row['override_reason'],
            'overridden_by'    => $row['overridden_by'] === null ? null : (int) $row['overridden_by'],
            'cancelled_reason' => $row['cancelled_reason'],
            'cancelled_at'     => $row['cancelled_at'],
            'previous_room_id' => $row['previous_room_id'] === null ? null : (int) $row['previous_room_id'],
            'notes'            => $row['notes'],
            'created_at'       => $row['created_at'],
            'updated_at'       => $row['updated_at'],
            'cohort'           => [
                'id'             => (int) $row['cohort_id'],
                'name'           => $row['cohort_name'],
                'enrolled_count' => (int) $row['enrolled_count'],
            ],
            'course'           => [
                'id'    => (int) $row['course_id'],
                'code'  => $row['course_code'],
                'title' => $row['course_title'],
            ],
            'lecturer'         => [
                'id'   => (int) $row['lecturer_id'],
                'name' => trim((string) $row['lecturer_first'] . ' ' . (string) $row['lecturer_last']),
            ],
            'room'             => [
                'id'       => (int) $row['room_id'],
                'code'     => $row['room_code'],
                'name'     => $row['room_name'],
                'building' => $row['building'],
                'capacity' => (int) $row['room_capacity'],
            ],
            'time_slot'        => [
                'id'          => (int) $row['time_slot_id'],
                'label'       => $row['slot_label'],
                'day_of_week' => (int) $row['day_of_week'],
                'start_time'  => $row['start_time'],
                'end_time'    => $row['end_time'],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $bindings
     */
    private function viewerSql(Identity $identity, string $scope, array &$bindings): string
    {
        if ($identity->hasRole('student') && !$identity->isAdmin()) {
            $bindings['viewer'] = $identity->userId();

            return ' AND a.cohort_id IN (
                SELECT e.cohort_id FROM `enrollments` e
                WHERE e.student_id = :viewer AND e.status = \'enrolled\'
            )';
        }
        if ($identity->hasRole('lecturer') && !$identity->isAdmin()) {
            $bindings['viewer'] = $identity->userId();

            return ' AND a.lecturer_id = :viewer';
        }

        return $this->departmentSql($identity, $scope, 'a.department_id', $bindings);
    }

    /**
     * @param array<string, mixed> $bindings
     */
    private function departmentSql(Identity $identity, string $scope, string $column, array &$bindings): string
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

    /**
     * @param array<string, mixed> $input
     */
    private function departmentFor(Identity $actor, array $input): int
    {
        if ($actor->departmentId() !== null) {
            return $actor->departmentId();
        }
        $requested = (int) ($input['department_id'] ?? 0);
        if ($requested < 1) {
            throw ValidationException::field('department_id', 'A department is required.');
        }

        return $requested;
    }

    private function assertSemester(int $departmentId, int $semesterId, bool $applying): void
    {
        $row = $this->database->selectOne(
            'SELECT `status` FROM `semesters` WHERE `id` = :id AND `department_id` = :department',
            ['id' => $semesterId, 'department' => $departmentId],
        );
        if ($row === null) {
            throw new NotFoundException('Semester', $semesterId);
        }
        if ($applying && in_array($row['status'], ['closed', 'archived'], true)) {
            throw new ConflictException('This semester is closed, so its timetable cannot be replaced.');
        }
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return list<int>
     */
    private function days(array $input): array
    {
        $scope = $input['scope'] ?? null;
        if (!is_array($scope)) {
            return [];
        }
        if (!empty($scope['cohort_ids']) || !empty($scope['room_ids'])) {
            throw ValidationException::field(
                'scope',
                'A run can be limited to days of the week (1 Monday through 7 Sunday).',
            );
        }
        if (!isset($scope['days']) || !is_array($scope['days'])) {
            return [];
        }
        $days = [];
        foreach ($scope['days'] as $day) {
            $number = (int) $day;
            if ($number < 1 || $number > 7) {
                throw ValidationException::field('scope.days', 'Days run from 1 (Monday) to 7 (Sunday).');
            }
            $days[] = $number;
        }

        return $days;
    }

    /**
     * @param list<int> $days
     */
    private function restrictToDays(LoadedProblem $loaded, array $days): LoadedProblem
    {
        $wanted = array_fill_keys($days, true);
        $slotIds = [];
        foreach ($loaded->problem->slots() as $slot) {
            if (isset($wanted[$slot->dayOfWeek()]) && $loaded->problem->isTeachable($slot)) {
                $slotIds[] = $slot->id();
            }
        }
        if ($slotIds === []) {
            throw ValidationException::field('scope.days', 'No teachable slot falls on the requested days.');
        }

        $sessionIds = [];
        foreach ($loaded->existingAssignments as $assignment) {
            $slot = $loaded->problem->slotById($assignment->timeSlotId());
            if ($slot !== null && isset($wanted[$slot->dayOfWeek()])) {
                $sessionIds[] = $assignment->sessionId();
            }
        }

        $problem = $loaded->problem->restrictedToSlots($slotIds);
        if ($loaded->existingAssignments !== [] && $sessionIds === []) {
            $problem = $problem->onlySessions([]);
        } elseif ($sessionIds !== []) {
            $problem = $problem->onlySessions($sessionIds);
        }

        $kept = [];
        foreach ($loaded->existingAssignments as $assignment) {
            if (in_array($assignment->sessionId(), $sessionIds, true)) {
                $kept[] = $assignment;
            }
        }

        return new LoadedProblem(
            problem: $problem,
            existingAssignments: $kept,
            departmentId: $loaded->departmentId,
            semesterId: $loaded->semesterId,
            semesterName: $loaded->semesterName,
            teachingStart: $loaded->teachingStart,
            teachingEnd: $loaded->teachingEnd,
            totalWeeks: $loaded->totalWeeks,
            warnings: $loaded->warnings,
            counts: $loaded->counts,
        );
    }

    /**
     * @param array<string, mixed> $allocation
     */
    private function assertPlacement(array $allocation, int $roomId, int $slotId, bool $force): void
    {
        $week = (int) $allocation['week_number'];
        $id = (int) $allocation['id'];
        $violations = [];

        $roomClash = $this->database->scalar(
            'SELECT `id` FROM `allocations`
             WHERE `room_id` = :room AND `time_slot_id` = :slot AND `week_number` = :week
               AND `status` IN ' . self::ACTIVE . ' AND `id` <> :id LIMIT 1',
            ['room' => $roomId, 'slot' => $slotId, 'week' => $week, 'id' => $id],
        );
        if ($roomClash !== null) {
            $violations[] = ['code' => 'HC-1', 'message' => 'That room is already booked in this slot.'];
        }

        $lecturerClash = $this->database->scalar(
            'SELECT `id` FROM `allocations`
             WHERE `lecturer_id` = :lecturer AND `time_slot_id` = :slot AND `week_number` = :week
               AND `status` IN ' . self::ACTIVE . ' AND `id` <> :id LIMIT 1',
            ['lecturer' => (int) $allocation['lecturer_id'], 'slot' => $slotId, 'week' => $week, 'id' => $id],
        );
        if ($lecturerClash !== null) {
            $violations[] = ['code' => 'HC-2', 'message' => 'The lecturer is already teaching in this slot.'];
        }

        $room = $this->database->selectOne(
            'SELECT `status` FROM `rooms` WHERE `id` = :id',
            ['id' => $roomId],
        );
        if ($room === null) {
            throw new NotFoundException('Room', $roomId);
        }
        if (in_array((string) $room['status'], ['maintenance', 'out_of_service'], true)) {
            $violations[] = ['code' => 'BR-06', 'message' => 'That room is not available for teaching.'];
        }

        $window = $this->database->selectOne(
            'SELECT `teaching_start`, `teaching_end` FROM `semesters` WHERE `id` = :id',
            ['id' => (int) $allocation['semester_id']],
        );
        $date = (string) ($allocation['effective_date'] ?? '');
        if ($window !== null && $date !== '' && ($date < (string) $window['teaching_start'] || $date > (string) $window['teaching_end'])) {
            $violations[] = ['code' => 'FR-CAL-04', 'message' => 'That date sits outside the teaching window.'];
        }

        $cohortClash = $this->database->scalar(
            'SELECT `id` FROM `allocations`
             WHERE `cohort_id` = :cohort AND `time_slot_id` = :slot AND `week_number` = :week
               AND `status` IN ' . self::ACTIVE . ' AND `id` <> :id LIMIT 1',
            ['cohort' => (int) $allocation['cohort_id'], 'slot' => $slotId, 'week' => $week, 'id' => $id],
        );
        if ($cohortClash !== null) {
            $violations[] = ['code' => 'HC-3', 'message' => 'The cohort already has a class in this slot.'];
        }

        $capacity = (int) $this->database->scalar('SELECT `capacity` FROM `rooms` WHERE `id` = :id', ['id' => $roomId]);
        $enrolled = (int) $allocation['enrolled_count'];
        $capacityViolation = null;
        if ($capacity > 0 && $capacity < $enrolled) {
            $capacityViolation = [
                'code'    => 'HC-4',
                'message' => sprintf('Room capacity %d is below the cohort enrolment of %d.', $capacity, $enrolled),
            ];
            if (!$force) {
                $violations[] = $capacityViolation;
            }
        }

        if ($violations !== []) {
            throw new ConflictException('The requested placement is not feasible.', 'CONFLICT', [
                'violations' => $violations,
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function runRow(string $runId, Identity $identity, string $scope): array
    {
        $bindings = ['id' => $runId];
        $where = 'id = :id' . $this->departmentSql($identity, $scope, 'department_id', $bindings);
        $row = $this->database->selectOne('SELECT * FROM `allocation_runs` WHERE ' . $where, $bindings);
        if ($row === null) {
            throw new NotFoundException('Allocation run', null);
        }

        return $this->presentRun($row);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function presentRun(array $row): array
    {
        $metrics = $row['metrics'] ?? null;
        if (is_string($metrics) && $metrics !== '') {
            $decoded = json_decode($metrics, true);
            $metrics = is_array($decoded) ? $decoded : null;
        }

        return [
            'id'                   => $row['id'],
            'department_id'        => (int) $row['department_id'],
            'semester_id'          => (int) $row['semester_id'],
            'mode'                 => $row['mode'],
            'random_seed'          => (int) $row['random_seed'],
            'engine_version'       => $row['engine_version'],
            'total_sessions'       => (int) $row['total_sessions'],
            'assigned_sessions'    => (int) $row['assigned_sessions'],
            'unallocated_sessions' => (int) $row['unallocated_sessions'],
            'accuracy'             => $row['accuracy'] === null ? null : (float) $row['accuracy'],
            'total_penalty'        => $row['total_penalty'] === null ? null : (float) $row['total_penalty'],
            'iterations'           => (int) $row['iterations'],
            'duration_ms'          => (int) $row['duration_ms'],
            'is_feasible'          => (bool) $row['is_feasible'],
            'is_applied'           => (bool) $row['is_applied'],
            'metrics'              => $metrics,
            'started_at'           => $row['started_at'],
            'finished_at'          => $row['finished_at'],
        ];
    }

    /**
     * @return list<int>
     */
    private function teachableSlotIds(LoadedProblem $loaded): array
    {
        $ids = [];
        foreach ($loaded->problem->slots() as $slot) {
            if ($loaded->problem->isTeachable($slot)) {
                $ids[] = $slot->id();
            }
        }

        return $ids;
    }

    private function notifyGenerated(LoadedProblem $loaded, SchedulingResult $result): void
    {
        $seen = [];
        foreach ($result->orderedAssignments() as $assignment) {
            $session = $loaded->problem->sessionById($assignment->sessionId());
            if ($session === null) {
                continue;
            }
            $cohortId = $session->cohortId();
            if (isset($seen[$cohortId])) {
                continue;
            }
            $seen[$cohortId] = true;
            $this->notifications->fanOut(
                $cohortId,
                'allocation.proposed',
                'Your timetable has been updated',
                'A class on your timetable has a room. Open the week view to see it.',
                null,
            );
        }
    }

    private function acquireLock(string $name): bool
    {
        $got = $this->database->scalar('SELECT GET_LOCK(:name, 0)', ['name' => $name]);

        return (int) $got === 1;
    }

    private function releaseLock(string $name): void
    {
        try {
            $this->database->execute('SELECT RELEASE_LOCK(:name)', ['name' => $name]);
        } catch (RuntimeException) {
            $this->logger->debug('Could not release the generation lock.', ['lock' => $name]);
        }
    }

    private function syntheticRunId(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
