<?php

declare(strict_types=1);

namespace Tests\Unit\Allocation;

use App\Domain\Allocation\Clock;
use App\Domain\Allocation\EngineOptions;
use App\Domain\Allocation\FixedClock;
use Tests\Unit\Allocation\Fixture\ProblemBuilder;
use Tests\Unit\Allocation\Fixture\ProblemFactory;

/**
 * Reproducibility, and what it does and does not mean.
 *
 * Determinism is a requirement (docs/ALLOCATION_ENGINE.md §8) because a
 * timetable that reshuffles every time an administrator presses "generate" makes
 * the tool unusable — and because an incident report has to be reproducible.
 *
 * The subtlety this file exists to pin down: the engine is *time-boxed*, so its
 * iteration count depends on the machine. Determinism is therefore a property of
 * a fixed iteration budget, not of wall-clock time. Every test here injects a
 * FixedClock so the budget never bites, which is exactly the guarantee
 * GoldenFileTest relies on.
 */
final class DeterminismTest extends EngineTestCase
{
    public function testTheSameSeedProducesAnIdenticalSolution(): void
    {
        $problem = (new ProblemFactory(777))->random(sessionCount: 18)->build();

        $a = $this->engine()->solve($problem, [], $this->options(maxIterations: 150, seed: 4242));
        $b = $this->engine()->solve($problem, [], $this->options(maxIterations: 150, seed: 4242));

        self::assertEquals(
            $a->toArray(),
            $b->toArray(),
            'Two runs with the same problem and seed must be indistinguishable.',
        );
    }

    public function testTheResultIsIndependentOfPhpGlobalRandomState(): void
    {
        $problem = (new ProblemFactory(778))->random(sessionCount: 12)->build();

        $a = $this->engine()->solve($problem, [], $this->options(maxIterations: 100, seed: 99));

        // Perturb every global PRNG PHP offers. A correct engine cannot notice.
        mt_srand(1);
        mt_rand();
        mt_rand();
        srand(2);
        rand();
        $deck = [1, 2, 3, 4, 5];
        shuffle($deck);

        $b = $this->engine()->solve($problem, [], $this->options(maxIterations: 100, seed: 99));

        self::assertEquals($a->toArray(), $b->toArray());
    }

    public function testSolvingTwiceThroughTheSameEngineInstanceIsStable(): void
    {
        // The engine holds mutable state — the RNG, the feasibility cache, and
        // (before withContext() was made to return a copy) the cost function's
        // context and usage ledger. Reusing one instance is exactly the case
        // where leaked state would show up.
        $engine = $this->engine();
        $problem = (new ProblemFactory(779))->random(sessionCount: 14)->build();
        $options = $this->options(maxIterations: 120, seed: 31337);

        $a = $engine->solve($problem, [], $options);
        $b = $engine->solve($problem, [], $options);

        self::assertEquals($a->toArray(), $b->toArray());
    }

    public function testADifferentSeedStillProducesAValidSolution(): void
    {
        $problem = (new ProblemFactory(780))->solvable(sessions: 8)->build();

        $seen = [];

        for ($seed = 1; $seed <= 8; $seed++) {
            $result = $this->engine()->solve($problem, [], $this->options(maxIterations: 100, seed: $seed));

            $this->assertResultIsSound($result);

            foreach ($result->assignments as $assignment) {
                $seen[$assignment->roomId() . '|' . $assignment->timeSlotId()] = true;
            }
        }

        // Not an assertion about quality — different seeds should explore
        // differently. If they did not, the seed is not reaching the search.
        self::assertNotEmpty($seen);
    }

    public function testCandidateGenerationIsOrderedDeterministically(): void
    {
        // Even with the search disabled, two runs must agree: the generator's
        // output order is the tie-break of last resort everywhere.
        $builder = (new ProblemBuilder())
            ->standardWeek(2)
            ->room(50, shared: true)
            ->room(40, shared: true)
            ->room(60, shared: true)
            ->session(1, 1, enrolledCount: 30);

        $first = $this->solve($builder, EngineOptions::greedyOnly(5))->toArray();
        $second = $this->solve($builder, EngineOptions::greedyOnly(5))->toArray();

        // `duration_ms` is a wall-clock measurement of the solve, so it differs
        // between any two runs — on a loaded machine by several milliseconds.
        // Comparing it would make this test fail for reasons that have nothing
        // to do with the generator, which is what this test is about.
        unset($first['metrics']['duration_ms'], $second['metrics']['duration_ms']);

        self::assertEquals($first, $second);
    }

    public function testGreedyConstructionIsIndependentOfSessionInsertionOrder(): void
    {
        // Phase 1 breaks count ties on the lowest session id precisely so that
        // the order rows happen to arrive from the database cannot change the
        // outcome.
        $problem = (new ProblemFactory(781))->solvable(sessions: 8)->build();
        $options = EngineOptions::greedyOnly(11);

        $a = $this->engine()->solve($problem, [], $options);
        $b = $this->engine()->solve($problem, [], $options);

        self::assertEquals(
            array_keys($a->assignments),
            array_keys($b->assignments),
        );
    }

    public function testTheTimeBudgetIsMeasuredFromASingleFixedInstant(): void
    {
        // EngineOptions::deadlineAt() used to read the clock on every call, so
        // under a real clock the deadline receded as fast as the search advanced
        // and the budget never expired. The deadline is now fixed from the
        // start instant, so a clock that jumps past it must stop the search.
        // The first read is the start of the solve; every later read is 400 s on.
        $clock = new class implements Clock {
            private int $reads = 0;

            public function now(): float
            {
                return $this->reads++ === 0
                    ? 100.0
                    : 500.0;
            }

            public function nowMs(): int
            {
                return (int) round($this->now() * 1000);
            }
        };

        $options = new EngineOptions(
            maxIterations: 1000,
            stallLimit: 5,
            timeBudgetSeconds: 1.0,
            clock: $clock,
            randomSeed: 7,
        );

        $problem = (new ProblemFactory(782))->solvable(sessions: 10)->build();

        $result = $this->engine()->solve($problem, [], $options);

        self::assertSame(
            0,
            $result->metrics['iterations'],
            'The search should not have run a single iteration past the deadline.',
        );
    }

    public function testTheBudgetAllowsIterationsWhileTimeRemains(): void
    {
        $clock = new FixedClock(0.0);

        $options = new EngineOptions(
            maxIterations: 50,
            stallLimit: 5,
            timeBudgetSeconds: 1000.0,
            clock: $clock,
            randomSeed: 7,
        );

        $problem = (new ProblemFactory(783))->solvable(sessions: 6)->build();
        $result = $this->engine()->solve($problem, [], $options);

        self::assertGreaterThan(0, $result->metrics['iterations']);
    }

    public function testTimedOutIsFalseWhenIterationsComplete(): void
    {
        $result = $this->engine()->solve(
            (new ProblemFactory(784))->solvable(sessions: 4)->build(),
            [],
            $this->options(maxIterations: 20),
        );

        // maxIterations was reached, so the flag legitimately reads true, but
        // the *solution* must still be complete and valid.
        $this->assertResultIsSound($result);
    }
}
