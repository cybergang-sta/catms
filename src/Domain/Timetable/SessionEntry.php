<?php

declare(strict_types=1);

namespace App\Domain\Timetable;

/**
 * One published sitting, as the read side needs it.
 *
 * Everything a timetable cell has to draw is resolved by a single query, so
 * building a cell never issues a query of its own. That is the whole reason this
 * is a flat value object with primitives in it rather than a set of lazily
 * loaded relations: N+1 in a timetable grid is 40 queries for one week, and on
 * the mobile connections the report is about, that is the difference between
 * meeting the 3-second target and not.
 *
 * A cancelled or draft allocation is not represented. `TimetableService` never
 * constructs one, which is what makes "you cannot be shown a cancelled class"
 * a type-level guarantee rather than a `WHERE` clause someone has to remember.
 */
final class SessionEntry
{
    /**
     * @param list<string> $courseFeatures
     * @param list<string> $roomFeatures
     */
    public function __construct(
        public readonly int $allocationId,
        public readonly int $cohortId,
        public readonly string $cohortName,
        public readonly int $headcount,
        public readonly string $courseCode,
        public readonly string $courseTitle,
        public readonly int $courseLevel,
        public readonly string $courseFeatures,
        public readonly int $lecturerId,
        public readonly string $lecturerName,
        public readonly int $roomId,
        public readonly string $roomCode,
        public readonly string $roomName,
        public readonly string $roomBuilding,
        public readonly ?int $roomFloor,
        public readonly int $roomCapacity,
        public readonly string $roomType,
        public readonly string $status,
        public readonly string $source,
        public readonly bool $isOverride,
        public readonly ?string $overrideReason,
        public readonly int $weekNumber,
        public readonly int $dayOfWeek,
        public readonly string $startTime,
        public readonly string $endTime,
        public readonly string $slotLabel,
        public readonly int $slotId,
    ) {
    }

    /**
     * Compact shape for the grid. This is the payload the PWA renders, so it is
     * deliberately flat and short-keyed: a week of 30 sittings is ~4 KB of JSON
     * this way, and the difference is visible on a metered 3G connection.
     *
     * @return array<string, mixed>
     */
    public function toGridArray(): array
    {
        return [
            'id'        => $this->allocationId,
            'cohort'    => ['id' => $this->cohortId, 'name' => $this->cohortName, 'headcount' => $this->headcount],
            'course'    => [
                'code'   => $this->courseCode,
                'title'  => $this->courseTitle,
                'level'  => $this->courseLevel,
                'needs'  => $this->courseFeatures,
            ],
            'lecturer'  => ['id' => $this->lecturerId, 'name' => $this->lecturerName],
            'room'      => [
                'id'       => $this->roomId,
                'code'     => $this->roomCode,
                'name'     => $this->roomName,
                'building' => $this->roomBuilding,
                'floor'    => $this->roomFloor,
                'capacity' => $this->roomCapacity,
                'type'     => $this->roomType,
            ],
            'slot'      => [
                'id'    => $this->slotId,
                'label' => $this->slotLabel,
                'start' => $this->startTime,
                'end'   => $this->endTime,
                'day'   => $this->dayOfWeek,
            ],
            'status'    => $this->status,
            'source'    => $this->source,
            'override'  => $this->isOverride,
            'why'       => $this->overrideReason,
            'week'      => $this->weekNumber,
        ];
    }

    /**
     * The full row for the day view and the CSV export, where there is room and
     * the reader is looking for detail rather than scanning.
     *
     * @return array<string, mixed>
     */
    public function toDetailArray(): array
    {
        return [
            'allocation_id' => $this->allocationId,
            'week_number'   => $this->weekNumber,
            'day_of_week'   => $this->dayOfWeek,
            'start_time'    => $this->startTime,
            'end_time'      => $this->endTime,
            'slot_label'    => $this->slotLabel,
            'course_code'   => $this->courseCode,
            'course_title'  => $this->courseTitle,
            'course_level'  => $this->courseLevel,
            'cohort_id'     => $this->cohortId,
            'cohort_name'   => $this->cohortName,
            'headcount'     => $this->headcount,
            'lecturer_id'   => $this->lecturerId,
            'lecturer_name' => $this->lecturerName,
            'room_id'       => $this->roomId,
            'room_code'     => $this->roomCode,
            'room_name'     => $this->roomName,
            'building'      => $this->roomBuilding,
            'floor'         => $this->roomFloor,
            'capacity'      => $this->roomCapacity,
            'room_type'     => $this->roomType,
            'status'        => $this->status,
            'source'        => $this->source,
            'is_override'   => $this->isOverride,
            'override_reason' => $this->overrideReason,
        ];
    }

    /**
     * @return list<string>
     */
    public function toCsvRow(): array
    {
        return [
            (string) $this->weekNumber,
            (string) $this->dayOfWeek,
            $this->startTime,
            $this->endTime,
            $this->courseCode,
            $this->courseTitle,
            $this->cohortName,
            (string) $this->headcount,
            $this->lecturerName,
            $this->roomCode,
            $this->roomName,
            $this->roomBuilding,
            $this->roomFloor === null ? '' : (string) $this->roomFloor,
            (string) $this->roomCapacity,
            $this->roomType,
            $this->status,
            $this->source,
            $this->overrideReason ?? '',
        ];
    }
}
