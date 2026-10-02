<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;
use Throwable;

/**
 * The one place a PDO connection exists.
 *
 * NOT IN THE DOMAIN LAYER
 * `src/Domain` receives plain value objects and never sees a PDO (ADR-001). That
 * is the rule that lets the allocation engine run inside a unit test, inside a
 * `bin/` script, and inside an HTTP request with identical behaviour.
 *
 * WHAT THIS CLASS IS RESPONSIBLE FOR
 *  - Strict mode. `PDO::ATTR_ERRMODE = EXCEPTION`, so a failed query throws
 *    instead of returning false and being silently treated as "no rows". A
 *    timetable that quietly omits a room is worse than an error page.
 *  - Real prepares. `ATTR_EMULATE_PREPARES = false`, so a bound parameter is
 *    never interpolated into SQL text by the driver. This is the difference
 *    between "we use prepared statements" and actually using them (NFR-SEC-04).
 *  - Retry on deadlock. MySQL aborts one of two writers on a lock cycle; with
 *    three allocation writers per department that is a normal event, not an
 *    error, and the correct response is to try again.
 *  - utf8mb4. Set on the connection so a Ghanaian name in a course title does
 *    not become three question marks.
 */
final class Database
{
    private ?PDO $pdo = null;

    private int $transactionDepth = 0;

    public function __construct(
        private readonly Config $config,
        private readonly Logger $logger,
        private readonly int $maxAttempts = 3,
    ) {
    }

    public function pdo(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $this->config->require('DB_HOST'),
            $this->config->int('DB_PORT', 3306),
            $this->config->require('DB_DATABASE'),
        );

        try {
            $this->pdo = new PDO(
                $dsn,
                $this->config->require('DB_USERNAME'),
                (string) $this->config->get('DB_PASSWORD', ''),
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                    PDO::ATTR_STRINGIFY_FETCHES  => false,
                ]
            );
        } catch (PDOException $exception) {
            throw new RuntimeException(
                'Cannot connect to the database. Check DB_HOST, DB_DATABASE and DB_*.',
                0,
                $exception,
            );
        }

        // Belt and braces: the DSN charset is advisory, this is authoritative.
        $this->pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION'");
        $this->pdo->exec("SET time_zone = '+00:00'");

        return $this->pdo;
    }

    /**
     * A read-only connection to a replica, when one is configured.
     *
     * Reports point here so a heavy export cannot consume the connection budget
     * the timetable endpoint needs at 08:00 (docs/DEPLOYMENT.md §9).
     */
    public function replica(): ?PDO
    {
        $host = $this->config->get('DB_REPLICA_HOST');
        if ($host === null || $host === '') {
            return null;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $host,
            $this->config->int('DB_PORT', 3306),
            $this->config->require('DB_DATABASE'),
        );

        try {
            return new PDO(
                $dsn,
                $this->config->require('DB_USERNAME'),
                (string) $this->config->get('DB_PASSWORD', ''),
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ],
            );
        } catch (PDOException $exception) {
            // A replica that is down must degrade a report, not fail a request.
            $this->logger->warning('Replica unavailable; falling back to the primary.', [
                'host'   => $host,
                'reason' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param array<array-key, mixed> $bindings Named (string keys) or positional (a list), never both
     *
     * @return list<array<string, mixed>>
     */
    public function select(string $sql, array $bindings = []): array
    {
        $statement = $this->run($sql, $bindings);
        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll();

        return $rows;
    }

    /**
     * @param array<array-key, mixed> $bindings Named (string keys) or positional (a list), never both
     *
     * @return array<string, mixed>|null Null when the query matched nothing.
     */
    public function selectOne(string $sql, array $bindings = []): ?array
    {
        $rows = $this->select($sql, $bindings);

        return $rows[0] ?? null;
    }

    /**
     * @param array<array-key, mixed> $bindings Named (string keys) or positional (a list), never both
     */
    public function scalar(string $sql, array $bindings = []): mixed
    {
        $statement = $this->run($sql, $bindings);
        $value = $statement->fetchColumn();

        return $value === false ? null : $value;
    }

    /**
     * @param array<array-key, mixed> $bindings Named (string keys) or positional (a list), never both
     */
    public function execute(string $sql, array $bindings = []): int
    {
        return $this->run($sql, $bindings)->rowCount();
    }

    /**
     * Insert and return the new primary key.
     *
     * @param array<string, mixed> $data
     */
    public function insert(string $table, array $data): int
    {
        $columns = array_keys($data);
        $quoted = array_map(
            static fn (string $column): string => '`' . str_replace('`', '', $column) . '`',
            $columns
        );

        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            str_replace('`', '', $table),
            implode(', ', $quoted),
            implode(', ', array_map(static fn (string $column): string => ':' . $column, $columns))
        );

        $this->run($sql, $data);

        return (int) $this->pdo()->lastInsertId();
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $where
     */
    public function update(string $table, array $data, array $where): int
    {
        $assignments = [];
        $bindings = [];
        foreach ($data as $column => $value) {
            $assignments[] = '`' . str_replace('`', '', $column) . '` = :set_' . $column;
            $bindings['set_' . $column] = $value;
        }

        $conditions = [];
        foreach ($where as $column => $value) {
            $conditions[] = '`' . str_replace('`', '', $column) . '` = :where_' . $column;
            $bindings['where_' . $column] = $value;
        }

        if ($assignments === [] || $conditions === []) {
            throw new RuntimeException('update() needs at least one column and one condition.');
        }

        $sql = sprintf(
            'UPDATE `%s` SET %s WHERE %s',
            str_replace('`', '', $table),
            implode(', ', $assignments),
            implode(' AND ', $conditions)
        );

        return $this->execute($sql, $bindings);
    }

    /**
     * @param array<string, mixed> $where
     */
    public function delete(string $table, array $where): int
    {
        $conditions = [];
        foreach ($where as $column => $value) {
            $conditions[] = '`' . str_replace('`', '', $column) . '` = :' . $column;
        }

        if ($conditions === []) {
            // A delete with no WHERE clause is never what a caller means.
            throw new RuntimeException('Refusing to delete from ' . $table . ' without a condition.');
        }

        return $this->execute(
            sprintf('DELETE FROM `%s` WHERE %s', str_replace('`', '', $table), implode(' AND ', $conditions)),
            $where
        );
    }

    /**
     * Run $work inside a transaction, retrying on deadlock.
     *
     * Nested calls join the outer transaction rather than opening a second one,
     * so a service method that is transactional can call another that is also
     * transactional without MySQL silently committing half the work (PDO has no
     * nested transactions and emulating them is a well-known way to lose data).
     *
     * @template T
     *
     * @param callable(self): T $work
     *
     * @return T
     */
    public function transaction(callable $work): mixed
    {
        if ($this->transactionDepth > 0) {
            $this->transactionDepth++;
            try {
                return $work($this);
            } finally {
                $this->transactionDepth--;
            }
        }

        $attempt = 0;
        while (true) {
            $attempt++;
            $pdo = $this->pdo();
            $pdo->beginTransaction();
            $this->transactionDepth = 1;

            try {
                $result = $work($this);
                // DDL such as CREATE TABLE ends the transaction itself. The work
                // is already durable, and a second commit is an error.
                if ($pdo->inTransaction()) {
                    $pdo->commit();
                }
                $this->transactionDepth = 0;

                return $result;
            } catch (Throwable $exception) {
                $this->transactionDepth = 0;
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                if ($attempt < $this->maxAttempts && $this->isRetryable($exception)) {
                    $this->logger->warning('Transaction rolled back; retrying.', [
                        'attempt' => $attempt,
                        'reason'  => $exception->getMessage(),
                    ]);
                    // Linear backoff. Deadlock victims are already queued behind
                    // the winner; retrying immediately just re-enters the cycle.
                    usleep($attempt * 50000);

                    continue;
                }

                throw $exception;
            }
        }
    }

    public function inTransaction(): bool
    {
        return $this->transactionDepth > 0;
    }

    public function isHealthy(): bool
    {
        try {
            return (int) $this->pdo()->query('SELECT 1')->fetchColumn() === 1;
        } catch (Throwable $exception) {
            $this->logger->error('Database health check failed.', ['reason' => $exception->getMessage()]);

            return false;
        }
    }

    /**
     * @param array<array-key, mixed> $bindings Named (string keys) or positional (a list), never both
     */
    private function run(string $sql, array $bindings): PDOStatement
    {
        $statement = $this->pdo()->prepare($sql);

        foreach ($bindings as $name => $value) {
            $parameter = is_int($name) ? $name + 1 : ':' . ltrim((string) $name, ':');
            $type = match (true) {
                is_int($value)  => PDO::PARAM_INT,
                is_bool($value) => PDO::PARAM_BOOL,
                $value === null => PDO::PARAM_NULL,
                default         => PDO::PARAM_STR,
            };
            $statement->bindValue($parameter, $value, $type);
        }

        $statement->execute();

        return $statement;
    }

    private function isRetryable(Throwable $exception): bool
    {
        if (!$exception instanceof PDOException) {
            return false;
        }

        // 1213 deadlock found, 1205 lock wait timeout. Both mean "you lost a
        // race", not "your statement was wrong".
        $code = $exception->errorInfo[1] ?? null;

        return $code === 1213 || $code === 1205;
    }
}
