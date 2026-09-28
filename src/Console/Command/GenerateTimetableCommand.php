<?php

declare(strict_types=1);

namespace App\Console\Command;

use App\Console\Input;
use App\Console\Kernel;
use App\Console\Output;
use App\Core\Database;
use App\Core\Logger;
use App\Domain\Allocation\AllocationEngine;
use App\Domain\Allocation\CandidateGenerator;
use App\Domain\Allocation\ConstraintChecker;
use App\Domain\Allocation\CostFunction;
use App\Domain\Allocation\CostWeights;
use App\Domain\Allocation\EngineOptions;
use App\Domain\Allocation\SchedulingResult;
use App\Domain\Allocation\UnallocatedSession;
use App\Domain\Allocation\Violation;
use App\Infrastructure\Persistence\Mysql\AllocationWriter;
use App\Infrastructure\Persistence\Mysql\AllocationWriteResult;
use App\Infrastructure\Persistence\Mysql\LoadedProblem;
use App\Infrastructure\Persistence\Mysql\SchedulingProblemLoader;
use InvalidArgumentException;
use Throwable;

/**
 * `bin/console generate` — the CLI entry point to the allocation engine.
 *
 * IT IS THE SAME CODE PATH AS THE API
 * `docs/ARCHITECTURE.md` §12 makes a point of this: a timetable produced by the
 * Saturday 02:30 job and a timetable produced by an administrator clicking
 * "Generate" come out of the same `SchedulingProblemLoader`, the same
 * `AllocationEngine` and the same `AllocationWriter`. A batch path that forked
 * from the HTTP one is how a "the nightly job produced something different"
 * incident happens.
 *
 * FOUR WAYS TO CALL IT, THREE DIFFERENT RISKS
 *   `--dry-run`      solves and reports, writes nothing at all. Start here.
 *   `--advisory`     writes the run and its conflicts, replaces nothing. This is
 *                    what the unattended nightly job uses, because a job with no
 *                    human watching must not be able to publish a timetable.
 *   (default)        applies, after a dry run has been reviewed.
 *   `--repair-day=N` re-solves one weekday and leaves every other day of the
 *                    published timetable byte-identical.
 *
 * THE ACCURACY GATE
 * `ENGINE_ACCURACY_GATE` (0.90, NFR-PERF-04) is checked before anything is
 * applied. Below the gate the run is still recorded — recording a bad run is how
 * you find out *why* it was bad — but the timetable is not replaced, and the
 * exit code is 1. An operator can override with `--force-accuracy`, which is
 * spelled out rather than hidden because overriding a published acceptance
 * criterion has to leave a trace in the shell history and the CI log.
 */
final class GenerateTimetableCommand extends Command
{
    /** Days of the week, for the `--repair-day` and `--exclude-day` messages. */
    private const DAY_NAMES = [
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
        7 => 'Sunday',
    ];

    public function name(): string
    {
        return 'generate';
    }

    public function description(): string
    {
        return 'Solve the allocation engine for a department and semester, then report or apply';
    }

    /** @return list<string> */
    public function aliases(): array
    {
        return ['app:generate', 'allocation:generate', 'generate-timetable'];
    }

    /** @return list<string> */
    public function synopsis(): array
    {
        return [
            'php bin/console generate --semester=2026-A --department=1 --dry-run',
            'php bin/console generate --semester=2026-A --seed=42',
            'php bin/console generate --semester=2026-A --advisory',
            'php bin/console generate --semester=2026-A --repair-day=1',
            'php bin/console generate --semester=2026-A --exhaustive --json',
        ];
    }

    /** @return array<string, string> */
    public function options(): array
    {
        return [
            'semester'      => 'Semester id or code, e.g. --semester=3 or --semester=2026-A (required)',
            'department'    => 'Department id or code, e.g. --department=1 or --department=CS (required)',
            'dry-run'       => 'Solve and report only. Nothing is written, not even a run row',
            'advisory'      => 'Record the run and its conflicts, but do not replace the timetable',
            'repair-day'    => 'Re-solve one weekday (1=Monday … 7=Sunday) and leave the rest untouched',
            'exclude-day'   => 'Remove a weekday from the teachable set entirely. Repeatable or comma-separated',
            'exhaustive'    => 'Unlimited search with a one-hour budget, for an overnight batch run',
            'seed'          => 'RNG seed, recorded on the run. Defaults to ENGINE_SEED',
            'iterations'    => 'Local-search steps. Defaults to ENGINE_MAX_ITERATIONS',
            'time-budget'   => 'Wall-clock ceiling in seconds. Defaults to ENGINE_TIME_BUDGET_SECONDS',
            'weights'       => 'Cost-weight profile: ' . implode(', ', $this->weightNames()),
            'status'        => 'Report the current published timetable without solving anything',
            'triggered-by'  => 'users.id recorded as the operator on allocation_runs',
            'force-accuracy' => 'Apply even when accuracy is below ENGINE_ACCURACY_GATE',
            'force'         => 'Proceed even when another run holds the generation lock',
            'json'          => 'Machine-readable output; nothing else is written to stdout',
        ];
    }

    /** @return list<string> */
    public function notes(): array
    {
        return [
            'Always --dry-run first. The apply is transactional, but a bad plan is cheaper to discard.',
            '--semester and --department accept an id or a code; an ambiguous code is an error, not a guess.',
            'The results are deterministic for a fixed --seed and --iterations, and are recorded on the run.',
            'A recurring weekly grid cannot express a single non-teaching date, so the command warns',
            'about calendar exceptions it could not apply rather than silently ignoring them.',
            'Overlapping runs are prevented by GET_LOCK on (department, semester). --force overrides it.',
        ];
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function run(Input $input, Output $output): int
    {
        $unknown = $input->unknownOptions([
            'semester', 'department', 'dry-run', 'advisory', 'repair-day', 'exclude-day',
            'exhaustive', 'seed', 'iterations', 'time-budget', 'weights', 'status',
            'triggered-by', 'force-accuracy', 'force', 'json',
        ]);
        if ($unknown !== []) {
            return $this->invalid($output, 'Unknown option: ' . implode(', ', $unknown));
        }

        $asJson = $input->boolOption('json');
        $dryRun = $input->boolOption('dry-run');

        if ($dryRun && $input->boolOption('advisory')) {
            return $this->invalid($output, '--dry-run writes nothing, so --advisory would have no effect.');
        }

        try {
            [$departmentId, $semesterId] = $this->resolveScope($input, $output, $asJson);
        } catch (InvalidArgumentException $exception) {
            if ($asJson) {
                $output->json(['error' => 'invalid_scope', 'message' => $exception->getMessage()]);

                return Kernel::INVALID;
            }

            return $this->invalid($output, $exception->getMessage());
        }

        try {
            if ($input->boolOption('status')) {
                return $this->status($departmentId, $semesterId, $output, $asJson);
            }

            return $this->solve($input, $output, $asJson, $dryRun, $departmentId, $semesterId);
        } catch (Throwable $exception) {
            $this->kernel->logger()->error('Generation failed.', [
                'command'   => $this->name(),
                'exception' => $exception::class,
                'message'   => $exception->getMessage(),
            ]);

            if ($asJson) {
                $output->json(['error' => 'failed', 'message' => $exception->getMessage()]);

                return Kernel::FAILURE;
            }

            $output->failure($exception->getMessage());

            if ($this->kernel->config()->isDebug()) {
                $output->line($exception->getTraceAsString());
            }

            return Kernel::FAILURE;
        }
    }

    // -----------------------------------------------------------------------
    // --status
    // -----------------------------------------------------------------------

    private function status(int $departmentId, int $semesterId, Output $output, bool $asJson): int
    {
        $rows = $this->database()->select(
            'SELECT status, COUNT(*) AS rows_count FROM `allocations`
             WHERE department_id = :department AND semester_id = :semester
             GROUP BY status ORDER BY status',
            ['department' => $departmentId, 'semester' => $semesterId],
        );

        $published = 0;
        $summary = [];
        foreach ($rows as $row) {
            $count = (int) $row['rows_count'];
            $summary[(string) $row['status']] = $count;
            if (in_array((string) $row['status'], ['proposed', 'confirmed', 'updated'], true)) {
                $published += $count;
            }
        }

        $latest = $this->database()->selectOne(
            'SELECT id, mode, accuracy, total_sessions, assigned_sessions, duration_ms,
                    iterations, engine_version, is_applied, started_at
             FROM `allocation_runs`
             WHERE department_id = :department AND semester_id = :semester
             ORDER BY started_at DESC, id DESC LIMIT 1',
            ['department' => $departmentId, 'semester' => $semesterId],
        );

        $openConflicts = (int) $this->database()->scalar(
            'SELECT COUNT(*) FROM `allocation_conflicts`
             WHERE department_id = :department AND semester_id = :semester AND resolved_at IS NULL',
            ['department' => $departmentId, 'semester' => $semesterId],
        );

        if ($asJson) {
            $output->json([
                'department_id'   => $departmentId,
                'semester_id'     => $semesterId,
                'by_status'       => $summary,
                'published_rows'  => $published,
                'open_conflicts'  => $openConflicts,
                'latest_run'      => $latest,
            ]);

            return Kernel::SUCCESS;
        }

        $output->title('Published timetable');

        $definitions = [
            'department'    => $departmentId,
            'semester'      => $semesterId,
            'active rows'   => $published,
            'open conflicts' => $openConflicts,
        ];
        foreach ($summary as $status => $count) {
            $definitions[$status] = $count;
        }
        $output->definitions($definitions, 0);

        if ($latest === null) {
            $output->line();
            $output->warn('No generation run has been recorded for this department and semester.');

            return Kernel::SUCCESS;
        }

        $output->line();
        $output->line('  Latest run:');
        $output->definitions([
            'run'          => (string) $latest['id'],
            'mode'         => (string) $latest['mode'],
            'applied'      => ((int) $latest['is_applied']) === 1 ? 'yes' : 'no',
            'accuracy'     => (float) $latest['accuracy'],
            'sessions'     => sprintf('%d/%d', (int) $latest['assigned_sessions'], (int) $latest['total_sessions']),
            'duration'     => sprintf('%d ms', (int) $latest['duration_ms']),
            'iterations'   => (int) $latest['iterations'],
            'engine'       => (string) $latest['engine_version'],
            'started (UTC)' => (string) $latest['started_at'],
        ], 4);
        $output->line();

        if ($openConflicts > 0) {
            $output->warn(sprintf(
                '%d unresolved conflict(s). Run `generate` to see current diagnostics, or resolve them in the UI.',
                $openConflicts,
            ));

            return Kernel::FAILURE;
        }

        $output->success('Nothing outstanding.');

        return Kernel::SUCCESS;
    }

    // -----------------------------------------------------------------------
    // The solve
    // -----------------------------------------------------------------------

    private function solve(
        Input $input,
        Output $output,
        bool $asJson,
        bool $dryRun,
        int $departmentId,
        int $semesterId,
    ): int {
        $config = $this->kernel->config();
        $database = $this->database();

        $profileName = $this->weightProfileName($input);
        $weights = $this->kernel->weightProfiles()->weights($profileName);
        $seed = $input->has('seed')
            ? $input->intOption('seed', 20260801)
            : $config->int('ENGINE_SEED', 20260801);
        $gate = $config->float('ENGINE_ACCURACY_GATE', 0.90);

        $options = $this->engineOptions($input, $seed);
        $checker = new ConstraintChecker(
            $config->int('ENGINE_MAX_LECTURER_SESSIONS_PER_DAY', 4),
        );

        $repairDay = $this->singleDay($input, 'repair-day');
        $excludedDays = $this->excludedDays($input);

        $lock = $this->acquireLock($departmentId, $semesterId, $input, $output, $asJson);
        if ($lock === null) {
            return Kernel::FAILURE;
        }

        try {
            $loader = new SchedulingProblemLoader($database, $this->kernel->logger());
            $loaded = $loader->load($departmentId, $semesterId, $weights, $excludedDays);

            if ($repairDay !== null) {
                $loaded = $this->restrictToDay($loaded, $repairDay);
            }

            $this->reportProblem($loaded, $repairDay, $profileName, $output, $asJson, $options);

            if (!$asJson && $loaded->problem->totalSessions() === 0) {
                return Kernel::FAILURE;
            }

            $engine = new AllocationEngine(
                $checker,
                new CandidateGenerator($checker),
                new CostFunction($weights),
            );

            $result = $engine->solve(
                $loaded->problem,
                $repairDay === null ? $loaded->existingAssignments : [],
                $options,
            );

            $write = null;
            if (!$dryRun) {
                $write = $this->persist(
                    $input,
                    $loaded,
                    $result,
                    $gate,
                    $profileName,
                );
            }

            return $this->report(
                $input,
                $output,
                $asJson,
                $dryRun,
                $loaded,
                $result,
                $write,
                $gate,
                $profileName,
                $weights,
                $options,
            );
        } finally {
            $this->releaseLock($lock);
        }
    }

    // -----------------------------------------------------------------------
    // Wiring
    // -----------------------------------------------------------------------

    private function database(): Database
    {
        return $this->kernel->database();
    }

    private function logger(): Logger
    {
        return $this->kernel->logger();
    }

    /**
     * `--department` and `--semester` accept an id or a code.
     *
     * A code is what a human has (`2026-A`, `CS`) and an id is what the tables
     * join on, so the command accepts both. A code matching more than one row is
     * an error rather than a first-match guess: silently scheduling the wrong
     * department's timetable is not a recoverable mistake.
     *
     * @return array{0: int, 1: int}
     */
    private function resolveScope(Input $input, Output $output, bool $asJson): array
    {
        $department = trim((string) $input->option('department', ''));
        $semester = trim((string) $input->option('semester', ''));

        if ($department === '' || $semester === '') {
            throw new InvalidArgumentException(sprintf(
                'Both --department and --semester are required. Try --department=%s --semester=2026-A.',
                $department === '' ? 'CS' : $department,
            ));
        }

        $departmentId = $this->resolveDepartment($department);
        $semesterId = $this->resolveSemester($semester, $departmentId);

        return [$departmentId, $semesterId];
    }

    private function resolveDepartment(string $value): int
    {
        if (preg_match('/^\d+$/', $value) === 1) {
            $row = $this->database()->selectOne(
                'SELECT id, name FROM `departments` WHERE id = :id',
                ['id' => (int) $value],
            );
        } else {
            $row = $this->database()->selectOne(
                'SELECT id, name FROM `departments` WHERE code = :code',
                ['code' => $value],
            );
        }

        if ($row === null) {
            throw new InvalidArgumentException(sprintf('No department matches "%s".', $value));
        }

        return (int) $row['id'];
    }

    /**
     * The code is only unique within a department, so the semester is resolved
     * after the department and is scoped to it. That is also what stops a
     * semester code being read out of another department's timetable.
     */
    private function resolveSemester(string $value, int $departmentId): int
    {
        if (preg_match('/^\d+$/', $value) === 1) {
            $row = $this->database()->selectOne(
                'SELECT id, name FROM `semesters` WHERE id = :id AND department_id = :department',
                ['id' => (int) $value, 'department' => $departmentId],
            );
        } else {
            $rows = $this->database()->select(
                'SELECT id, name FROM `semesters` WHERE department_id = :department AND name = :name',
                ['department' => $departmentId, 'name' => $value],
            );

            if (\count($rows) > 1) {
                throw new InvalidArgumentException(sprintf(
                    'Semester code "%s" matches %d semesters in department %d. Use the numeric id.',
                    $value,
                    \count($rows),
                    $departmentId,
                ));
            }

            $row = $rows[0] ?? null;
        }

        if ($row === null) {
            throw new InvalidArgumentException(sprintf(
                'No semester matches "%s" in department %d. Semester codes are per department (e.g. 2026-A).',
                $value,
                $departmentId,
            ));
        }

        return (int) $row['id'];
    }

    private function weightProfileName(Input $input): string
    {
        $requested = $input->option('weights');
        if ($requested === null || trim($requested) === '') {
            return 'balanced';
        }

        return trim($requested);
    }

    /** @return list<string> */
    private function weightNames(): array
    {
        try {
            return $this->kernel->weightProfiles()->names();
        } catch (Throwable) {
            return ['balanced'];
        }
    }

    /**
     * `EngineOptions` from the environment, with the flags layered on top.
     *
     * `--exhaustive` replaces the budget rather than adding to it, because the
     * two documented shapes are different jobs: a bounded run that answers an
     * interactive request, and an overnight run where the only deadline is the
     * one the shell imposes.
     */
    private function engineOptions(Input $input, int $seed): EngineOptions
    {
        $config = $this->kernel->config();

        if ($input->boolOption('exhaustive')) {
            return EngineOptions::exhaustive($seed);
        }

        return new EngineOptions(
            maxIterations: $input->has('iterations')
                ? $input->intOption('iterations', 2000)
                : $config->int('ENGINE_MAX_ITERATIONS', 2000),
            stallLimit: 60,
            maxNeighbours: 40,
            randomSeed: $seed,
            timeBudgetSeconds: $input->has('time-budget')
                ? $input->floatOption('time-budget', 2.5)
                : $config->float('ENGINE_TIME_BUDGET_SECONDS', 2.5),
        );
    }

    /**
     * `--repair-day` re-solves one weekday and nothing else.
     *
     * The problem is restricted to that day's slots, and the warm start is
     * dropped, so the engine plans only what it can see. Sessions already placed
     * on other days are not in the problem, so they cannot be moved, which is
     * exactly the "leaving other days byte-identical" property
     * `IncrementalRepairTest` asserts. Applying the result then supersedes the
     * whole timetable and rewrites only the rows for the repaired day, because
     * `AllocationWriter` is deliberately not day-aware — narrowing that would put
     * a second, subtly different write path next to the one everything else uses.
     */
    private function restrictToDay(LoadedProblem $loaded, int $day): LoadedProblem
    {
        $slotIds = [];
        foreach ($loaded->problem->slots() as $slot) {
            if ($slot->dayOfWeek() === $day && $loaded->problem->isTeachable($slot)) {
                $slotIds[] = $slot->id();
            }
        }

        if ($slotIds === []) {
            throw new InvalidArgumentException(sprintf(
                'No teachable slot falls on %s (#%d), so there is nothing to repair. Check the weekly grid, '
                . 'or the calendar exceptions that removed the day.',
                self::DAY_NAMES[$day] ?? 'that day',
                $day,
            ));
        }

        $restricted = $loaded->problem->restrictedToSlots($slotIds);

        return new LoadedProblem(
            problem: $restricted,
            existingAssignments: [],
            departmentId: $loaded->departmentId,
            semesterId: $loaded->semesterId,
            semesterName: $loaded->semesterName,
            teachingStart: $loaded->teachingStart,
            teachingEnd: $loaded->teachingEnd,
            totalWeeks: $loaded->totalWeeks,
            warnings: $loaded->warnings,
            counts: $loaded->counts,
        );
    }

    private function singleDay(Input $input, string $option): ?int
    {
        $raw = $input->option($option);
        if ($raw === null) {
            return null;
        }

        $raw = trim($raw);
        if (preg_match('/^[1-7]$/', $raw) !== 1) {
            throw new InvalidArgumentException(sprintf(
                '--%s must be a day of the week, 1 (Monday) to 7 (Sunday). Got "%s".',
                $option,
                $raw,
            ));
        }

        return (int) $raw;
    }

    /**
     * `--exclude-day=1,3` or `--exclude-day=1 --exclude-day=3`.
     *
     * A bare `--exclude-day` with no value throws from `Input::intListOption`
     * with a message naming the `=` form, which is the right place for it: the
     * command does not have to invent a second convention for the same flag.
     *
     * @return list<int>
     */
    private function excludedDays(Input $input): array
    {
        if (!$input->has('exclude-day')) {
            return [];
        }

        $days = $input->intListOption('exclude-day');

        foreach ($days as $day) {
            if ($day < 1 || $day > 7) {
                throw new InvalidArgumentException(sprintf(
                    '--exclude-day takes days of the week, 1 (Monday) to 7 (Sunday). Got %d.',
                    $day,
                ));
            }
        }

        return $days;
    }

    // -----------------------------------------------------------------------
    // Locking
    // -----------------------------------------------------------------------

    /**
     * `GET_LOCK` on the (department, semester) pair.
     *
     * Two triggers generating at once would both read the same current timetable
     * and both try to supersede it; the second one would silently discard the
     * first one's result. A named lock makes that a visible failure instead, and
     * unlike a table row it is released automatically if the process dies.
     *
     * @return int|null The lock handle, or null when the lock was not taken.
     */
    private function acquireLock(
        int $departmentId,
        int $semesterId,
        Input $input,
        Output $output,
        bool $asJson,
    ): ?int {
        $name = sprintf('catms_generate_%d_%d', $departmentId, $semesterId);
        $handle = (int) $this->database()->scalar('SELECT GET_LOCK(:name, 0)', ['name' => $name]);

        if ($handle === 1) {
            return $handle;
        }

        $message = sprintf(
            'Another generation is already running for department %d, semester %d. Wait for it, or pass --force.',
            $departmentId,
            $semesterId,
        );

        $this->logger()->warning('Generation lock is held by another process.', [
            'lock' => $name,
        ]);

        if ($asJson) {
            $output->json(['error' => 'locked', 'message' => $message]);

            return null;
        }

        $output->failure($message);

        if ($input->boolOption('force')) {
            $output->line();
            $output->warn('--force is accepted for an unattended job; two runs will both write.');
            $output->line('  Prefer waiting. A second run discards the first one\'s timetable.');

            return -1;
        }

        return null;
    }

    private function releaseLock(int $handle): void
    {
        if ($handle === -1) {
            return; // never acquired
        }

        try {
            $this->database()->execute('SELECT RELEASE_LOCK(:handle)', ['handle' => $handle]);
        } catch (Throwable $exception) {
            // Releasing is best effort: MySQL drops the lock when the session
            // ends, and a failure here must not mask the run's real outcome.
            $this->logger()->debug('Could not release the generation lock.', [
                'reason' => $exception->getMessage(),
            ]);
        }
    }

    // -----------------------------------------------------------------------
    // Persistence
    // -----------------------------------------------------------------------

    /**
     * Record and, unless advisory, apply.
     *
     * @param string $profileName Kept in `allocation_runs.metrics` so a published
     *                            timetable can be attributed to the policy that
     *                            produced it, months later.
     */
    private function persist(
        Input $input,
        LoadedProblem $loaded,
        SchedulingResult $result,
        float $gate,
        string $profileName,
    ): ?AllocationWriteResult {
        $advisory = $input->boolOption('advisory');
        $accuracy = (float) ($result->metrics['accuracy'] ?? 0.0);

        $writer = new AllocationWriter(
            $this->database(),
            $this->logger(),
            $this->kernel->config()->get('ENGINE_VERSION', '1.0.0') ?? '1.0.0',
        );

        $metrics = [
            'weight_profile'       => $profileName,
            'weight_description'   => $this->kernel->weightProfiles()->description($profileName),
            'accuracy_gate'        => $gate,
            'accuracy_gate_passed' => $accuracy >= $gate,
        ];

        $triggeredBy = $input->has('triggered-by') ? $input->intOption('triggered-by', 0) : null;

        if ($advisory || ($accuracy < $gate && !$input->boolOption('force-accuracy'))) {
            return $writer->recordAdvisory(
                $loaded,
                $result,
                $triggeredBy,
                $this->mode($input),
                $metrics,
            );
        }

        return $writer->apply(
            $loaded,
            $result,
            $triggeredBy,
            $this->mode($input),
            'proposed',
            $metrics,
        );
    }

    private function mode(Input $input): string
    {
        return $input->has('repair-day') ? 'repair' : 'full';
    }

    // -----------------------------------------------------------------------
    // Reporting
    // -----------------------------------------------------------------------

    private function reportProblem(
        LoadedProblem $loaded,
        ?int $repairDay,
        string $profileName,
        Output $output,
        bool $asJson,
        EngineOptions $options,
    ): void {
        if ($asJson) {
            return;
        }

        $output->title('Problem');
        $output->definitions([
            'semester'      => sprintf('%s (#%d)', $loaded->semesterName, $loaded->semesterId),
            'department'    => $loaded->departmentId,
            'week 1 Monday' => $loaded->weekStart(1),
            'teaching'      => sprintf(
                '%s → %s (%d weeks)',
                $loaded->teachingStart,
                $loaded->teachingEnd,
                $loaded->totalWeeks,
            ),
            'sessions'      => $loaded->problem->totalSessions(),
            'rooms'         => \count($loaded->problem->rooms()),
            'teachable slots' => $this->teachableCount($loaded),
            'warm start'    => \count($loaded->existingAssignments),
            'weights'       => $this->weightLabel($profileName),
            'seed'          => $options->randomSeed,
            'iterations'    => $options->maxIterations,
            'time budget'   => sprintf('%.1f s', $options->timeBudgetSeconds),
            'mode'          => $repairDay === null
                ? 'full generation'
                : sprintf('repair of %s only', self::DAY_NAMES[$repairDay] ?? ('day ' . $repairDay)),
        ], 0);

        if ($loaded->hasWarnings()) {
            $output->line();
            $output->line('  Data warnings');
            foreach ($loaded->warnings as $warning) {
                $output->warn($warning);
            }
        }

        $output->line();
    }

    private function teachableCount(LoadedProblem $loaded): int
    {
        $count = 0;
        foreach ($loaded->problem->slots() as $slot) {
            if ($loaded->problem->isTeachable($slot)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * `utilisation_first (Pack the cheap rooms first)`.
     *
     * The description is taken from `config/weights.php` rather than repeated
     * here, so retuning a profile updates the operator's understanding of what
     * the run did without a second edit.
     */
    private function weightLabel(string $name): string
    {
        $description = '';

        try {
            $description = $this->kernel->weightProfiles()->description($name);
        } catch (Throwable) {
            // Resolving the profile already raised the real error if it is
            // broken; this is only the label.
        }

        return $description === '' ? $name : sprintf('%s (%s)', $name, $description);
    }

    /**
     * @param AllocationWriteResult|null $write null when `--dry-run`.
     */
    private function report(
        Input $input,
        Output $output,
        bool $asJson,
        bool $dryRun,
        LoadedProblem $loaded,
        SchedulingResult $result,
        ?AllocationWriteResult $write,
        float $gate,
        string $profileName,
        CostWeights $weights,
        EngineOptions $options,
    ): int {
        $accuracy = (float) ($result->metrics['accuracy'] ?? 0.0);
        $passed = $accuracy >= $gate;

        if ($asJson) {
            $output->json([
                'dry_run'      => $dryRun,
                'advisory'     => $input->boolOption('advisory'),
                'problem'      => $loaded->toArray(),
                'weights'      => [
                    'profile'   => $profileName,
                    'waste'     => $weights->waste,
                    'movement'  => $weights->movement,
                    'churn'     => $weights->churn,
                    'equity'    => $weights->equity,
                    'preference' => $weights->preference,
                    'tightness' => $weights->tightness,
                    'fragmentation' => $weights->fragmentation,
                ],
                'options'      => [
                    'seed'          => $options->randomSeed,
                    'max_iterations' => $options->maxIterations,
                    'time_budget_seconds' => $options->timeBudgetSeconds,
                ],
                'accuracy'     => $accuracy,
                'accuracy_gate' => $gate,
                'accuracy_passed' => $passed,
                'metrics'      => $result->metrics,
                'unallocated'  => array_map(
                    static fn (UnallocatedSession $u): array => $u->toArray(),
                    $result->unallocated,
                ),
                'violations'   => array_map(
                    static fn (Violation $v): array => $v->toArray(),
                    $result->violations,
                ),
                'write'        => $write?->toArray(),
            ]);

            return $result->violations === [] && ($passed || $dryRun || $input->boolOption('advisory'))
                ? Kernel::SUCCESS
                : Kernel::FAILURE;
        }

        $output->title($dryRun ? 'Result (dry run — nothing was written)' : 'Result');

        $output->definitions([
            'accuracy'    => sprintf('%.4f  (gate %.2f)', $accuracy, $gate),
            'placed'      => sprintf(
                '%d / %d',
                (int) ($result->metrics['assigned_sessions'] ?? 0),
                (int) ($result->metrics['total_sessions'] ?? 0),
            ),
            'unallocated' => $result->unallocatedCount(),
            'violations'  => \count($result->violations),
            'penalty'     => (float) ($result->metrics['total_penalty'] ?? 0.0),
            'seat utilisation' => (float) ($result->metrics['seat_utilisation'] ?? 0.0),
            'iterations'  => (int) ($result->metrics['iterations'] ?? 0),
            'engine time' => sprintf('%d ms', (int) ($result->metrics['duration_ms'] ?? 0)),
            'warm start rejected' => (int) ($result->metrics['warm_start_rejected'] ?? 0),
        ], 0);

        $this->reportUnallocated($result, $output);
        $this->reportViolations($result, $output);

        if ($result->unallocated !== []) {
            $output->line();
            $output->line('  Blocked by constraint');
            $byCode = [];
            foreach ($result->unallocated as $entry) {
                foreach ($entry->blockingConstraints as $code => $count) {
                    $byCode[$code] = ($byCode[$code] ?? 0) + $count;
                }
            }
            arsort($byCode);
            $rows = [];
            foreach (array_slice($byCode, 0, 6, true) as $code => $count) {
                $rows[] = [(string) $code, $count];
            }
            if ($rows !== []) {
                $output->table(['constraint', 'combinations eliminated'], $rows, 4);
            }
        }

        $output->line();

        if ($dryRun) {
            $output->line(sprintf(
                '  Nothing was written. Re-run without --dry-run to apply (%s).',
                $input->boolOption('advisory')
                    ? '--advisory records the run only'
                    : 'or with --advisory to record it only',
            ));
            $output->line();

            return $result->violations === [] ? Kernel::SUCCESS : Kernel::FAILURE;
        }

        if ($write === null) {
            $output->warn('The run was solved but not recorded. Treat it as nothing having happened.');

            return Kernel::FAILURE;
        }

        $output->line(sprintf('  Run %s', $write->runId));
        $output->definitions([
            'applied'      => $write->applied ? 'yes' : 'no (advisory)',
            'allocations'  => $write->allocationsWritten,
            'superseded'   => $write->supersededRows,
            'conflicts'    => $write->conflictsWritten,
            'duration'     => sprintf('%d ms', $write->durationMs),
        ], 4);
        $output->line();

        if ($result->violations !== []) {
            $output->failure(sprintf(
                '%d hard-constraint violation(s) survived verification, so nothing was applied. '
                . 'This is an engine defect — see storage/logs.',
                \count($result->violations),
            ));
            $output->line();

            return Kernel::FAILURE;
        }

        if (!$passed) {
            $output->failure(sprintf(
                'Accuracy %.4f is below the ENGINE_ACCURACY_GATE of %.2f (NFR-PERF-04). The run was recorded '
                . 'but the published timetable was not replaced.',
                $accuracy,
                $gate,
            ));
            $output->line('  Read the constraint table above: the cause is in the data, not the engine.');
            $output->line('  Fix it, or re-run with --force-accuracy and say so in the ticket.');
            $output->line();

            return Kernel::FAILURE;
        }

        if ($input->boolOption('advisory')) {
            $output->success(
                'Recorded. The published timetable is unchanged — review the conflicts and apply from the UI.'
            );

            return Kernel::SUCCESS;
        }

        $output->success(sprintf(
            'Applied. %d allocation(s) are now `proposed`; confirm them in the UI to publish.',
            $write->allocationsWritten,
        ));

        return Kernel::SUCCESS;
    }

    private function reportUnallocated(SchedulingResult $result, Output $output): void
    {
        if ($result->unallocated === []) {
            return;
        }

        $output->line();
        $output->warn(sprintf('%d session(s) could not be placed:', \count($result->unallocated)));
        foreach (array_slice($result->unallocated, 0, 10) as $entry) {
            $output->line(sprintf('    %s', $entry->summary));
        }
        if (\count($result->unallocated) > 10) {
            $output->line(sprintf('    … and %d more.', \count($result->unallocated) - 10));
        }
    }

    private function reportViolations(SchedulingResult $result, Output $output): void
    {
        if ($result->violations === []) {
            return;
        }

        $output->line();
        $output->failure(sprintf(
            '%d hard-constraint violation(s). Nothing has been written and nothing will be.',
            \count($result->violations),
        ));
        foreach (array_slice($result->violations, 0, 10) as $violation) {
            $output->line(sprintf('    [%s] %s', (string) $violation->code, (string) $violation->message));
        }
    }
}
