<?php

declare(strict_types=1);

namespace Tests\Unit\Allocation;

use App\Domain\Allocation\Rng;
use PHPUnit\Framework\TestCase;

/**
 * The RNG is the one place where a portability bug would be invisible until
 * production: it fatal-errors on the very first call, or — worse — silently
 * produces a biased sequence on one PHP build and a good one on another.
 *
 * The specific bug these tests exist to prevent: the original implementation
 * used the 64-bit constants of xorshift128+ and SplitMix64
 * (0x9E3779B97F4A7C15 and friends). PHP integers are *signed* 64-bit, so those
 * literals exceed PHP_INT_MAX and PHP parses them as floats — and `&` on a float
 * is a TypeError. The engine could not have run at all.
 */
final class RngTest extends TestCase
{
    public function testEveryValueStaysWithinTheGeneratorRange(): void
    {
        $rng = new Rng(1);

        for ($i = 0; $i < 100_000; $i++) {
            $value = $rng->next();

            self::assertGreaterThanOrEqual(0, $value);
            self::assertLessThan(2147483648, $value, 'next() must stay inside [0, 2^31).');
        }
    }

    public function testTheSameSeedProducesTheSameStream(): void
    {
        $a = new Rng(20260801);
        $b = new Rng(20260801);

        for ($i = 0; $i < 10_000; $i++) {
            self::assertSame($a->next(), $b->next());
        }
    }

    public function testReseedingRestartsTheStream(): void
    {
        $rng = new Rng(7);
        $reference = new Rng(7);

        for ($i = 0; $i < 100; $i++) {
            $rng->next();
        }

        $rng->seed(7);

        for ($i = 0; $i < 100; $i++) {
            self::assertSame($reference->next(), $rng->next());
        }
    }

    public function testSeedZeroIsNotADegenerateStream(): void
    {
        // A generator that returns zeros for seed 0 — the single most common
        // value anyone passes — would make every "random" choice identical and
        // the local search a no-op.
        $rng = new Rng(0);
        $values = [];

        for ($i = 0; $i < 1000; $i++) {
            $values[$rng->next()] = true;
        }

        self::assertGreaterThan(500, \count($values), 'Seed 0 produced far too few distinct values.');
    }

    public function testSequentialSeedsProduceDistinctStreams(): void
    {
        // Correlated opening moves across seeds is the failure that makes a
        // seeded search untunable: every seed then explores the same corner.
        $streams = [];

        for ($seed = 0; $seed < 200; $seed++) {
            $rng = new Rng($seed);
            $stream = [];
            for ($i = 0; $i < 6; $i++) {
                $stream[] = $rng->next();
            }
            $streams[implode(',', $stream)] = true;
        }

        self::assertCount(200, $streams, 'Seeds 0..199 should all open differently.');
    }

    public function testIntIsInclusiveOfBothBounds(): void
    {
        $rng = new Rng(99);
        $seen = [];

        for ($i = 0; $i < 20_000; $i++) {
            $seen[$rng->int(3, 7)] = true;
        }

        self::assertSame([3, 4, 5, 6, 7], array_values(array_unique(array_keys($seen))));
    }

    public function testIntWithAnEqualMinAndMaxReturnsThatValue(): void
    {
        self::assertSame(5, (new Rng(1))->int(5, 5));
    }

    public function testIntIsReasonablyUniform(): void
    {
        // Chi-square against the 5 % critical value for 6 degrees of freedom
        // (12.59). Not a proof of uniformity, but a factor-of-ten regression in
        // the modulo bias would fail it comfortably.
        $rng = new Rng(4242);
        $buckets = array_fill(0, 7, 0);
        $draws = 70_000;

        for ($i = 0; $i < $draws; $i++) {
            $buckets[$rng->int(0, 6)]++;
        }

        $expected = $draws / 7;
        $chiSquare = 0.0;
        foreach ($buckets as $observed) {
            $chiSquare += (($observed - $expected) ** 2) / $expected;
        }

        self::assertLessThan(
            12.59,
            $chiSquare,
            sprintf('int() is not uniform: chi-square %.2f exceeds the 5%% critical value.', $chiSquare),
        );
    }

    public function testIntIsUniformAcrossAWiderRange(): void
    {
        $rng = new Rng(4242);
        $buckets = array_fill(0, 100, 0);
        $draws = 200_000;

        for ($i = 0; $i < $draws; $i++) {
            $buckets[intdiv($rng->int(0, 999), 10)]++;
        }

        $expected = $draws / 100;
        $chiSquare = 0.0;
        foreach ($buckets as $observed) {
            $chiSquare += (($observed - $expected) ** 2) / $expected;
        }

        self::assertLessThan(124.0, $chiSquare, sprintf('chi-square %.2f', $chiSquare));
    }

    public function testFloatIsInTheUnitIntervalWithMeanOneHalf(): void
    {
        $rng = new Rng(99);
        $sum = 0.0;
        $draws = 200_000;

        for ($i = 0; $i < $draws; $i++) {
            $value = $rng->float();
            self::assertGreaterThanOrEqual(0.0, $value);
            self::assertLessThan(1.0, $value);
            $sum += $value;
        }

        self::assertEqualsWithDelta(0.5, $sum / $draws, 0.01);
    }

    public function testChanceIsClampedSoAWeightCannotDisableABranch(): void
    {
        $rng = new Rng(3);

        for ($i = 0; $i < 100; $i++) {
            self::assertFalse($rng->chance(0.0));
            self::assertFalse($rng->chance(-1.0));
            self::assertTrue($rng->chance(1.0));
            self::assertTrue($rng->chance(2.0));
        }
    }

    public function testPickReturnsNullForAnEmptyArray(): void
    {
        self::assertNull((new Rng(1))->pick([]));
    }

    public function testPickOnlyEverReturnsAMemberOfTheInput(): void
    {
        $rng = new Rng(5);
        $items = ['a', 'b', 'c', 'd'];

        for ($i = 0; $i < 1000; $i++) {
            self::assertContains($rng->pick($items), $items);
        }
    }

    public function testSampleReturnsDistinctElements(): void
    {
        $rng = new Rng(6);
        $items = range(1, 50);

        for ($i = 0; $i < 200; $i++) {
            $sample = $rng->sample($items, 10);

            self::assertCount(10, $sample);
            self::assertSame($sample, array_values(array_unique($sample)), 'sample() repeated an element.');
        }
    }

    public function testSampleReturnsFewerWhenAskedForMoreThanExist(): void
    {
        $rng = new Rng(7);

        self::assertCount(3, $rng->sample([1, 2, 3], 10));
        self::assertSame([], $rng->sample([1, 2, 3], 0));
        self::assertSame([], $rng->sample([], 5));
    }

    public function testShuffleIsAPermutationAndIsDeterministic(): void
    {
        $items = range(1, 100);

        $a = (new Rng(8))->shuffle($items);
        $b = (new Rng(8))->shuffle($items);

        self::assertSame($a, $b);
        self::assertSame($items, array_values(array_unique($a)));
        self::assertCount(100, array_unique($a));
    }

    public function testShuffleActuallyReorders(): void
    {
        $items = range(1, 100);

        self::assertNotSame($items, (new Rng(9))->shuffle($items));
    }
}
