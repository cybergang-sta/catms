<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Mysql;

use App\Core\Database;
use App\Core\Logger;
use App\Domain\Allocation\SchedulingProblem;
use App\Domain\Allocation\SchedulingResult;
use RuntimeException;

/**
 * Persists a `SchedulingResult`.
 *
 * TWO MODES, DELIBERATELY
 *  - `recordAdvisory()` writes the run and its conflicts and touches no
 *    allocation. This is what the Saturday 02:30 job runs
 *    (docs/DEPLOYMENT.md §10): an unattended process must not be able to
 *    replace a timetable a human approved.
 *  - `apply()` additionally supersedes the current timetable and writes the new
 *    one, in one transaction.
 *
 * WHY SUPERSEDE RATHER THAN UPSERT
 * The three unique keys on `allocations` (ADR-006) are `(room_id, time_slot_id,
 * week_number, active_guard)` and its two siblings, where `active_guard` is a
 * generated column that is NULL for anything not `proposed`/`confirmed`/
 * `updated`. So cancelling the old rows frees the keys without deleting them:
 * history is kept, the index still cannot be violated, and a rollback is a
 * status change rather than a restore-from-dump. Deleting them would be
 * simpler and would destroy the audit trail the schema was designed to preserve.
 *
 * WHY A RESULT WITH VIOLATIONS IS NEVER APPLIED
 * A violation means Phase 3 re-checked the answer and found a hard constraint
 * broken. That is an internal invariant failure, not a scheduling outcome, and
 * committing it would put a double booking into the database. `apply()` refuses.
 * The engine's own guarantee says every session appears in exactly one of
 * `assignments`/`unallocated`, so there is no third possibility to handle.
 */
final class AllocationWriter
{
    public function __construct(
        private readonly Database $database,
        private readonly Logger $logger,
        private readonly string $engineVersion = '1.0.0',
    ) {
    }

    /**
     * Record the run without touching the published timetable.
     *
     * @param array<string, mixed> $metricsExtra Merged into `allocation_runs.metrics`,
     *                                           e.g. the weight profile used.
     */
    public function recordAdvisory(
        LoadedProblem $loaded,
        SchedulingResult $result,
        ?int $triggeredBy = null,
        string $mode = 'full',
        array $metricsExtra = [],
    ): AllocationWriteResult {
        return $this->write($loaded, $result, $triggeredBy, $mode, false, 'proposed', $metricsExtra);
    }

    /**
     * Replace the published timetable with this result, atomically.
     *
     * @param string                $status  `proposed` for an administrator to
     *                                       review, `confirmed` when the
     *                                       decision has already been taken.
     * @param array<string, mixed>  $metricsExtra
     * @param list<int>|null        $limitToSlotIds
     *
     * @throws RuntimeException when the result violates a hard constraint
     */
    public function apply(
        LoadedProblem $loaded,
        SchedulingResult $result,
        ?int $triggeredBy = null,
        string $mode = 'full',
        string $status = 'proposed',
        array $metricsExtra = [],
        ?array $limitToSlotIds = null,
    ): AllocationWriteResult {
        if ($result->violations !== []) {
            $first = $result->violations[0];

            throw new RuntimeException(sprintf(
                'Refusing to apply run for %s: %d hard-constraint violation(s) survived verification, '
                . 'starting with [%s] %s. This is an engine defect, not a scheduling outcome.',
                $loaded->semesterName,
                \count($result->violations),
                (string) $first->code,
                (string) $first->message,
            ));
        }

        return $this->write($loaded, $result, $triggeredBy, $mode, true, $status, $metricsExtra, $limitToSlotIds);
    }

    /**
     * @param array<string, mixed> $metricsExtra
     * @param list<int>|null       $limitToSlotIds
     */
    private function write(
        LoadedProblem $loaded,
        SchedulingResult $result,
        ?int $triggeredBy,
        string $mode,
        bool $apply,
        string $status,
        array $metricsExtra,
        ?array $limitToSlotIds = null,
    ): AllocationWriteResult {
        $runId = $this->uuid4();
        $problem = $loaded->problem;

        /** @var array{0:int,1:int,2:int,3:int,4:float} $written */
        $written = $this->database->transaction(
            function (Database $database) use (
                $loaded,
                $result,
                $problem,
                $runId,
                $triggeredBy,
                $mode,
                $apply,
                $status,
                $metricsExtra,
                $limitToSlotIds,
            ): array {
                $superseded = 0;
                $previousRoom = [];

                if ($apply) {
                    // Read the old timetable before cancelling it, so the new rows
                    // can point at the room they came from. `previous_room_id` is
                    // what makes the churn and movement reports answerable after
                    // the fact.
                    foreach ($this->activeRows($loaded, $limitToSlotIds) as $row) {
                        $previousRoom[sprintf(
                            '%d|%d|%d',
                            (int) $row['cohort_id'],
                            (int) $row['course_id'],
                            (int) $row['time_slot_id'],
                        )] = (int) $row['room_id'];
                    }

                    $superseded = $this->supersede($loaded, $runId, $limitToSlotIds);
                }

                $allocations = $apply
                    ? $this->insertAllocations($loaded, $problem, $result, $runId, $status, $previousRoom)
                    : 0;

                $conflicts = $this->insertConflicts($loaded, $result, $runId);

                $this->insertRun(
                    $loaded,
                    $result,
                    $runId,
                    $triggeredBy,
                    $mode,
                    $apply,
                    $metricsExtra,
                );

                return [$allocations, $conflicts, $superseded, \count($result->violations), $this->accuracy($result)];
            }
        );

        $this->logger->info('Allocation run recorded.', [
            'run_id'      => $runId,
            'applied'     => $apply,
            'allocations' => $written[0],
            'conflicts'   => $written[1],
            'accuracy'    => $written[4],
        ]);

        return new AllocationWriteResult(
            runId: $runId,
            applied: $apply,
            allocationsWritten: $written[0],
            conflictsWritten: $written[1],
            supersededRows: $written[2],
            violationsRejected: $written[3],
            accuracy: $written[4],
            durationMs: (int) ($result->metrics['duration_ms'] ?? 0),
        );
    }

    // -----------------------------------------------------------------------
    // Timetable
    // -----------------------------------------------------------------------

    /**
     * @param list<int>|null $slotIds
     *
     * @return list<array<string, mixed>>
     */
    private function activeRows(LoadedProblem $loaded, ?array $slotIds = null): array
    {
        $bindings = ['department' => $loaded->departmentId, 'semester' => $loaded->semesterId];
        $slots = $this->slotClause($slotIds, $bindings);

        return $this->database->select(
            'SELECT cohort_id, course_id, room_id, time_slot_id
             FROM `allocations`
             WHERE department_id = :department
               AND semester_id = :semester
               AND status IN (\'proposed\', \'confirmed\', \'updated\')' . $slots,
            $bindings,
        );
    }

    /**
     * Cancel the published rows and clear the open conflicts from earlier
     * advisory runs.
     *
     * The conflicts are resolved rather than deleted: the same reasoning as the
     * allocations — an administrator asking "was this ever reported, and what
     * happened to it?" must be able to find out.
     *
     * @param list<int>|null $slotIds
     *
     * @return int rows cancelled
     */
    private function supersede(LoadedProblem $loaded, string $runId, ?array $slotIds = null): int
    {
        $bindings = [
            'reason'     => sprintf('Superseded by generation %s', $runId),
            'department' => $loaded->departmentId,
            'semester'   => $loaded->semesterId,
        ];
        $slots = $this->slotClause($slotIds, $bindings);
        $cancelled = $this->database->execute(
            'UPDATE `allocations`
             SET status = \'cancelled\',
                 cancelled_at = UTC_TIMESTAMP(),
                 cancelled_reason = :reason
             WHERE department_id = :department
               AND semester_id = :semester
               AND status IN (\'proposed\', \'confirmed\', \'updated\')' . $slots,
            $bindings,
        );

        if ($slotIds !== null) {
            return $cancelled;
        }

        $this->database->execute(
            'UPDATE `allocation_runs` SET is_applied = 0
             WHERE department_id = :department AND semester_id = :semester AND is_applied = 1',
            ['department' => $loaded->departmentId, 'semester' => $loaded->semesterId],
        );

        $this->database->execute(
            'UPDATE `allocation_conflicts` SET resolved_at = UTC_TIMESTAMP()
             WHERE department_id = :department AND semester_id = :semester AND resolved_at IS NULL',
            ['department' => $loaded->departmentId, 'semester' => $loaded->semesterId],
        );

        return $cancelled;
    }

    /**
     * @param list<int>|null       $slotIds
     * @param array<string, mixed> $bindings
     */
    private function slotClause(?array $slotIds, array &$bindings): string
    {
        if ($slotIds === null) {
            return '';
        }

        $marks = [];
        foreach (array_values($slotIds) as $index => $id) {
            $key = 'limit_slot_' . $index;
            $marks[] = ':' . $key;
            $bindings[$key] = $id;
        }

        if ($marks === []) {
            return ' AND 1 = 0';
        }

        return ' AND time_slot_id IN (' . implode(', ', $marks) . ')';
    }

    /**
     * One row per placement, all in week 1.
     *
     * The engine produces a weekly *pattern*; `week_number = 1` is that pattern
     * and a specific week's exception is expressed by cancelling that week's
     * row. Writing fourteen identical rows instead would multiply the write cost
     * by the semester length and make "the class was cancelled that week"
     * indistinguishable from "the class was cancelled".
     *
     * Individual INSERTs rather than one multi-row statement: at the scale this
     * runs (hundreds of sessions) the difference is tens of milliseconds inside
     * a transaction that is already atomic, and per-row inserts keep a duplicate
     * key failure pointing at exactly one placement.
     *
     * @param array<string, int> $previousRoom
     */
    private function insertAllocations(
        LoadedProblem $loaded,
        SchedulingProblem $problem,
        SchedulingResult $result,
        string $runId,
        string $status,
        array $previousRoom,
    ): int {
        $effectiveDate = $loaded->weekStart(1);
        $written = 0;

        foreach ($result->orderedAssignments() as $assignment) {
            $session = $problem->sessionById($assignment->sessionId());
            if ($session === null) {
                // Cannot happen while the result came from this problem, and if it
                // ever did, skipping is strictly better than writing a row with a
                // zero cohort id.
                $this->logger->error('Assignment references an unknown session; skipped.', [
                    'run_id'     => $runId,
                    'session_id' => $assignment->sessionId(),
                ]);
                continue;
            }

            $breakdown = $assignment->breakdown();

            $this->database->insert('allocations', [
                'department_id'   => $loaded->departmentId,
                'semester_id'     => $loaded->semesterId,
                'cohort_id'       => $session->cohortId(),
                'course_id'       => $session->courseId(),
                'lecturer_id'     => $session->lecturerId(),
                'room_id'         => $assignment->roomId(),
                'time_slot_id'    => $assignment->timeSlotId(),
                'week_number'     => 1,
                'effective_date'  => $effectiveDate,
                'status'          => $status,
                'source'          => 'auto',
                'engine_score'    => round($assignment->cost(), 4),
                'score_breakdown' => $breakdown === null || $breakdown === []
                    ? null
                    : json_encode($breakdown, JSON_UNESCAPED_SLASHES),
                'previous_room_id' => $previousRoom[sprintf(
                    '%d|%d|%d',
                    $session->cohortId(),
                    $session->courseId(),
                    $assignment->timeSlotId(),
                )] ?? null,
            ]);

            $written++;
        }

        return $written;
    }

    // -----------------------------------------------------------------------
    // Conflicts
    // -----------------------------------------------------------------------

    /**
     * FR-ALLOC-05: everything the engine could not place, and every violation it
     * found, written down with the reason.
     *
     * On an advisory run these rows are the *only* output, which is the whole
     * point — the run is a report.
     */
    private function insertConflicts(
        LoadedProblem $loaded,
        SchedulingResult $result,
        string $runId,
    ): int {
        $written = 0;

        foreach ($result->unallocated as $entry) {
            $this->database->insert('allocation_conflicts', [
                'run_id'          => $runId,
                'department_id'   => $loaded->departmentId,
                'semester_id'     => $loaded->semesterId,
                'cohort_id'       => $entry->cohortId,
                'course_id'       => $entry->courseId,
                'severity'        => 'error',
                'constraint_code' => $entry->primaryConstraint() ?? 'UNPLACED',
                // VARCHAR(500): the summary is prose from the engine, and a
                // truncated explanation is still an explanation while a rejected
                // INSERT is a failed run.
                'message'         => mb_substr($entry->summary, 0, 500),
                'details'         => json_encode(
                    [
                        'session_id'              => $entry->sessionId,
                        'considered_combinations' => $entry->consideredCombinations,
                        'blocking_constraints'    => $entry->blockingConstraints,
                    ],
                    JSON_UNESCAPED_SLASHES,
                ),
            ]);

            $written++;
        }

        foreach ($result->violations as $violation) {
            $this->database->insert('allocation_conflicts', [
                'run_id'          => $runId,
                'department_id'   => $loaded->departmentId,
                'semester_id'     => $loaded->semesterId,
                'cohort_id'       => $this->cohortForSession($loaded, $violation->sessionId),
                'course_id'       => $this->courseForSession($loaded, $violation->sessionId),
                'severity'        => 'error',
                'constraint_code' => mb_substr($violation->code, 0, 20),
                'message'         => mb_substr($violation->message, 0, 500),
                'details'         => json_encode($violation->toArray(), JSON_UNESCAPED_SLASHES),
            ]);

            $written++;
        }

        if ($written === 0) {
            $this->logger->debug('Run placed every session; no conflicts to record.', [
                'run_id' => $runId,
            ]);
        }

        return $written;
    }

    // -----------------------------------------------------------------------
    // Run header
    // -----------------------------------------------------------------------

    /**
     * @param array<string, mixed> $metricsExtra
     */
    private function insertRun(
        LoadedProblem $loaded,
        SchedulingResult $result,
        string $runId,
        ?int $triggeredBy,
        string $mode,
        bool $apply,
        array $metricsExtra,
    ): void {
        $metrics = $result->metrics;
        $durationMs = (int) ($metrics['duration_ms'] ?? 0);

        // The engine only reports how long it took, so the start instant is
        // reconstructed from it. Deriving it is accurate to the millisecond;
        // storing `CURRENT_TIMESTAMP` for both would lose the only timing fact the
        // run actually knows, and `allocation_runs` is what makes the <3 s
        // response claim auditable after the fact.
        $finishedAt = time();
        $startedAt = $finishedAt - intdiv($durationMs, 1000);

        $this->database->insert('allocation_runs', [
            'id'                   => $runId,
            'department_id'        => $loaded->departmentId,
            'semester_id'          => $loaded->semesterId,
            'triggered_by'         => $triggeredBy,
            'mode'                 => $mode === 'repair' ? 'repair' : 'full',
            'random_seed'          => (int) ($metrics['random_seed'] ?? 0),
            'engine_version'       => $this->engineVersion,
            'total_sessions'       => (int) $metrics['total_sessions'],
            'assigned_sessions'    => (int) $metrics['assigned_sessions'],
            'unallocated_sessions' => (int) $metrics['unallocated_sessions'],
            'accuracy'             => $this->accuracy($result),
            'total_penalty'        => (float) ($metrics['total_penalty'] ?? 0.0),
            'iterations'           => (int) ($metrics['iterations'] ?? 0),
            'duration_ms'          => $durationMs,
            'is_feasible'          => $result->violations === [] ? 1 : 0,
            'is_applied'           => $apply ? 1 : 0,
            // `weight_profile` and `weights` travel with the run so a timetable
            // can always be attributed to the policy that produced it, which is
            // what makes a retune reviewable after the fact.
            'metrics'              => json_encode($metrics + $metricsExtra, JSON_UNESCAPED_SLASHES),
            'started_at'           => gmdate('Y-m-d H:i:s', $startedAt),
            'finished_at'          => gmdate('Y-m-d H:i:s', $finishedAt),
        ]);
    }

    private function accuracy(SchedulingResult $result): float
    {
        $value = $result->metrics['accuracy'] ?? null;
        if (!is_numeric($value)) {
            $total = (int) ($result->metrics['total_sessions'] ?? 0);

            return $total > 0 ? round($result->assignedCount() / $total, 4) : 1.0;
        }

        return round((float) $value, 4);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function cohortForSession(LoadedProblem $loaded, int $sessionId): int
    {
        $session = $loaded->problem->sessionById($sessionId);

        return $session?->cohortId() ?? 0;
    }

    private function courseForSession(LoadedProblem $loaded, int $sessionId): int
    {
        $session = $loaded->problem->sessionById($sessionId);

        return $session?->courseId() ?? 0;
    }

    /**
     * RFC 4122 version 4.
     *
     * Hand-rolled because the alternative is a symfony/uid dependency for one
     * call, and because a 36-character hex id built from `random_bytes()` is
     * trivially auditable — `bin2hex(random_bytes(16))` would also do the job
     * and would not be a valid UUID.
     */
    private function uuid4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40); // version 4
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80); // variant 10xx

        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }
}
