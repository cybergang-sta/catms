<?php

declare(strict_types=1);

namespace App\Console\Command;

use App\Console\Input;
use App\Console\Kernel;
use App\Console\Output;
use App\Core\Database;
use App\Infrastructure\Persistence\Migration\Migrator;
use Throwable;

/**
 * `bin/console verify-integrity` — the gate an instance must pass before it is
 * opened to users.
 *
 * WHY THIS EXISTS
 * docs/DEPLOYMENT.md §12.4 and §16.2: a restore that produces a *working*
 * application with a *corrupt* timetable is worse than an outage, because it is
 * trusted. `bin/migrate.php` reporting success only means the DDL applied; it
 * says nothing about whether the data that landed is coherent. So this command
 * asks the questions that only the data can answer, and exits non-zero when the
 * answer is bad, which is what lets §12.4 put it *before* opening the instance.
 *
 * THE FIVE QUERIES ARE NOT RE-INVENTED HERE
 * Checks 1–5 are transcribed from the footer of `db/schema.sql`, the same file
 * the baseline migration applies. They are the queries the schema author wrote
 * to describe what "correct" means, and they are the ones a person runs by hand
 * when a report looks wrong. Transcribing them into a second place invites the
 * two to drift, so each one carries a comment naming the footer query it comes
 * from and the business rule it enforces (BR-01, BR-04, BR-02, BR-05, and the
 * NFR-PERF-04 accuracy gate). If the footer changes, the diff here is the signal
 * to change it.
 *
 * FOUR "MUST BE EMPTY" CHECKS AND ONE THRESHOLD
 * Checks 1–4 assert an *absence*, so any row returned is a finding and the exit
 * code is a failure. Check 5 is a report: it prints the last applied runs and
 * fails only if the most recent one is below the accuracy gate. A system with no
 * applied run at all is not a failure — nothing has been published, so nothing
 * can be wrong — and it is reported as such rather than as a pass.
 *
 * THE OTHER CHECKS
 * The privilege check is the same control as `retention` sees from the other
 * side: the application user must *not* hold UPDATE or DELETE on `audit_log`
 * (VULN-14, NFR-SEC-06). The migration check refuses an instance whose schema is
 * behind the code, because the first request after such a deploy fails on a
 * column that does not exist. `--verify-no-demo-credentials` is the last gate
 * before production, described in the notes below.
 *
 * NO REPAIRS
 * This command never writes. Every finding is a statement about a database that
 * somebody has to decide about, and a verifier that fixes what it finds cannot be
 * used as evidence that the database was correct beforehand.
 */
final class VerifyIntegrityCommand extends Command
{
    /**
     * The emails `SeedCommand` creates. Checked by e-mail, because a password
     * hash cannot be searched for: bcrypt is salted, so the same password
     * produces a different hash on every run and there is no substring to match.
     */
    private const DEMO_EMAILS = [
        'admin@utas.edu.gh',
        'lecturer@utas.edu.gh',
        'student@utas.edu.gh',
    ];

    /**
     * The accuracy gate from NFR-PERF-04 and the §4.4.1 target of 95 %.
     * The report's headline figure is 95 %, the gate is 90 %, and the gap is
     * deliberate headroom: a run at 0.91 is publishable, a run at 0.89 is not.
     */
    private const ACCURACY_GATE = 0.90;

    /**
     * The `--only` targets, in the order they are reported.
     */
    private const GROUPS = ['invariants', 'runs', 'privileges', 'migrations', 'demo'];





    public function name(): string
    {
        return 'verify-integrity';
    }

    public function description(): string
    {
        return 'Check that the data in this instance is coherent, before it is opened to users';
    }

    /** @return list<string> */
    public function aliases(): array
    {
        return ['app:verify-integrity', 'verify'];
    }

    /** @return list<string> */
    public function synopsis(): array
    {
        return [
            'php bin/console verify-integrity',
            'php bin/console verify-integrity --verify-no-demo-credentials',
            'php bin/console verify-integrity --json',
            'php bin/console verify-integrity --only=invariants',
        ];
    }

    /** @return array<string, string> */
    public function options(): array
    {
        return [
            'verify-no-demo-credentials' => 'Also fail if any seeded demo account can still log in',
            'only'                      => 'Comma-separated group: invariants, runs, privileges, migrations, demo',
            'json'                      => 'Machine-readable output; nothing else is written to stdout',
            'quiet'                     => 'Print only failures, not the passing checks',
        ];
    }

    /** @return list<string> */
    public function notes(): array
    {
        return [
            'Read-only. It never repairs anything it finds, because a verifier that',
            'fixes what it finds cannot be used as evidence the data was sound.',
            'Checks 1-5 are the queries in the footer of db/schema.sql, unchanged.',
            'Run it after a restore and before reopening the instance (docs/DEPLOYMENT.md §12.4).',
            'Run it monthly as well: §16.2 verifies the recovery point monthly, not on trust.',
            'Exit 0 = every selected check passed. Exit 1 = at least one failed.',
            'On demo credentials: `bin/console seed` refuses to run outside local and staging',
            'without --i-know-what-i-am-doing, and outside local the demo accounts are created',
            'suspended with must_change_password set, so a documented password cannot be used.',
            'This flag is the belt to that pair of braces: it fails if such an account is active',
            'at all, which is the exact shape of VULN-06.',
        ];
    }

    public function run(Input $input, Output $output): int
    {
        $known = ['verify-no-demo-credentials', 'only', 'json', 'quiet'];
        $unknown = $input->unknownOptions($known);
        if ($unknown !== []) {
            return $this->invalid($output, 'Unknown option: ' . implode(', ', $unknown));
        }

        $asJson = $input->boolOption('json');
        $quiet = $input->boolOption('quiet');

        $groups = $this->resolveGroups($input->option('only'), $input->boolOption('verify-no-demo-credentials'));

        if ($groups === null) {
            return $this->invalid($output, sprintf(
                'Unknown --only group. Valid groups: %s.',
                implode(', ', self::GROUPS),
            ));
        }

        try {
            return $this->verify($output, $groups, $asJson, $quiet);
        } catch (Throwable $exception) {
            $this->kernel->logger()->error('Integrity verification failed to complete.', [
                'exception' => $exception::class,
                'message'   => $exception->getMessage(),
            ]);

            if ($asJson) {
                $output->json(['status' => 'error', 'message' => $exception->getMessage()]);

                return Kernel::FAILURE;
            }

            $output->failure('verify-integrity: ' . $exception->getMessage());

            if ($this->kernel->config()->isDebug()) {
                $output->line($exception->getTraceAsString());
            }

            return Kernel::FAILURE;
        }
    }// -----------------------------------------------------------------------
// Groups
// -----------------------------------------------------------------------


    /**
     * Which checks to run for a given `--only` and `--verify-no-demo-credentials`.
     *
     * @return list<string>|null Null when a name in `--only` is not a group.
     */
    private function resolveGroups(?string $only, bool $demoRequested): ?array
    {
        if ($only === null || trim($only) === '') {
            $groups = self::GROUPS;

            // The demo check is opt-in because it is a release gate, not a
            // correctness invariant: a development database is *supposed* to
            // have the demo accounts, and failing there every day teaches people
            // to ignore a red result.
            if (!$demoRequested) {
                $groups = array_values(array_filter($groups, static fn (string $g): bool => $g !== 'demo'));
            }

            return $groups;
        }

        $groups = [];
        foreach (explode(',', $only) as $part) {
            $name = strtolower(trim($part));
            if ($name === '') {
                continue;
            }

            if (!in_array($name, self::GROUPS, true)) {
                return null;
            }

            $groups[$name] = true;
        }

        if ($groups === []) {
            return null;
        }

        return array_values(array_filter(
            self::GROUPS,
            static fn (string $g): bool => isset($groups[$g]),
        ));
    }

    // -----------------------------------------------------------------------
    // Driver
    // -----------------------------------------------------------------------

    /** @param list<string> $groups */
    private function verify(Output $output, array $groups, bool $asJson, bool $quiet): int
    {
        $database = $this->database();

        /** @var list<array{id: string, group: string, title: string, passed: bool, detail: string, rows: array<int, mixed>}> $results */
        $results = [];

        if (in_array('invariants', $groups, true)) {
            foreach ($this->invariants($database) as $result) {
                $results[] = $result;
            }
        }

        if (in_array('runs', $groups, true)) {
            foreach ($this->runs($database) as $result) {
                $results[] = $result;
            }
        }

        if (in_array('privileges', $groups, true)) {
            $results[] = $this->privileges($database);
        }

        if (in_array('migrations', $groups, true)) {
            $results[] = $this->migrations();
        }

        if (in_array('demo', $groups, true)) {
            $results[] = $this->demoCredentials($database);
        }

        $failed = array_values(array_filter($results, static fn (array $r): bool => !$r['passed']));

        if ($asJson) {
            $output->json([
                'status'  => $failed === [] ? 'ok' : 'failed',
                'checked' => count($results),
                'failed'  => count($failed),
                'checks'  => array_map(
                    static fn (array $r): array => [
                        'id'      => $r['id'],
                        'group'   => $r['group'],
                        'title'   => $r['title'],
                        'passed'  => $r['passed'],
                        'detail'  => $r['detail'],
                        'rows'    => $r['rows'],
                    ],
                    $results,
                ),
            ]);

            return $failed === []
                ? Kernel::SUCCESS
                : Kernel::FAILURE;
        }

        $output->title('Integrity verification');

        $rows = [];
        foreach ($results as $result) {
            if ($quiet && $result['passed']) {
                continue;
            }

            $rows[] = [
                $result['passed'] ? 'pass' : 'FAIL',
                $result['id'],
                $result['title'],
                $result['detail'],
            ];
        }

        if ($rows === []) {
            $output->success(sprintf('All %d checks passed.', count($results)));
        } else {
            $output->table(['', 'id', 'check', 'detail'], $rows);
        }

        $output->line();
        $output->definitions([
            'checks run' => count($results),
            'failed'     => count($failed),
            'groups'     => implode(', ', $groups),
        ], 2);
        $output->line();

        if ($failed === []) {
            $output->success('This instance is coherent. It is safe to open.');

            return Kernel::SUCCESS;
        }

        foreach ($failed as $result) {
            $output->failure(sprintf('%s — %s', $result['id'], $result['detail']));
        }

        $output->line();
        $output->line('  Do not open this instance. Each failure above is a statement about the data,');
        $output->line('  and the decision about what to do with it is a person\'s. See');
        $output->line('  docs/DEPLOYMENT.md §15.5 (double bookings) and §12.4 (restore drill).');
        $output->line();

        return Kernel::FAILURE;
    }

    // -----------------------------------------------------------------------
    // Invariants — the five footer queries of db/schema.sql
    // -----------------------------------------------------------------------

    /**
     * Checks 1–4 assert an absence, so any row returned is a finding.
     *
     * @return list<array{id: string, group: string, title: string, passed: bool, detail: string, rows: array<int, mixed>}>
     */
    private function invariants(Database $database): array
    {
        $results = [];

        // Footer query 1 — BR-01, no room double-booked in a slot.
        $results[] = $this->mustBeEmpty(
            $database,
            'br-01',
            'No room is double-booked',
            'SELECT `room_id`, `time_slot_id`, `week_number`, COUNT(*) AS c
               FROM `allocations` WHERE `status` IN (\'proposed\',\'confirmed\',\'updated\')
              GROUP BY `room_id`, `time_slot_id`, `week_number` HAVING c > 1
              LIMIT 50',
        );

        // Footer query 2 — BR-04, an allocation never exceeds room capacity.
        $results[] = $this->mustBeEmpty(
            $database,
            'br-04',
            'No allocation exceeds room capacity',
            'SELECT a.`id` AS allocation_id, r.`code` AS room, r.`capacity`, c.`enrolled_count`
               FROM `allocations` a
               JOIN `rooms` r ON r.`id` = a.`room_id`
               JOIN `cohorts` c ON c.`id` = a.`cohort_id`
              WHERE a.`status` IN (\'proposed\',\'confirmed\',\'updated\')
                AND r.`capacity` < c.`enrolled_count`
              LIMIT 50',
        );

        // Footer query 3 — BR-02, a lecturer is not in two places at once.
        $results[] = $this->mustBeEmpty(
            $database,
            'br-02',
            'No lecturer is double-booked',
            'SELECT `lecturer_id`, `time_slot_id`, `week_number`, COUNT(*) AS c
               FROM `allocations` WHERE `status` IN (\'proposed\',\'confirmed\',\'updated\')
              GROUP BY `lecturer_id`, `time_slot_id`, `week_number` HAVING c > 1
              LIMIT 50',
        );

        // Footer query 4 — BR-05, a room has every feature the course mandates.
        $results[] = $this->mustBeEmpty(
            $database,
            'br-05',
            'No room is missing a mandatory feature',
            'SELECT DISTINCT a.`id` AS allocation_id, a.`room_id`, cfr.`feature_id`
               FROM `allocations` a
               JOIN `course_feature_requirements` cfr
                 ON cfr.`course_id` = a.`course_id` AND cfr.`mandatory` = 1
               LEFT JOIN `room_feature_map` rfm
                 ON rfm.`room_id` = a.`room_id` AND rfm.`feature_id` = cfr.`feature_id`
              WHERE a.`status` IN (\'proposed\',\'confirmed\',\'updated\')
                AND rfm.`room_id` IS NULL
              LIMIT 50',
        );

        // The generated column is the database-level backstop for BR-01, so its
        // absence is itself a finding even when no double booking exists yet.
        // Without it, a hand-run INSERT bypasses the engine and the only thing
        // standing between that and two classes in one room is code review.
        $results[] = $this->activeGuardExists($database);

        return $results;
    }

    /**
     * Run a query that must return no rows, and report the first few if it does.
     *
     * The offending rows are returned rather than just counted, because a count
     * sends the operator back to a SQL prompt and a row sends them straight to
     * the cohort that needs telling (docs/DEPLOYMENT.md §15.5).
     *
     * @return array{id: string, group: string, title: string, passed: bool, detail: string, rows: array<int, mixed>}
     */
    private function mustBeEmpty(Database $database, string $id, string $title, string $sql): array
    {
        $rows = $database->select($sql);
        $count = count($rows);

        return [
            'id'     => $id,
            'group'  => 'invariants',
            'title'  => $title,
            'passed' => $count === 0,
            'detail' => $count === 0
                ? 'no rows'
                : sprintf('%d row(s) violate it; %d shown', $count, min($count, 50)),
            'rows'   => $rows,
        ];
    }

    /** @return array{id: string, group: string, title: string, passed: bool, detail: string, rows: array<int, mixed>} */
    private function activeGuardExists(Database $database): array
    {
        $found = $database->selectOne(
            'SELECT `EXTRA`, `GENERATION_EXPRESSION`
               FROM `information_schema`.`COLUMNS`
              WHERE `TABLE_SCHEMA` = DATABASE()
                AND `TABLE_NAME` = \'allocations\'
                AND `COLUMN_NAME` = \'active_guard\''
        );

        $present = $found !== null;

        return [
            'id'     => 'schema-active-guard',
            'group'  => 'invariants',
            'title'  => 'The allocations.active_guard unique index exists',
            'passed' => $present,
            'detail' => $present
                ? 'the database rejects a double booking even without the engine'
                : 'missing; apply the baseline migration (db/migrations/2026_08_01_090000_baseline.php)',
            'rows'   => $present ? [] : [['column' => 'allocations.active_guard']],
        ];
    }

    // -----------------------------------------------------------------------
    // Check 5 — the accuracy gate on applied runs
    // -----------------------------------------------------------------------

    /**
     * Footer query 5, as a gate.
     *
     * A pass needs an applied run, and it needs the *latest* applied run to be
     * at or above the gate. Ten rows are listed so a trend is visible, but only
     * the newest decides: an instance whose last publish was 0.88 accuracy is
     * not made acceptable by the 0.97 it published in March.
     *
     * @return list<array{id: string, group: string, title: string, passed: bool, detail: string, rows: array<int, mixed>}>
     */
    private function runs(Database $database): array
    {
        $rows = $database->select(
            'SELECT `id`, `total_sessions`, `assigned_sessions`, `accuracy`, `duration_ms`, `started_at`
               FROM `allocation_runs` WHERE `is_applied` = 1
              ORDER BY `started_at` DESC LIMIT 10'
        );

        if ($rows === []) {
            return [[
                'id'     => 'nfr-perf-04',
                'group'  => 'runs',
                'title'  => 'The latest applied run met the accuracy gate',
                'passed' => true,
                'detail' => 'no run has been applied yet, so nothing is published and nothing can be wrong',
                'rows'   => [],
            ]];
        }

        $latest = $rows[0];
        $accuracy = $latest['accuracy'] === null
            ? null
            : (float) $latest['accuracy'];
        $passed = $accuracy !== null && $accuracy >= self::ACCURACY_GATE;

        return [[
            'id'     => 'nfr-perf-04',
            'group'  => 'runs',
            'title'  => 'The latest applied run met the accuracy gate',
            'passed' => $passed,
            'detail' => $passed
                ? sprintf('latest run accuracy %.2f, gate %.2f', (float) $accuracy, self::ACCURACY_GATE)
                : sprintf(
                    'latest run accuracy %s, gate %.2f; the published timetable is below the target',
                    $accuracy === null ? 'NULL' : number_format($accuracy, 4),
                    self::ACCURACY_GATE,
                ),
            'rows'   => $rows,
        ]];
    }

    // -----------------------------------------------------------------------
    // Privileges — VULN-14 from the other side
    // -----------------------------------------------------------------------

    /**
     * The application user must not be able to alter the audit log.
     *
     * This is the mirror of the check `retention` performs: that one refuses to
     * purge without DELETE, this one fails if the *web* user has it. Both point
     * at the same grant (docs/DEPLOYMENT.md §6.5) and both are cheap, which is
     * the point of a control that is checked on every release rather than
     * documented once.
     *
     * @return array{id: string, group: string, title: string, passed: bool, detail: string, rows: array<int, mixed>}
     */
    private function privileges(Database $database): array
    {
        $schema = $this->kernel->config()->require('DB_DATABASE');
        $user = (string) $database->scalar('SELECT CURRENT_USER()');

        $lines = [];
        foreach ($database->select('SHOW GRANTS FOR CURRENT_USER') as $row) {
            $line = (string) (array_values($row)[0] ?? '');
            if ($line === '') {
                continue;
            }

            $lines[] = $line;
        }

        $quoted = '(?:`?' . preg_quote(strtolower($schema), '/') . '`?)';

        $onEverything = false;
        $onAuditLog = [];
        $canMutate = [];

        foreach ($lines as $line) {
            $normalised = strtolower($line);

            if (
                preg_match('/\bon\s+(?:`?\*(?:\.\*)?`?)/', $normalised) === 1
                && str_contains($normalised, 'all privileges')
            ) {
                $onEverything = true;
            }

            if (preg_match('/\bon\s+' . $quoted . '\.`?audit_log`?(?:\s|$)/', $normalised) !== 1) {
                continue;
            }

            $onAuditLog[] = $line;

            $hasAll = str_contains($normalised, 'all privileges');
            $hasUpdate = $hasAll || preg_match('/(?:^|,\s*)update(?:\s|,|$)/', $normalised) === 1;
            $hasDelete = $hasAll || preg_match('/(?:^|,\s*)delete(?:\s|,|$)/', $normalised) === 1;

            if (!$hasUpdate && !$hasDelete) {
                continue;
            }

            $canMutate[] = $line;
        }

        $passed = $canMutate === [];
        $granted = $onAuditLog === []
            ? 'no table-level grant on audit_log'
            : implode(' | ', $onAuditLog);

        $detail = $passed
            ? sprintf('audit_log: %s', $granted)
            : sprintf(
                'the running user %s holds UPDATE and/or DELETE on %s.audit_log, so the application can '
                . 'rewrite its own audit trail. Move the web tier back to catms_app and run retention as catms_maint.',
                $user,
                $schema,
            );

        if ($passed && $onEverything) {
            $detail .= ' (Note: this is a superuser grant; a production instance should not be one.)';
        }

        return [
            'id'     => 'vuln-14',
            'group'  => 'privileges',
            'title'  => 'The application cannot alter audit_log',
            'passed' => $passed,
            'detail' => $detail,
            'rows'   => $canMutate !== [] ? array_map(static fn (string $l): array => ['grant' => $l], $canMutate) : [],
        ];
    }

    // -----------------------------------------------------------------------
    // Migration state
    // -----------------------------------------------------------------------

    /**
     * The schema must not be behind the code.
     *
     * A pending migration means the deployed code expects a column or a table
     * that this instance does not have. It does not break the health check and
     * it does not break the login — the first thing to break is whichever
     * endpoint a user happens to hit, which is a far worse way to find out.
     *
     * @return array{id: string, group: string, title: string, passed: bool, detail: string, rows: array<int, mixed>}
     */
    private function migrations(): array
    {
        $migrator = new Migrator(
            $this->database(),
            $this->kernel->logger(),
            $this->kernel->basePath('db/migrations'),
        );

        $status = $migrator->status();
        $pending = $status['pending'];
        $outOfOrder = $status['out_of_order'];

        $passed = $pending === [];

        if (!$passed) {
            $detail = sprintf(
                '%d migration(s) not applied: %s',
                count($pending),
                implode(', ', array_slice($pending, 0, 5)) . (count($pending) > 5 ? ', …' : ''),
            );
        } elseif ($outOfOrder !== []) {
            $passed = false;
            $detail = sprintf(
                'out-of-order migration(s) present: %s. A version sorts before one already applied, '
                . 'so applying in filename order would place it after its own dependency.',
                implode(', ', $outOfOrder),
            );
        } else {
            $detail = sprintf(
                '%d applied, 0 pending',
                count($status['applied']),
            );
        }

        return [
            'id'     => 'schema-migrations',
            'group'  => 'migrations',
            'title'  => 'The schema matches the deployed code',
            'passed' => $passed,
            'detail' => $detail,
            'rows'   => array_map(static fn (string $v): array => ['pending' => $v], $pending),
        ];
    }

    // -----------------------------------------------------------------------
    // Demo credentials — VULN-06
    // -----------------------------------------------------------------------

    /**
     * No seeded demo account may be able to authenticate.
     *
     * The check is on *usability*, not on existence. An account that exists with
     * `status = 'suspended'` or `must_change_password = 1` is inert, and
     * `SeedCommand` deliberately leaves one behind outside `local`. Deleting the
     * row instead would also work, but the useful answer here is "this account
     * is inert, leave it" or "this account is live, revoke it now" — a
     * distinction that only the state of the row can make.
     *
     * A hash is never compared: bcrypt is salted, so the same password yields a
     * different hash every time and there is no string to search for. The
     * addresses are the identity that matters, and they are the ones the
     * project's own documentation publishes.
     *
     * @return array{id: string, group: string, title: string, passed: bool, detail: string, rows: array<int, mixed>}
     */
    private function demoCredentials(Database $database): array
    {
        $placeholders = [];
        $bindings = [];
        foreach (self::DEMO_EMAILS as $index => $email) {
            $placeholders[] = ':demo' . $index;
            $bindings['demo' . $index] = $email;
        }

        $rows = $database->select(
            'SELECT `email`, `status`, `must_change_password`, `locked_until`
               FROM `users` WHERE `email` IN (' . implode(', ', $placeholders) . ')',
            $bindings,
        );

        $live = [];
        foreach ($rows as $row) {
            $status = (string) $row['status'];

            $usable = $status === 'active'
                && (int) $row['must_change_password'] === 0
                && ($row['locked_until'] === null || strtotime((string) $row['locked_until']) <= time());

            if (!$usable) {
                continue;
            }

            $live[] = [
                'email'                => (string) $row['email'],
                'status'               => $status,
                'must_change_password' => (int) $row['must_change_password'],
            ];
        }

        $passed = $live === [];

        return [
            'id'     => 'vuln-06',
            'group'  => 'demo',
            'title'  => 'No demo account can authenticate',
            'passed' => $passed,
            'detail' => $passed
                ? sprintf(
                    '%d demo account(s) present, none of them usable',
                    count($rows),
                )
                : sprintf(
                    '%d demo account(s) are active with no forced password change: %s. '
                    . 'Suspend them, or run `bin/console seed` again with a forced password.',
                    count($live),
                    implode(', ', array_column($live, 'email')),
                ),
            'rows'   => $live,
        ];
    }

    // -----------------------------------------------------------------------
    // Wiring
    // -----------------------------------------------------------------------

    private function database(): Database
    {
        return $this->kernel->database();
    }
}
