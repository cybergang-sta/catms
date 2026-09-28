<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Migration;

use App\Core\Database;

/**
 * One schema change.
 *
 * WHY AN INTERFACE AND NOT A SQL FILE
 * A `.sql` file cannot be reviewed for a `down` path that is symmetric with its
 * `up`, cannot compute a value, and cannot refuse to run twice. A PHP file can
 * do all three, and `docs/DEPLOYMENT.md` §8.3 depends on the `down` path
 * existing in the same release as the `up` (ground rule 8).
 *
 * THE CONTRACT
 *  - `name()` is the sort key, taken from the filename with `.php` stripped:
 *    `2026_08_01_090000_baseline`. It is recorded verbatim in
 *    `schema_migrations.version`, so renaming a file after it has been applied
 *    makes the migrator believe it has not run. Never rename one.
 *  - `up()` must be safe to run against a database that already has some of the
 *    change (`docs/DEPLOYMENT.md` §6.2 expand/migrate/contract). In practice:
 *    `CREATE TABLE IF NOT EXISTS`, `ADD COLUMN IF NOT EXISTS`.
 *  - `down()` must reverse `up()` and must not depend on tables that `up()` did
 *    not create. It exists for the incident case; production is forward-only
 *    (NFR-MAINT-05).
 *
 * A NOTE ON TRANSACTIONS
 * `Migrator` wraps each `up()` in a transaction so that the ledger row and the
 * work move together. MySQL commits implicitly on DDL, so the transaction
 * protects the *record*, not the schema: a `CREATE TABLE` that succeeds and a
 * ledger row that then fails leaves a table nobody recorded, and the next run
 * will try again and hit "table already exists". That is why `up()` is required
 * to be idempotent, and why `--status` is the first thing to read after a
 * crashed deploy.
 */
interface Migration
{
    /**
     * The version string, e.g. `2026_08_01_090000_baseline`.
     */
    public function name(): string;

    /**
     * Apply the change. Must be idempotent.
     */
    public function up(Database $database): void;

    /**
     * Reverse the change. Must be idempotent and must not touch tables it did
     * not create.
     */
    public function down(Database $database): void;
}
