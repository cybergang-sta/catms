<?php

declare(strict_types=1);

namespace Tests\Unit\Allocation;

use App\Domain\Allocation\AllocationEngine;
use App\Domain\Allocation\CandidateGenerator;
use App\Domain\Allocation\ConstraintChecker;
use App\Domain\Allocation\CostFunction;
use App\Domain\Allocation\CostWeights;
use App\Domain\Allocation\EngineOptions;
use App\Domain\Allocation\FixedClock;
use App\Domain\Allocation\Rng;
use App\Domain\Allocation\SchedulingResult;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Allocation\Fixture\ProblemBuilder;

/**
 * Shared wiring for the engine tests.
 *
 * The default options deliberately disable the wall-clock budget by pinning a
 * FixedClock: an engine test that consults the real clock is not reproducible,
 * and a slow CI machine would silently run a different number of iterations
 * than a fast laptop — turning a tuning regression into a heisenbug. Tests that
 * specifically exercise the time budget advance the FixedClock by hand.
 */
abstract class EngineTestCase extends TestCase
{
    protected const SEED = 20260801;

    protected function engine(
        ?EngineOptions $options = null,
        ?CostWeights $weights = null,
        int $maxLecturerSessionsPerDay = 4,
    ): AllocationEngine {
        $checker = new ConstraintChecker($maxLecturerSessionsPerDay);

        return new AllocationEngine(
            $checker,
            new CandidateGenerator($checker),
            new CostFunction($weights ?? CostWeights::balanced()),
            new Rng(self::SEED),
        );
    }

    /**
     * Options with a frozen clock: reproducible, and fast.
     */
    protected function options(int $maxIterations = 200, int $seed = self::SEED): EngineOptions
    {
        return new EngineOptions(
            maxIterations: $maxIterations,
            randomSeed: $seed,
            timeBudgetSeconds: 3600.0,
            clock: new FixedClock(0.0),
        );
    }

    /**
     * Greedy only — no local search. The right default when a test is about
     * construction or filtering rather than about optimisation.
     */
    protected function greedyOptions(int $seed = self::SEED): EngineOptions
    {
        return EngineOptions::greedyOnly($seed);
    }

    protected function solve(ProblemBuilder $builder, ?EngineOptions $options = null): SchedulingResult
    {
        return $this->engine()->solve(
            $builder->build(),
            [],
            $options ?? $this->options(),
        );
    }

    /**
     * Assert the two invariants that hold for every result, whatever the input.
     *
     * Called by nearly every test here on purpose: an invariant that is only
     * checked in one place is an invariant that quietly stops being true.
     */
    protected function assertResultIsSound(SchedulingResult $result): void
    {
        self::assertSame(
            [],
            array_map(static fn ($v): string => (string) $v, $result->violations),
            'The engine returned a solution that violates a hard constraint.',
        );

        foreach ($result->assignments as $sessionId => $assignment) {
            self::assertSame(
                $sessionId,
                $assignment->sessionId(),
                'Assignments must be keyed by their own session id.',
            );
        }

        $unplaced = array_map(
            static fn ($u): int => $u->sessionId,
            $result->unallocated,
        );

        $overlap = array_intersect(array_keys($result->assignments), $unplaced);
        self::assertSame(
            [],
            array_values($overlap),
            'A session cannot be both allocated and unallocated.',
        );
    }
}
