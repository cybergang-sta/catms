<?php

declare(strict_types=1);

namespace App\Console\Command;

use App\Console\Input;
use App\Console\Kernel;
use App\Console\Output;
use App\Core\Database;
use App\Core\Rbac;
use App\Infrastructure\Persistence\Migration\Migrator;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * `bin/console seed` — roles, permissions and reference data.
 *
 * WHY THE SEED IS A COMMAND AND NOT JUST db/seed.sql
 * Two of the three things it loads cannot live in a `.sql` file:
 *  - Password hashes. A bcrypt hash is salted, so a file can only contain a
 *    hash generated when the file was written. `password_hash()` has to run at
 *    seed time, which means PHP, which means a command. Writing a hash into
 *    `db/seed.sql` would also put a working credential in version control.
 *  - The RBAC matrix. Roles are data seeded from `config/rbac.php` (ADR-007), so
 *    the published matrix in `docs/SECURITY.md` §3 and the enforced matrix are
 *    the same file. Keeping a second copy in SQL is how they drift.
 * The institutional reference data — departments, the weekly slot grid, rooms,
 * courses, cohorts, the calendar — *is* plain data, so it stays in
 * `db/seed.sql` and the command executes it.
 *
 * ORDER OF OPERATIONS
 * RBAC, then the demo users, then `db/seed.sql`. The last two are in that order
 * because the reference script assigns teaching to the demo lecturer through a
 * subquery on an e-mail address, and a subquery needs its row to already exist.
 * See the comment at the call site.
 *
 * WHY IT REFUSES TO RUN IN PRODUCTION (VULN-06)
 * `--i-know-what-i-am-doing` has to be typed. The flag name is deliberately
 * awkward: it is a speed bump, not a permission, and a speed bump only works if
 * stopping is easier than continuing. `docs/DEPLOYMENT.md` §1 makes the same
 * promise about production never carrying the demo accounts, and this is the
 * mechanism that keeps it.
 *
 * DEMO PASSWORDS DEPEND ON THE ENVIRONMENT
 * `local` gets the passwords printed in the README, because a developer who
 * cannot log in on the first try will not log in at all. Everywhere else gets a
 * random 16-character password, shown once and never stored in plaintext, with
 * `status='pending'` and `must_change_password=1` — so even a leaked password
 * cannot be used without a second factor the account does not have.
 */
final class SeedCommand extends Command
{
    /** The environments a seed is allowed to touch without the flag. */
    private const SAFE_ENVIRONMENTS = ['local', 'staging'];

    /** The accounts README §7.4 publishes, and only in `local`. */
    private const DEMO_ACCOUNTS = [
        [
            'email'         => 'admin@utas.edu.gh',
            'password'      => 'Admin@1234',
            'role'          => 'admin',
            'first_name'    => 'Ada',
            'last_name'     => 'Mensah',
            'staff_id'      => 'UTAS/ADM/0001',
            // The demo administrator belongs to Computer Science, the department
            // that owns the seeded timetable. A null department is a system
            // administrator, and allocation and new semesters both refuse that
            // account until a department is supplied.
            'department'    => 'CS',
        ],
        [
            'email'         => 'lecturer@utas.edu.gh',
            'password'      => 'Lecturer@1234',
            'role'          => 'lecturer',
            'first_name'    => 'Kwabena',
            'last_name'     => 'Owusu',
            'staff_id'      => 'UTAS/LEC/0001',
            'department'    => 'CS',
        ],
        [
            'email'         => 'student@utas.edu.gh',
            'password'      => 'Student@1234',
            'role'          => 'student',
            'first_name'    => 'Ama',
            'last_name'     => 'Serwaa',
            'student_index' => '20210412166',
            'department'    => 'CS',
        ],
    ];

    /** Extra local directory so the catalogue is not three accounts and one lecturer. */
    private const CAMPUS_ACCOUNTS = [
        [
            'email' => 'akosua.boateng@utas.edu.gh', 'password' => 'Lecturer@1234', 'role' => 'lecturer',
            'first_name' => 'Akosua', 'last_name' => 'Boateng', 'staff_id' => 'UTAS/LEC/0002', 'department' => 'CS',
        ],
        [
            'email' => 'yaw.asante@utas.edu.gh', 'password' => 'Lecturer@1234', 'role' => 'lecturer',
            'first_name' => 'Yaw', 'last_name' => 'Asante', 'staff_id' => 'UTAS/LEC/0003', 'department' => 'CS',
        ],
        [
            'email' => 'yaw.darko@utas.edu.gh', 'password' => 'Admin@1234', 'role' => 'admin',
            'first_name' => 'Yaw', 'last_name' => 'Darko', 'staff_id' => 'UTAS/ADM/0002', 'department' => 'IT',
        ],
        [
            'email' => 'efua.mensah@utas.edu.gh', 'password' => 'Lecturer@1234', 'role' => 'lecturer',
            'first_name' => 'Efua', 'last_name' => 'Mensah', 'staff_id' => 'UTAS/LEC/0004', 'department' => 'IT',
        ],
        [
            'email' => 'kofi.addo@utas.edu.gh', 'password' => 'Lecturer@1234', 'role' => 'lecturer',
            'first_name' => 'Kofi', 'last_name' => 'Addo', 'staff_id' => 'UTAS/LEC/0005', 'department' => 'IT',
        ],
        [
            'email' => 'ama.amponsah@utas.edu.gh', 'password' => 'Admin@1234', 'role' => 'admin',
            'first_name' => 'Ama', 'last_name' => 'Amponsah', 'staff_id' => 'UTAS/ADM/0003', 'department' => 'CE',
        ],
        [
            'email' => 'nana.amponsah@utas.edu.gh', 'password' => 'Lecturer@1234', 'role' => 'lecturer',
            'first_name' => 'Nana', 'last_name' => 'Amponsah', 'staff_id' => 'UTAS/LEC/0006', 'department' => 'CE',
        ],
        [
            'email' => 'kwame.sarpong@utas.edu.gh', 'password' => 'Admin@1234', 'role' => 'admin',
            'first_name' => 'Kwame', 'last_name' => 'Sarpong', 'staff_id' => 'UTAS/ADM/0004', 'department' => 'EE',
        ],
        [
            'email' => 'abena.sarpong@utas.edu.gh', 'password' => 'Lecturer@1234', 'role' => 'lecturer',
            'first_name' => 'Abena', 'last_name' => 'Sarpong', 'staff_id' => 'UTAS/LEC/0007', 'department' => 'EE',
        ],
        [
            'email' => 'akosua.frimpong@utas.edu.gh', 'password' => 'Admin@1234', 'role' => 'admin',
            'first_name' => 'Akosua', 'last_name' => 'Frimpong', 'staff_id' => 'UTAS/ADM/0005', 'department' => 'IS',
        ],
        [
            'email' => 'kojo.frimpong@utas.edu.gh', 'password' => 'Lecturer@1234', 'role' => 'lecturer',
            'first_name' => 'Kojo', 'last_name' => 'Frimpong', 'staff_id' => 'UTAS/LEC/0008', 'department' => 'IS',
        ],
        [
            'email' => 'kwame.ansah@utas.edu.gh', 'password' => 'Student@1234', 'role' => 'student',
            'first_name' => 'Kwame', 'last_name' => 'Ansah', 'student_index' => '20230410001', 'department' => 'CS',
        ],
        [
            'email' => 'abena.osei@utas.edu.gh', 'password' => 'Student@1234', 'role' => 'student',
            'first_name' => 'Abena', 'last_name' => 'Osei', 'student_index' => '20230410002', 'department' => 'CS',
        ],
        [
            'email' => 'fiifi.baah@utas.edu.gh', 'password' => 'Student@1234', 'role' => 'student',
            'first_name' => 'Fiifi', 'last_name' => 'Baah', 'student_index' => '20230410003', 'department' => 'CS',
        ],
        [
            'email' => 'ama.darko@utas.edu.gh', 'password' => 'Student@1234', 'role' => 'student',
            'first_name' => 'Ama', 'last_name' => 'Darko', 'student_index' => '20230410011', 'department' => 'IT',
        ],
        [
            'email' => 'yaw.boateng@utas.edu.gh', 'password' => 'Student@1234', 'role' => 'student',
            'first_name' => 'Yaw', 'last_name' => 'Boateng', 'student_index' => '20230410012', 'department' => 'IT',
        ],
        [
            'email' => 'akua.owusu@utas.edu.gh', 'password' => 'Student@1234', 'role' => 'student',
            'first_name' => 'Akua', 'last_name' => 'Owusu', 'student_index' => '20230410013', 'department' => 'IT',
        ],
        [
            'email' => 'kojo.mensah@utas.edu.gh', 'password' => 'Student@1234', 'role' => 'student',
            'first_name' => 'Kojo', 'last_name' => 'Mensah', 'student_index' => '20230410021', 'department' => 'CE',
        ],
        [
            'email' => 'ama.adjei@utas.edu.gh', 'password' => 'Student@1234', 'role' => 'student',
            'first_name' => 'Ama', 'last_name' => 'Adjei', 'student_index' => '20230410022', 'department' => 'CE',
        ],
        [
            'email' => 'kofi.sarpong@utas.edu.gh', 'password' => 'Student@1234', 'role' => 'student',
            'first_name' => 'Kofi', 'last_name' => 'Sarpong', 'student_index' => '20230410031', 'department' => 'EE',
        ],
        [
            'email' => 'afia.nyarko@utas.edu.gh', 'password' => 'Student@1234', 'role' => 'student',
            'first_name' => 'Afia', 'last_name' => 'Nyarko', 'student_index' => '20230410032', 'department' => 'EE',
        ],
        [
            'email' => 'nana.yeboah@utas.edu.gh', 'password' => 'Student@1234', 'role' => 'student',
            'first_name' => 'Nana', 'last_name' => 'Yeboah', 'student_index' => '20230410041', 'department' => 'IS',
        ],
        [
            'email' => 'esi.appiah@utas.edu.gh', 'password' => 'Student@1234', 'role' => 'student',
            'first_name' => 'Esi', 'last_name' => 'Appiah', 'student_index' => '20230410042', 'department' => 'IS',
        ],
        [
            'email' => 'nana.mensah@utas.edu.gh', 'password' => 'Admin@1234', 'role' => 'admin',
            'first_name' => 'Nana', 'last_name' => 'Mensah', 'staff_id' => 'UTAS/ADM/0006', 'department' => 'MA',
        ],
        [
            'email' => 'adjoa.mensah@utas.edu.gh', 'password' => 'Lecturer@1234', 'role' => 'lecturer',
            'first_name' => 'Adjoa', 'last_name' => 'Mensah', 'staff_id' => 'UTAS/LEC/0009', 'department' => 'MA',
        ],
        [
            'email' => 'kwesi.owusu@utas.edu.gh', 'password' => 'Lecturer@1234', 'role' => 'lecturer',
            'first_name' => 'Kwesi', 'last_name' => 'Owusu', 'staff_id' => 'UTAS/LEC/0010', 'department' => 'MA',
        ],
        [
            'email' => 'kojo.darko@utas.edu.gh', 'password' => 'Admin@1234', 'role' => 'admin',
            'first_name' => 'Kojo', 'last_name' => 'Darko', 'staff_id' => 'UTAS/ADM/0007', 'department' => 'AC',
        ],
        [
            'email' => 'abena.darko@utas.edu.gh', 'password' => 'Lecturer@1234', 'role' => 'lecturer',
            'first_name' => 'Abena', 'last_name' => 'Darko', 'staff_id' => 'UTAS/LEC/0011', 'department' => 'AC',
        ],
        [
            'email' => 'yaw.mensah@utas.edu.gh', 'password' => 'Lecturer@1234', 'role' => 'lecturer',
            'first_name' => 'Yaw', 'last_name' => 'Mensah', 'staff_id' => 'UTAS/LEC/0012', 'department' => 'AC',
        ],
        [
            'email' => 'efua.asante@utas.edu.gh', 'password' => 'Admin@1234', 'role' => 'admin',
            'first_name' => 'Efua', 'last_name' => 'Asante', 'staff_id' => 'UTAS/ADM/0008', 'department' => 'NS',
        ],
        [
            'email' => 'akosua.asante@utas.edu.gh', 'password' => 'Lecturer@1234', 'role' => 'lecturer',
            'first_name' => 'Akosua', 'last_name' => 'Asante', 'staff_id' => 'UTAS/LEC/0013', 'department' => 'NS',
        ],
        [
            'email' => 'kofi.boateng@utas.edu.gh', 'password' => 'Lecturer@1234', 'role' => 'lecturer',
            'first_name' => 'Kofi', 'last_name' => 'Boateng', 'staff_id' => 'UTAS/LEC/0014', 'department' => 'NS',
        ],
        [
            'email' => 'kwadwo.baah@utas.edu.gh', 'password' => 'Lecturer@1234', 'role' => 'lecturer',
            'first_name' => 'Kwadwo', 'last_name' => 'Baah', 'staff_id' => 'UTAS/LEC/0015', 'department' => 'CS',
        ],
        [
            'email' => 'ama.quaye@utas.edu.gh', 'password' => 'Student@1234', 'role' => 'student',
            'first_name' => 'Ama', 'last_name' => 'Quaye', 'student_index' => '20230410051', 'department' => 'MA',
        ],
        [
            'email' => 'kojo.asare@utas.edu.gh', 'password' => 'Student@1234', 'role' => 'student',
            'first_name' => 'Kojo', 'last_name' => 'Asare', 'student_index' => '20230410052', 'department' => 'MA',
        ],
        [
            'email' => 'efua.opoku@utas.edu.gh', 'password' => 'Student@1234', 'role' => 'student',
            'first_name' => 'Efua', 'last_name' => 'Opoku', 'student_index' => '20230410061', 'department' => 'AC',
        ],
        [
            'email' => 'yaw.danquah@utas.edu.gh', 'password' => 'Student@1234', 'role' => 'student',
            'first_name' => 'Yaw', 'last_name' => 'Danquah', 'student_index' => '20230410062', 'department' => 'AC',
        ],
        [
            'email' => 'abena.tetteh@utas.edu.gh', 'password' => 'Student@1234', 'role' => 'student',
            'first_name' => 'Abena', 'last_name' => 'Tetteh', 'student_index' => '20230410071', 'department' => 'NS',
        ],
        [
            'email' => 'nana.owusu@utas.edu.gh', 'password' => 'Student@1234', 'role' => 'student',
            'first_name' => 'Nana', 'last_name' => 'Owusu', 'student_index' => '20230410072', 'department' => 'NS',
        ],
        [
            'email' => 'akua.frimpong@utas.edu.gh', 'password' => 'Student@1234', 'role' => 'student',
            'first_name' => 'Akua', 'last_name' => 'Frimpong', 'student_index' => '20230410004', 'department' => 'CS',
        ],
        [
            'email' => 'kojo.asante@utas.edu.gh', 'password' => 'Student@1234', 'role' => 'student',
            'first_name' => 'Kojo', 'last_name' => 'Asante', 'student_index' => '20230410005', 'department' => 'CS',
        ],
    ];

    /** @return list<array<string, string>> */
    private static function accounts(): array
    {
        return array_merge(self::DEMO_ACCOUNTS, self::CAMPUS_ACCOUNTS);
    }

    public function name(): string
    {
        return 'seed';
    }

    public function description(): string
    {
        return 'Load roles, permissions and the reference dataset';
    }

    /** @return list<string> */
    public function aliases(): array
    {
        return ['app:seed', 'db:seed'];
    }

    /** @return list<string> */
    public function synopsis(): array
    {
        return [
            'php bin/console seed',
            'php bin/console seed --dry-run',
            'php bin/console seed --no-demo-users',
            'php bin/console seed --demo-password=… --json',
        ];
    }

    /** @return array<string, string> */
    public function options(): array
    {
        return [
            'sql'         => 'Reference-data script to execute (default db/seed.sql)',
            'no-sql'      => 'Skip the reference-data script; load roles and permissions only',
            'demo-users'  => 'Create the README demo accounts. Default in local, off elsewhere',
            'no-demo-users' => 'Never create the demo accounts, whatever the environment',
            'demo-password' => 'One known password for every demo account instead of random ones',
            'i-know-what-i-am-doing' => 'Permit a seed in production (VULN-06 speed bump)',
            'dry-run'     => 'Report what would be loaded and change nothing',
            'json'        => 'Machine-readable output; nothing else is written to stdout',
        ];
    }

    /** @return list<string> */
    public function notes(): array
    {
        return [
            'Roles and permissions come from config/rbac.php, which is also what docs/SECURITY.md §3 renders.',
            'Password hashes are computed here with password_hash(); none is ever stored in a .sql file.',
            'Outside local, demo accounts get a random password printed once, status=pending and',
            'must_change_password=1, so a leaked password still cannot be used.',
            'Seeding is idempotent: re-running updates labels and grants, and never resets a live password.',
            'Run `php bin/console migrate` first — seeding an unmigrated database fails with a clear message.',
        ];
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function run(Input $input, Output $output): int
    {
        $unknown = $input->unknownOptions([
            'sql', 'no-sql', 'demo-users', 'no-demo-users', 'demo-password',
            'i-know-what-i-am-doing', 'dry-run', 'json',
        ]);
        if ($unknown !== []) {
            return $this->invalid($output, 'Unknown option: ' . implode(', ', $unknown));
        }

        if ($input->boolOption('no-sql') && $input->has('sql')) {
            return $this->invalid($output, '--no-sql and --sql contradict each other.');
        }

        if ($input->boolOption('demo-users') && $input->boolOption('no-demo-users')) {
            return $this->invalid($output, '--demo-users and --no-demo-users contradict each other.');
        }

        $asJson = $input->boolOption('json');
        $environment = $this->kernel->config()->environment();
        $forced = $input->boolOption('i-know-what-i-am-doing');

        if (!in_array($environment, self::SAFE_ENVIRONMENTS, true) && !$forced) {
            return $this->refuseOutsideLocal($output, $environment, $asJson);
        }

        $rbac = $this->rbac();
        $coverage = $this->coverage($rbac);

        if ($coverage['unassigned'] !== []) {
            return $this->failCoverage($output, $coverage, $asJson);
        }

        $dryRun = $input->boolOption('dry-run');
        $sql = $dryRun ? null : $this->sqlPath($input);

        try {
            $counts = [
                'permissions'   => \count($rbac->allPermissions()),
                'roles'         => \count($rbac->allRoles()),
                'grants'        => $coverage['grants'],
                'sql_statements' => $sql === null ? 0 : $this->countStatements($sql),
                'demo_users'    => 0,
            ];

            if (!$dryRun) {
                $counts = $this->load($rbac, $sql, $input, $environment, $counts, $output, $asJson);
            }
        } catch (InvalidArgumentException $exception) {
            if ($asJson) {
                $output->json(['error' => 'invalid', 'message' => $exception->getMessage()]);

                return Kernel::INVALID;
            }

            return $this->invalid($output, $exception->getMessage());
        } catch (Throwable $exception) {
            $this->kernel->logger()->error('Seed failed.', [
                'command'   => $this->name(),
                'exception' => $exception::class,
                'message'   => $exception->getMessage(),
            ]);

            if ($asJson) {
                $output->json(['error' => 'failed', 'message' => $exception->getMessage()]);

                return Kernel::FAILURE;
            }

            $output->failure($exception->getMessage());
            $output->line('  Nothing was committed: the whole seed runs in one transaction.');

            if ($this->kernel->config()->isDebug()) {
                $output->line($exception->getTraceAsString());
            }

            return Kernel::FAILURE;
        }

        if ($asJson) {
            $output->json([
                'environment' => $environment,
                'dry_run'     => $dryRun,
                'sql'         => $sql,
                'counts'      => $counts,
            ]);

            return Kernel::SUCCESS;
        }

        $output->title($dryRun ? 'Seed (dry run — nothing was written)' : 'Seed');

        $output->definitions([
            'environment' => $environment,
            'permissions' => $counts['permissions'],
            'roles'       => $counts['roles'],
            'grants'      => $counts['grants'],
            'sql'         => $sql ?? 'skipped',
            'statements'  => $counts['sql_statements'],
            'demo users'  => $counts['demo_users'],
        ], 0);
        $output->line();

        if ($dryRun) {
            $output->line('  Nothing was written.');

            return Kernel::SUCCESS;
        }

        $output->success('Seed complete.');

        if ($counts['sql_statements'] > 0) {
            $output->line();
            $output->line('  Next:');
            $output->line('    php bin/console generate --status');
            $output->line('    php bin/console generate --dry-run   # then apply');
        }

        return Kernel::SUCCESS;
    }

    // -----------------------------------------------------------------------
    // Refusals and assertions
    // -----------------------------------------------------------------------

    private function refuseOutsideLocal(Output $output, string $environment, bool $asJson): int
    {
        $message = sprintf(
            'Refusing to seed with APP_ENV=%s. Seeding writes demo accounts and reference data, which '
            . 'must never reach a production database (VULN-06). Set APP_ENV=local or staging, or pass '
            . '--i-know-what-i-am-doing if you have confirmed the target is disposable.',
            $environment,
        );

        if ($asJson) {
            $output->json(['error' => 'refused', 'environment' => $environment, 'message' => $message]);

            return Kernel::FAILURE;
        }

        $output->failure($message);

        return Kernel::FAILURE;
    }

    /**
     * The union of every role's permissions, and what it fails to cover.
     *
     * A permission no role holds is unreachable: it can be checked in a
     * controller, documented in the matrix, and always denied. That is the kind
     * of bug nobody notices until a feature is switched on and nothing works, so
     * it is a seed-time failure rather than a warning.
     *
     * @return array{grants: int, assigned: list<string>, unassigned: list<string>, unknown: array<string, list<string>>}
     */
    private function coverage(Rbac $rbac): array
    {
        $declared = $rbac->allPermissions();
        $grants = 0;
        $assigned = [];
        $unknown = [];

        foreach ($rbac->allRoles() as $role) {
            foreach ($rbac->permissionsFor($role) as $permission) {
                $grants++;
                $assigned[$permission] = true;

                if (!$rbac->isKnownPermission($permission)) {
                    $unknown[$permission][] = $role;
                }
            }
        }

        $unassigned = [];
        foreach ($declared as $permission) {
            if (!isset($assigned[$permission])) {
                $unassigned[] = $permission;
            }
        }

        // A permission granted to a role but never declared would be stored in
        // `permissions` with no group or description, and the API would echo a
        // capability nobody wrote down.
        ksort($unknown);

        return [
            'grants'     => $grants,
            'assigned'   => array_keys($assigned),
            'unassigned' => $unassigned,
            'unknown'    => $unknown,
        ];
    }

    /**
     * @param array{grants: int, assigned: list<string>, unassigned: list<string>, unknown: array<string, list<string>>} $coverage
     */
    private function failCoverage(Output $output, array $coverage, bool $asJson): int
    {
        if ($asJson) {
            $output->json([
                'error'      => 'rbac_incomplete',
                'unassigned' => $coverage['unassigned'],
                'unknown'    => $coverage['unknown'],
                'message'    => 'config/rbac.php is inconsistent. See the docs/SECURITY.md §3 matrix.',
            ]);

            return Kernel::FAILURE;
        }

        $output->title('config/rbac.php is inconsistent');
        $output->line();

        if ($coverage['unassigned'] !== []) {
            $output->failure(sprintf(
                '%d permission(s) are declared but held by no role. They can never be granted:',
                \count($coverage['unassigned']),
            ));
            foreach ($coverage['unassigned'] as $permission) {
                $output->line('    ' . $permission);
            }
            $output->line();
        }

        foreach ($coverage['unknown'] as $permission => $roles) {
            $output->failure(sprintf(
                'Permission "%s" is granted to %s but is not declared above them.',
                $permission,
                implode(', ', $roles),
            ));
        }

        $output->line();
        $output->line('  Fix config/rbac.php in the same pull request as the change that needs it.');

        return Kernel::FAILURE;
    }

    // -----------------------------------------------------------------------
    // Loading
    // -----------------------------------------------------------------------

    /**
     * @param array<string, int> $counts
     *
     * @return array<string, int>
     */
    private function load(
        Rbac $rbac,
        ?string $sql,
        Input $input,
        string $environment,
        array $counts,
        Output $output,
        bool $asJson,
    ): array {
        $database = $this->database();
        $cost = $this->kernel->config()->int('PASSWORD_BCRYPT_COST', 12);

        $demoUsers = $this->wantsDemoUsers($input, $environment);

        /** @var array<string, int> $counts */
        $counts = $database->transaction(
            function (
                Database $database,
            ) use ($rbac, $sql, $counts, $demoUsers, $cost, $input, $environment, $output, $asJson): array {
            $permissionIds = $this->seedPermissions($database, $rbac);
            $roleIds = $this->seedRoles($database, $rbac);
            $counts['grants'] = $this->seedGrants($database, $rbac, $roleIds, $permissionIds);

            // Users before reference data, and the order is load-bearing.
            // db/seed.sql assigns teaching to the demo lecturer with a subquery
            // on an e-mail address, because a committed SQL file cannot contain
            // a user id (auto-increment values differ between a laptop, a CI
            // service container and a restored dump) and cannot contain a
            // password hash. Running it first would therefore find no such user
            // and insert no teaching assignments, leaving every cohort
            // unschedulable with a warning that points at the wrong thing.
            if ($demoUsers) {
                $counts['demo_users'] = $this->seedDemoUsers(
                    $database,
                    $roleIds,
                    $input->option('demo-password'),
                    $environment,
                    $cost,
                    $output,
                    $asJson,
                );
            }

            if ($sql !== null) {
                $counts['sql_statements'] = $this->execScript($database, $sql);
            }

            if ($demoUsers) {
                $this->attachDemoDepartments($database);
            }

                return $counts;
            }
        );

        return $counts;
    }

    private function wantsDemoUsers(Input $input, string $environment): bool
    {
        if ($input->boolOption('no-demo-users')) {
            return false;
        }

        if ($input->boolOption('demo-users')) {
            return true;
        }

        // Only `local` gets them by default. Staging is explicitly a
        // "seeded, then deactivated" environment (docs/DEPLOYMENT.md §1), and
        // the demo accounts there would have to be switched off by hand.
        return $environment === 'local';
    }

    /**
     * @return array<string, int> permission name => id
     */
    private function seedPermissions(Database $database, Rbac $rbac): array
    {
        $ids = [];

        foreach ($rbac->allPermissions() as $name) {
            $group = (string) ($rbac->groupOf($name) ?? 'general');
            $description = $rbac->descriptionOf($name);

            // ON DUPLICATE KEY UPDATE rather than INSERT IGNORE: re-seeding
            // after a description is reworded must propagate the reword, or the
            // database becomes a second, stale copy of the matrix.
            $database->execute(
                'INSERT INTO `permissions` (`name`, `group_name`, `description`)
                 VALUES (:name, :group, :description)
                 ON DUPLICATE KEY UPDATE `group_name` = VALUES(`group_name`),
                                         `description` = VALUES(`description`)',
                ['name' => $name, 'group' => $group, 'description' => $description],
            );

            $ids[$name] = (int) $database->scalar(
                'SELECT id FROM `permissions` WHERE `name` = :name',
                ['name' => $name],
            );
        }

        return $ids;
    }

    /**
     * @return array<string, int> role name => id
     */
    private function seedRoles(Database $database, Rbac $rbac): array
    {
        /** @var array<string, array{label: string, description: string, permissions: list<string>}> $definition */
        $definition = require $this->kernel->config()->basePath('config/rbac.php');
        $ids = [];

        foreach ($rbac->allRoles() as $name) {
            $role = $definition['roles'][$name] ?? ['label' => $name, 'description' => null];

            $database->execute(
                'INSERT INTO `roles` (`name`, `label`, `description`)
                 VALUES (:name, :label, :description)
                 ON DUPLICATE KEY UPDATE `label` = VALUES(`label`),
                                         `description` = VALUES(`description`)',
                [
                    'name'        => $name,
                    'label'       => (string) ($role['label'] ?? $name),
                    'description' => isset($role['description']) ? (string) $role['description'] : null,
                ],
            );

            $ids[$name] = (int) $database->scalar(
                'SELECT id FROM `roles` WHERE `name` = :name',
                ['name' => $name],
            );
        }

        return $ids;
    }

    /**
     * The grants, made exact.
     *
     * The role's permission set is replaced rather than merged, so a permission
     * removed from `config/rbac.php` is actually revoked. A merge would leave the
     * grant in place forever, and a stale grant is a capability nobody is
     * tracking.
     *
     * @param array<string, int> $roleIds
     * @param array<string, int> $permissionIds
     */
    private function seedGrants(Database $database, Rbac $rbac, array $roleIds, array $permissionIds): int
    {
        $granted = 0;

        foreach ($rbac->allRoles() as $role) {
            $roleId = $roleIds[$role];

            $database->delete('role_permissions', ['role_id' => $roleId]);

            foreach ($rbac->permissionsFor($role) as $permission) {
                if (!isset($permissionIds[$permission])) {
                    // Already reported by coverage(); unreachable here, but a
                    // skipped grant is better than a row with a zero id.
                    continue;
                }

                $database->insert('role_permissions', [
                    'role_id'       => $roleId,
                    'permission_id' => $permissionIds[$permission],
                ]);
                $granted++;
            }
        }

        return $granted;
    }

    /**
     * The three README accounts, with a hash computed now.
     *
     * A password that is already set is left alone. Re-seeding is a routine step
     * in every environment, and an idempotent seed that quietly reset a real
     * user's password would be a serious one: a developer testing locally would
     * find their own staging account locked out by a tool they ran on purpose.
     */
    /**
     * Departments are loaded by db/seed.sql, which runs after the accounts so
     * that script can find the lecturer by e-mail. Attach the department once
     * that row exists.
     */
    private function attachDemoDepartments(Database $database): void
    {
        foreach (self::accounts() as $account) {
            $database->execute(
                'UPDATE `users` u
                 JOIN `departments` d ON d.`code` = :code
                 SET u.`department_id` = d.`id`
                 WHERE u.`email` = :email',
                [
                    'code'  => (string) $account['department'],
                    'email' => (string) $account['email'],
                ],
            );
        }
    }

    /**
     * @param array<string, int> $roleIds
     */
    private function seedDemoUsers(
        Database $database,
        array $roleIds,
        ?string $forcedPassword,
        string $environment,
        int $cost,
        Output $output,
        bool $asJson,
    ): int {
        $local = $environment === 'local' && $forcedPassword === null;
        $created = 0;
        $printed = [];

        $demoEmails = array_column(self::DEMO_ACCOUNTS, 'email');

        foreach (self::accounts() as $account) {
            $email = (string) $account['email'];

            $existing = $database->selectOne(
                'SELECT id FROM `users` WHERE `email` = :email',
                ['email' => $email],
            );

            $password = $forcedPassword
                ?? ($local ? (string) $account['password'] : $this->randomPassword());

            $found = $database->scalar(
                'SELECT id FROM `departments` WHERE `code` = :code',
                ['code' => $account['department']],
            );
            $departmentId = $found === null ? null : (int) $found;

            if ($existing !== null) {
                $database->update('users', [
                    'role_id'       => $roleIds[(string) $account['role']],
                    'department_id' => $departmentId,
                ], ['id' => (int) $existing['id']]);

                continue;
            }

            $database->insert('users', [
                'role_id'              => $roleIds[(string) $account['role']],
                'department_id'        => $departmentId,
                'first_name'           => (string) $account['first_name'],
                'last_name'            => (string) $account['last_name'],
                'email'                => $email,
                'student_index'        => isset($account['student_index']) ? (string) $account['student_index'] : null,
                'staff_id'             => isset($account['staff_id']) ? (string) $account['staff_id'] : null,
                'password_hash'        => password_hash($password, PASSWORD_BCRYPT, ['cost' => $cost]),
                // Outside `local` the account exists but cannot be used until a
                // human sets a password: a published password plus a usable
                // account is exactly the VULN-06 shape.
                'must_change_password' => $local ? 0 : 1,
                'status'               => $local ? 'active' : 'pending',
                'email_verified_at'    => $local ? gmdate('Y-m-d H:i:s') : null,
            ]);

            $created++;
            $printed[$email] = $password;
        }

        if ($printed !== [] && !$asJson) {
            $demoPrinted = array_intersect_key($printed, array_flip($demoEmails));
            $campusPrinted = array_diff_key($printed, $demoPrinted);
            $output->line();
            if ($demoPrinted !== []) {
                $output->line('  Demo accounts created. These passwords are shown once and never stored:');
                foreach ($demoPrinted as $email => $password) {
                    $output->line(sprintf('    %-28s %s', $email, $password));
                }
                $output->line();
            }
            if ($campusPrinted !== []) {
                $output->line('  Campus lecturers and students created. Local passwords are Lecturer@1234 and Student@1234.');
                foreach (array_keys($campusPrinted) as $email) {
                    $output->line(sprintf('    %s', $email));
                }
                $output->line();
            }
            $output->warn(
                'These accounts use published credentials. They exist for local development; the seed '
                . 'refuses to run in production without --i-know-what-i-am-doing.',
            );
            $output->line();
        }

        return $created;
    }

    /**
     * 16 characters from the alphabet a password is likely to survive.
     *
     * `random_int` rather than `random_bytes` with a base64 alphabet, because
     * base64's alphabet includes characters that get ambiguous when a password
     * is read aloud or retyped from a printed page — and this password is
     * printed exactly once.
     */
    private function randomPassword(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
        $password = '';
        $max = \strlen($alphabet) - 1;

        for ($i = 0; $i < 16; $i++) {
            $password .= $alphabet[random_int(0, $max)];
        }

        return $password;
    }

    // -----------------------------------------------------------------------
    // The reference-data script
    // -----------------------------------------------------------------------

    private function sqlPath(Input $input): ?string
    {
        if ($input->boolOption('no-sql')) {
            return null;
        }

        $override = $input->option('sql');
        $path = $override === null || trim($override) === ''
            ? $this->kernel->config()->basePath('db/seed.sql')
            : $this->resolve($override);

        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException(sprintf(
                'Reference data script not found or unreadable: %s. Run `php bin/console migrate` first, '
                . 'and check --sql.',
                $path,
            ));
        }

        return $path;
    }

    private function resolve(string $override): string
    {
        if (str_starts_with($override, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $override) === 1) {
            return $override;
        }

        return $this->kernel->config()->basePath($override);
    }

    /**
     * `Migrator::split()` rather than a second statement splitter.
     *
     * One implementation means one set of answers to "does a semicolon inside a
     * string end a statement", and the reference data and the schema are both
     * hand-written SQL that will eventually both contain a semicolon in a
     * comment.
     */
    private function execScript(Database $database, string $path): int
    {
        $script = @file_get_contents($path);
        if (!is_string($script)) {
            throw new RuntimeException(sprintf('Cannot read %s.', $path));
        }

        $executed = 0;
        foreach (Migrator::split($script) as $statement) {
            // The connection is already the database named by DB_DATABASE.
            // Honouring `USE utas_catms` here would write reference data into
            // the live schema whenever a test or CI database has another name.
            if (preg_match('/^(USE|CREATE\s+DATABASE)\b/i', ltrim($statement)) === 1) {
                continue;
            }

            $database->pdo()->exec($statement);
            $executed++;
        }

        return $executed;
    }

    private function countStatements(string $path): int
    {
        $script = @file_get_contents($path);
        if (!is_string($script)) {
            return 0;
        }

        return \count(Migrator::split($script));
    }

    private function database(): Database
    {
        return $this->kernel->database();
    }

    /**
     * The RBAC matrix, read from `config/rbac.php`.
     *
     * The command builds its own `Rbac` rather than asking the container,
     * because the container's instance is created inside `App`, which this
     * command never constructs. Same file, same parsing, no framework.
     */
    private function rbac(): Rbac
    {
        /** @var array{permissions: array<string, array{group: string, description: string}>, roles: array<string, array{label: string, description: string, permissions: list<string>}>} $definition */
        $definition = require $this->kernel->config()->basePath('config/rbac.php');

        return Rbac::fromDefinition($definition);
    }
}
