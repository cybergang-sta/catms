<?php

declare(strict_types=1);

namespace App\Console\Command;

use App\Console\Input;
use App\Console\Kernel;
use App\Console\Output;
use App\Core\App;
use App\Core\Database;
use App\Core\Request;
use App\Domain\Allocation\AllocationEngine;
use App\Domain\Allocation\Assignment;
use App\Domain\Allocation\CandidateGenerator;
use App\Domain\Allocation\ConstraintChecker;
use App\Domain\Allocation\CostFunction;
use App\Domain\Allocation\EngineOptions;
use App\Domain\Allocation\FixedClock;
use App\Domain\Allocation\Room;
use App\Domain\Allocation\RoomFeatures;
use App\Domain\Allocation\SchedulingProblem;
use App\Domain\Allocation\SchedulingResult;
use App\Domain\Allocation\SessionRequest;
use App\Domain\Allocation\TimeSlot;
use App\Domain\Allocation\Violation;
use App\Infrastructure\Persistence\Migration\Migrator;
use Throwable;

/**
 * `bin/console smoke` — "is this instance actually working?", answered in a few
 * seconds without a browser.
 *
 * WHERE IT IS CALLED FROM
 * docs/DEPLOYMENT.md §7.3 finishes a staging deploy with "wait for `/health` to
 * report `ok`, run a smoke suite", and §12.4 step 5 runs `app:smoke` after a
 * restore. In both cases the question is the same and it is not "does the
 * process answer", because `/health` already covers that. It is: *would a real
 * request succeed?*
 *
 * THE CHECKS, IN THE ORDER THEY FAIL FASTEST
 *   1. Configuration. `Config::assertProductionSafe()` is the same guard the HTTP
 *      bootstrap uses, so a misconfigured production instance fails here rather
 *      than on the first request a member of staff makes.
 *   2. The database answers.
 *   3. The schema is the one the code expects — tables present, no pending
 *      migrations.
 *   4. Reference data is seeded. An instance with no time slots, rooms or
 *      semester is *running* and cannot *schedule*, and the first sign of that is
 *      an empty timetable a registrar believes is real.
 *   5. A real request through the real pipeline. `App::handle()` is called
 *      in-process with a hand-built `Request`, so the router, the middleware
 *      stack, the handler resolution and the error envelope are all exercised
 *      without a web server. `/health` proves the application answers; an
 *      authenticated route returning 401 proves the *auth* path is wired, which
 *      is the thing that is silently broken by a bad container wiring.
 *   6. The engine solves a small problem end to end, within the NFR-PERF-01
 *      budget. This is the one check that cannot be faked by a health endpoint,
 *      and it is why the smoke suite touches the domain layer at all.
 *
 * WHAT IT DOES NOT DO
 * It does not write application data, and it does not need the database to be
 * writable. Two `SELECT 1`s and a health probe are enough to place an instance.
 * The one exception is a `migrations.status()` call, which creates the
 * `schema_migrations` ledger if it is absent — that is a `CREATE TABLE IF NOT
 * EXISTS` on an empty database and is reported as such by the check that found
 * it, so the surprise is visible rather than silent.
 *
 * WHY NOT A SEPARATE HTTP CLIENT
 * `curl /api/v1/health` from a shell proves the *server* is up. This runs
 * in-process, so it also proves the code on this disk is the code that answered,
 * which is exactly the difference that matters when a release went out to the
 * wrong tag.
 */
final class SmokeCommand extends Command
{
    /**
     * The NFR-PERF-01 response budget. The engine gets the whole second here: a
     * smoke run is a cold process with a cold autoloader, so a full second is
     * generous for a 20-session problem and still catches an order-of-magnitude
     * regression.
     */
    private const ENGINE_BUDGET_SECONDS = 1.0;

    /**
     * The seed the engine check uses.
     *
     * Fixed, not random, because a smoke suite has to be reproducible: the same
     * failure has to be the same failure tomorrow. `20260801` is the same seed
     * the test fixtures and the nightly generation use, so a regression here
     * matches a regression there.
     */
    private const ENGINE_SEED = 20260801;

    /**
     * Reference data a usable instance cannot do without.
     *
     * Each is one `SELECT COUNT(*)`, and each maps to a thing the timetable
     * needs: a department to schedule, slots to schedule *into*, rooms to
     * schedule *in*, and a semester to schedule *for*. The predicates match
     * `SchedulingProblemLoader` exactly — a plain `COUNT(*)` on `rooms` would
     * pass for a building where every room is in maintenance, and a count on
     * `time_slots` would pass for a grid that has been entirely deactivated.
     */
    private const REFERENCE_DATA = [
        'departments' => [
            'sql'     => 'SELECT COUNT(*) FROM `departments`',
            'label'   => 'at least one department',
            'minimum' => 1,
            'remedy'  => 'departments has no row',
        ],
        'roles' => [
            'sql'     => 'SELECT COUNT(*) FROM `roles`',
            'label'   => 'the three RBAC roles',
            'minimum' => 3,
            'remedy'  => 'config/rbac.php declares three roles; the seed did not write them',
        ],
        'time-slots' => [
            'sql'     => 'SELECT COUNT(*) FROM `time_slots` WHERE `is_active` = 1',
            'label'   => 'an active teaching week',
            'minimum' => 1,
            'remedy'  => 'every time slot is deactivated, so there is no teaching window (HC-7)',
        ],
        'rooms' => [
            'sql'     => "SELECT COUNT(*) FROM `rooms` WHERE `status` = 'available' AND `is_bookable` = 1",
            'label'   => 'at least one available, bookable room',
            'minimum' => 1,
            'remedy'  => 'BR-06 allows only available rooms, and there are none',
        ],
        'semesters' => [
            'sql'     => 'SELECT COUNT(*) FROM `semesters`',
            'label'   => 'at least one semester',
            'minimum' => 1,
            'remedy'  => 'the generate command has nothing to schedule a semester for',
        ],
        'features' => [
            'sql'     => 'SELECT COUNT(*) FROM `room_features`',
            'label'   => 'the room feature catalogue',
            'minimum' => 1,
            'remedy'  => 'BR-05 feature requirements cannot be expressed without it',
        ],
    ];

    /**
     * The smallest thing the engine can be handed: a cohort that has students and
     * a lecturer.
     *
     * The lecturer can come from either source `SchedulingProblemLoader` reads —
     * a cohort-level `lecturer_course_assignments` row, a course-level default,
     * or `courses.default_lecturer_id` — and an instance missing all three runs
     * the generate command to produce zero sessions, with a warning, and an empty
     * timetable that a registrar may believe is real.
     */
    private const SCHEDULABLE_SQL = <<<'SQL'
        SELECT COUNT(DISTINCT c.`id`)
          FROM `cohorts` c
          JOIN `courses` co ON co.`id` = c.`course_id`
          LEFT JOIN `lecturer_course_assignments` lca ON lca.`cohort_id` = c.`id`
          LEFT JOIN `lecturer_course_assignments` lcd
                 ON lcd.`course_id` = c.`course_id` AND lcd.`cohort_id` IS NULL
         WHERE co.`is_active` = 1
           AND (
                c.`enrolled_count` > 0
                OR EXISTS (SELECT 1 FROM `enrollments` e
                            WHERE e.`cohort_id` = c.`id` AND e.`status` = 'enrolled')
           )
           AND (lca.`id` IS NOT NULL OR lcd.`id` IS NOT NULL OR co.`default_lecturer_id` IS NOT NULL)
        SQL;

    /** The `--only` targets, in the order they run. */
    private const GROUPS = ['config', 'database', 'schema', 'data', 'http', 'engine'];

    public function name(): string
    {
        return 'smoke';
    }

    public function description(): string
    {
        return 'End-to-end checks that this instance would actually serve a request';
    }

    /** @return list<string> */
    public function aliases(): array
    {
        return ['app:smoke', 'smoke-test'];
    }

    /** @return list<string> */
    public function synopsis(): array
    {
        return [
            'php bin/console smoke',
            'php bin/console smoke --json',
            'php bin/console smoke --skip-engine',
            'php bin/console smoke --only=http',
        ];
    }

    /** @return array<string, string> */
    public function options(): array
    {
        return [
            'json'        => 'Machine-readable output; nothing else is written to stdout',
            'skip-engine' => 'Skip the engine solve; useful when diagnosing a slow machine',
            'only'        => 'Comma-separated group: config, database, schema, data, http, engine',
            'quiet'       => 'Print only the failures',
        ];
    }

    /** @return list<string> */
    public function notes(): array
    {
        return [
            'Reads only. It never writes application data, so it is safe to run against production.',
            'Run it after a deploy and after a restore (docs/DEPLOYMENT.md §7.3 and §12.4).',
            'A red smoke run means do not open the instance yet — the runbook step that',
            'follows it depends on the instance being sound, not merely up.',
            'The http check runs the real request pipeline in this process, so it needs no',
            'web server and no port. It is not the same as curl-ing /health, and it is',
            'strictly more informative: it also exercises the auth and RBAC wiring.',
        ];
    }

    public function run(Input $input, Output $output): int
    {
        $unknown = $input->unknownOptions(['json', 'skip-engine', 'only', 'quiet']);
        if ($unknown !== []) {
            return $this->invalid($output, 'Unknown option: ' . implode(', ', $unknown));
        }

        $groups = $this->resolveGroups($input->option('only'), $input->boolOption('skip-engine'));
        if ($groups === null) {
            return $this->invalid($output, sprintf(
                'Unknown --only group. Valid groups: %s.',
                implode(', ', self::GROUPS),
            ));
        }

        $asJson = $input->boolOption('json');
        $quiet = $input->boolOption('quiet');

        /** @var list<array{id: string, group: string, title: string, passed: bool, detail: string}> $results */
        $results = [];

        // Each group is wrapped so that one exploding check does not abandon the
        // rest: a smoke run that stops at the first problem tells you one thing,
        // and the operator usually needs three.
        foreach ($groups as $group) {
            try {
                foreach ($this->runGroup($group, $output, $asJson) as $result) {
                    $results[] = $result;
                }
            } catch (Throwable $exception) {
                $this->kernel->logger()->error('Smoke check raised.', [
                    'group'     => $group,
                    'exception' => $exception::class,
                    'message'   => $exception->getMessage(),
                ]);

                $results[] = [
                    'id'     => $group,
                    'group'  => $group,
                    'title'  => sprintf('The %s check completed', $group),
                    'passed' => false,
                    'detail' => 'raised: ' . $exception->getMessage(),
                ];

                // Without a database nothing after `database` can be answered, and
                // continuing turns one clear failure into six confusing ones.
                if ($group === 'database') {
                    break;
                }
            }
        }

        $failed = array_values(array_filter($results, static fn (array $r): bool => !$r['passed']));

        if ($asJson) {
            $output->json([
                'status'  => $failed === [] ? 'ok' : 'failed',
                'checked' => count($results),
                'failed'  => count($failed),
                'checks'  => $results,
            ]);

            return $failed === []
                ? Kernel::SUCCESS
                : Kernel::FAILURE;
        }

        $output->title('Smoke test');

        $rows = [];
        foreach ($results as $result) {
            if ($quiet && $result['passed']) {
                continue;
            }

            $rows[] = [
                $result['passed'] ? 'pass' : 'FAIL',
                $result['group'],
                $result['title'],
                $result['detail'],
            ];
        }

        if ($rows === []) {
            $output->success(sprintf('All %d checks passed.', count($results)));
        } else {
            $output->table(['', 'group', 'check', 'detail'], $rows);
        }

        $output->line();
        $output->definitions([
            'checks run' => count($results),
            'failed'     => count($failed),
            'groups'     => implode(', ', $groups),
        ], 2);
        $output->line();

        if ($failed === []) {
            $output->success('This instance would serve a request.');

            return Kernel::SUCCESS;
        }

        foreach ($failed as $result) {
            $output->failure(sprintf('%s — %s', $result['group'], $result['detail']));
        }

        $output->line();
        $output->line('  Do not open this instance yet. The first two failures are almost always');
        $output->line('  `bin/migrate.php` not having been run, or `bin/seed.php` not having been run.');
        $output->line();

        return Kernel::FAILURE;
    }

    // -----------------------------------------------------------------------
    // Groups
    // -----------------------------------------------------------------------

    /**
     * @return list<string>|null Null when a name in `--only` is not a group.
     */
    private function resolveGroups(?string $only, bool $skipEngine): ?array
    {
        if ($only === null || trim($only) === '') {
            $groups = self::GROUPS;

            return $skipEngine
                ? array_values(array_filter($groups, static fn (string $g): bool => $g !== 'engine'))
                : $groups;
        }

        $wanted = [];
        foreach (explode(',', $only) as $part) {
            $name = strtolower(trim($part));
            if ($name === '') {
                continue;
            }

            if (!in_array($name, self::GROUPS, true)) {
                return null;
            }

            $wanted[$name] = true;
        }

        if ($wanted === []) {
            return null;
        }

        if ($skipEngine) {
            unset($wanted['engine']);
        }

        return array_values(array_filter(self::GROUPS, static fn (string $g): bool => isset($wanted[$g])));
    }

    /**
     * @return list<array{id: string, group: string, title: string, passed: bool, detail: string}>
     */
    private function runGroup(string $group, Output $output, bool $asJson): array
    {
        return match ($group) {
            'config'   => $this->checkConfig(),
            'database' => $this->checkDatabase(),
            'schema'   => $this->checkSchema(),
            'data'     => $this->checkReferenceData(),
            'http'     => $this->checkHttp($output, $asJson),
            'engine'   => $this->checkEngine(),
            default    => [],
        };
    }

    // -----------------------------------------------------------------------
    // 1. Configuration
    // -----------------------------------------------------------------------

    /** @return list<array{id: string, group: string, title: string, passed: bool, detail: string}> */
    private function checkConfig(): array
    {
        $config = $this->kernel->config();
        $results = [];

        // The same guard the HTTP bootstrap applies. If it throws, the group
        // wrapper turns it into a failure rather than letting it escape.
        $safe = true;
        $reason = $config->isProduction()
            ? 'APP_KEY is long enough, APP_DEBUG is off, LOG_LEVEL is not debug'
            : 'not production, so the APP_KEY, APP_DEBUG and LOG_LEVEL guards do not apply';
        try {
            $config->assertProductionSafe();
        } catch (Throwable $exception) {
            $safe = false;
            $reason = $exception->getMessage();
        }

        $results[] = [
            'id'     => 'config-production-safe',
            'group'  => 'config',
            'title'  => 'The configuration is safe for this environment',
            'passed' => $safe,
            'detail' => $safe ? sprintf('%s (APP_ENV=%s)', $reason, $config->environment()) : $reason,
        ];

        // API_PREFIX is read by the Router and by the client, so a mismatch is
        // silently a 404 for every endpoint.
        $prefix = $config->apiPrefix();
        $results[] = [
            'id'     => 'config-api-prefix',
            'group'  => 'config',
            'title'  => 'The API prefix is set',
            'passed' => $prefix !== '' && str_starts_with($prefix, '/'),
            'detail' => 'API_PREFIX=' . $prefix,
        ];

        $storage = $config->storagePath();
        $writable = is_dir($storage) && is_writable($storage);
        $results[] = [
            'id'     => 'config-storage-writable',
            'group'  => 'config',
            'title'  => 'storage/ is writable',
            'passed' => $writable,
            'detail' => $writable
                ? $storage
                : sprintf('%s is not writable; a deploy that cannot write its log has no deploy', $storage),
        ];

        return $results;
    }

    // -----------------------------------------------------------------------
    // 2. Database
    // -----------------------------------------------------------------------

    /** @return list<array{id: string, group: string, title: string, passed: bool, detail: string}> */
    private function checkDatabase(): array
    {
        $database = $this->database();
        $config = $this->kernel->config();

        $connected = $database->isHealthy();
        $results = [[
            'id'     => 'db-connect',
            'group'  => 'database',
            'title'  => 'The database answers',
            'passed' => $connected,
            'detail' => $connected
                ? sprintf(
                    '%s:%s/%s',
                    $config->get('DB_HOST'),
                    $config->int('DB_PORT', 3306),
                    $config->require('DB_DATABASE'),
                )
                : sprintf(
                    'cannot reach %s:%s/%s as %s. Check DB_HOST, DB_PORT, DB_DATABASE and that the container is up.',
                    $config->get('DB_HOST'),
                    $config->int('DB_PORT', 3306),
                    $config->require('DB_DATABASE'),
                    (string) $config->get('DB_USERNAME'),
                ),
        ]];

        if (!$connected) {
            return $results;
        }

        // The connection is open but may be to a server that is read-only, in a
        // replica role, or in a maintenance window. A read-only smoke run cannot
        // tell the difference, and the first write would be the thing that finds
        // out.
        $version = $database->scalar('SELECT VERSION()');
        $results[] = [
            'id'     => 'db-version',
            'group'  => 'database',
            'title'  => 'The server version is reported',
            'passed' => is_string($version) && $version !== '',
            'detail' => is_string($version) ? 'MySQL ' . $version : 'the server did not report a version',
        ];

        $results[] = [
            'id'     => 'db-timezone',
            'group'  => 'database',
            'title'  => 'The session time zone is UTC',
            'passed' => (string) $database->scalar('SELECT @@session.time_zone') === '+00:00',
            'detail' => sprintf('@@session.time_zone = %s', (string) $database->scalar('SELECT @@session.time_zone')),
        ];

        return $results;
    }

    // -----------------------------------------------------------------------
    // 3. Schema
    // -----------------------------------------------------------------------

    /** @return list<array{id: string, group: string, title: string, passed: bool, detail: string}> */
    private function checkSchema(): array
    {
        $database = $this->database();

        $migrator = new Migrator(
            $database,
            $this->kernel->logger(),
            $this->kernel->basePath('db/migrations'),
        );

        $status = $migrator->status();
        $pending = $status['pending'];

        $results = [[
            'id'     => 'schema-migrations',
            'group'  => 'schema',
            'title'  => 'No migration is pending',
            'passed' => $pending === [],
            'detail' => $pending === []
                ? sprintf('%d applied', count($status['applied']))
                : sprintf('run `php bin/migrate.php`; %d pending: %s', count($pending), implode(', ', $pending)),
        ]];

        // A smoke run against an unmigrated database is only meaningful if the
        // tables are actually there, and `migrations.status()` above creates the
        // ledger. Counting the application tables is what distinguishes "the
        // ledger is new" from "the baseline migration ran".
        $count = (int) $database->scalar(
            'SELECT COUNT(*) FROM `information_schema`.`TABLES`
              WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_TYPE` = \'BASE TABLE\''
        );

        $results[] = [
            'id'     => 'schema-tables',
            'group'  => 'schema',
            'title'  => 'The application tables exist',
            'passed' => $count >= 29,
            'detail' => $count >= 29
                ? sprintf('%d tables', $count)
                : sprintf(
                    '%d table(s); db/schema.sql defines 29. The baseline migration has not been applied.',
                    $count,
                ),
        ];

        $views = (int) $database->scalar(
            'SELECT COUNT(*) FROM `information_schema`.`VIEWS` WHERE `TABLE_SCHEMA` = DATABASE()'
        );

        $results[] = [
            'id'     => 'schema-views',
            'group'  => 'schema',
            'title'  => 'The reporting views exist',
            'passed' => $views >= 3,
            'detail' => $views >= 3
                ? sprintf('%d views (v_room_utilisation, v_peak_usage, v_lecturer_load)', $views)
                : sprintf('%d view(s); db/schema.sql defines 3', $views),
        ];

        return $results;
    }

    // -----------------------------------------------------------------------
    // 4. Reference data
    // -----------------------------------------------------------------------

    /** @return list<array{id: string, group: string, title: string, passed: bool, detail: string}> */
    private function checkReferenceData(): array
    {
        $database = $this->database();
        $results = [];

        foreach (self::REFERENCE_DATA as $key => $requirement) {
            $count = (int) $database->scalar($requirement['sql']);

            $results[] = [
                'id'     => 'data-' . $key,
                'group'  => 'data',
                'title'  => 'Seeded: ' . $requirement['label'],
                'passed' => $count >= $requirement['minimum'],
                'detail' => $count >= $requirement['minimum']
                    ? sprintf('%d', $count)
                    : sprintf(
                        'found %d, needs %d. Run `php bin/seed.php`; %s.',
                        $count,
                        $requirement['minimum'],
                        $requirement['remedy'],
                    ),
            ];
        }

        $schedulable = (int) $database->scalar(self::SCHEDULABLE_SQL);

        $results[] = [
            'id'     => 'data-schedulable',
            'group'  => 'data',
            'title'  => 'At least one cohort has students and a lecturer',
            'passed' => $schedulable > 0,
            'detail' => $schedulable > 0
                ? sprintf('%d cohort(s) the engine could schedule', $schedulable)
                : 'the engine has nothing to schedule. Add enrollments and a lecturer_course_assignments '
                    . 'row (or a courses.default_lecturer_id); otherwise generate reports 0 sessions and an '
                    . 'empty timetable looks like a real one',
        ];

        return $results;
    }

    // -----------------------------------------------------------------------
    // 5. The HTTP pipeline, in process
    // -----------------------------------------------------------------------

    /** @return list<array{id: string, group: string, title: string, passed: bool, detail: string}> */
    private function checkHttp(Output $output, bool $asJson): array
    {
        $prefix = $this->kernel->config()->apiPrefix();
        $app = new App($this->kernel->basePath());

        $results = [];

        // --- /health --------------------------------------------------------
        // A real Request through the real pipeline. `remoteAddress` is loopback
        // so a rate limiter has something stable to key on if it is reached.
        $health = $app->handle(new Request('GET', $prefix . '/health', [], [], '', [], '127.0.0.1', 'catms-smoke'));

        $results[] = [
            'id'     => 'http-health',
            'group'  => 'http',
            'title'  => 'GET /health returns 200 through the real pipeline',
            'passed' => $health->status() === 200,
            'detail' => sprintf('HTTP %d', $health->status()),
        ];

        // The envelope is a published contract (docs/API.md §1), so a smoke run
        // that only checked the status code would miss a controller that
        // accidentally returns a bare array.
        $envelope = $health->decoded();
        $envelopeOk = array_key_exists('data', $envelope) && array_key_exists('error', $envelope);
        $results[] = [
            'id'     => 'http-envelope',
            'group'  => 'http',
            'title'  => 'The response uses the {data, meta, error} envelope',
            'passed' => $envelopeOk,
            'detail' => $envelopeOk ? 'keys present' : 'keys are ' . implode(', ', array_keys($envelope)),
        ];

        $results[] = [
            'id'     => 'http-request-id',
            'group'  => 'http',
            'title'  => 'Every response carries X-Request-Id',
            'passed' => ($health->headers()['X-Request-Id'] ?? '') !== '',
            'detail' => 'X-Request-Id: ' . (string) ($health->headers()['X-Request-Id'] ?? '(absent)'),
        ];

        // --- an authenticated route -----------------------------------------
        // 401 rather than 501: permission checking runs before handler
        // resolution (App::dispatch), so an anonymous caller must be refused
        // before anything looks at whether the controller exists. A 501 here
        // would mean the order was inverted, which is a security finding, not a
        // missing feature.
        //
        // The path is `/timetable`, not `/timetable/week`: only a path in
        // config/routes.php reaches the middleware pipeline, and an unmatched
        // path is answered with a plain 404 before authentication runs
        // (App::handle). A check naming a URL the router does not know would
        // report a 404 masquerading as a 401 on every run, and would still read
        // as a pass to anyone who only looked at "not 200".
        $guarded = $app->handle(
            new Request('GET', $prefix . '/timetable', [], [], '', [], '127.0.0.1', 'catms-smoke')
        );

        $results[] = [
            'id'     => 'http-auth-before-handler',
            'group'  => 'http',
            'title'  => 'An anonymous request to a protected route is refused',
            'passed' => $guarded->status() === 401,
            'detail' => sprintf(
                'GET /timetable as anonymous -> HTTP %d; 401 is correct, 501 would mean the '
                . 'permission check runs after handler resolution',
                $guarded->status(),
            ),
        ];

        // --- a malformed request --------------------------------------------
        // The documented error shape, on a path that needs no database. This is
        // the one most likely to regress quietly, because a 400 nobody looks at
        // is invisible until a client depends on the code.
        $notFound = $app->handle(new Request(
            'GET',
            $prefix . '/definitely-not-a-route',
            [],
            [],
            '',
            [],
            '127.0.0.1',
            'catms-smoke',
        ));
        $notFoundBody = $notFound->decoded();
        $code = $notFoundBody['error']['code'] ?? null;

        $results[] = [
            'id'     => 'http-not-found',
            'group'  => 'http',
            'title'  => 'An unknown route returns 404 with a documented error code',
            'passed' => $notFound->status() === 404 && is_string($code) && $code !== '',
            'detail' => sprintf('HTTP %d, error.code = %s', $notFound->status(), is_string($code) ? $code : '(absent)'),
        ];

        // --- maintenance mode ------------------------------------------------
        // The flag file is in storage/ and never touches the database, so this
        // can be exercised safely: write it, prove the 503, remove it. If the
        // file already exists, the instance is in maintenance and this check says
        // so rather than lying about having toggled it.
        $flag = $this->kernel->config()->storagePath('maintenance.flag');
        $preExisting = is_file($flag);

        if ($preExisting) {
            $results[] = [
                'id'     => 'http-maintenance',
                'group'  => 'http',
                'title'  => 'Maintenance mode is off',
                'passed' => false,
                'detail' => 'storage/maintenance.flag exists; this instance is in maintenance mode',
            ];
        } else {
            $results[] = $this->probeMaintenance($app, $prefix, $flag, $output, $asJson);
        }

        return $results;
    }

    /**
     * Write the maintenance flag, prove a write route is refused with 503, remove
     * it again.
     *
     * The flag is a file in `storage/`, which is why this is safe: no database
     * write, no data, nothing to clean up if the process dies mid-probe. A
     * `finally` removes it, and a crash leaves an instance in maintenance mode —
     * loud, and one `app:maintenance --disable` away from fixed. That is the
     * correct failure direction: a deploy should not be able to leave writes
     * silently enabled after a check that claimed to have disabled them.
     *
     * @return array{id: string, group: string, title: string, passed: bool, detail: string}
     */
    private function probeMaintenance(App $app, string $prefix, string $flag, Output $output, bool $asJson): array
    {
        $written = @file_put_contents($flag, 'smoke test ' . gmdate('c') . "\n");

        if ($written === false) {
            return [
                'id'     => 'http-maintenance',
                'group'  => 'http',
                'title'  => 'Maintenance mode is off after the probe',
                'passed' => false,
                'detail' => sprintf('could not write %s; the directory is not writable', $flag),
            ];
        }

        $status = 0;
        $error = null;

        try {
            $response = $app->handle(new Request(
                'POST',
                $prefix . '/auth/login',
                [],
                ['content-type' => 'application/json'],
                '{"email":"nobody@example.invalid","password":"irrelevant"}',
                [],
                '127.0.0.1',
                'catms-smoke',
            ));

            $status = $response->status();
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
        } finally {
            $removed = @unlink($flag);
        }

        // A flag left behind puts the instance into maintenance mode, which is
        // loud and fixed by one command — the right direction to fail in, since
        // a check that claims to have re-enabled writes and did not is the
        // dangerous outcome. It is still reported as a failure of this check.
        if (!$removed) {
            return [
                'id'     => 'http-maintenance',
                'group'  => 'http',
                'title'  => 'Maintenance mode is off after the probe',
                'passed' => false,
                'detail' => sprintf(
                    'wrote %s but could not remove it. This instance is now in maintenance mode. '
                    . 'Run: php bin/console maintenance --disable',
                    $flag,
                ),
            ];
        }

        // 503, not 401: the caller is unauthenticated, but maintenance mode wraps
        // authentication precisely so a deploy does not send a logged-in user
        // round the login loop (App::pipelineFor).
        $passed = $error === null && $status === 503;

        if ($error !== null && !$asJson) {
            $output->warn('The maintenance probe raised: ' . $error);
        }

        return [
            'id'     => 'http-maintenance',
            'group'  => 'http',
            'title'  => 'Maintenance mode returns 503 before authentication',
            'passed' => $passed,
            'detail' => $passed
                ? 'flag written and removed; 503 while it was present'
                : $error ?? sprintf(
                    'POST /auth/login with the flag present -> HTTP %d, expected 503',
                    $status,
                ),
        ];
    }

    // -----------------------------------------------------------------------
    // 6. The engine
    // -----------------------------------------------------------------------

    /**
     * Solve a small problem in this process and check the result.
     *
     * This is the check a health endpoint cannot do. It builds its own
     * `SchedulingProblem` rather than loading one from the database, for two
     * reasons: it must work on an instance whose reference data is not seeded
     * yet (so it can still tell an operator whether the *engine* is broken), and
     * a fixed problem gives a fixed answer, which is what makes a regression
     * visible as a failure rather than as noise.
     *
     * The problem is deliberately easy — one room per session-sized band and a
     * full teaching week — because the question here is "does the engine still
     * work", not "is this department's timetable good". Accuracy is asserted at
     * 1.0 so that *any* unplaced session fails: a smoke suite that tolerates an
     * unplaced session is a smoke suite that will pass a broken engine.
     *
     * @return list<array{id: string, group: string, title: string, passed: bool, detail: string}>
     */
    private function checkEngine(): array
    {
        $problem = $this->engineProblem();
        $weights = $this->kernel->weightProfiles()->weights();

        $checker = new ConstraintChecker(
            $this->kernel->config()->int('ENGINE_MAX_LECTURER_SESSIONS_PER_DAY', 4),
        );

        $engine = new AllocationEngine(
            $checker,
            new CandidateGenerator($checker),
            new CostFunction($weights),
        );

        $options = new EngineOptions(
            maxIterations: 500,
            randomSeed: self::ENGINE_SEED,
            timeBudgetSeconds: self::ENGINE_BUDGET_SECONDS,
        );

        $startedAt = microtime(true);
        $result = $engine->solve($problem, [], $options);
        $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);

        $total = $problem->totalSessions();
        $assigned = $result->assignedCount();
        $accuracy = $total === 0
            ? 0.0
            : $assigned / $total;

        $results = [];

        // Violations are the engine's own hard-constraint report. A non-empty
        // list is an internal invariant failure, not a scheduling outcome, and
        // docs/ALLOCATION_ENGINE.md §12 says a run with violations must not be
        // persisted — so it must not be published here either.
        $results[] = [
            'id'     => 'engine-violations',
            'group'  => 'engine',
            'title'  => 'The engine reports no hard-constraint violations',
            'passed' => $result->violations === [],
            'detail' => $result->violations === []
                ? 'none'
                : sprintf(
                    '%d violation(s): %s',
                    count($result->violations),
                    $this->firstViolation($result->violations),
                ),
        ];

        $results[] = [
            'id'     => 'engine-accuracy',
            'group'  => 'engine',
            'title'  => 'The engine places every session',
            'passed' => $accuracy >= 1.0,
            'detail' => sprintf(
                '%d/%d placed (accuracy %.2f), %d unallocated, seed %d',
                $assigned,
                $total,
                $accuracy,
                $result->unallocatedCount(),
                self::ENGINE_SEED,
            ),
        ];

        // NFR-PERF-01 allows 3 s for the whole request. The engine is given 1 s
        // of that, and a 20-session problem should take single-digit
        // milliseconds, so anything in the hundreds means something structural.
        $budgetMs = (int) round(self::ENGINE_BUDGET_SECONDS * 1000);
        $results[] = [
            'id'     => 'engine-budget',
            'group'  => 'engine',
            'title'  => 'The engine finishes inside its time budget',
            'passed' => $elapsedMs <= $budgetMs,
            'detail' => sprintf(
                '%d ms, budget %d ms (NFR-PERF-01 allows 3000 ms for the request)',
                $elapsedMs,
                $budgetMs,
            ),
        ];

        // Determinism: the same seed must give the same answer (ADR-005). A
        // second solve is cheap at this size, and it is the only check that can
        // catch a lost `seed()` call, which no other assertion would notice.
        // Reproducibility is promised for a fixed iteration count, not for a
        // wall-clock budget, so both solves run on a clock that never moves.
        $pinned = new EngineOptions(
            maxIterations: $options->maxIterations,
            randomSeed: self::ENGINE_SEED,
            timeBudgetSeconds: self::ENGINE_BUDGET_SECONDS,
            clock: new FixedClock(),
        );
        $first = $engine->solve($problem, [], $pinned);
        $repeat = $engine->solve($problem, [], $pinned);
        $placements = static fn (SchedulingResult $run): array => array_map(
            static fn (Assignment $assignment): array => $assignment->toArray(),
            $run->orderedAssignments(),
        );
        $same = $placements($repeat) === $placements($first);

        $results[] = [
            'id'     => 'engine-deterministic',
            'group'  => 'engine',
            'title'  => 'The same seed gives the same timetable',
            'passed' => $same,
            'detail' => $same
                ? 'two solves with seed ' . self::ENGINE_SEED . ' agreed'
                : sprintf(
                    'two solves with seed %d disagreed (%d then %d assigned)',
                    self::ENGINE_SEED,
                    $first->assignedCount(),
                    $repeat->assignedCount(),
                ),
        ];

        return $results;
    }

    /**
     * A small, always-solvable problem: five days × four slots, one room per
     * session, distinct lecturers and cohorts, no required features.
     *
     * Built inline rather than taken from `tests/`, deliberately: the command must
     * not depend on `autoload-dev`, because the deployed image ships without it
     * and a smoke suite that only runs on a developer machine is worth nothing at
     * 02:00 during an incident.
     */
    private function engineProblem(): SchedulingProblem
    {
        $times = [['08:00', '09:00'], ['10:00', '11:00'], ['12:00', '13:00'], ['14:00', '15:00']];

        $slots = [];
        $slotId = 1;
        for ($day = 1; $day <= 5; $day++) {
            foreach ($times as [$start, $end]) {
                $slots[] = new TimeSlot($slotId++, $day, $start, $end);
            }
        }

        $sessions = [];
        $rooms = [];
        for ($i = 1; $i <= 20; $i++) {
            $rooms[] = new Room(
                $i,
                sprintf('SMK%02d', $i),
                sprintf('Smoke room %d', $i),
                'Main',
                50,
                new RoomFeatures(),
                'available',
                true,
                null,
                true,
            );

            $sessions[] = new SessionRequest(
                $i,
                $i,        // cohortId
                $i,        // courseId
                $i,        // lecturerId
                40,        // enrolledCount, under the room capacity
                60,        // durationMinutes
                new RoomFeatures(),
                0,         // sequence
                null,      // preferredBuilding
                1,         // departmentId
                sprintf('Smoke C%d', $i),
            );
        }

        return (new SchedulingProblem())
            ->withSessions($sessions)
            ->withRooms($rooms)
            ->withSlots($slots);
    }

    /**
     * The headline of a violation list, for a one-line detail string.
     *
     * `Violation::__toString()` already renders as `HC-4: message`, and the codes
     * are the ones documented in docs/ALLOCATION_ENGINE.md §4, so the operator
     * gets the constraint name and can look it up.
     *
     * @param list<Violation> $violations
     */
    private function firstViolation(array $violations): string
    {
        return (string) $violations[0];
    }

    // -----------------------------------------------------------------------
    // Wiring
    // -----------------------------------------------------------------------

    private function database(): Database
    {
        return $this->kernel->database();
    }
}
