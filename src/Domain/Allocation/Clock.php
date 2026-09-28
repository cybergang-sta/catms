<?php

declare(strict_types=1);

namespace App\Domain\Allocation;

/**
 * Monotonic time source.
 *
 * The engine enforces a wall-clock budget (NFR-PERF) and reports `duration_ms`
 * in its metrics. Reading the clock directly would make those two things
 * untestable and would quietly break the determinism guarantee: a golden-file
 * test that runs for 0.5 ms one day and 40 ms the next produces different
 * metrics and fails for no reason.
 *
 * Injecting the clock keeps AllocationEngine a pure function of
 * (problem, existing, weights, seed, options) — see
 * docs/ALLOCATION_ENGINE.md §8.
 */
interface Clock
{
    /**
     * Seconds from an arbitrary but fixed origin, monotonically non-decreasing.
     *
     * microtime(true) is the production implementation. Tests substitute
     * FixedClock, which returns whatever the test tells it to.
     */
    public function now(): float;

    /**
     * The current time in milliseconds since the epoch. Used only for the
     * human-facing `duration_ms` metric and for stamping allocation_runs.
     */
    public function nowMs(): int;
}
