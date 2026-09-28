<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Mysql;

/**
 * What a write actually did, for the command's summary line and for tests.
 */
final class AllocationWriteResult
{
    public function __construct(
        public readonly string $runId,
        public readonly bool $applied,
        public readonly int $allocationsWritten,
        public readonly int $conflictsWritten,
        public readonly int $supersededRows,
        public readonly int $violationsRejected,
        public readonly float $accuracy,
        public readonly int $durationMs,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'run_id'            => $this->runId,
            'applied'           => $this->applied,
            'allocations'       => $this->allocationsWritten,
            'conflicts'         => $this->conflictsWritten,
            'superseded'        => $this->supersededRows,
            'violations'        => $this->violationsRejected,
            'accuracy'          => $this->accuracy,
            'duration_ms'       => $this->durationMs,
        ];
    }
}
