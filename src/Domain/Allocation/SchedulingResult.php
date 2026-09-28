<?php

declare(strict_types=1);

namespace App\Domain\Allocation;

/**
 * The outcome of one solve.
 *
 * Two invariants hold for every result the engine returns, and both are asserted
 * by AllocationEngineTest before this object is handed back:
 *
 *  1. No committed assignment violates HC-1 … HC-10. `violations` is the
 *     evidence, and it must be empty for a successful run.
 *  2. Every session appears in exactly one of `assignments` or `unallocated`.
 *     A session silently in neither would make the reported accuracy a lie.
 */
final class SchedulingResult
{
    /**
     * @param array<int, Assignment>     $assignments   sessionId => committed placement
     * @param list<UnallocatedSession>   $unallocated   sessions that could not be placed
     * @param list<Violation>            $violations    Should be empty; non-empty means an
     *                                                    internal invariant failed and the
     *                                                    run must not be persisted
     *                                                    (see docs/ALLOCATION_ENGINE.md §12).
     * @param array<string, mixed>       $metrics       Persisted to allocation_runs.
     */
    public function __construct(
        public readonly array $assignments,
        public readonly array $unallocated,
        public readonly array $violations,
        public readonly array $metrics,
    ) {
    }

    /**
     * True when every session was placed and nothing violates a hard constraint —
     * the only condition under which a run may be auto-committed.
     */
    public function isPublishable(): bool
    {
        return $this->violations === [] && $this->unallocated === [];
    }

    public function assignedCount(): int
    {
        return \count($this->assignments);
    }

    public function unallocatedCount(): int
    {
        return \count($this->unallocated);
    }

    /**
     * Placed sessions as a list, ordered by session id for a stable API response.
     *
     * @return list<Assignment>
     */
    public function orderedAssignments(): array
    {
        $assignments = $this->assignments;
        ksort($assignments);

        return array_values($assignments);
    }

    public function assignmentFor(int $sessionId): ?Assignment
    {
        return $this->assignments[$sessionId] ?? null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'assignments' => array_map(
                static fn (Assignment $a): array => $a->toArray(),
                $this->orderedAssignments(),
            ),
            'unallocated' => array_map(
                static fn (UnallocatedSession $u): array => $u->toArray(),
                $this->unallocated,
            ),
            'violations'  => array_map(
                static fn (Violation $v): array => $v->toArray(),
                $this->violations,
            ),
            'metrics'     => $this->metrics,
        ];
    }
}
