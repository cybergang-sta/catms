<?php

declare(strict_types=1);

namespace App\Domain\Allocation;

/**
 * A clock the test drives by hand.
 *
 * Time only moves when advance() is called, so a test can assert "the engine
 * stopped after exactly N iterations because the budget ran out" without
 * sleeping, and a golden-file test never sees a different duration.
 */
final class FixedClock implements Clock
{
    private float $seconds;

    public function __construct(float $seconds = 0.0)
    {
        $this->seconds = $seconds;
    }

    public function now(): float
    {
        return $this->seconds;
    }

    public function nowMs(): int
    {
        return (int) round($this->seconds * 1000);
    }

    public function advance(float $seconds): self
    {
        $this->seconds += $seconds;

        return $this;
    }

    /**
     * Jump to an absolute position in the monotonic timeline.
     */
    public function set(float $seconds): self
    {
        $this->seconds = $seconds;

        return $this;
    }
}
