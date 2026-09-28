<?php

declare(strict_types=1);

namespace App\Domain\Allocation\Exception;

use RuntimeException;

/**
 * The problem handed to the engine is structurally unusable — as opposed to
 * merely hard to satisfy.
 *
 * These are operator errors (a misconfigured calendar, a term with no rooms
 * loaded), not scheduling failures. Failing loudly is the point: a silent
 * "0 % allocated" result would be indistinguishable from a genuinely
 * over-subscribed department, and nobody would look for the real cause.
 *
 * A timetable that is simply full — more sessions than the rooms and slots can
 * hold — is NOT this. That is a normal outcome and is reported through
 * UnallocatedSession (FR-ALLOC-05).
 */
final class InfeasibleProblemException extends RuntimeException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function noSessions(): self
    {
        return new self(
            'The scheduling problem contains no sessions. Nothing to allocate; '
            . 'check that cohorts have been enrolled and lecturers assigned.',
        );
    }

    public static function noTeachableSlots(): self
    {
        return new self(
            'The scheduling problem contains sessions but no teachable time slots. '
            . 'At least one time_slot must be active and fall inside a teaching window.',
        );
    }

    public static function noUsableRooms(): self
    {
        return new self(
            'The scheduling problem contains sessions but no serviceable room. '
            . 'Every room is either unbookable, out of scope, or too small for the '
            . 'smallest cohort.',
        );
    }
}
