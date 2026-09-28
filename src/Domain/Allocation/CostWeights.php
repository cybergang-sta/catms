<?php

declare(strict_types=1);

namespace App\Domain\Allocation;

/**
 * Tunable weights for the soft-preference cost function.
 *
 * Changing a weight is a tuning decision, not a code detail: re-run
 * GoldenFileTest and the benchmark, and record the before/after accuracy and
 * penalty in the pull request (docs/ALLOCATION_ENGINE.md §10).
 */
final class CostWeights
{
    public function __construct(
        /** Penalty for occupying seats nobody will use. Objective OBJ-3. */
        public readonly float $waste = 1.00,
        /** Penalty for making a student change building between classes. */
        public readonly float $movement = 0.60,
        /** Penalty for a room that differs from last week's. */
        public readonly float $churn = 0.45,
        /** Penalty for under-using a room relative to its capacity band. */
        public readonly float $equity = 0.50,
        /** Penalty for ignoring the course's preferred building. */
        public readonly float $preference = 0.30,
        /** Penalty for a room far larger than strictly necessary. */
        public readonly float $tightness = 0.25,
        /** Penalty for splitting a course across rooms within one week. */
        public readonly float $fragmentation = 0.35,
    ) {
    }

    public static function balanced(): self
    {
        return new self();
    }

    /**
     * Utilisation-first: used when the department's stated priority is making
     * the most of limited teaching space.
     */
    public static function utilisationFirst(): self
    {
        return new self(
            waste: 1.60,
            movement: 0.35,
            churn: 0.30,
            equity: 0.85,
            preference: 0.15,
            tightness: 0.45,
            fragmentation: 0.25,
        );
    }

    /**
     * Stability-first: used when students are already complaining about rooms
     * changing week to week.
     */
    public static function stabilityFirst(): self
    {
        return new self(
            waste: 0.70,
            movement: 0.85,
            churn: 1.10,
            equity: 0.30,
            preference: 0.45,
            tightness: 0.15,
            fragmentation: 0.75,
        );
    }

    /** @return array<string, float> */
    public function toArray(): array
    {
        return [
            'waste'         => $this->waste,
            'movement'      => $this->movement,
            'churn'         => $this->churn,
            'equity'        => $this->equity,
            'preference'    => $this->preference,
            'tightness'     => $this->tightness,
            'fragmentation' => $this->fragmentation,
        ];
    }
}
