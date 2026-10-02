<?php

declare(strict_types=1);

namespace App\Domain\Allocation;

use function count;

/**
 * Self-contained, seeded pseudo-random number generator.
 *
 * Chosen over mt_rand() for two reasons (docs/ALLOCATION_ENGINE.md §8):
 *
 *  1. Determinism must not depend on PHP's global PRNG state. Two engine runs in
 *     the same request, or on different PHP builds, must produce identical
 *     streams from the same seed.
 *  2. No global side effects. Seeding this class cannot perturb anything else.
 *
 * ARITHMETIC NOTE — READ BEFORE "OPTIMISING" THIS
 * ------------------------------------------------
 * PHP's integers are signed 64-bit, so there is no unsigned 64-bit type and no
 * wrapping multiply. The textbook 64-bit constants of xorshift128+ and SplitMix64
 * (0x9E3779B97F4A7C15, 0xBF58476D1CE4E5B9, …) are *larger* than PHP_INT_MAX, so
 * PHP silently parses them as floats — and `&` on a float is a TypeError. A
 * 64-bit generator cannot be written safely in plain PHP.
 *
 * This implementation is therefore xorshift128 over two 31-bit words. Every
 * value stays in [0, 2^31), every intermediate fits comfortably in a signed
 * 64-bit int, and the effective period (≈2^62, far more than the engine will
 * ever consume) is indistinguishable from 64 bits for this purpose.
 *
 * Do not "upgrade" this to 64-bit constants. If genuine 64-bit entropy is ever
 * needed, add ext-gmp or ext-uint as an explicit dependency first.
 */
final class Rng
{
    /** Keeps every value inside [0, 2^31). Also the modulus of int(). */
    private const RANGE = 2147483648; // 2^31

    private const MASK = 2147483647; // 2^31 - 1

    private int $s0;
    private int $s1;

    public function __construct(int $seed = 0)
    {
        $this->seed($seed);
    }

    /**
     * (Re)seed the generator. The same seed always produces the same stream.
     *
     * A SplitMix-style avalanche step is applied to the raw seed so that
     * sequential seeds — and the very common seed = 0 — start from well
     * separated states. Without it, seeds 0, 1, 2 … produce correlated opening
     * moves, which is precisely when you least want correlation.
     */
    public function seed(int $seed): void
    {
        // Collapse the seed into 31 bits with an avalanche, so that
        // PHP_INT_MIN, 0 and 2^62 all map to distinct states.
        $z = ($seed & self::MASK) ^ (($seed >> 31) & self::MASK);
        $z = ($z ^ ($z >> 15)) & self::MASK;
        $z = ($z ^ ($z >> 13)) & self::MASK;
        $z = ($z + 0x2545F491) & self::MASK; // 0x2545F491 = 625364625

        $this->s0 = $z === 0
            ? 0x9E3779B9
            : $z;              // 2654435769
        $this->s1 = self::scramble($z ^ 0x7F4A7C15);
    }

    /**
     * Next 31-bit unsigned value as a non-negative int in [0, 2^31).
     */
    public function next(): int
    {
        $x = $this->s0;
        $y = $this->s1;

        $x ^= ($x << 11) & self::MASK;
        $x ^= $x >> 8;

        $y ^= ($y << 13) & self::MASK;
        $y ^= $y >> 19;

        $this->s0 = $y;
        $this->s1 = $x;

        return $x;
    }

    /**
     * Uniform float in [0, 1).
     */
    public function float(): float
    {
        return $this->next() / (float) self::RANGE;
    }

    /**
     * Uniform integer in [$min, $max] inclusive.
     *
     * Rejection sampling removes the modulo bias that plain `$r % $span` would
     * introduce; the bias is small but this engine makes millions of draws and
     * has no reason to accept a known skew.
     */
    public function int(int $min, int $max): int
    {
        if ($min >= $max) {
            return $min;
        }

        $span = $max - $min + 1;

        if ($span > self::RANGE) {
            // A range wider than 31 bits cannot be covered by one draw, so
            // compose two. The recursion terminates because $q is always
            // smaller than $span. Unreachable from the engine, which only ever
            // asks for the size of a candidate list.
            $q = intdiv($span, self::RANGE);

            return $min + ($this->int(0, $q) * self::RANGE) + $this->next();
        }

        // Largest multiple of $span that fits in RANGE. Values at or above the
        // limit would make some residues more likely than others.
        $limit = self::RANGE - (self::RANGE % $span);

        do {
            $r = $this->next();
        } while ($r >= $limit);

        return $min + ($r % $span);
    }

    /**
     * True with probability $p. Clamped to [0,1] so a misconfigured weight can
     * never make a branch unreachable.
     */
    public function chance(float $p): bool
    {
        if ($p <= 0.0) {
            return false;
        }

        if ($p >= 1.0) {
            return true;
        }

        return $this->float() < $p;
    }

    /**
     * Pick one element at random, or null when the array is empty.
     *
     * @template T
     * @param  array<array-key, T> $items
     * @return T|null
     */
    public function pick(array $items): mixed
    {
        if ($items === []) {
            return null;
        }

        $values = array_values($items);

        return $values[$this->int(0, count($values) - 1)];
    }

    /**
     * Pick $count distinct elements, preserving order of selection. Returns
     * fewer when the input holds fewer than $count items.
     *
     * @template T
     * @param  array<array-key, T> $items
     * @return list<T>
     */
    public function sample(array $items, int $count): array
    {
        $values = array_values($items);
        $count = min($count, count($values));

        if ($count <= 0) {
            return [];
        }

        // Partial Fisher-Yates.
        for ($i = 0; $i < $count; $i++) {
            $j = $this->int($i, count($values) - 1);
            [$values[$i], $values[$j]] = [$values[$j], $values[$i]];
        }

        return array_values(array_slice($values, 0, $count));
    }

    /**
     * Fisher-Yates shuffle. Deterministic for a given seed.
     *
     * @template T
     * @param  array<array-key, T> $items
     * @return list<T>
     */
    public function shuffle(array $items): array
    {
        $values = array_values($items);

        for ($i = count($values) - 1; $i > 0; $i--) {
            $j = $this->int(0, $i);
            [$values[$i], $values[$j]] = [$values[$j], $values[$i]];
        }

        return $values;
    }

    /** One SplitMix finalizer step; 31-bit safe (shift and mask only). */
    private static function scramble(int $x): int
    {
        $x &= self::MASK;
        $x = ($x ^ ($x >> 16)) & self::MASK;
        $x = ($x ^ ($x >> 13)) & self::MASK;
        $x = ($x ^ ($x >> 7)) & self::MASK;

        return $x === 0
            ? 0x27BB2EE7
            : $x;
    }
}
