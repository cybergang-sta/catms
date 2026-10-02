<?php

declare(strict_types=1);

namespace App\Domain\Allocation;

/**
 * The score of one assignment, with enough detail to explain it.
 *
 * `total`     weighted penalty — the value the optimiser minimises
 * `weighted`  per-term weighted contribution, persisted to
 *             allocations.score_breakdown for the admin "why this room?" view
 * `raw`       per-term normalised value in [0,1], which is what unit tests pin
 */
final class CostBreakdown
{
    /**
     * @param float                $total    weighted total penalty
     * @param array<string, float> $weighted per-term weighted contribution
     * @param array<string, float> $raw      per-term normalised contribution
     */
    public function __construct(
        public readonly float $total,
        public readonly array $weighted = [],
        public readonly array $raw = [],
    ) {
    }

    /**
     * The single term that contributed most, for a one-line explanation.
     */
    public function dominantTerm(): ?string
    {
        if ($this->weighted === []) {
            return null;
        }

        $max = max($this->weighted);
        if ($max <= 0.0) {
            return null;
        }

        foreach ($this->weighted as $term => $value) {
            if ($value === $max) {
                return $term;
            }
        }

        return null;
    }

    /**
     * Human-readable explanation, for FR-ALLOC-04: an administrator overriding an
     * allocation should be able to see the engine's reasoning.
     *
     * @return list<string>
     */
    public function explain(): array
    {
        $labels = [
            'waste'         => 'leaves seats empty',
            'movement'      => 'makes the group change building',
            'churn'         => 'differs from the room already in use',
            'equity'        => 'uses a room that is busier than its peers',
            'preference'    => 'ignores the requested building',
            'tightness'     => 'is larger than the class needs',
            'fragmentation' => 'splits the class across rooms',
            'unknown'       => 'could not be evaluated',
        ];

        $lines = [];
        foreach ($this->weighted as $term => $value) {
            if ($value <= 0.0) {
                continue;
            }

            $lines[] = sprintf('%+.2f  %s', $value, $labels[$term] ?? $term);
        }

        return $lines;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'total'    => round($this->total, 4),
            'weighted' => array_map(static fn (float $v): float => round($v, 4), $this->weighted),
            'raw'      => array_map(static fn (float $v): float => round($v, 4), $this->raw),
        ];
    }
}
