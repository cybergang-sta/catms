<?php

declare(strict_types=1);

namespace App\Domain\Allocation;

/**
 * The production clock. The only place in the domain that touches the machine.
 */
final class SystemClock implements Clock
{
    public function now(): float
    {
        return microtime(true);
    }

    public function nowMs(): int
    {
        return (int) round(microtime(true) * 1000);
    }
}
