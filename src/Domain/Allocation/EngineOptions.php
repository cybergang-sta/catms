<?php

declare(strict_types=1);

namespace App\Domain\Allocation;

/**
 * Knobs for one engine run.
 *
 * The defaults encode the production budget: an allocation must be produced
 * inside the 3-second response target of NFR-PERF-01 while the HTTP request is
 * still open, which is why the engine is time-boxed rather than run to
 * convergence.
 *
 * Because the budget is a wall-clock limit, a run is NOT bit-for-bit
 * reproducible across machines — it is reproducible for a fixed iteration count
 * (see maxIterations and randomSeed). That is the trade made for a bounded
 * response time, and GoldenFileTest is written against the iteration count, not
 * the elapsed time. docs/ALLOCATION_ENGINE.md §8.
 */
final class EngineOptions
{
    /**
     * @param int    $maxIterations  Local-search steps. 0 disables Phase 2 entirely,
     *                               leaving the greedy solution from Phase 1 — the
     *                               right choice for a CLI batch run with no deadline.
     * @param int    $stallLimit     Non-improving steps tolerated before a perturbation
     *                               (kick) is applied to escape a local minimum.
     * @param int    $maxNeighbours  Candidate moves considered per session per iteration.
     *                               Caps the cost of one step on a large problem.
     * @param int    $randomSeed     Seeds the RNG. Same seed + same problem => same
     *                               greedy construction, always.
     * @param float  $timeBudgetSeconds Wall-clock ceiling for the whole solve.
     * @param Clock  $clock          Time source; injected for testability.
     * @param bool   $strict         Throw InfeasibleProblemException when the problem
     *                               has no usable slot at all, instead of returning
     *                               every session as unallocated.
     */
    public function __construct(
        public readonly int $maxIterations = 2000,
        public readonly int $stallLimit = 60,
        public readonly int $maxNeighbours = 40,
        public readonly int $randomSeed = 20260801,
        public readonly float $timeBudgetSeconds = 2.5,
        public readonly Clock $clock = new SystemClock(),
        public readonly bool $strict = false,
    ) {
        if ($this->maxIterations < 0) {
            throw new \InvalidArgumentException('maxIterations must not be negative.');
        }

        if ($this->stallLimit < 1) {
            throw new \InvalidArgumentException('stallLimit must be at least 1.');
        }

        if ($this->maxNeighbours < 1) {
            throw new \InvalidArgumentException('maxNeighbours must be at least 1.');
        }

        if ($this->timeBudgetSeconds <= 0.0) {
            throw new \InvalidArgumentException('timeBudgetSeconds must be positive.');
        }
    }

    /**
     * The instant after which Phase 2 must stop and hand back the best solution
     * found so far.
     *
     * Takes the start instant explicitly rather than reading the clock itself,
     * because a deadline computed from a live clock on every call would move
     * with every call and the budget would never expire.
     */
    public function budgetExpiresAt(float $startedAt): float
    {
        return $startedAt + $this->timeBudgetSeconds;
    }

    public function nowMs(): int
    {
        return $this->clock->nowMs();
    }

    /**
     * Greedy construction only. Useful for tests, for debugging, and for the
     * "preview without search" mode the admin UI offers before committing a run.
     */
    public static function greedyOnly(int $seed = 20260801): self
    {
        return new self(maxIterations: 0, randomSeed: $seed);
    }

    /**
     * An unlimited run for overnight batch generation, where the only deadline
     * is the one the operator imposes on the shell.
     */
    public static function exhaustive(int $seed = 20260801): self
    {
        return new self(
            maxIterations: 1000000,
            stallLimit: 400,
            maxNeighbours: 400,
            randomSeed: $seed,
            timeBudgetSeconds: 3600.0,
        );
    }
}
