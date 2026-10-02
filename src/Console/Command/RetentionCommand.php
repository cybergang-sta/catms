<?php

declare(strict_types=1);

namespace App\Console\Command;

use App\Console\Input;
use App\Console\Kernel;
use App\Console\Output;
use App\Core\Database;
use Throwable;

/**
 * `bin/console retention` — the only process permitted to delete an `audit_log`
 * row (docs/SECURITY.md §10.2, docs/DEPLOYMENT.md §6.5 and §15.6).
 *
 * WHY A SEPARATE COMMAND AND NOT `worker prune`
 * Three reasons, in order of importance.
 *
 *  1. It runs under a *different database user*. `catms_app` is granted
 *     `SELECT, INSERT` on `audit_log` and nothing else, so the application
 *     cannot rewrite or erase its own audit trail — a convention that is not
 *     enforced is a comment, and "immutable audit log" (NFR-SEC-06) has to be
 *     a privilege rather than a promise. `catms_maint` holds the `DELETE`, and
 *     only this command runs as `catms_maint`.
 *  2. The privilege is *checked*, not assumed. Before the first delete this
 *     command reads `SHOW GRANTS FOR CURRENT_USER` and refuses to touch
 *     `audit_log` unless the connected user really holds `DELETE` on it. Run it
 *     as the wrong user and it stops in the first second, rather than failing
 *     halfway through a seven-year backlog with a 1142 error.
 *  3. Deleting a compliance record is a policy decision, taken on a schedule
 *     and written down. So this command writes its own `system.retention_purge`
 *     audit entry — including when it deleted nothing — which is what answers
 *     "was retention running?" without reading a log file.
 *
 * THE WINDOWS ARE DECLARED ONCE, HERE
 * docs/DATA_MODEL.md §12 is the policy; `self::WINDOWS` is that table in
 * executable form. The three operational tables are also handled by
 * `worker prune` / `worker sweep` on a much shorter schedule, and the overlap is
 * deliberate rather than an oversight: the operational job keeps the tables
 * small between runs so the nightly purge is not the first query in a month to
 * touch a large index. Both use the same predicates, so whichever runs first
 * simply finds nothing left to do.
 *
 * WHAT IS NEVER DELETED
 * `users`, `cohorts`, `courses` and cancelled `allocations` have no expiry.
 * Act 843 §14 requires erasure on request but permits retention where deletion
 * would defeat the purpose of the processing, and an academic record and an
 * audit of what was published are both inside that exemption. They are listed in
 * `NEVER_PURGED` and printed by `--status`, so the decision appears in the
 * output rather than being absent from it.
 *
 * BATCHES, NOT ONE BIG DELETE
 * `audit_log` is the fastest-growing table in the system (~20 000 rows/year per
 * docs/DATA_MODEL.md §14) and `created_at` is the trailing column of three
 * composite keys. One `DELETE` covering seven years of history would hold locks
 * long enough to stall the 08:00 timetable reads. Every delete here runs in
 * `--batch`-sized chunks (1 000 by default), each in its own transaction, so the
 * lock is held for milliseconds and a crash mid-sweep resumes on the next run
 * instead of unwinding.
 */
final class RetentionCommand extends Command
{
    /**
     * The `action` recorded in `audit_log` for every run. docs/SECURITY.md
     * §13.1 lists it as a system action, not a user one.
     */
    private const AUDIT_ACTION = 'system.retention_purge';

    /**
     * Tables with a documented retention window, and therefore a `DELETE`.
     *
     * `days` is the window; the predicate is an SQL fragment with one positional
     * placeholder for it. Kept as data rather than as methods so `--status`,
     * `--dry-run` and the real run cannot drift apart.
     */
    private const WINDOWS = [
        'audit-log' => [
            'table'  => 'audit_log',
            'column' => 'created_at',
            'days'   => 2557,
            'window' => '7 years',
            'basis'  => 'Act 843: processing records',
        ],
        'security-events' => [
            'table'  => 'security_events',
            'column' => 'created_at',
            'days'   => 365,
            'window' => '1 year',
            'basis'  => 'Slow, low-volume attacks are only visible over a year',
        ],
        'refresh-tokens' => [
            'table'  => 'refresh_tokens',
            'column' => 'expires_at',
            'days'   => 30,
            'window' => 'expiry + 30 days',
            'basis'  => 'A revocation must stay provable (NFR-SEC-01)',
        ],
        'password-reset-tokens' => [
            'table'  => 'password_reset_tokens',
            'column' => 'created_at',
            'days'   => 30,
            'window' => '30 days',
            'basis'  => 'Forensic value only',
        ],
        'rate-limit-buckets' => [
            'table'  => 'rate_limit_buckets',
            'column' => 'window_start',
            'days'   => 1,
            'window' => '24 hours',
            'basis'  => 'Self-cleaning; the limiter reads the current window only',
        ],
    ];

    /**
     * Data with no expiry, documented in docs/DATA_MODEL.md §12 and reprinted by
     * `--status`, because "we delete nothing here" is an answer an auditor
     * should not have to take on trust.
     */
    private const NEVER_PURGED = [
        'users'       => 'Academic record; erasure is pseudonymisation, not deletion',
        'cohorts'     => 'Academic record',
        'courses'     => 'Academic record',
        'allocations' => 'Audit of what was published, including cancelled rows',
    ];

    /**
     * The grant this command needs in order to purge `audit_log`, named so the
     * refusal message can tell an operator exactly which user to configure.
     */
    private const MAINTENANCE_USER = 'catms_maint';

    public function name(): string
    {
        return 'retention';
    }

    public function description(): string
    {
        return 'Purge data past its documented retention window, and record that it happened';
    }

    /** @return list<string> */
    public function aliases(): array
    {
        return ['app:retention', 'purge'];
    }

    /** @return list<string> */
    public function synopsis(): array
    {
        return [
            'php bin/console retention',
            'php bin/console retention --dry-run',
            'php bin/console retention --status',
            'php bin/console retention --only=audit-log',
            'php bin/console retention --json',
        ];
    }

    /** @return array<string, string> */
    public function options(): array
    {
        return [
            'dry-run' => 'Report what would be deleted and delete nothing',
            'status'  => 'Report what is past due and delete nothing; same as --dry-run, quieter framing',
            'only'    => 'Comma-separated subset of: ' . implode(', ', array_keys(self::WINDOWS)),
            'batch'   => 'Rows per DELETE. Default 1000. Lower it on a busy primary',
            'force'   => 'Purge audit_log even without the DELETE grant. Development only; see VULN-14',
            'json'    => 'Machine-readable output; nothing else is written to stdout',
        ];
    }

    /** @return list<string> */
    public function notes(): array
    {
        return [
            'This is the only process permitted to DELETE from audit_log (docs/SECURITY.md §10.2).',
            'It must run as the ' . self::MAINTENANCE_USER . ' database user, because that is the only user',
            'holding DELETE on the table. catms_app holds SELECT, INSERT and nothing more (VULN-14).',
            'Windows come from docs/DATA_MODEL.md §12: audit_log 7 years, security_events 1 year,',
            'refresh_tokens expiry + 30 days, password_reset_tokens 30 days, rate_limit_buckets 24 hours.',
            'Never purged, by policy: ' . implode(', ', array_keys(self::NEVER_PURGED)) . '.',
            'Every run writes a ' . self::AUDIT_ACTION . ' audit entry, including one that deletes nothing,',
            'so "was retention running?" is answerable from the table rather than from the log file.',
        ];
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function run(Input $input, Output $output): int
    {
        $unknown = $input->unknownOptions(['dry-run', 'status', 'only', 'batch', 'force', 'json']);
        if ($unknown !== []) {
            return $this->invalid($output, 'Unknown option: ' . implode(', ', $unknown));
        }

        $asJson = $input->boolOption('json');
        $statusOnly = $input->boolOption('status') || $input->boolOption('dry-run');
        $force = $input->boolOption('force');
        $batch = max(1, $input->intOption('batch', 1000));

        $selected = $this->selectWindows($input->option('only'));
        if ($selected === null) {
            return $this->invalid($output, sprintf(
                'Unknown --only target. Valid targets: %s.',
                implode(', ', array_keys(self::WINDOWS)),
            ));
        }

        try {
            return $this->sweep($output, $selected, $batch, $statusOnly, $force, $asJson);
        } catch (Throwable $exception) {
            $this->kernel->logger()->error('Retention sweep failed.', [
                'exception' => $exception::class,
                'message'   => $exception->getMessage(),
            ]);

            if ($asJson) {
                $output->json(['status' => 'error', 'message' => $exception->getMessage()]);

                return Kernel::FAILURE;
            }

            $output->failure('retention: ' . $exception->getMessage());

            if ($this->kernel->config()->isDebug()) {
                $output->line($exception->getTraceAsString());
            }

            return Kernel::FAILURE;
        }
    }

    // -----------------------------------------------------------------------
    // The sweep
    // -----------------------------------------------------------------------

    /**
     * @param array<string, array{table: string, column: string, days: int, window: string, basis: string}> $selected
     */
    private function sweep(
        Output $output,
        array $selected,
        int $batch,
        bool $statusOnly,
        bool $force,
        bool $asJson,
    ): int {
        $database = $this->database();

        $plan = [];
        foreach ($selected as $name => $window) {
            $plan[$name] = $this->plan($database, $window);
        }

        $total = 0;
        foreach ($plan as $entry) {
            $total += $entry['eligible'];
        }

        if ($statusOnly) {
            return $this->reportStatus($output, $plan, $asJson);
        }

        // The grant check happens before anything is written, so a misconfigured
        // user finds out in the first second rather than after the first delete.
        if (isset($plan['audit-log']) && $plan['audit-log']['eligible'] > 0 && !$force) {
            $grants = $this->grantsOnAuditLog($database);
            if (!$grants['permitted']) {
                return $this->refuse($output, $grants, $plan['audit-log']['eligible'], $asJson);
            }
        }

        // One sweep at a time. Two concurrent purges would race for the same
        // rows and the second would report counts the first had already taken,
        // which makes the audit entry wrong.
        $lock = (int) $database->scalar('SELECT GET_LOCK(:name, 0)', ['name' => 'catms_retention']);
        if ($lock !== 1) {
            return $this->refuseLocked($output, $asJson);
        }

        $deleted = [];
        $failures = [];

        try {
            foreach ($plan as $name => $entry) {
                if ($entry['eligible'] === 0) {
                    $deleted[$name] = 0;

                    continue;
                }

                try {
                    $deleted[$name] = $this->deleteInBatches($database, $entry, $batch);
                } catch (Throwable $exception) {
                    // One unreachable table must not abandon the rest of the
                    // sweep, and the audit entry has to say so.
                    $deleted[$name] = 0;
                    $failures[] = sprintf('%s: %s', $name, $exception->getMessage());

                    $this->kernel->logger()->error('Retention window failed.', [
                        'window'  => $name,
                        'table'   => $entry['table'],
                        'message' => $exception->getMessage(),
                    ]);
                }
            }
        } finally {
            $this->releaseLock($database, $lock);
        }

        $runId = $this->uuid4();
        $this->recordAuditEntry($database, $runId, $selected, $plan, $deleted, $failures);

        $this->kernel->logger()->info('Retention sweep complete.', [
            'run_id'   => $runId,
            'deleted'  => $deleted,
            'failures' => count($failures),
        ]);

        if ($asJson) {
            $output->json([
                'status'  => $failures === [] ? 'ok' : 'partial',
                'run_id'  => $runId,
                'deleted' => $deleted,
                'total'   => array_sum($deleted),
                'failures' => $failures,
                'windows' => $this->windowSummary($plan),
            ]);

            return $failures === []
                ? Kernel::SUCCESS
                : Kernel::FAILURE;
        }

        $output->title('Retention purge');

        $rows = [];
        foreach ($plan as $name => $entry) {
            $rows[] = [
                $name,
                $entry['table'],
                $entry['window'],
                $entry['eligible'],
                $deleted[$name] ?? 0,
                $entry['oldest'] ?? '-',
            ];
        }

        $output->table(['window', 'table', 'policy', 'past due', 'deleted', 'oldest row'], $rows);
        $output->line();
        $output->definitions([
            'run id'      => $runId,
            'deleted'     => array_sum($deleted),
            'audit entry' => self::AUDIT_ACTION,
        ], 2);
        $output->line();

        foreach ($failures as $failure) {
            $output->failure((string) $failure);
        }

        if ($failures === []) {
            $output->success(sprintf(
                'Purged %d row(s) and recorded the run as %s.',
                array_sum($deleted),
                self::AUDIT_ACTION,
            ));
        }

        return $failures === []
            ? Kernel::SUCCESS
            : Kernel::FAILURE;
    }

    // -----------------------------------------------------------------------
    // Planning
    // -----------------------------------------------------------------------

    /**
     * Count what is eligible and build the statement that will remove it.
     *
     * The window is a bound value, never an interpolated number, so it cannot
     * become an injection point. The column name is interpolated because it
     * comes from `self::WINDOWS` rather than from input. The binding is
     * positional (a list) because the placeholder in the predicate is `?`;
     * `Database::run()` turns an integer key into a positional index and a
     * string key into a named one, and the two cannot be mixed.
     *
     * @param array{table: string, column: string, days: int, window: string, basis: string} $window
     * @return array{table: string, window: string, basis: string, days: int, eligible: int,
     *               oldest: string|null, deleteSql: string}
     */
    private function plan(Database $database, array $window): array
    {
        $predicate = sprintf('`%s` < UTC_TIMESTAMP() - INTERVAL ? DAY', $window['column']);

        $eligible = (int) $database->scalar(
            sprintf('SELECT COUNT(*) FROM `%s` WHERE %s', $window['table'], $predicate),
            [$window['days']],
        );

        $oldest = $database->scalar(
            sprintf('SELECT MIN(`%s`) FROM `%s`', $window['column'], $window['table']),
        );

        return [
            'table'     => $window['table'],
            'window'    => $window['window'],
            'basis'     => $window['basis'],
            'days'      => $window['days'],
            'eligible'  => $eligible,
            'oldest'    => is_string($oldest) ? $oldest : null,
            'deleteSql' => sprintf('DELETE FROM `%s` WHERE %s', $window['table'], $predicate),
        ];
    }

    /**
     * Delete in bounded chunks, each chunk in its own transaction.
     *
     * `LIMIT` without `ORDER BY` is deliberate. The set of eligible rows only
     * shrinks, so the loop always terminates, and leaving the order unspecified
     * lets the optimiser pick whichever index fits the predicate. `ORDER BY id`
     * on `audit_log` would force a filesort across the whole table in order to
     * remove a thousand rows.
     *
     * @param array{table: string, window: string, basis: string, days: int, eligible: int,
     *               oldest: string|null, deleteSql: string} $entry
     */
    private function deleteInBatches(Database $database, array $entry, int $batch): int
    {
        $total = 0;
        $chunks = 0;

        while (true) {
            $affected = $database->transaction(static function (Database $database) use ($entry, $batch): int {
                return $database->execute($entry['deleteSql'] . ' LIMIT ' . $batch, [$entry['days']]);
            });

            $total += $affected;

            if ($affected < $batch) {
                return $total;
            }

            // A predicate matching millions of rows would otherwise hold the
            // connection for as long as it took. Bailing out is safe: the next
            // scheduled run continues from where this one stopped.
            if (++$chunks > 1000) {
                $this->kernel->logger()->warning('Retention stopped early; the backlog is large.', [
                    'table'   => $entry['table'],
                    'deleted' => $total,
                    'batch'   => $batch,
                ]);

                return $total;
            }
        }
    }

    // -----------------------------------------------------------------------
    // The audit record
    // -----------------------------------------------------------------------

    /**
     * Write the `system.retention_purge` entry.
     *
     * Written after the deletes, outside their transactions, because a
     * seven-year `audit_log` purge and its own audit entry cannot share a
     * transaction without the undo log growing to match. The tradeoff is
     * accepted deliberately: a crash between the last `DELETE` and this
     * `INSERT` loses the *record* of a purge, not the purge itself, and the
     * next `--status` shows the table past due again — visible, and cheap to
     * repeat.
     *
     * @param array<string, array{table: string, column: string, days: int, window: string, basis: string}> $selected
     * @param array<string, array<string, mixed>>                                                    $plan
     * @param array<string, int>                                                                     $deleted
     * @param list<string>                                                                           $failures
     */
    private function recordAuditEntry(
        Database $database,
        string $runId,
        array $selected,
        array $plan,
        array $deleted,
        array $failures,
    ): void {
        $after = ['run_id' => $runId, 'failures' => $failures];

        foreach ($selected as $name => $window) {
            $after[$name] = [
                'table'    => $window['table'],
                'policy'   => $window['window'],
                'eligible' => $plan[$name]['eligible'],
                'deleted'  => $deleted[$name] ?? 0,
                'cutoff'   => gmdate('Y-m-d', time() - ($window['days'] * 86400)),
            ];
        }

        $database->insert('audit_log', [
            'actor_id'     => null,
            'actor_role'   => 'system',
            'action'       => self::AUDIT_ACTION,
            'entity_type'  => 'retention_policy',
            'entity_id'    => null,
            'before_state' => null,
            'after_state'  => json_encode($after, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR),
            'ip_address'   => null,
            'user_agent'   => 'bin/retention.php',
            'request_id'   => $runId,
        ]);
    }

    // -----------------------------------------------------------------------
    // Privileges
    // -----------------------------------------------------------------------

    /**
     * Does the connected user actually hold `DELETE` on `audit_log`?
     *
     * Read from `SHOW GRANTS FOR CURRENT_USER` rather than from the
     * configuration, because the configuration says what was intended and the
     * grants say what is true. The schema name is taken from `DB_DATABASE`, so
     * a renamed database is checked against its own grants instead of against a
     * hard-coded `utas_catms`.
     *
     * @return array{permitted: bool, user: string, schema: string, lines: list<string>}
     */
    private function grantsOnAuditLog(Database $database): array
    {
        $schema = $this->kernel->config()->require('DB_DATABASE');
        $current = (string) $database->scalar('SELECT CURRENT_USER()');

        $lines = [];
        $permitted = false;

        foreach ($database->select('SHOW GRANTS FOR CURRENT_USER') as $row) {
            $line = (string) (array_values($row)[0] ?? '');
            if ($line === '') {
                continue;
            }

            $lines[] = $line;
            $permitted = $permitted || $this->grantAllowsDelete($line, $schema);
        }

        return ['permitted' => $permitted, 'user' => $current, 'schema' => $schema, 'lines' => $lines];
    }

    /**
     * Does one `SHOW GRANTS` line grant DELETE on `audit_log`?
     *
     * The forms MySQL prints, and which of them count:
     *
     *   GRANT ALL PRIVILEGES ON `utas_catms`.* TO …              → yes
     *   GRANT ALL PRIVILEGES ON *.* TO …                         → yes
     *   GRANT ALL PRIVILEGES ON `utas_catms`.`audit_log` TO …   → yes
     *   GRANT SELECT, INSERT, DELETE ON `utas_catms`.`audit_log` … → yes
     *   GRANT ALL PRIVILEGES ON `utas_catms`.`allocations` …    → no, another table
     *   GRANT SELECT, INSERT ON `utas_catms`.`audit_log` …      → no, and that is VULN-14
     *
     * The check is about the audit log being reachable for deletion, not about
     * the grant being narrow, so a development superuser on `*.*` passes. The
     * narrowness is what §6.5 asks for; this is the guard against running the
     * purge as a user that cannot do it.
     */
    private function grantAllowsDelete(string $line, string $schema): bool
    {
        $normalised = strtolower($line);
        $quoted = '(?:`?' . preg_quote(strtolower($schema), '/') . '`?)';

        // A grant on the whole schema, or on everything, with ALL PRIVILEGES.
        $wide = $quoted . '\.`?\*`?|\*\.\*';
        if (
            preg_match('/\bon\s+(?:' . $wide . ')/', $normalised) === 1
            && str_contains($normalised, 'all privileges')
        ) {
            return true;
        }

        // A grant naming the table must also name DELETE, or ALL PRIVILEGES.
        // `SHOW GRANTS` always qualifies a table with its schema, so an
        // unqualified match is not attempted: that would let a grant on another
        // database's `audit_log` satisfy the check.
        if (preg_match('/\bon\s+' . $quoted . '\.`?audit_log`?(?:\s|$)/', $normalised) !== 1) {
            return false;
        }

        if (str_contains($normalised, 'all privileges')) {
            return true;
        }

        return preg_match('/(?:^|,\s*)delete(?:\s|,|$)/', $normalised) === 1;
    }

    /**
     * @param array{permitted: bool, user: string, schema: string, lines: list<string>} $grants
     */
    private function refuse(Output $output, array $grants, int $eligible, bool $asJson): int
    {
        if ($asJson) {
            $output->json([
                'status'    => 'insufficient_privilege',
                'user'      => $grants['user'],
                'eligible'  => $eligible,
                'grants'    => $grants['lines'],
            ]);

            return Kernel::FAILURE;
        }

        $output->title('Retention purge — refused');

        $output->failure(sprintf(
            'The connected database user %s does not hold DELETE on %s.audit_log.',
            $grants['user'],
            $grants['schema'],
        ));
        $output->line();
        $output->line('  This refusal is the control, not an obstacle. The application user must not');
        $output->line('  hold DELETE, or the audit trail is not immutable (docs/SECURITY.md §10.2).');
        $output->line();
        $output->line('  Run retention as the separate maintenance user instead:');
        $output->line(sprintf(
            '    DB_USERNAME=%s DB_PASSWORD=… php bin/retention.php',
            self::MAINTENANCE_USER,
        ));
        $output->line();
        $output->line('  Grants found for the current user:');
        foreach ($grants['lines'] as $line) {
            $output->line('    ' . $line);
        }

        $output->line();
        $output->line(sprintf('  %d row(s) remain past due.', $eligible));
        $output->line('  --force overrides the check. Only do that on a development database.');

        return Kernel::FAILURE;
    }

    private function refuseLocked(Output $output, bool $asJson): int
    {
        if ($asJson) {
            $output->json([
                'status'  => 'locked',
                'message' => 'Another retention sweep is already running. Nothing was changed.',
            ]);

            return Kernel::FAILURE;
        }

        $output->failure('Another retention sweep is already running. Nothing was changed.');
        $output->line();
        $output->line('  If you are sure that is not true, no sweep holds this lock: MySQL drops a');
        $output->line("  named lock when the connection that took it closes, including on a crash.");

        return Kernel::FAILURE;
    }

    private function releaseLock(Database $database, int $handle): void
    {
        try {
            $database->execute('SELECT RELEASE_LOCK(:handle)', ['handle' => $handle]);
        } catch (Throwable $exception) {
            $this->kernel->logger()->warning('Could not release the retention lock.', [
                'reason' => $exception->getMessage(),
            ]);
        }
    }

    // -----------------------------------------------------------------------
    // Reporting
    // -----------------------------------------------------------------------

    /**
     * @return array<string, array{table: string, column: string, days: int, window: string, basis: string}>|null
     *         Null when `--only` named a window that does not exist.
     */
    private function selectWindows(?string $only): ?array
    {
        if ($only === null || trim($only) === '') {
            return self::WINDOWS;
        }

        $selected = [];
        foreach (explode(',', $only) as $part) {
            $name = strtolower(trim($part));
            if ($name === '') {
                continue;
            }

            if (!isset(self::WINDOWS[$name])) {
                return null;
            }

            $selected[$name] = self::WINDOWS[$name];
        }

        return $selected === []
            ? null
            : $selected;
    }

    /** @param array<string, array<string, mixed>> $plan */
    private function reportStatus(Output $output, array $plan, bool $asJson): int
    {
        if ($asJson) {
            $output->json([
                'status'      => 'ok',
                'past_due'    => $this->pastDue($plan),
                'total'       => array_sum($this->pastDue($plan)),
                'windows'     => $this->windowSummary($plan),
                'never_purged' => self::NEVER_PURGED,
            ]);

            return Kernel::SUCCESS;
        }

        $output->title('Retention status');

        $rows = [];
        foreach ($plan as $name => $entry) {
            $rows[] = [
                $name,
                $entry['table'],
                $entry['window'],
                $entry['eligible'],
                $entry['oldest'] ?? '-',
            ];
        }

        $output->table(['window', 'table', 'policy', 'past due', 'oldest row'], $rows);
        $output->line();

        $total = array_sum($this->pastDue($plan));
        $output->definitions(['rows past due' => $total], 2);
        $output->line();

        if ($total === 0) {
            $output->success('Nothing is past due. Retention is keeping up.');
        } else {
            $output->line(sprintf('  %d row(s) are past due; `php bin/retention.php` purges them.', $total));
        }

        $output->line();
        $output->line('  Never purged, by policy (docs/DATA_MODEL.md §12):');
        foreach (self::NEVER_PURGED as $table => $reason) {
            $output->line(sprintf('    %-14s %s', $table, $reason));
        }

        $output->line();

        return Kernel::SUCCESS;
    }

    /**
     * @param array<string, array<string, mixed>> $plan
     * @return array<string, int>
     */
    private function pastDue(array $plan): array
    {
        $counts = [];
        foreach ($plan as $name => $entry) {
            $counts[$name] = (int) $entry['eligible'];
        }

        return $counts;
    }

    /**
     * @param array<string, array<string, mixed>> $plan
     * @return array<string, array{policy: string, basis: string, past_due: int}>
     */
    private function windowSummary(array $plan): array
    {
        $summary = [];
        foreach ($plan as $name => $entry) {
            $summary[$name] = [
                'policy'   => (string) $entry['window'],
                'basis'    => (string) $entry['basis'],
                'past_due' => (int) $entry['eligible'],
            ];
        }

        return $summary;
    }

    // -----------------------------------------------------------------------
    // Small helpers
    // -----------------------------------------------------------------------

    private function database(): Database
    {
        return $this->kernel->database();
    }

    /**
     * A UUID v4 in canonical form, for `audit_log.request_id` and for tying this
     * run to the log line it produced.
     *
     * Written out rather than pulled in for one call: the alternative is a
     * dependency, and this is five lines.
     */
    private function uuid4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
