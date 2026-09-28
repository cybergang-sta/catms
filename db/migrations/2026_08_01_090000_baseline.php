<?php

declare(strict_types=1);

/**
 * The baseline schema.
 *
 * Applies `db/schema.sql` as written, rather than restating 29 `CREATE TABLE`
 * statements here. The file is the reviewed, consolidated artefact
 * (`docs/DATA_MODEL.md` §1) and two copies of a schema is one copy too many;
 * the migration exists to place it under the migrator's ledger and locking.
 *
 * `db/schema.sql` opens with `CREATE DATABASE IF NOT EXISTS utas_catms; USE
 * utas_catms;`, so it does not rely on the connection's own default database.
 * The `USE` is what makes the rest of the file land in the right schema even
 * when `DB_DATABASE` on the connection says something else.
 */

use App\Core\Database;
use App\Infrastructure\Persistence\Migration\Migration;
use App\Infrastructure\Persistence\Migration\Migrator;
use PDOException;
use RuntimeException;

return new class implements Migration {
    /**
     * Views are dropped before tables, and in the reverse of the order
     * `schema.sql` creates them, because a view is a compiled query: dropping
     * the table underneath one first makes the view's definition unresolvable
     * and MySQL refuses the drop.
     *
     * Named explicitly rather than read from `information_schema`, on purpose.
     * A metadata-driven drop would happily remove `schema_migrations` — the
     * table that is recording this very rollback — partway through and leave a
     * half-reversed database with no ledger to finish the job. The explicit list
     * is a duplicate of the schema, and a duplicate that cannot destroy itself
     * is a good trade.
     *
     * @var list<string>
     */
    private const VIEWS = [
        'v_lecturer_load',
        'v_peak_usage',
        'v_room_utilisation',
    ];

    /**
     * Children before parents, so no foreign key is in the way.
     *
     * @var list<string>
     */
    private const TABLES = [
        'room_utilisation_daily',
        'notification_outbox',
        'notifications',
        'allocation_conflicts',
        'allocation_runs',
        'allocations',
        'lecturer_availability',
        'room_unavailability',
        'enrollments',
        'lecturer_course_assignments',
        'cohorts',
        'course_feature_requirements',
        'room_feature_map',
        'rooms',
        'room_features',
        'calendar_exceptions',
        'time_slots',
        'semesters',
        'password_reset_tokens',
        'refresh_tokens',
        'security_events',
        'rate_limit_buckets',
        'audit_log',
        'users',
        'role_permissions',
        'permissions',
        'roles',
        'departments',
    ];

    public function name(): string
    {
        return '2026_08_01_090000_baseline';
    }

    public function up(Database $database): void
    {
        $file = $this->schemaPath();

        if (!is_file($file)) {
            throw new RuntimeException(
                'db/schema.sql is missing. It is required by the baseline migration; '
                . 'restore it rather than writing a second copy of the schema here.'
            );
        }

        $sql = file_get_contents($file);
        if ($sql === false) {
            throw new RuntimeException('db/schema.sql exists but could not be read.');
        }

        // `Migrator::split()` rather than a single `exec()`: the file is a
        // 700-line script and PDO will not send two statements in one call.
        $pdo = $database->pdo();
        foreach (Migrator::split($sql) as $index => $statement) {
            try {
                $pdo->exec($statement);
            } catch (PDOException $exception) {
                throw new RuntimeException(
                    sprintf(
                        "db/schema.sql statement %d failed: %s\n\n  %s",
                        $index + 1,
                        $exception->getMessage(),
                        mb_substr(preg_replace('/\s+/', ' ', $statement) ?? $statement, 0, 160),
                    ),
                    0,
                    $exception,
                );
            }
        }
    }

    public function down(Database $database): void
    {
        $pdo = $database->pdo();

        // Everything at once: dropping a table in MySQL does not take a
        // `RESTRICT` on the rows, only on the object, and 32 separate DDL
        // statements would each rewrite the data dictionary for no benefit.
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

        try {
            foreach (self::VIEWS as $view) {
                $pdo->exec(sprintf('DROP VIEW IF EXISTS `%s`', $view));
            }

            foreach (self::TABLES as $table) {
                $pdo->exec(sprintf('DROP TABLE IF EXISTS `%s`', $table));
            }
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    private function schemaPath(): string
    {
        return \dirname(__DIR__, 2) . '/db/schema.sql';
    }
};
