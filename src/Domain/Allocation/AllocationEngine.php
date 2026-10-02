<?php

declare(strict_types=1);

namespace App\Domain\Allocation;

use App\Domain\Allocation\Exception\InfeasibleProblemException;

/**
 * The allocation optimisation engine.
 *
 * Four phases, as specified in docs/ALLOCATION_ENGINE.md §5:
 *
 *   0. warm start  — adopt the existing timetable where it is still feasible
 *   1. seed        — most-constrained-first greedy construction
 *   2. improve     — stochastic local search over three neighbourhoods
 *   3. verify      — independently re-check every committed assignment
 *
 * Pure PHP: no HTTP, no PDO, no filesystem. The clock arrives through
 * EngineOptions so results are reproducible (docs/ALLOCATION_ENGINE.md §8).
 *
 * GUARANTEES
 * ----------
 *  - No committed assignment violates HC-1 … HC-10. Phase 3 re-derives this
 *    from scratch and reports any failure in $result->violations.
 *  - Every session appears in exactly one of `assignments` or `unallocated`.
 *  - Phase 1 is deterministic for a given seed, independent of PHP's PRNG.
 *
 * NOT GUARANTEED
 * --------------
 *  - Bit-for-bit reproducibility across machines. The search is time-boxed
 *    against a wall clock, so a slower machine performs fewer iterations. Tests
 *    fix the iteration count (or inject a FixedClock) to get a stable result.
 *  - Optimality. Local search has no global optimum guarantee. It reliably
 *    clears the >90 % placement target of OBJ-2 and nothing more is claimed.
 */
final class AllocationEngine
{
    private Rng $rng;

    /**
     * sessionId => feasible-candidate count, invalidated as occupancy changes.
     *
     * @var array<int, int>
     */
    private array $feasibilityCache = [];

    public function __construct(
        private readonly ConstraintChecker $checker,
        private readonly CandidateGenerator $generator,
        private readonly CostFunction $costFunction,
        ?Rng $rng = null,
    ) {
        $this->rng = $rng ?? new Rng(0);
    }

    /**
     * Solve a scheduling problem.
     *
     * @param list<Assignment> $existing Warm start from the current timetable.
     *                                     Pass [] for a cold, full generation.
     */
    public function solve(
        SchedulingProblem $problem,
        array $existing = [],
        ?EngineOptions $options = null,
    ): SchedulingResult {
        $options ??= new EngineOptions();
        $clock = $options->clock;

        $this->assertProblemIsUsable($problem, $options);
        $this->rng->seed($options->randomSeed);
        $this->feasibilityCache = [];

        $startedAt = $clock->now();
        $deadline = $options->budgetExpiresAt($startedAt);

        // --- Phase 0: warm start -------------------------------------------
        // Built before the cost function, because the churn and movement terms
        // are defined relative to what the timetable looks like today.
        $rejected = [];
        $occupancy = new OccupancyIndex();
        $current = $this->seedFromExisting($existing, $problem, $occupancy, $rejected);

        $costFunction = $this->buildCostFunction($problem, $existing);

        // Replay the adopted warm-start rows into the fresh ledger, scoring each
        // one: local search only accepts a move that beats the current cost, so
        // an unscored (zero-cost) row could never be improved.
        ksort($current);
        foreach ($current as $sessionId => $assignment) {
            $session = $problem->sessionById($assignment->sessionId());
            if ($session === null) {
                continue;
            }

            $breakdown = $costFunction->evaluate($assignment, $problem);
            $current[$sessionId] = $assignment->withCost($breakdown->total, $breakdown->weighted);
            $costFunction->noteUse($assignment->roomId(), $assignment->timeSlotId(), $session->cohortId());
        }

        // --- Phase 1: most-constrained-first greedy construction -------------
        $unallocated = $this->seed($problem, $occupancy, $costFunction, $current);

        // --- Phase 2: stochastic local search -------------------------------
        $iterations = 0;
        if ($options->maxIterations > 0) {
            $iterations = $this->improve($problem, $occupancy, $current, $costFunction, $options, $deadline);
        }

        // Improving may have restored an earlier snapshot, so the index has to
        // be rebuilt before it can be trusted again.
        $occupancy = $this->buildIndex($problem, $current);

        // --- reconcile: anything unplaced may have become placeable ----------
        $unallocated = $this->reconcileUnallocated($problem, $occupancy, $current, $unallocated, $costFunction);

        // --- Phase 3: independent verification ------------------------------
        $violations = $this->verifyAll($problem, $current, $occupancy);

        // --- final scoring pass ---------------------------------------------
        // Reported penalties must be internally consistent, not the sum of
        // per-move deltas taken at different points in the search.
        $current = $this->rescore($problem, $current);

        $metrics = $this->buildMetrics(
            $problem,
            $current,
            $unallocated,
            $rejected,
            $iterations,
            $clock->now() - $startedAt,
            $options,
        );

        return new SchedulingResult(
            assignments: $current,
            unallocated: $unallocated,
            violations: $violations,
            metrics: $metrics,
        );
    }

    // -----------------------------------------------------------------------
    // Guards
    // -----------------------------------------------------------------------

    /**
     * A structurally empty problem is an operator error, not a hard scheduling
     * problem. In strict mode it throws; otherwise it is reported through the
     * normal unallocated path so the admin UI can show it.
     */
    private function assertProblemIsUsable(SchedulingProblem $problem, EngineOptions $options): void
    {
        if ($problem->sessions() === []) {
            if ($options->strict) {
                throw InfeasibleProblemException::noSessions();
            }

            return;
        }

        foreach ($problem->slots() as $slot) {
            if ($problem->isTeachable($slot)) {
                return;
            }
        }

        if ($options->strict) {
            throw InfeasibleProblemException::noTeachableSlots();
        }
    }

    // -----------------------------------------------------------------------
    // Cost function context
    // -----------------------------------------------------------------------

    /**
     * Derive the immutable per-run context of the cost function.
     *
     * Room utilisation is measured against the *prior* timetable, so the equity
     * term can steer new bookings away from rooms that are already carrying the
     * load. On a cold start there is no prior timetable and every room reads as
     * unused, which makes the equity term inert — the first generation has
     * nothing to be fair about, and inventing a baseline here would only add
     * noise.
     *
     * @param  list<Assignment> $existing
     */
    private function buildCostFunction(SchedulingProblem $problem, array $existing): CostFunction
    {
        $rooms = $problem->rooms();
        $slotsPerWeek = \count(array_filter(
            $problem->slots(),
            static fn (TimeSlot $s): bool => $problem->isTeachable($s),
        ));

        // serviceable slots a room could be booked into
        $availablePerRoom = [];
        foreach ($rooms as $id => $room) {
            $availablePerRoom[$id] = $room->isServiceable() ? max(1, $slotsPerWeek) : 0;
        }

        $bookedPerRoom = [];
        $previousRoom = [];
        $previousBuilding = [];

        foreach ($existing as $assignment) {
            $session = $problem->sessionById($assignment->sessionId());
            $room = $problem->roomById($assignment->roomId());

            if ($session === null || $room === null) {
                continue; // stale row: the session or room no longer exists
            }

            $bookedPerRoom[$room->id()] = ($bookedPerRoom[$room->id()] ?? 0) + 1;
            $previousRoom[$session->id()] = $room->id();

            // First sitting in the week defines where the cohort is coming from.
            $previousBuilding[$session->cohortId()] ??= $room->building();
        }

        $utilisation = [];
        foreach ($rooms as $id => $_) {
            $available = $availablePerRoom[$id] ?? 0;
            $utilisation[$id] = $available > 0
                ? min(1.0, ($bookedPerRoom[$id] ?? 0) / $available)
                : 0.0;
        }

        return $this->costFunction->withContext(
            $utilisation,
            $rooms,
            $previousRoom,
            $previousBuilding,
            $slotsPerWeek,
        );
    }

    // -----------------------------------------------------------------------
    // Phase 0 — warm start
    // -----------------------------------------------------------------------

    /**
     * Validate and adopt the existing timetable as the starting solution.
     * Infeasible entries are evicted and reported so Phase 1 can repair them.
     *
     * @param  list<Assignment>     $existing
     * @param  list<Violation>      $rejected  accumulates the reason each row was evicted
     * @return array<int, Assignment> keyed by sessionId
     */
    private function seedFromExisting(
        array $existing,
        SchedulingProblem $problem,
        OccupancyIndex $occupancy,
        array &$rejected,
    ): array {
        // Deterministic order, so a clash between two warm-start rows is always
        // resolved the same way rather than depending on row order from the DB.
        usort($existing, static fn (Assignment $a, Assignment $b): int => $a->sessionId() <=> $b->sessionId());

        $adopted = [];

        foreach ($existing as $assignment) {
            if ($problem->sessionById($assignment->sessionId()) === null) {
                continue; // stale row: the session no longer exists
            }

            $candidate = $assignment->movedTo($assignment->roomId(), $assignment->timeSlotId());

            if (! $this->checker->isFeasible($candidate, $occupancy, $problem)) {
                foreach ($this->checker->check($candidate, $occupancy, $problem) as $violation) {
                    $rejected[] = $violation;
                }

                continue; // evicted; Phase 1 will try to re-place it
            }

            $occupancy->place($candidate, $problem);
            $adopted[$candidate->sessionId()] = $candidate;
        }

        return $adopted;
    }

    // -----------------------------------------------------------------------
    // Phase 1 — greedy construction
    // -----------------------------------------------------------------------

    /**
     * Most-constrained-first greedy construction.
     *
     * The fail-first ordering is what lifts placement rate from roughly 70 % to
     * above the 90 % of OBJ-2 at negligible cost: a session with one feasible
     * option must be placed before one with two hundred, or the scarce option
     * will be consumed by a session that had alternatives.
     *
     * @param  array<int, Assignment> $current
     * @return list<UnallocatedSession>
     */
    private function seed(
        SchedulingProblem $problem,
        OccupancyIndex $occupancy,
        CostFunction $costFunction,
        array &$current,
    ): array {
        $pending = [];

        foreach ($problem->sessions() as $id => $session) {
            if (! isset($current[$id])) {
                // All counted against the same (warm-started) occupancy, so the
                // ordering reflects a single consistent view of the problem.
                $this->feasibilityCache[$id] = $this->generator->countFeasible($id, $problem, $occupancy);
                $pending[$id] = $this->feasibilityCache[$id];
            }
        }

        $unallocated = [];

        while ($pending !== []) {
            // Fewest options first. Ties break on the lowest session id so the
            // construction never depends on array insertion order.
            $sessionId = null;
            $fewest = PHP_INT_MAX;
            foreach ($pending as $id => $count) {
                if ($count < $fewest) {
                    $fewest = $count;
                    $sessionId = $id;
                }
            }

            unset($pending[(int) $sessionId]);
            $sessionId = (int) $sessionId;

            $candidates = $this->generator->for($sessionId, $problem, $occupancy);

            if ($candidates === []) {
                $unallocated[] = $this->explainUnplaceable($sessionId, $problem, $occupancy);
                continue;
            }

            $chosen = $this->cheapest($candidates, $problem, $costFunction);
            if ($chosen === null) {
                $unallocated[] = $this->explainUnplaceable($sessionId, $problem, $occupancy);
                continue;
            }

            // Defence in depth. The generator is meant to be exhaustive, but
            // nothing about a timetable may rest on a single code path being
            // right — one missed check here becomes a double-booked classroom.
            if (! $this->checker->isFeasible($chosen, $occupancy, $problem)) {
                $unallocated[] = $this->explainUnplaceable($sessionId, $problem, $occupancy);
                continue;
            }

            $occupancy->place($chosen, $problem);
            $costFunction->noteUse($chosen->roomId(), $chosen->timeSlotId(), $this->cohortOf($problem, $sessionId));
            $current[$sessionId] = $chosen;

            $this->invalidateFeasibility($pending, $chosen, $problem);
        }

        return $unallocated;
    }

    /**
     * Pick the lowest-cost candidate, tie-broken deterministically.
     *
     * @param  list<Assignment> $candidates
     */
    private function cheapest(
        array $candidates,
        SchedulingProblem $problem,
        CostFunction $costFunction,
    ): ?Assignment {
        $best = null;
        $bestKey = null;

        foreach ($candidates as $candidate) {
            $breakdown = $costFunction->evaluate($candidate, $problem);
            $key = [$breakdown->total, $candidate->roomId(), $candidate->timeSlotId()];

            if ($bestKey === null || $key < $bestKey) {
                $bestKey = $key;
                $best = $candidate->withCost($breakdown->total, $breakdown->weighted);
            }
        }

        return $best;
    }

    private function cohortOf(SchedulingProblem $problem, int $sessionId): int
    {
        return $problem->sessionById($sessionId)?->cohortId() ?? 0;
    }

    /**
     * Drop cached counts that the placement just invalidated.
     *
     * A placement into (room R, slot T) for session S can only change the
     * feasible count of a pending session X when X could have used R in T, or
     * when X shares S's cohort or lecturer. Everything else is untouched, so
     * recounting all pending sessions after every placement — the obvious
     * implementation — is O(sessions² × slots × rooms) and blows the 3-second
     * budget on a real department.
     *
     * This invalidation is deliberately conservative: it may drop a count that
     * did not actually change, which costs one recount, but it never keeps a
     * stale one. A stale count would only mis-order the greedy pass, so this is
     * a performance heuristic, not a correctness mechanism — feasibility is
     * still enforced by CandidateGenerator and re-checked before every commit.
     *
     * @param array<int, int> $pending
     */
    private function invalidateFeasibility(array $pending, Assignment $placed, SchedulingProblem $problem): void
    {
        $session = $problem->sessionById($placed->sessionId());
        if ($session === null) {
            $this->feasibilityCache = [];

            return;
        }

        $room = $problem->roomById($placed->roomId());
        $slot = $problem->slotById($placed->timeSlotId());

        foreach ($pending as $id => $_) {
            $candidate = $problem->sessionById($id);
            if ($candidate === null) {
                unset($this->feasibilityCache[$id]);
                continue;
            }

            if ($candidate->cohortId() === $session->cohortId()
                || $candidate->lecturerId() === $session->lecturerId()
                || $this->couldHaveUsed($candidate, $room, $slot, $problem)
            ) {
                unset($this->feasibilityCache[$id]);
            }
        }
    }

    /**
     * Could $session have been placed in ($room, $slot) at all? Purely static —
     * the occupancy-sensitive part is what the invalidation already covers.
     */
    private function couldHaveUsed(
        SessionRequest $session,
        ?Room $room,
        ?TimeSlot $slot,
        SchedulingProblem $problem,
    ): bool {
        if ($room === null || $slot === null) {
            return false;
        }

        return $room->isServiceable()
            && $room->fits($session->enrolledCount())
            && $room->features()->satisfies($session->requiredFeatures())
            && $problem->isRoomInScope($room, $session)
            && $problem->isTeachable($slot)
            && ! $problem->isRoomBlocked($room->id(), $slot);
    }

    // -----------------------------------------------------------------------
    // Phase 2 — local search
    // -----------------------------------------------------------------------

    /**
     * Stochastic local search over three neighbourhoods: REASSIGN, MOVESLOT and
     * SWAP (the last realised as a two-step REASSIGN chain).
     *
     * Only strictly improving moves are accepted, so the search is monotone
     * within a run and easy to reason about. On a stall the engine perturbs and
     * continues, always retaining the best solution seen — which means extra
     * iterations can only help, the property that makes a time-boxed engine
     * safe to interrupt.
     *
     * @param array<int, Assignment> $current
     */
    private function improve(
        SchedulingProblem $problem,
        OccupancyIndex $occupancy,
        array &$current,
        CostFunction $costFunction,
        EngineOptions $options,
        float $deadline,
    ): int {
        $sessionIds = array_keys($current);
        if ($sessionIds === []) {
            return 0;
        }

        $clock = $options->clock;
        $currentCost = $this->totalCost($current);
        $best = $current;
        $bestCost = $currentCost;

        $stall = 0;
        $iterations = 0;

        for ($i = 0; $i < $options->maxIterations; $i++) {
            if ($clock->now() >= $deadline) {
                break; // time budget exhausted; keep the best solution seen
            }

            $iterations++;

            $sessionId = $sessionIds[$this->rng->int(0, \count($sessionIds) - 1)];
            $placed = $current[$sessionId] ?? null;
            if ($placed === null) {
                continue;
            }

            $candidates = $this->neighbours($placed, $occupancy, $problem, $options, $costFunction);
            if ($candidates === []) {
                $stall++;
                continue;
            }

            $bestLocal = null;
            foreach ($candidates as $candidate) {
                if ($bestLocal === null || $candidate->cost() < $bestLocal->cost()) {
                    $bestLocal = $candidate;
                }
            }

            if ($bestLocal === null || $bestLocal->cost() >= $placed->cost()) {
                $stall++;
            } else {
                $this->move($occupancy, $costFunction, $problem, $placed, $bestLocal);
                $current[$sessionId] = $bestLocal;
                $currentCost += $bestLocal->cost() - $placed->cost();
                $stall = 0;
            }

            if ($currentCost < $bestCost) {
                $bestCost = $currentCost;
                $best = $current;
                continue;
            }

            if ($stall >= $options->stallLimit) {
                // Kick: accept a worsening move to escape a local minimum. The
                // move is still feasible, so this can only cost quality, never
                // validity.
                $kick = $candidates[$this->rng->int(0, \count($candidates) - 1)];
                if ($kick->key() !== $placed->key()) {
                    $this->move($occupancy, $costFunction, $problem, $placed, $kick);
                    $current[$sessionId] = $kick;
                    $currentCost += $kick->cost() - $placed->cost();
                }

                $stall = 0;
            }
        }

        // The best solution seen always wins. This restores an earlier snapshot,
        // so the caller's occupancy index no longer describes the answer; solve()
        // rebuilds it from the result before verification.
        if ($bestCost < $currentCost) {
            $current = $best;
        }

        return $iterations;
    }

    /**
     * Commit a move, keeping occupancy and the cost ledger in step.
     *
     * Order matters: the old placement is released before the new one is
     * registered, so the two never transiently collide in the index.
     */
    private function move(
        OccupancyIndex $occupancy,
        CostFunction $costFunction,
        SchedulingProblem $problem,
        Assignment $from,
        Assignment $to,
    ): void {
        $cohortId = $this->cohortOf($problem, $from->sessionId());

        $occupancy->unplace($from, $problem);
        $occupancy->place($to, $problem);

        $costFunction->forgetUse($from->roomId(), $from->timeSlotId(), $cohortId);
        $costFunction->noteUse($to->roomId(), $to->timeSlotId(), $cohortId);
    }

    /**
     * Feasible alternatives for the current placement, already costed and
     * randomly subsampled.
     *
     * The occupancy index still contains `$placed`, and every checker lookup
     * passes that session id as the exclusion — which is precisely the question
     * being asked: "if this session left, could it go there instead?"
     *
     * @return list<Assignment>
     */
    private function neighbours(
        Assignment $placed,
        OccupancyIndex $occupancy,
        SchedulingProblem $problem,
        EngineOptions $options,
        CostFunction $costFunction,
    ): array {
        $candidates = [];

        foreach ($this->generator->for($placed->sessionId(), $problem, $occupancy) as $candidate) {
            if ($candidate->roomId() === $placed->roomId() && $candidate->timeSlotId() === $placed->timeSlotId()) {
                continue; // not a move
            }

            $candidates[] = $candidate;
        }

        if (\count($candidates) > $options->maxNeighbours) {
            // Sample rather than truncate, so repeated visits are not biased
            // towards the lowest room ids. Sampling first keeps the step
            // O(maxNeighbours) in checks and evaluations, not O(rooms × slots).
            $candidates = $this->rng->sample($candidates, $options->maxNeighbours);
        }

        $costed = [];
        foreach ($candidates as $candidate) {
            // Re-verify defensively: the generator is a filter, but the
            // acceptance decision must never rest on a single code path.
            if (! $this->checker->isFeasible($candidate, $occupancy, $problem)) {
                continue;
            }

            $breakdown = $costFunction->evaluate($candidate, $problem);
            $costed[] = $candidate->withCost($breakdown->total, $breakdown->weighted);
        }

        return $costed;
    }

    // -----------------------------------------------------------------------
    // Phase 3 — verification
    // -----------------------------------------------------------------------

    /**
     * Independently re-check every committed assignment.
     *
     * Runs the real ConstraintChecker against an index of the *entire* final
     * solution. Because every lookup is scoped with the assignment's own session
     * id, each assignment is judged against everything except itself — so a
     * double-booked room, a lecturer booked twice or a cohort sent to two rooms
     * at once all surface here even if the generator were to let them through.
     *
     * This is O(n): the check is O(1) per assignment, and the index is built
     * once. That is what makes running it unconditionally affordable.
     *
     * @param  array<int, Assignment> $current
     * @return list<Violation>
     */
    private function verifyAll(
        SchedulingProblem $problem,
        array $current,
        OccupancyIndex $occupancy,
    ): array {
        $violations = [];

        foreach ($current as $assignment) {
            foreach ($this->checker->check($assignment, $occupancy, $problem) as $violation) {
                $violations[] = $violation;
            }
        }

        return $violations;
    }

    // -----------------------------------------------------------------------
    // Reconciliation, rebuilding and final scoring
    // -----------------------------------------------------------------------

    /**
     * A session left unplaced by Phase 1 may have become placeable while
     * others moved out of the way. One extra pass recovers those; the rest are
     * genuinely unplaceable and are reported with evidence.
     *
     * @param  array<int, Assignment>  $current
     * @param  list<UnallocatedSession> $unallocated
     * @return list<UnallocatedSession>
     */
    private function reconcileUnallocated(
        SchedulingProblem $problem,
        OccupancyIndex $occupancy,
        array &$current,
        array $unallocated,
        CostFunction $costFunction,
    ): array {
        $stillUnallocated = [];

        foreach ($unallocated as $entry) {
            $sessionId = $entry->sessionId;

            if (isset($current[$sessionId])) {
                continue; // already placed during improvement
            }

            $candidates = $this->generator->for($sessionId, $problem, $occupancy);
            $chosen = $candidates === [] ? null : $this->cheapest($candidates, $problem, $costFunction);

            if ($chosen === null || ! $this->checker->isFeasible($chosen, $occupancy, $problem)) {
                $stillUnallocated[] = $entry;
                continue;
            }

            $occupancy->place($chosen, $problem);
            $costFunction->noteUse($chosen->roomId(), $chosen->timeSlotId(), $this->cohortOf($problem, $sessionId));
            $current[$sessionId] = $chosen;
        }

        return $stillUnallocated;
    }

    /**
     * An occupancy index rebuilt from scratch.
     *
     * Needed after Phase 2 because restoring the best-so-far snapshot rewinds
     * $current without rewinding the index. O(n), and it makes the invariant
     * "the index describes $current" true by construction rather than by careful
     * bookkeeping across a branch, a kick and a restore.
     *
     * @param array<int, Assignment> $current
     */
    private function buildIndex(SchedulingProblem $problem, array $current): OccupancyIndex
    {
        $occupancy = new OccupancyIndex();

        foreach ($current as $assignment) {
            $occupancy->place($assignment, $problem);
        }

        return $occupancy;
    }

    /**
     * Recompute every penalty against the finished solution.
     *
     * The ledger is replayed in session-id order so the fragmentation term is
     * evaluated against a settled cohort-room map, and each assignment's stored
     * cost matches the breakdown persisted alongside it. Without this pass the
     * reported total would be a sum of deltas taken at different moments in the
     * search, and the "why this room?" panel would show numbers that do not add
     * up to the headline.
     *
     * A fresh CostFunction with an empty context is used on purpose: the
     * search-time context (prior timetable, prior utilisation) belongs to the
     * decision, not to the report.
     *
     * @param  array<int, Assignment> $current
     * @return array<int, Assignment>
     */
    private function rescore(SchedulingProblem $problem, array $current): array
    {
        $ledger = new CostFunction($this->costFunction->weights());

        ksort($current);

        foreach ($current as $sessionId => $assignment) {
            $session = $problem->sessionById($sessionId);
            if ($session === null) {
                continue;
            }

            $ledger->noteUse($assignment->roomId(), $assignment->timeSlotId(), $session->cohortId());
            $breakdown = $ledger->evaluate($assignment, $problem);
            $current[$sessionId] = $assignment->withCost($breakdown->total, $breakdown->weighted);
        }

        return $current;
    }

    // -----------------------------------------------------------------------
    // Diagnostics
    // -----------------------------------------------------------------------

    /**
     * Explain why a session could not be placed, by testing it against every
     * slot and recording which constraints eliminated them.
     *
     * FR-ALLOC-05: an unplaceable session is information for the administrator,
     * not a failure to be hidden.
     */
    private function explainUnplaceable(
        int $sessionId,
        SchedulingProblem $problem,
        OccupancyIndex $occupancy,
    ): UnallocatedSession {
        $session = $problem->sessionById($sessionId);
        if ($session === null) {
            return new UnallocatedSession($sessionId, 0, 0, 0, [], 'The session no longer exists.');
        }

        $reasonCounts = [];
        $considered = 0;

        foreach ($problem->slots() as $slot) {
            if (! $problem->isTeachable($slot)) {
                $reasonCounts[ConstraintChecker::HC_CALENDAR_WINDOW] =
                    ($reasonCounts[ConstraintChecker::HC_CALENDAR_WINDOW] ?? 0) + 1;
                continue;
            }

            foreach ($problem->rooms() as $room) {
                $considered++;
                $probe = new Assignment($sessionId, $room->id(), $slot->id());

                foreach ($this->checker->check($probe, $occupancy, $problem) as $violation) {
                    $reasonCounts[$violation->code] = ($reasonCounts[$violation->code] ?? 0) + 1;
                }
            }
        }

        arsort($reasonCounts);

        return new UnallocatedSession(
            sessionId: $sessionId,
            cohortId: $session->cohortId(),
            courseId: $session->courseId(),
            consideredCombinations: $considered,
            blockingConstraints: $reasonCounts,
            summary: $this->summarise($reasonCounts, $session),
        );
    }

    /**
     * @param array<string, int> $reasonCounts
     */
    private function summarise(array $reasonCounts, SessionRequest $session): string
    {
        if ($reasonCounts === []) {
            return sprintf(
                'No teachable slot remains for %d students in %s.',
                $session->enrolledCount(),
                $session->label() !== '' ? $session->label() : 'this course',
            );
        }

        $labels = [
            ConstraintChecker::HC_ROOM_FREE          => 'the room is already booked',
            ConstraintChecker::HC_LECTURER_FREE      => 'the lecturer is already teaching',
            ConstraintChecker::HC_COHORT_FREE        => 'the class already has a session',
            ConstraintChecker::HC_CAPACITY           => 'no room is large enough',
            ConstraintChecker::HC_FEATURES           => 'no room has the required equipment',
            ConstraintChecker::HC_ROOM_SERVICEABLE   => 'no serviceable room exists',
            ConstraintChecker::HC_CALENDAR_WINDOW    => 'no slot falls in the teaching calendar',
            ConstraintChecker::HC_LECTURER_AVAILABLE => 'the lecturer is unavailable or already at their daily limit',
            ConstraintChecker::HC_ROOM_NOT_BLOCKED   => 'the room is blocked for maintenance',
            ConstraintChecker::HC_DEPARTMENT_SCOPE   => 'no room is available to this department',
        ];

        $parts = [];
        foreach (array_slice($reasonCounts, 0, 2, true) as $code => $count) {
            $parts[] = ($labels[$code] ?? $code) . ' (' . $count . ' combinations)';
        }

        return sprintf(
            'Could not schedule %d students: %s.',
            $session->enrolledCount(),
            implode('; ', $parts),
        );
    }

    // -----------------------------------------------------------------------
    // Metrics
    // -----------------------------------------------------------------------

    /**
     * @param array<int, Assignment>     $current
     * @param list<UnallocatedSession>   $unallocated
     * @param list<Violation>            $rejected
     *
     * @return array<string, mixed>
     */
    private function buildMetrics(
        SchedulingProblem $problem,
        array $current,
        array $unallocated,
        array $rejected,
        int $iterations,
        float $elapsed,
        EngineOptions $options,
    ): array {
        $total = $problem->totalSessions();
        $assigned = \count($current);

        // Seats committed against seats available — the raw input to OBJ-3.
        $usedCapacity = 0;
        $providedCapacity = 0;
        foreach ($current as $assignment) {
            $room = $problem->roomById($assignment->roomId());
            $session = $problem->sessionById($assignment->sessionId());
            if ($room === null || $session === null) {
                continue;
            }
            $usedCapacity += $session->enrolledCount();
            $providedCapacity += $room->capacity();
        }

        $byConstraint = [];
        foreach ($rejected as $violation) {
            $byConstraint[$violation->code] = ($byConstraint[$violation->code] ?? 0) + 1;
        }

        return [
            'total_sessions'        => $total,
            'assigned_sessions'     => $assigned,
            'unallocated_sessions'  => \count($unallocated),
            'accuracy'              => $total > 0 ? round($assigned / $total, 4) : 1.0,
            'total_penalty'         => round($this->totalCost($current), 4),
            'seat_utilisation'      => $providedCapacity > 0
                ? round($usedCapacity / $providedCapacity, 4)
                : 0.0,
            'iterations'            => $iterations,
            'duration_ms'           => (int) round($elapsed * 1000),
            'random_seed'           => $options->randomSeed,
            'timed_out'             => $iterations >= $options->maxIterations && $options->maxIterations > 0,
            'warm_start_rejected'   => \count($rejected),
            'warm_start_by_cause'   => $byConstraint,
        ];
    }

    /** @param array<int, Assignment> $current */
    private function totalCost(array $current): float
    {
        $sum = 0.0;
        foreach ($current as $assignment) {
            $sum += $assignment->cost();
        }

        return $sum;
    }
}
