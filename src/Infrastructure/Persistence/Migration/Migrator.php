<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Migration;

use App\Core\Database;
use App\Core\Logger;
use RuntimeException;
use Throwable;

/**
 * Applies the migrations in `db/migrations/`, in filename order.
 *
 * THE LEDGER
 * Its own `schema_migrations` table, created on demand, holding one row per
 * applied file: version, batch and when. A batch is one `migrate` invocation, so
 * `rollback --steps=1` can reverse exactly what the last deploy did rather than
 * guessing from timestamps.
 *
 * THE LOCK
 * `GET_LOCK('catms_migrations', 30)`. A rolling deploy starts two instances at
 * once and both run `bin/migrate.php`; without the advisory lock one of them
 * applies half the files and the other applies the same half again, and the
 * symptom is a duplicate-key error on a table that already exists (DEPLOYMENT.md
 * §6.3). The lock is released explicitly in a `finally` rather than relied on
 * at connection close, because a released-on-close lock is a lock that dies with
 * the process and the deployment script has no way to tell.
 *
 * ORDER
 * Filename order, and the name *is* the ordering: `YYYY_MM_DD_HHMMSS_name.php`.
 * A migration added with an old date is applied after a newer one that depends
 * on it, and the failure is a confusing "unknown column" rather than a clear
 * "out of order". `status()` therefore reports a gap: an applied version
 * followed by a pending version that sorts before it.
 */
final class Migrator
{
    private const LOCK_NAME = 'catms_migrations';

    private const LOCK_TIMEOUT_SECONDS = 30;

    public function __construct(
        private readonly Database $database,
        private readonly Logger $logger,
        private readonly string $migrationsPath,
    ) {
    }

    /**
     * Apply every migration that has not run, in order.
     *
     * @param (callable(string, string): void)|null $progress Called with the version and a status word.
     *
     * @return list<string> The versions applied by this call, in order.
     */
    public function migrate(?callable $progress = null): array
    {
        return $this->locked(function () use ($progress): array {
            $this->ensureLedger();

            $applied = $this->appliedVersions();
            $batch = $this->nextBatch();

            $done = [];
            foreach ($this->discover() as $name) {
                if (in_array($name, $applied, true)) {
                    continue;
                }

                $migration = $this->load($name);

                if ($progress !== null) {
                    $progress($name, 'applying');
                }

                $this->runOne($migration, 'up', $batch);

                $done[] = $name;

                $this->logger->info('Migration applied.', ['version' => $name, 'batch' => $batch]);
            }

            if ($done === [] && $progress !== null) {
                $progress('', 'nothing to do');
            }

            return $done;
        });
    }

    /**
     * Reverse the most recent $steps batches, newest first.
     *
     * Batches, not files, because a deploy applies several files as one unit: an
     * incident that reverses only the last file leaves the schema at a state
     * nobody wrote. `steps = 0` means "all of them", which is what a destroyed
     * scratch instance wants and what production never wants.
     *
     * @return list<string> The versions reversed, newest first.
     */
    public function rollback(int $steps = 1, ?callable $progress = null): array
    {
        return $this->locked(function () use ($steps, $progress): array {
            $this->ensureLedger();

            $batches = $steps > 0 ? $steps : PHP_INT_MAX;

            $rows = $this->database->select(
                'SELECT version, batch FROM `schema_migrations` ORDER BY batch DESC, version DESC'
            );

            $targets = [];
            foreach ($rows as $row) {
                $batch = (int) $row['batch'];
                if (!isset($targets[$batch])) {
                    if (\count($targets) >= $batches) {
                        break;
                    }
                    $targets[$batch] = [];
                }
                $targets[$batch][] = (string) $row['version'];
            }

            $reversed = [];
            foreach ($targets as $batch => $versions) {
                foreach ($versions as $version) {
                    $migration = $this->load($version);

                    if ($progress !== null) {
                        $progress($version, 'reversing');
                    }

                    $this->runOne($migration, 'down', (int) $batch);

                    $reversed[] = $version;

                    $this->logger->warning('Migration reversed.', [
                        'version' => $version,
                        'batch'   => $batch,
                    ]);
                }
            }

            if ($reversed === [] && $progress !== null) {
                $progress('', 'nothing to reverse');
            }

            return $reversed;
        });
    }

    /**
     * What has run, what has not, and whether the order is coherent.
     *
     * @return array{
     *     applied: list<array{version: string, batch: int, applied_at: string}>,
     *     pending: list<string>,
     *     out_of_order: list<string>
     * }
     */
    public function status(): array
    {
        $this->ensureLedger();

        $rows = $this->database->select(
            'SELECT version, batch, applied_at FROM `schema_migrations` ORDER BY version'
        );

        $applied = [];
        $names = [];
        foreach ($rows as $row) {
            $version = (string) $row['version'];
            $applied[] = [
                'version'    => $version,
                'batch'      => (int) $row['batch'],
                'applied_at' => (string) $row['applied_at'],
            ];
            $names[] = $version;
        }

        $pending = [];
        foreach ($this->discover() as $name) {
            if (!in_array($name, $names, true)) {
                $pending[] = $name;
            }
        }

        // A pending version that sorts *before* an applied one was added with a
        // date in the past. Applying it in filename order would put it after
        // something that already depends on it, so it is called out explicitly
        // rather than discovered as a "unknown column" mid-deploy.
        $outOfOrder = [];
        $latestApplied = '';
        foreach ($applied as $row) {
            $latestApplied = $row['version'];
        }

        foreach ($pending as $candidate) {
            if ($latestApplied !== '' && strcmp($candidate, $latestApplied) < 0) {
                $outOfOrder[] = $candidate;
            }
        }

        return [
            'applied'      => $applied,
            'pending'      => $pending,
            'out_of_order' => array_values(array_unique($outOfOrder)),
        ];
    }

    /**
     * Run arbitrary SQL, splitting on the statement delimiter.
     *
     * `db/schema.sql` is a script, not a statement, and PDO's `exec()` sends
     * everything to the server as one string — which MySQL's client protocol
     * rejects the moment it sees a second statement. Splitting is safe here only
     * because the file is machine-written and contains no delimiter inside a
     * string literal; `MakeMigrationCommand`'s template keeps that true.
     *
     * @return int statements executed
     */
    public function execScript(string $sql): int
    {
        $pdo = $this->database->pdo();
        $statements = self::split($sql);

        foreach ($statements as $statement) {
            try {
                $pdo->exec($statement);
            } catch (Throwable $exception) {
                throw new RuntimeException(
                    sprintf(
                        "Migration statement failed:\n  %s\n\n  %s",
                        self::preview($statement),
                        $exception->getMessage(),
                    ),
                    0,
                    $exception,
                );
            }
        }

        return \count($statements);
    }

    /**
     * @return list<string>
     */
    public function discover(): array
    {
        if (!is_dir($this->migrationsPath)) {
            return [];
        }

        $found = glob($this->migrationsPath . '/*.php');
        if ($found === false) {
            return [];
        }

        $names = [];
        foreach ($found as $file) {
            $names[] = pathinfo($file, PATHINFO_FILENAME);
        }

        // strcmp, not natsort: `2026_08_01_100000` must sort after
        // `2026_08_01_090000`, and a locale-aware comparison would decide that
        // differently on the developer's laptop than on the deploy host.
        usort($names, 'strcmp');

        return $names;
    }

    public function migrationExists(string $name): bool
    {
        return in_array($name, $this->discover(), true);
    }

    /**
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    private function locked(callable $work): mixed
    {
        $pdo = $this->database->pdo();

        $acquired = $pdo->query(
            sprintf('SELECT GET_LOCK(%s, %d)', $pdo->quote(self::LOCK_NAME), self::LOCK_TIMEOUT_SECONDS)
        )->fetchColumn();

        if ((int) $acquired !== 1) {
            throw new RuntimeException(sprintf(
                'Another process is holding the %s lock (waited %d s). If nothing is running, '
                . 'a previous deploy was killed mid-migration — check `migrate --status` before retrying.',
                self::LOCK_NAME,
                self::LOCK_TIMEOUT_SECONDS,
            ));
        }

        try {
            return $work();
        } finally {
            $pdo->query(sprintf('SELECT RELEASE_LOCK(%s)', $pdo->quote(self::LOCK_NAME)));
        }
    }

    private function runOne(Migration $migration, string $direction, int $batch): void
    {
        $name = $migration->name();

        $this->database->transaction(function (Database $database) use ($migration, $direction, $batch, $name): void {
            if ($direction === 'up') {
                $migration->up($database);
                $database->insert('schema_migrations', [
                    'version'    => $name,
                    'batch'      => $batch,
                    'applied_at' => gmdate('Y-m-d H:i:s'),
                ]);

                return;
            }

            $migration->down($database);
            $database->delete('schema_migrations', ['version' => $name]);
        });
    }

    private function ensureLedger(): void
    {
        // `CREATE TABLE IF NOT EXISTS` so this is safe on a database that has
        // never been migrated and on one that has. Deliberately not part of a
        // migration file: the ledger has to exist before the ledger can be used.
        $this->database->pdo()->exec(
            'CREATE TABLE IF NOT EXISTS `schema_migrations` (
                `version`    VARCHAR(191) NOT NULL,
                `batch`      INT UNSIGNED NOT NULL,
                `applied_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`version`),
                KEY `ix_schema_migrations_batch` (`batch`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci'
        );
    }

    private function nextBatch(): int
    {
        $highest = $this->database->scalar('SELECT MAX(batch) FROM `schema_migrations`');

        return ((int) $highest) + 1;
    }

    /**
     * @return list<string>
     */
    private function appliedVersions(): array
    {
        $rows = $this->database->select('SELECT version FROM `schema_migrations`');

        return array_map(static fn (array $row): string => (string) $row['version'], $rows);
    }

    /**
     * @throws RuntimeException when the file is missing, unreadable or does not
     *                          return a Migration.
     */
    private function load(string $name): Migration
    {
        $file = $this->migrationsPath . '/' . $name . '.php';

        if (!is_file($file)) {
            throw new RuntimeException(sprintf(
                'Migration %s is recorded as applied but its file is missing from %s. '
                . 'Restore the file; do not delete the ledger row.',
                $name,
                $this->migrationsPath,
            ));
        }

        $migration = require $file;

        if (!$migration instanceof Migration) {
            throw new RuntimeException(sprintf(
                'Migration %s must return an instance of %s.',
                $name,
                Migration::class,
            ));
        }

        if ($migration->name() !== $name) {
            throw new RuntimeException(sprintf(
                'Migration %s reports its name as %s. The filename and name() must agree, '
                . 'or the ledger will record a version the migrator can never find again.',
                $name,
                $migration->name(),
            ));
        }

        return $migration;
    }

    /**
     * Split a SQL script into statements.
     *
     * Handles the three things the shipped scripts actually contain: `;`
     * terminators, `--` and `#` line comments, and `/* … *\/` block comments.
     * Quoted strings and identifiers are tracked so a semicolon inside one is
     * not treated as a terminator.
     *
     * @return list<string>
     */
    public static function split(string $sql): array
    {
        $statements = [];
        $current = '';
        $length = \strlen($sql);

        $quote = null;
        $inLineComment = false;
        $inBlockComment = false;

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $sql[$i + 1] ?? '';

            if ($inLineComment) {
                if ($char === "\n") {
                    $inLineComment = false;
                    $current .= $char;
                }
                continue;
            }

            if ($inBlockComment) {
                if ($char === '*' && $next === '/') {
                    $inBlockComment = false;
                    $i++;
                }
                continue;
            }

            if ($quote === null) {
                if ($char === '-' && $next === '-') {
                    $inLineComment = true;
                    $i++;
                    continue;
                }

                if ($char === '#') {
                    $inLineComment = true;
                    continue;
                }

                if ($char === '/' && $next === '*') {
                    $inBlockComment = true;
                    $i++;
                    continue;
                }

                if ($char === "'" || $char === '"' || $char === '`') {
                    $quote = $char;
                    $current .= $char;
                    continue;
                }

                if ($char === ';') {
                    $trimmed = trim($current);
                    if ($trimmed !== '') {
                        $statements[] = $trimmed;
                    }
                    $current = '';
                    continue;
                }

                $current .= $char;
                continue;
            }

            // Inside a quoted run. A doubled quote is an escaped quote, not a
            // terminator, which is what `'It''s here; really'` needs.
            $current .= $char;

            if ($char === '\\' && $quote !== '`') {
                $i++;
                $current .= $sql[$i] ?? '';
                continue;
            }

            if ($char === $quote) {
                if ($next === $quote) {
                    $current .= $next;
                    $i++;
                    continue;
                }
                $quote = null;
            }
        }

        $trimmed = trim($current);
        if ($trimmed !== '') {
            $statements[] = $trimmed;
        }

        return $statements;
    }

    private static function preview(string $statement): string
    {
        $oneLine = preg_replace('/\s+/', ' ', $statement) ?? $statement;

        return mb_strlen($oneLine) > 160 ? mb_substr($oneLine, 0, 157) . '…' : $oneLine;
    }
}
