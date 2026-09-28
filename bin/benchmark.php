#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Time the allocation engine, and answer the two questions the report asks.
 *
 *   NFR-PERF-01  a timetable page responds in under 3 seconds
 *   NFR-PERF-04  at least 90 % of sessions are placed
 *
 * Both are checked here and both make the process exit non-zero on a miss, so
 * `composer bench` is usable as a performance gate in CI and not only as a
 * number to look at.
 *
 * NO DATABASE AND NO TEST FIXTURES
 * The problem is built inline from the domain value objects rather than through
 * `Tests\...\ProblemBuilder`, so this runs in a production image where the dev
 * autoloader was never installed. The shape is deliberately the same as the
 * reference dataset in `bin/regenerate-golden.php` at the default size, so the
 * default run is comparable with the golden baseline.
 *
 * WHAT THE TIMING DOES AND DOES NOT MEAN
 * It measures the engine alone, in-process, with a `FixedClock` and therefore no
 * wall-clock abort. A real `generate` run also loads the problem from MySQL and
 * writes the result, so it is slower by roughly the load and write cost. Treat
 * this as the floor of the budget, not as the 3-second figure itself. The
 * same caveat applies to the engine's own reproducibility: a fixed iteration
 * count is reproducible, elapsed time is not (docs/ALLOCATION_ENGINE.md §8).
 *
 * Usage:
 *   php bin/benchmark.php                       # the reference week, 5 runs
 *   php bin/benchmark.php --runs=20             # look at the spread, not one run
 *   php bin/benchmark.php --cohorts=40 --rooms=60
 *   php bin/benchmark.php --sweep               # 4 sizes, ascending
 *   php bin/benchmark.php --json                # for a CI step to parse
 */


namespace App\Bin;

use App\Console\Input;
use App\Console\Output;
use App\Domain\Allocation\AllocationEngine;
use App\Domain\Allocation\CandidateGenerator;
use App\Domain\Allocation\ConstraintChecker;
use App\Domain\Allocation\CostFunction;
use App\Domain\Allocation\CostWeights;
use App\Domain\Allocation\EngineOptions;
use App\Domain\Allocation\FixedClock;
use App\Domain\Allocation\Rng;
use App\Domain\Allocation\Room;
use App\Domain\Allocation\RoomFeatures;
use App\Domain\Allocation\SchedulingProblem;
use App\Domain\Allocation\SchedulingResult;
use App\Domain\Allocation\SessionRequest;
use App\Domain\Allocation\TimeSlot;
use InvalidArgumentException;

if (\PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "This script must be run from the command line.\n";
    exit(1);
}

$root = \dirname(__DIR__);

if (! is_file($root . '/vendor/autoload.php')) {
    fwrite(STDERR, "Dependencies are not installed. Run: composer install\n");
    exit(1);
}

require $root . '/vendor/autoload.php';

/** The acceptance gates, named. NFR-PERF-01 and NFR-PERF-04. */
const GATE_SECONDS = 3.0;
const GATE_ACCURACY = 0.90;

$input = Input::fromArgv($argv);
$output = Output::standard();

if ($input->boolOption('help') || $input->boolOption('h')) {
    usage($output);

    exit(0);
}

$unknown = $input->unknownOptions(['runs', 'cohorts', 'rooms', 'slots', 'iterations', 'seed', 'sweep', 'json']);
if ($unknown !== []) {
    $output->error('Unknown option: ' . implode(', ', $unknown) . "\n");
    usage($output);

    exit(2);
}

try {
    $seed = $input->intOption('seed', 20260801);
    $iterations = $input->intOption('iterations', 250);
    $runs = max(1, $input->intOption('runs', 5));
    $cohorts = max(1, $input->intOption('cohorts', 6));
    $roomCount = max(1, $input->intOption('rooms', 12));
    $slotsPerDay = max(1, min(5, $input->intOption('slots', 3)));
} catch (InvalidArgumentException $exception) {
    $output->error($exception->getMessage() . "\n");
    usage($output);

    exit(2);
}

$shapes = $input->boolOption('sweep')
    ? [
        ['label' => 'small', 'cohorts' => 6, 'rooms' => 12, 'slots' => 3],
        ['label' => 'medium', 'cohorts' => 20, 'rooms' => 30, 'slots' => 4],
        ['label' => 'large', 'cohorts' => 40, 'rooms' => 45, 'slots' => 5],
        ['label' => 'stress', 'cohorts' => 70, 'rooms' => 60, 'slots' => 5],
    ]
    : [['label' => 'custom', 'cohorts' => $cohorts, 'rooms' => $roomCount, 'slots' => $slotsPerDay]];

$weights = CostWeights::balanced();

$rows = [];
$worstSeconds = 0.0;
$worstAccuracy = 1.0;
$totalViolations = 0;

foreach ($shapes as $shape) {
    $problem = buildProblem((int) $shape['cohorts'], (int) $shape['rooms'], (int) $shape['slots'], $weights);
    $sessions = $problem->totalSessions();

    $times = [];
    $accuracies = [];
    $placed = 0;
    $penalty = 0.0;
    $violations = 0;
    $unallocated = 0;

    for ($i = 0; $i < $runs; $i++) {
        $result = solve($problem, $weights, $seed + $i, $iterations);

        $elapsed = (float) $result->metrics['duration_ms'] / 1000.0;
        $accuracy = (float) $result->metrics['accuracy'];

        $times[] = $elapsed;
        $accuracies[] = $accuracy;

        // The last run stands in for the shape. Placement and penalty are
        // reported from it because they are a property of a solution, not a
        // distribution; the timings and the accuracy floor are summarised
        // across every run, because those are what vary.
        $placed = (int) $result->metrics['assigned_sessions'];
        $penalty = (float) $result->metrics['total_penalty'];
        $violations = \count($result->violations);
        $unallocated = $result->unallocatedCount();

        $worstSeconds = max($worstSeconds, $elapsed);
        $worstAccuracy = min($worstAccuracy, $accuracy);
        $totalViolations += $violations;
    }

    sort($times);

    $rows[] = [
        'shape'        => $shape['label'],
        'sessions'     => $sessions,
        'rooms'        => \count($problem->rooms()),
        'slots'        => \count($problem->slots()),
        'placed'       => sprintf('%d/%d', $placed, $sessions),
        'min_accuracy' => min($accuracies),
        'median'       => $times[intdiv(\count($times), 2)],
        'slowest'      => max($times),
        'penalty'      => $penalty,
        'violations'   => $violations,
        'unallocated'  => $unallocated,
    ];
}

$passesSeconds = $worstSeconds < GATE_SECONDS;
$passesAccuracy = $worstAccuracy >= GATE_ACCURACY;
$passes = $passesSeconds && $passesAccuracy && $totalViolations === 0;

if ($input->boolOption('json')) {
    $output->json([
        'gates' => [
            'max_seconds'    => GATE_SECONDS,
            'min_accuracy'   => GATE_ACCURACY,
            'slowest_run_ms' => (int) round($worstSeconds * 1000),
            'lowest_accuracy' => round($worstAccuracy, 4),
            'violations'     => $totalViolations,
            'passed'         => $passes,
        ],
        'shapes' => $rows,
    ]);

    exit($passes ? 0 : 1);
}

$output->title('Allocation engine benchmark');
$output->line();
$output->definitions([
    'php'        => \PHP_VERSION . ' / ' . (\PHP_INT_SIZE * 8) . '-bit',
    'runs'       => sprintf('%d per shape', $runs),
    'seed'       => sprintf('%d, +1 per run', $seed),
    'iterations' => (string) $iterations,
    'weights'    => 'balanced',
], 2);
$output->line();

$output->table(
    ['', 'sessions', 'rooms', 'slots', 'placed', 'acc', 'median', 'slowest', 'penalty'],
    array_map(static fn (array $r): array => [
        $r['shape'],
        $r['sessions'],
        $r['rooms'],
        $r['slots'],
        $r['placed'],
        sprintf('%.4f', $r['min_accuracy']),
        sprintf('%.3fs', $r['median']),
        sprintf('%.3fs', $r['slowest']),
        sprintf('%.1f', $r['penalty']),
    ], $rows),
    2,
);

$output->line();
$output->definitions([
    sprintf('NFR-PERF-01  slowest run under %.1fs', GATE_SECONDS)
        => sprintf('%.3fs  %s', $worstSeconds, $passesSeconds ? 'PASS' : 'FAIL'),
    sprintf('NFR-PERF-04  every run at least %.0f%%', GATE_ACCURACY * 100)
        => sprintf('%.4f  %s', $worstAccuracy, $passesAccuracy ? 'PASS' : 'FAIL'),
    'HC-1..HC-10    no violation survived a solve'
        => sprintf('%d  %s', $totalViolations, $totalViolations === 0 ? 'PASS' : 'FAIL'),
], 2);

$output->line();

if (! $passes) {
    $output->failure('One or more gates missed.');
    $output->line();
    $output->line('  That is not automatically a regression: a shared CI runner is slower than a');
    $output->line('  laptop, and --sweep deliberately includes a shape larger than the one the');
    $output->line('  budget was set for. Re-run on comparable hardware before changing');
    $output->line('  ENGINE_TIME_BUDGET_SECONDS — docs/ALLOCATION_ENGINE.md §8.');
    $output->line();

    exit(1);
}

$output->success('All gates met.');
$output->line();

exit(0);

// ---------------------------------------------------------------------------
// Problem and solve
// ---------------------------------------------------------------------------

/**
 * A synthetic teaching week.
 *
 * Every cohort gets one lecturer and two sessions an hour, the room pool is a
 * mix of capacities, and roughly one cohort in three requires a projector. The
 * shape is what matters here, not the data: the point is to make the candidate
 * space big enough to time something.
 *
 * The capacities and features come from a deterministic arithmetic progression
 * on the room id, never from a random source, so the same `--cohorts`/`--rooms`
 * pair produces the same problem on every machine and two people benchmarking
 * the same shape compare like with like.
 */
function buildProblem(int $cohorts, int $roomCount, int $slotsPerDay, CostWeights $weights): SchedulingProblem
{
    $problem = new SchedulingProblem($weights);

    $times = [
        ['08:00', '09:00'],
        ['10:00', '11:00'],
        ['12:00', '13:00'],
        ['14:00', '15:00'],
        ['16:00', '17:00'],
    ];

    $slots = [];
    $slotId = 1;
    for ($day = 1; $day <= 5; $day++) {
        for ($i = 0; $i < $slotsPerDay; $i++) {
            $slots[] = new TimeSlot($slotId++, $day, $times[$i][0], $times[$i][1], '', true);
        }
    }
    $problem->withSlots($slots);

    $sessions = [];
    $sessionId = 1;
    for ($cohort = 1; $cohort <= $cohorts; $cohort++) {
        $lecturer = (int) (($cohort - 1) % max(1, (int) ceil($cohorts / 2))) + 1;
        $enrolled = 15 + (($cohort * 7) % 45);
        $needsProjector = $cohort % 3 === 0;
        $sequence = 0;

        foreach ([1, 2] as $_) {
            $sessions[] = new SessionRequest(
                $sessionId++,
                $cohort,
                $cohort,
                $lecturer,
                $enrolled,
                60,
                new RoomFeatures($needsProjector ? ['projector'] : []),
                $sequence,
                $needsProjector ? 'Main' : null,
                1,
                "C{$cohort}S{$sequence}",
            );
            $sequence++;
        }
    }
    $problem->withSessions($sessions);

    $rooms = [];
    for ($id = 1; $id <= $roomCount; $id++) {
        $rooms[] = new Room(
            $id,
            sprintf('B%03d', $id),
            sprintf('Room %d', $id),
            $id % 3 === 0 ? 'Annex' : 'Main',
            20 + (($id * 13) % 6) * 15,
            new RoomFeatures($id % 3 === 0 ? [] : ['projector']),
            'available',
            true,
            null,
            true,
            [],
        );
    }
    $problem->withRooms($rooms);

    return $problem;
}

/**
 * One solve, on a clock that never moves.
 *
 * The `FixedClock` matters: the production `generate` run is wall-clock
 * time-boxed, so a benchmark that inherited that box would report the budget
 * rather than the cost. Here the box is the gate being measured, not a limit
 * that shaped the answer.
 */
function solve(
    SchedulingProblem $problem,
    CostWeights $weights,
    int $seed,
    int $iterations
): SchedulingResult {
    $checker = new ConstraintChecker();

    $engine = new AllocationEngine(
        $checker,
        new CandidateGenerator($checker),
        new CostFunction($weights),
        new Rng($seed),
    );

    return $engine->solve(
        $problem,
        [],
        new EngineOptions(
            maxIterations: $iterations,
            stallLimit: 60,
            maxNeighbours: 40,
            randomSeed: $seed,
            // Generous: the gate is measured, not enforced. A budget that
            // truncated the search would report a fast, inaccurate number and
            // quietly make the accuracy gate meaningless.
            timeBudgetSeconds: 3600.0,
            clock: new FixedClock(0.0),
            // An impossible shape reports 0 % accuracy rather than throwing.
            // For a benchmark that is the more useful answer: the table then
            // shows which size broke, instead of stopping at the first one.
            strict: false,
        ),
    );
}

function usage(Output $output): void
{
    $output->title('benchmark — time the allocation engine against NFR-PERF-01 and NFR-PERF-04');
    $output->line();
    $output->line('  Usage:');
    $output->line('    php bin/benchmark.php [options]');
    $output->line();
    $output->line('  Options:');
    $output->definitions([
        'runs'       => 'Solves per shape. Default 5. One run is a rumour; 20 is a distribution',
        'cohorts'    => 'Cohorts in the problem. Default 6',
        'rooms'      => 'Rooms in the pool. Default 12',
        'slots'      => 'Slots per weekday, 1-5. Default 3',
        'sweep'      => 'Benchmark four ascending shapes instead of one',
        'iterations' => 'Local-search steps per solve. Default 250',
        'seed'       => 'RNG seed; each run uses seed + n. Default 20260801',
        'json'       => 'Machine-readable output, for a CI step',
    ], 4);
    $output->line();
    $output->line('  Exits 0 when every gate is met, 1 when one is missed, 2 on a usage error.');
    $output->line();
    $output->line('  Timings cover the engine alone. A real `generate` run also loads from MySQL');
    $output->line('  and writes the result, so treat these numbers as the floor of the budget.');
    $output->line();
}
