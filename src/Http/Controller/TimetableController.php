<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Core\Exception\NotFoundException;
use App\Core\Exception\ValidationException;
use App\Core\Identity;
use App\Core\Request;
use App\Core\Response;
use App\Domain\Service\TimetableService;
use App\Domain\Timetable\Semester;
use App\Domain\Timetable\SessionEntry;
use App\Domain\Timetable\TeachingWeek;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The timetable read API (FR-TIME-01 … FR-TIME-06).
 *
 * Four actions, all read-only, all scoped by the caller's role rather than by a
 * parameter they could set. `?scope=department` does not exist, and its absence
 * is the point: the only way to widen what a student sees is to change their
 * role, which is an audited administrator action.
 *
 * Authorisation is enforced here rather than in a pipeline stage. The route's
 * `permission` is `timetable:view` for all four, and the call site is the same
 * screen a reviewer is already reading, so the check and the data it protects
 * are reviewed together.
 */
final class TimetableController extends Controller
{
    /**
     * GET /api/v1/timetable
     *
     * The week grid. `?week=N` or `?date=YYYY-MM-DD`; with neither, the current
     * week is used, clamped into the semester so a client in the wrong month
     * gets a real week rather than an empty one.
     */
    public function week(Request $request): Response
    {
        $identity = $this->identity($request);
        $this->rbac()->assert($identity, (string) $request->attribute('route.permission', 'timetable:view'));

        $service = $this->timetable();
        $semester = $this->pickSemester($service, $request, $identity);
        $viewer = $service->viewerFor($identity);

        $week = $this->resolveWeek($request, $semester);

        $body = $service->weekView($semester, $week, $viewer);

        return Response::success(
            $body,
            [
                'scope'   => $viewer->label(),
                'semester' => $semester->name,
                'week'    => $week->number,
                'generated_at' => gmdate('c'),
            ],
        );
    }

    /**
     * GET /api/v1/timetable/day?date=YYYY-MM-DD
     *
     * "What is on today", which is the query a student actually makes and the
     * one the PWA puts on its home screen.
     */
    public function day(Request $request): Response
    {
        $identity = $this->identity($request);
        $this->rbac()->assert($identity, (string) $request->attribute('route.permission', 'timetable:view'));

        $service = $this->timetable();
        $semester = $this->pickSemester($service, $request, $identity);
        $viewer = $service->viewerFor($identity);

        $date = $request->query('date');
        if ($date === null || $date === '') {
            $date = gmdate('Y-m-d');
        }

        $this->assertDate($date);
        $this->assertWithinSemester($semester, $date);

        $body = $service->dayView($semester, $date, $viewer);

        return Response::success(
            $body,
            [
                'scope'   => $viewer->label(),
                'semester' => $semester->name,
                'date'    => $date,
                'generated_at' => gmdate('c'),
            ],
        );
    }

    /**
     * GET /api/v1/timetable/semester
     *
     * Every sitting in the term, flat, plus per-week counts so a client can draw
     * a density bar without walking the whole payload.
     */
    public function semester(Request $request): Response
    {
        $identity = $this->identity($request);
        $this->rbac()->assert($identity, (string) $request->attribute('route.permission', 'timetable:view'));

        $service = $this->timetable();
        $semester = $this->pickSemester($service, $request, $identity);
        $viewer = $service->viewerFor($identity);

        $entries = $service->semesterEntries($semester, $viewer);

        $byWeek = [];
        foreach ($entries as $entry) {
            $byWeek[$entry->weekNumber] = ($byWeek[$entry->weekNumber] ?? 0) + 1;
        }
        if (isset($byWeek[1]) && count($byWeek) === 1) {
            $pattern = $byWeek[1];
            for ($number = 2; $number <= $semester->totalWeeks; $number++) {
                $byWeek[$number] = $pattern;
            }
        }
        ksort($byWeek);

        $weeks = [];
        for ($number = 1; $number <= $semester->totalWeeks; $number++) {
            $weeks[] = [
                'week'     => $number,
                'sessions' => $byWeek[$number] ?? 0,
                'start'    => $semester->week($number)->startDate,
                'end'      => $semester->week($number)->endDate,
            ];
        }

        return Response::success(
            [
                'semester'    => $semester->toArray(),
                'weeks'       => $weeks,
                'total'       => count($entries),
                'entries'     => array_map(
                    static fn (SessionEntry $entry): array => $entry->toDetailArray(),
                    $entries,
                ),
            ],
            [
                'scope'   => $viewer->label(),
                'semester' => $semester->name,
                'generated_at' => gmdate('c'),
            ],
        );
    }

    /**
     * GET /api/v1/timetable/{semesterId}/export.csv
     *
     * FR-REPORT-03. The semester comes from the path so the URL is shareable,
     * but it is still resolved through the same visibility rules — an export is
     * a read, and a read that skips the scope check is a data leak with a
     * `Content-Disposition` header.
     */
    public function export(Request $request): Response
    {
        $identity = $this->identity($request);
        $this->rbac()->assert($identity, (string) $request->attribute('route.permission', 'timetable:view'));

        $service = $this->timetable();
        $viewer = $service->viewerFor($identity);

        $semesterId = $this->param($request, 'semesterId');

        $semesters = $service->resolveSemesters($identity, (string) $semesterId, $identity->isAdmin());
        if ($semesters === []) {
            throw new NotFoundException('Semester', $semesterId);
        }
        $semester = $semesters[0];

        $entries = $service->semesterEntries($semester, $viewer);

        $rows = array_map(
            static fn (SessionEntry $entry): array => $entry->toCsvRow(),
            $entries,
        );

        $filename = sprintf(
            'timetable-%s-%s.csv',
            $semester->name,
            $identity->role(),
        );

        $this->logger()->info('Timetable exported.', [
            'semester_id' => $semester->id,
            'user_id'     => $identity->userId(),
            'rows'        => count($rows),
        ]);

        return Response::csv($filename, TimetableService::csvHeader(), $rows);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Which semester to render.
     *
     * `?semester=` accepts an id or a name. With no parameter, the caller's
     * department's current semester is used. A student whose department has no
     * published semester gets a 404 with a message that says so, rather than an
     * empty grid that reads as "you have no classes this week" — the difference
     * between a misconfiguration and a genuinely free week matters to the person
     * reading it.
     */
    private function pickSemester(TimetableService $service, Request $request, Identity $identity): Semester
    {
        $requested = $request->query('semester');

        $semesters = $service->resolveSemesters($identity, $requested, $identity->isAdmin());

        if ($semesters !== []) {
            if ($requested === null || $requested === '') {
                $today = gmdate('Y-m-d');
                foreach ($semesters as $semester) {
                    if ($semester->startDate <= $today && $today <= $semester->endDate) {
                        return $semester;
                    }
                }
                foreach ($semesters as $semester) {
                    if ($semester->status === 'active') {
                        return $semester;
                    }
                }
            }

            return $semesters[0];
        }

        if ($requested !== null && $requested !== '') {
            throw new NotFoundException('Semester');
        }

        // No semester was named and none matched: fall back to the most recent
        // published one so a term that has just been archived is still readable.
        $fallback = $service->resolveSemesters($identity, null, false);
        if ($fallback !== []) {
            return $fallback[0];
        }

        throw new NotFoundException(
            'Semester for this account. No semester has been published for your department yet.'
        );
    }

    /**
     * Turn `?week=` or `?date=` into a TeachingWeek.
     */
    private function resolveWeek(Request $request, Semester $semester): TeachingWeek
    {
        $date = $request->query('date');
        if ($date !== null && $date !== '') {
            $this->assertDate($date);

            return $semester->weekFor($date);
        }

        $week = $request->queryInt('week');
        if ($week === null) {
            $today = $request->query('today');

            return $semester->weekFor(is_string($today) && $today !== '' ? $this->assertDate($today) : gmdate('Y-m-d'));
        }

        return $semester->week($week);
    }

    private function assertDate(string $date): string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            throw ValidationException::field('date', 'Use a date in YYYY-MM-DD form.');
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'));
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            // Catches 2026-02-30 and friends, which createFromFormat accepts by
            // rolling over. A rolled-over date would silently return the wrong
            // week, which is worse than a validation error.
            throw ValidationException::field('date', 'That date does not exist.');
        }

        return $date;
    }

    /**
     * A date outside the semester is a 422, not an empty week.
     */
    private function assertWithinSemester(Semester $semester, string $date): void
    {
        if ($date < $semester->startDate || $date > $semester->endDate) {
            throw ValidationException::field(
                'date',
                sprintf(
                    'That date is outside semester %s (%s to %s).',
                    $semester->name,
                    $semester->startDate,
                    $semester->endDate,
                ),
            );
        }
    }

    private function timetable(): TimetableService
    {
        /** @var TimetableService $service */
        $service = $this->service(TimetableService::class);

        return $service;
    }
}
