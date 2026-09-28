<?php

declare(strict_types=1);

/**
 * Make the audit trail tamper-resistant for the application account (VULN-14).
 *
 * `audit_log` is append-only by convention in `docs/SECURITY.md` §10.2. A
 * convention is not a control: the application account currently holds whatever
 * the MySQL image's `GRANT ALL ON utas_catms.*` gave it, which includes
 * `UPDATE` and `DELETE`. This migration removes them, so the only way to change
 * a historical audit row is to have filesystem or `GRANT` access to the server.
 *
 * `bin/retention.php` is the documented exception and runs under a *different*
 * user (`catms_maint`) that does hold `DELETE`. That is what makes retention
 * possible without the web tier being able to rewrite history.
 *
 * WHY THIS IS A MIGRATION AND NOT PART OF SCHEMA.SQL
 * `schema.sql` is applied by the same `catms_app` user this migration grants to,
 * so a `GRANT` in it would be a grant issued by the account it constrains —
 * circular, and a `GRANT` inside a `CREATE DATABASE` script is not portable.
 * It belongs after the user exists.
 *
 * WHY IT IS SAFE TO RUN TWICE
 * `GRANT` and `REVOKE` are both idempotent, which is what the migration
 * contract requires of `up()`.
 *
 * IF IT FAILS
 * A missing `GRANT OPTION` produces error 1227. That is expected when the
 * migration user is not the account being granted, and the fix is to run the
 * statement as root rather than to delete the migration — the grant is the
 * control, not the convention.
 */

use App\Core\Database;
use App\Infrastructure\Persistence\Migration\Migration;

return new class implements Migration {
    /** SELECT and INSERT only. UPDATE and DELETE are deliberately absent. */
    private const PRIVILEGES = ['SELECT', 'INSERT'];

    public function name(): string
    {
        return '2026_08_01_091500_audit_log_grants';
    }

    public function up(Database $database): void
    {
        $pdo = $database->pdo();

        $pdo->exec(sprintf(
            'GRANT %s ON `%s`.`audit_log` TO %s',
            implode(', ', self::PRIVILEGES),
            $this->schema(),
            $this->account(),
        ));
    }

    public function down(Database $database): void
    {
        $pdo = $database->pdo();

        // REVOKE the specific privileges, never `REVOKE ALL`. Revoking all
        // would strip the SELECT the admin UI needs to read the audit trail,
        // which is a functional regression in exchange for a rollback nobody
        // asked for.
        foreach (self::PRIVILEGES as $privilege) {
            $pdo->exec(sprintf(
                'REVOKE %s ON `%s`.`audit_log` FROM %s',
                $privilege,
                $this->schema(),
                $this->account(),
            ));
        }
    }

    /**
     * Read from the environment rather than hard-coded, because the schema and
     * account are per-deployment (`docker-compose.yml` takes them from `.env`).
     * A GRANT naming the wrong schema either fails or, worse, succeeds against
     * a leftover development schema.
     */
    private function schema(): string
    {
        return (string) (getenv('DB_DATABASE') ?: 'utas_catms');
    }

    /**
     * The account's host part is part of its identity, so an e-mail-shaped
     * `DB_USERNAME` is used as-is rather than mangled into a different account.
     */
    private function account(): string
    {
        $user = (string) (getenv('DB_USERNAME') ?: 'catms_app');

        return str_contains($user, '@') ? $user : "'" . $user . "'@'%'";
    }
};
