<?php

declare(strict_types=1);

namespace App\Domain\Allocation;

/**
 * A failed hard-constraint check, with a message a human administrator can act on.
 *
 * These are persisted to allocation_conflicts (FR-ALLOC-05), so the message text
 * is user-facing copy, not a debug string.
 */
final class Violation
{
    private function __construct(
        public readonly string $code,
        public readonly string $message,
        public readonly int $sessionId,
        public readonly int $roomId,
        public readonly int $timeSlotId,
    ) {
    }

    public static function of(string $code, string $message, Assignment $candidate): self
    {
        return new self(
            $code,
            $message,
            $candidate->sessionId(),
            $candidate->roomId(),
            $candidate->timeSlotId(),
        );
    }

    /** Raised when an assignment references ids absent from the problem. */
    public static function malformed(Assignment $candidate): self
    {
        return new self(
            'HC-MALFORMED',
            'Assignment references an unknown session, room or time slot.',
            $candidate->sessionId(),
            $candidate->roomId(),
            $candidate->timeSlotId(),
        );
    }

    /** @return array{code:string, message:string, session_id:int, room_id:int, time_slot_id:int} */
    public function toArray(): array
    {
        return [
            'code'         => $this->code,
            'message'      => $this->message,
            'session_id'   => $this->sessionId,
            'room_id'      => $this->roomId,
            'time_slot_id' => $this->timeSlotId,
        ];
    }

    public function __toString(): string
    {
        return $this->code . ': ' . $this->message;
    }
}
