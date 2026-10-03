<?php

declare(strict_types=1);

namespace App\Domain\Timetable;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * The week a timetable view is anchored to, resolved from whatever the client
 * happened to send.
 *
 * WHY THIS IS A VALUE OBJECT AND NOT A QUERY PARAMETER PASSED THROUGH
 * `?week=` arrives as a string, `?date=` arrives as a different string, and both
 * have to become the same thing: a clamped week number plus the seven calendar
 * dates it covers. Doing that resolution once, here, means every consumer — the
 * week view, the day view, the CSV export — agrees on what "week 3" means, and a
 * client cannot put the application into a state where `week=99` returns an
 * empty grid that looks like "no classes scheduled".
 *
 * A department's week numbering starts at the semester's `start_date`, not at
 * `teaching_start`. They are frequently the same day, and when they are not the
 * difference is exactly the orientation week, which is week 1 of the grid and
 * must be reachable.
 */
final class TeachingWeek
{
    private const ISO_DAY_NAMES = [
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
        7 => 'Sunday',
    ];

    /**
     * @param int    $number    1-based, clamped into 1..$totalWeeks
     * @param string $startDate First calendar date of the week, `Y-m-d`
     * @param string $endDate   Last calendar date of the week, `Y-m-d`
     */
    private function __construct(
        public readonly int $number,
        public readonly int $totalWeeks,
        public readonly string $startDate,
        public readonly string $endDate,
    ) {
    }

    /**
     * @param int $totalWeeks
     */
    public static function fromNumber(int $number, string $semesterStart, int $totalWeeks): self
    {
        $totalWeeks = max(1, $totalWeeks);
        $number = min($totalWeeks, max(1, $number));

        $start = self::parseDate($semesterStart);
        $start = $start->modify(sprintf('%+d days', ($number - 1) * 7));

        return new self(
            $number,
            $totalWeeks,
            self::format($start),
            self::format($start->modify('+6 days')),
        );
    }

    /**
     * Map a calendar date onto the week that contains it.
     *
     * A date before the semester starts is week 1 rather than a negative number:
     * the client asked for a week and an empty week is a worse answer than the
     * first week, and a negative `week_number` would be an invalid `allocations`
     * key. A date past the end is the last week, for the same reason.
     */
    public static function fromDate(string $date, string $semesterStart, int $totalWeeks): self
    {
        $totalWeeks = max(1, $totalWeeks);
        $target = self::parseDate($date);
        $start = self::parseDate($semesterStart);

        $elapsedDays = (int) $start->diff($target)->format('%r%a');
        $number = (int) floor($elapsedDays / 7) + 1;

        return self::fromNumber($number, $semesterStart, $totalWeeks);
    }

    /**
     * The seven calendar dates of the week, in calendar order from the start date.
     *
     * Note that this is *not* Monday..Sunday when the semester starts mid-week.
     * Callers that need to place a date in a grid column should key off the ISO
     * weekday of each date rather than off its position in this list, because
     * `time_slots.day_of_week` is a true ISO weekday.
     *
     * @return list<string>
     */
    public function dates(): array
    {
        $dates = [];
        for ($offset = 0; $offset < 7; $offset++) {
            $dates[] = self::format(self::parseDate($this->startDate)->modify(sprintf('%+d days', $offset)));
        }

        return $dates;
    }

    /**
     * @return list<array{iso: int, short: string, name: string}>
     */
    public static function weekdayLabels(): array
    {
        $labels = [];
        foreach (self::ISO_DAY_NAMES as $iso => $name) {
            $labels[] = [
                'iso'   => $iso,
                'short' => substr($name, 0, 3),
                'name'  => $name,
            ];
        }

        return $labels;
    }

    public static function weekdayName(int $iso): string
    {
        return self::ISO_DAY_NAMES[$iso] ?? ('Day ' . $iso);
    }

    private static function parseDate(string $date): DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'));
        if ($parsed === false) {
            // An unparseable date from a query string is a client error, not an
            // internal fault, so it is a plain InvalidArgumentException that the
            // controller turns into a 422 rather than a 500.
            throw new InvalidArgumentException(sprintf('"%s" is not a valid Y-m-d date.', $date));
        }

        return $parsed;
    }

    private static function format(DateTimeImmutable $date): string
    {
        return $date->format('Y-m-d');
    }
}
