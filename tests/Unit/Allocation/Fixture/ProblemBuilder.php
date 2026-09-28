<?php

declare(strict_types=1);

namespace Tests\Unit\Allocation\Fixture;

use App\Domain\Allocation\CostFunction;
use App\Domain\Allocation\CostWeights;
use App\Domain\Allocation\Room;
use App\Domain\Allocation\RoomFeatures;
use App\Domain\Allocation\SchedulingProblem;
use App\Domain\Allocation\SessionRequest;
use App\Domain\Allocation\TimeSlot;

/**
 * Fluent builder for scheduling problems.
 *
 * Every argument has a default, so a test only states the thing it is actually
 * about. "Cohort 1 needs 30 seats, room 1 has 20" is a three-line test here; the
 * alternative — 200 lines of unrelated setup repeated in every file — makes the
 * interesting line impossible to find.
 *
 * Ids are assigned automatically unless given explicitly, because every
 * assertion in the suite refers to them by name.
 */
final class ProblemBuilder
{
    /** @var list<SessionRequest> */
    private array $sessions = [];

    /** @var list<Room> */
    private array $rooms = [];

    /** @var list<TimeSlot> */
    private array $slots = [];

    /** @var list<array{lecturer_id: int, slot_id?: int|null, day_of_week?: int|null}> */
    private array $lecturerUnavailable = [];

    /** @var list<int> */
    private ?array $teachableSlotIds = null;

    private int $nextSessionId = 1;

    private int $nextRoomId = 1;

    private int $nextSlotId = 1;

    private ?CostWeights $weights = null;

    private int $departmentId = 1;

    public function session(
        int $cohortId,
        int $lecturerId,
        int $enrolledCount = 30,
        int $durationMinutes = 60,
        array $requiredFeatures = [],
        ?string $preferredBuilding = null,
        int $sequence = 0,
        ?int $departmentId = null,
        ?string $label = null,
        ?int $id = null,
    ): self {
        $this->sessions[] = new SessionRequest(
            $id ?? $this->nextSessionId++,
            $cohortId,
            $cohortId, // courseId tracks the cohort in these fixtures
            $lecturerId,
            $enrolledCount,
            $durationMinutes,
            new RoomFeatures($requiredFeatures),
            $sequence,
            $preferredBuilding,
            $departmentId ?? $this->departmentId,
            $label ?? "C{$cohortId}S{$sequence}",
        );

        return $this;
    }

    public function room(
        int $capacity = 50,
        array $features = [],
        string $building = 'Main',
        string $status = 'available',
        bool $bookable = true,
        ?int $departmentId = null,
        bool $shared = false,
        array $unavailableSlotIds = [],
        ?string $code = null,
        ?int $id = null,
    ): self {
        $assignedId = $id ?? $this->nextRoomId++;

        $this->rooms[] = new Room(
            $assignedId,
            $code ?? "R{$assignedId}",
            "Room {$assignedId}",
            $building,
            $capacity,
            new RoomFeatures($features),
            $status,
            $bookable,
            $departmentId,
            $shared,
            $unavailableSlotIds,
        );

        return $this;
    }

    /**
     * @param int $dayOfWeek ISO-8601: 1 = Monday … 7 = Sunday
     */
    public function slot(
        int $dayOfWeek = 1,
        string $startTime = '08:00',
        string $endTime = '09:00',
        bool $active = true,
        ?int $id = null,
    ): self {
        $this->slots[] = new TimeSlot(
            $id ?? $this->nextSlotId++,
            $dayOfWeek,
            $startTime,
            $endTime,
            '',
            $active,
        );

        return $this;
    }

    /**
     * A full teaching week: five days, four slots each, 08:00–16:00.
     */
    public function standardWeek(int $slotsPerDay = 4): self
    {
        $times = [
            ['08:00', '09:00'],
            ['10:00', '11:00'],
            ['12:00', '13:00'],
            ['14:00', '15:00'],
            ['16:00', '17:00'],
        ];

        for ($day = 1; $day <= 5; $day++) {
            for ($i = 0; $i < $slotsPerDay; $i++) {
                $this->slot($day, $times[$i][0], $times[$i][1]);
            }
        }

        return $this;
    }

    public function lecturerUnavailableSlot(int $lecturerId, int $slotId): self
    {
        $this->lecturerUnavailable[] = ['lecturer_id' => $lecturerId, 'slot_id' => $slotId];

        return $this;
    }

    public function lecturerUnavailableDay(int $lecturerId, int $dayOfWeek): self
    {
        $this->lecturerUnavailable[] = ['lecturer_id' => $lecturerId, 'day_of_week' => $dayOfWeek];

        return $this;
    }

    /**
     * Restrict scheduling to the given slot ids. Everything else becomes
     * untouchable, which is how the "no teaching window" cases are built.
     *
     * @param list<int> $slotIds
     */
    public function teachableSlots(array $slotIds): self
    {
        $this->teachableSlotIds = $slotIds;

        return $this;
    }

    public function departmentId(int $departmentId): self
    {
        $this->departmentId = $departmentId;

        return $this;
    }

    public function weights(CostWeights $weights): self
    {
        $this->weights = $weights;

        return $this;
    }

    public function costFunction(): CostFunction
    {
        return new CostFunction($this->weights ?? CostWeights::balanced());
    }

    public function build(): SchedulingProblem
    {
        $problem = new SchedulingProblem($this->weights);
        $problem->withRooms($this->rooms);
        $problem->withSlots($this->slots, $this->teachableSlotIds);
        $problem->withSessions($this->sessions);
        $problem->withLecturerUnavailability($this->lecturerUnavailable);

        return $problem;
    }

    // --- accessors used by assertions -------------------------------------

    public function sessionIds(): array
    {
        return array_map(static fn (SessionRequest $s): int => $s->id(), $this->sessions);
    }

    public function roomIds(): array
    {
        return array_map(static fn (Room $r): int => $r->id(), $this->rooms);
    }

    public function slotIds(): array
    {
        return array_map(static fn (TimeSlot $s): int => $s->id(), $this->slots);
    }
}
