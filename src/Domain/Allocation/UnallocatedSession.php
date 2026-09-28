<?php

declare(strict_types=1);

namespace App\Domain\Allocation;

/**
 * A session the engine could not place, with enough evidence to act on.
 *
 * FR-ALLOC-05 requires this to be surfaced rather than swallowed. An
 * administrator who sees "Cohort #7 cannot be scheduled: no room seats 120
 * (14 combinations); every room with a projector is already booked (9
 * combinations)" can fix the timetable. An administrator who sees a missing row
 * cannot.
 */
final class UnallocatedSession
{
    /**
     * @param int                       $sessionId
     * @param int                       $cohortId
     * @param int                       $courseId
     * @param int                       $consideredCombinations Slot/room pairs examined.
     * @param array<string, int>        $blockingConstraints   Constraint code => times it
     *                                                           eliminated a combination.
     *                                                           Several constraints can
     *                                                           reject the same pair, so
     *                                                           this sums to more than
     *                                                           $consideredCombinations.
     * @param string                    $summary               One-line human explanation.
     */
    public function __construct(
        public readonly int $sessionId,
        public readonly int $cohortId,
        public readonly int $courseId,
        public readonly int $consideredCombinations,
        public readonly array $blockingConstraints,
        public readonly string $summary,
    ) {
    }

    /**
     * The single most common blocking constraint, or null when the counts are
     * empty. This is what the admin UI shows as the headline reason.
     */
    public function primaryConstraint(): ?string
    {
        if ($this->blockingConstraints === []) {
            return null;
        }

        $top = max($this->blockingConstraints);

        foreach ($this->blockingConstraints as $code => $count) {
            if ($count === $top) {
                return $code;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'session_id'              => $this->sessionId,
            'cohort_id'               => $this->cohortId,
            'course_id'               => $this->courseId,
            'considered_combinations' => $this->consideredCombinations,
            'blocking_constraints'    => $this->blockingConstraints,
            'primary_constraint'      => $this->primaryConstraint(),
            'summary'                 => $this->summary,
        ];
    }
}
