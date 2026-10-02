<?php

declare(strict_types=1);

namespace App\Domain\Allocation;

use function count;

/**
 * Scores a feasible assignment. Lower is better; 0 is a perfect placement.
 *
 * Every term is kept separately in the breakdown, so when an administrator asks
 * "why this room?" they get seven concrete reasons rather than one number. That
 * explainability is what makes a manual override (FR-ALLOC-04) an informed
 * decision rather than a guess.
 *
 *   penalty = W_waste·waste + W_move·moved + W_churn·churn + W_equity·equity
 *           + W_pref·prefMiss + W_tight·tightness + W_frag·frag
 *
 * LIFECYCLE
 * ---------
 * The immutable context (prior timetable, room utilisation, slot count) is set
 * once per engine run via withContext(), which returns a *new* instance. The
 * mutable usage ledger is then maintained incrementally by the engine through
 * noteUse() and forgetUse() as placements move around.
 *
 * That split matters: the engine evaluates a candidate *before* committing it, so
 * the ledger must be reversible. forgetUse() is what makes a move evaluation
 * honest rather than drifting further from reality with every iteration.
 */
final class CostFunction
{
    /** @var array<int, float> roomId => booked/available ratio from the prior timetable */
    private array $roomUtilisation = [];

    /** @var array<string, float> capacity band => mean utilisation in that band */
    private array $bandMeanUtilisation = [];

    /** @var array<int, int> sessionId => roomId from the prior timetable */
    private array $previousRoomBySession = [];

    /** @var array<int, string|null> cohortId => building the cohort starts from */
    private array $cohortPreviousBuilding = [];

    /** Number of teachable slots in the week — the denominator for utilisation. */
    private int $slotsPerWeek = 1;

    /**
     * Usage ledger: cohortId => roomId => times used this run.
     *
     * Counting references rather than distinct rooms is deliberate: forgetUse()
     * can then remove exactly one reference, so a cohort with two sessions in
     * the same room still reads as "one room" after one of them moves away.
     *
     * @var array<int, array<int, int>>
     */
    private array $cohortRoomUse = [];

    public function __construct(private readonly CostWeights $weights)
    {
    }

    public function weights(): CostWeights
    {
        return $this->weights;
    }

    /**
     * Derive a fresh cost function for one engine run.
     *
     * Returns a new instance rather than mutating `$this`, so two solves in the
     * same process (a batch job re-running a semester, a test looping over
     * seeds) can never contaminate each other's ledger.
     *
     * @param array<int, float>       $roomUtilisation       roomId => booked/available
     * @param array<int, Room>        $rooms                 needed to group utilisation by capacity band
     * @param array<int, int>         $previousRoomBySession sessionId => prior roomId
     * @param array<int, string|null> $cohortPreviousBuilding cohortId => prior building
     */
    public function withContext(
        array $roomUtilisation,
        array $rooms,
        array $previousRoomBySession,
        array $cohortPreviousBuilding,
        int $slotsPerWeek = 1,
    ): self {
        $clone = new self($this->weights);
        $clone->roomUtilisation = $roomUtilisation;
        $clone->previousRoomBySession = $previousRoomBySession;
        $clone->cohortPreviousBuilding = $cohortPreviousBuilding;
        $clone->slotsPerWeek = max(1, $slotsPerWeek);
        $clone->bandMeanUtilisation = self::computeBandMeans($roomUtilisation, $rooms);

        return $clone;
    }

    /**
     * Record that $roomId is now used by $cohortId, so the fragmentation term of
     * a sibling session of the same cohort reflects it.
     */
    public function noteUse(int $roomId, int $timeSlotId, int $cohortId): void
    {
        unset($timeSlotId); // slot identity is irrelevant to the room-count term

        $this->cohortRoomUse[$cohortId][$roomId] = ($this->cohortRoomUse[$cohortId][$roomId] ?? 0) + 1;
    }

    /**
     * Undo exactly one noteUse(). The engine must call this whenever it releases a
     * placement during local search.
     */
    public function forgetUse(int $roomId, int $timeSlotId, int $cohortId): void
    {
        unset($timeSlotId);

        if (! isset($this->cohortRoomUse[$cohortId][$roomId])) {
            return;
        }

        $remaining = $this->cohortRoomUse[$cohortId][$roomId] - 1;

        if ($remaining > 0) {
            $this->cohortRoomUse[$cohortId][$roomId] = $remaining;
        } else {
            unset($this->cohortRoomUse[$cohortId][$roomId]);
        }
    }

    /**
     * How many distinct rooms $cohortId is currently spread across.
     */
    public function distinctRoomsFor(int $cohortId): int
    {
        return count($this->cohortRoomUse[$cohortId] ?? []);
    }

    /**
     * Full evaluation. Returns the weighted total plus both the weighted terms
     * (for the admin breakdown) and the raw normalised terms (for tests).
     */
    public function evaluate(Assignment $candidate, SchedulingProblem $problem): CostBreakdown
    {
        $session = $problem->sessionById($candidate->sessionId());
        $room = $problem->roomById($candidate->roomId());

        if ($session === null || $room === null) {
            // Unscoreable: make it uncompetitive rather than accidentally free.
            return new CostBreakdown(PHP_FLOAT_MAX, ['unknown' => 1.0], ['unknown' => 1.0]);
        }

        $enrolled = max(1, $session->enrolledCount());
        $capacity = max(1, $room->capacity());

        // --- waste: fraction of seats that will sit empty (OBJ-3) -----------
        $waste = max(0.0, ($capacity - $enrolled) / $capacity);

        // --- tightness: an exact fit scores 0, a grossly oversized room ~1 ----
        $tightness = min(1.0, $waste * 2.0);

        // --- movement: did the cohort have to cross buildings? ---------------
        $previousBuilding = $this->cohortPreviousBuilding[$session->cohortId()] ?? null;
        $movement = ($previousBuilding !== null && $previousBuilding !== $room->building())
            ? 1.0
            : 0.0;

        // --- churn: a different room than the one already in use? -----------
        $previousRoomId = $this->previousRoomBySession[$session->id()] ?? null;
        $churn = ($previousRoomId !== null && $previousRoomId !== $room->id())
            ? 1.0
            : 0.0;

        // --- equity: is this an under-used room of comparable size? ----------
        $equity = $this->equityTerm($room);

        // --- preference: did we honour the course's requested building? ------
        $preferred = $session->preferredBuilding();
        $preferenceMiss = ($preferred !== null && $preferred !== $room->building())
            ? 1.0
            : 0.0;

        // --- fragmentation: is the class being spread across rooms? ----------
        // 0 for the first room, 0.5 for a second, saturating at 1.0 for a third.
        $fragmentation = min(1.0, $this->distinctRoomsFor($session->cohortId()) / 2.0);

        $raw = [
            'waste'         => $waste,
            'movement'      => $movement,
            'churn'         => $churn,
            'equity'        => $equity,
            'preference'    => $preferenceMiss,
            'tightness'     => $tightness,
            'fragmentation' => $fragmentation,
        ];

        $w = $this->weights;

        $weighted = [
            'waste'         => $w->waste * $waste,
            'movement'      => $w->movement * $movement,
            'churn'         => $w->churn * $churn,
            'equity'        => $w->equity * $equity,
            'preference'    => $w->preference * $preferenceMiss,
            'tightness'     => $w->tightness * $tightness,
            'fragmentation' => $w->fragmentation * $fragmentation,
        ];

        return new CostBreakdown(array_sum($weighted), $weighted, $raw);
    }

    /**
     * Utilisation of $room *including* this candidate, compared with the mean of
     * its capacity band. Rewards placing a class in an under-used room of
     * similar size; never penalises a room already at or above the band mean.
     */
    private function equityTerm(Room $room): float
    {
        $baseline = $this->roomUtilisation[$room->id()] ?? 0.0;
        $mean = $this->bandMeanUtilisation[$room->capacityBand()] ?? 0.0;

        if ($mean <= 0.0) {
            return 0.0; // no prior utilisation anywhere: nothing to be fair about
        }

        // One extra booking out of a week of slots.
        $projected = min(1.0, $baseline + (1.0 / $this->slotsPerWeek));

        return max(0.0, ($mean - $projected) / $mean);
    }

    /**
     * Utilisation is only meaningful relative to comparable rooms — a 200-seat
     * hall can never reach the utilisation of a 20-seat seminar room. So the
     * mean is taken per capacity band, not globally.
     *
     * @param  array<int, float> $roomUtilisation
     * @param  array<int, Room>  $rooms
     * @return array<string, float>
     */
    private static function computeBandMeans(array $roomUtilisation, array $rooms): array
    {
        $sums   = [];
        $counts = [];

        foreach ($roomUtilisation as $roomId => $ratio) {
            $room = $rooms[$roomId] ?? null;
            if ($room === null) {
                continue;
            }

            $band = $room->capacityBand();
            $sums[$band] = ($sums[$band] ?? 0.0) + $ratio;
            $counts[$band] = ($counts[$band] ?? 0) + 1;
        }

        $means = [];
        foreach ($sums as $band => $sum) {
            $means[$band] = $sum / $counts[$band];
        }

        return $means;
    }
}
