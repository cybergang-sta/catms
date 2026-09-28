<?php

declare(strict_types=1);

namespace Tests\Integration\Support;

use App\Console\Kernel;
use App\Console\Output;
use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Persistence\Migration\Migrator;
use PDO;
use PDOException;
use RuntimeException;

/**
 * One MySQL database and one booted application for the integration suite.
 *
 * The live `utas_catms` database is never the target. When the process has not
 * already named a test database, this installs `utas_catms_test`, loads the
 * schema, seeds the reference data and demo accounts, and generates the CS
 * timetable so the named tests have something to read.
 */
final class Catalogue
{
    public const SEMESTER = '2026-A';

    public const DEPARTMENT = 'CS';

    private const DATABASE = 'utas_catms_test';

    private static ?App $app = null;

    private static ?string $adminToken = null;

    private static ?string $studentToken = null;

    public static int $semesterId = 0;

    public static int $roomId = 0;

    public static function app(): App
    {
        if (!$app = self::$app) {
            self::install();
            $app = self::$app = new App(self::root());
        }

        return $app;
    }

    public static function adminToken(): string
    {
        return self::$adminToken ??= self::login('admin@utas.edu.gh', 'Admin@1234');
    }

    public static function studentToken(): string
    {
        return self::$studentToken ??= self::login('student@utas.edu.gh', 'Student@1234');
    }

    public static function call(
        string $method,
        string $path,
        array $query = [],
        ?array $body = null,
        ?string $token = null,
    ): Response {
        $headers = [];
        if ($token !== null) {
            $headers['authorization'] = 'Bearer ' . $token;
        }

        return self::app()->handle(new Request(
            $method,
            '/api/v1' . $path,
            $query,
            $headers,
            $body === null ? '' : (string) json_encode($body),
            [],
            '127.0.0.1',
            'catms-phpunit',
        ));
    }

    private static function install(): void
    {
        self::configureEnvironment();
        $pdo = self::connect();
        self::loadSchema($pdo);
        self::seed($pdo);
        self::generate($pdo);
        self::enrolStudent($pdo);
        $pdo->exec('DELETE FROM `rate_limit_buckets`');

        $semester = $pdo->query(
            "SELECT `id` FROM `semesters` WHERE `name` = '2026-A' ORDER BY `id` LIMIT 1"
        )->fetch();
        $room = $pdo->query('SELECT `id` FROM `rooms` ORDER BY `id` LIMIT 1')->fetch();
        if (!is_array($semester) || !is_array($room)) {
            throw new RuntimeException('The test database has no semester or room after seeding.');
        }

        self::$semesterId = (int) $semester['id'];
        self::$roomId = (int) $room['id'];
    }

    private static function configureEnvironment(): void
    {
        $database = getenv('DB_DATABASE');
        if (!is_string($database) || $database === '' || $database === 'utas_catms') {
            putenv('DB_DATABASE=' . self::DATABASE);
        }

        $environment = getenv('APP_ENV');
        if (!is_string($environment) || !in_array($environment, ['local', 'staging'], true)) {
            putenv('APP_ENV=local');
        }

        $cost = getenv('PASSWORD_BCRYPT_COST');
        if (!is_string($cost) || (int) $cost < 10 || (int) $cost > 15) {
            putenv('PASSWORD_BCRYPT_COST=12');
        }
    }

    private static function connect(): PDO
    {
        $host = self::setting('DB_HOST', '127.0.0.1');
        $port = self::setting('DB_PORT', '3306');
        $user = self::setting('DB_USERNAME', 'catms_app');
        $password = self::setting('DB_PASSWORD', '');
        $name = self::setting('DB_DATABASE', self::DATABASE);
        $server = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, (int) $port);

        try {
            $admin = self::pdo($server, $user, $password);
            $admin->exec(
                'CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '``', $name)
                . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci'
            );
        } catch (PDOException) {
            self::createAsRoot($server, $name, $user);
        }

        return self::pdo($server . ';dbname=' . $name, $user, $password);
    }

    private static function createAsRoot(string $server, string $name, string $user): void
    {
        $last = null;
        foreach ([['root', ''], ['root', 'root_test_only']] as [$rootUser, $rootPassword]) {
            try {
                $root = self::pdo($server, $rootUser, $rootPassword);
                $quoted = '`' . str_replace('`', '``', $name) . '`';
                $root->exec(
                    'CREATE DATABASE IF NOT EXISTS ' . $quoted
                    . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci'
                );
                $account = str_replace("'", "''", $user);
                foreach (['%', 'localhost', '127.0.0.1'] as $host) {
                    $root->exec(
                        "GRANT ALL PRIVILEGES ON {$quoted}.* TO '{$account}'@'{$host}'"
                    );
                }
                $root->exec('FLUSH PRIVILEGES');

                return;
            } catch (PDOException $exception) {
                $last = $exception;
            }
        }

        throw new RuntimeException(
            'Cannot create the integration database. ' . ($last?->getMessage() ?? ''),
            0,
            $last,
        );
    }

    private static function loadSchema(PDO $pdo): void
    {
        $exists = $pdo->query(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = 'users'"
        )->fetchColumn();
        if ((int) $exists > 0) {
            return;
        }

        $sql = file_get_contents(self::root() . '/db/schema.sql');
        if (!is_string($sql)) {
            throw new RuntimeException('Cannot read db/schema.sql.');
        }

        foreach (Migrator::split($sql) as $statement) {
            if (preg_match('/^(USE|CREATE\s+DATABASE)\b/i', ltrim($statement)) === 1) {
                continue;
            }
            $pdo->exec($statement);
        }
    }

    private static function seed(PDO $pdo): void
    {
        $ready = $pdo->query(
            "SELECT COUNT(*) FROM `users` WHERE `email` = 'admin@utas.edu.gh'"
        )->fetchColumn();
        if ((int) $ready > 0) {
            return;
        }

        self::command(['bin/console', 'seed', '--demo-users'], 'seed');
    }

    private static function generate(PDO $pdo): void
    {
        $placed = $pdo->query(
            "SELECT COUNT(*) FROM `allocations` a
             JOIN `semesters` s ON s.id = a.semester_id
             WHERE s.name = '2026-A'"
        )->fetchColumn();
        if ((int) $placed > 0) {
            return;
        }

        self::command([
            'bin/console', 'generate',
            '--semester=' . self::SEMESTER,
            '--department=' . self::DEPARTMENT,
            '--iterations=2000',
            '--time-budget=25',
        ], 'generate');
    }

    /**
     * @param list<string> $argv
     */
    private static function command(array $argv, string $label): void
    {
        $stream = fopen('php://temp', 'w+');
        if ($stream === false) {
            throw new RuntimeException('Cannot capture console output.');
        }

        $code = (new Kernel(self::root(), $argv, new Output($stream, $stream, false)))->run();
        rewind($stream);
        $text = stream_get_contents($stream);
        if ($code !== Kernel::SUCCESS) {
            throw new RuntimeException($label . ' failed (' . $code . '): ' . (is_string($text) ? $text : ''));
        }
    }

    private static function enrolStudent(PDO $pdo): void
    {
        $pdo->exec(
            "INSERT IGNORE INTO `enrollments` (`cohort_id`, `student_id`, `status`)
             SELECT c.id, u.id, 'enrolled'
             FROM `cohorts` c
             JOIN `users` u ON u.email = 'student@utas.edu.gh'"
        );
    }

    private static function login(string $email, string $password): string
    {
        $response = self::call('POST', '/auth/login', [], [
            'email'    => $email,
            'password' => $password,
        ]);
        $body = $response->decoded();
        $token = $body['data']['access_token'] ?? null;
        if ($response->status() !== 200 || !is_string($token) || $token === '') {
            throw new RuntimeException('Login failed for ' . $email . ': ' . $response->body());
        }

        return $token;
    }

    private static function pdo(string $dsn, string $user, string $password): PDO
    {
        return new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    private static function setting(string $key, string $default): string
    {
        $value = getenv($key);
        if (is_string($value) && $value !== '') {
            return $value;
        }

        return self::dotEnv()[$key] ?? $default;
    }

    /**
     * @return array<string, string>
     */
    private static function dotEnv(): array
    {
        static $values = null;
        if (is_array($values)) {
            return $values;
        }

        $values = [];
        $path = self::root() . '/.env';
        if (!is_file($path)) {
            return $values;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if (!is_array($lines)) {
            return $values;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $value = trim($value);
            if (
                strlen($value) >= 2
                && (($value[0] === '"' && str_ends_with($value, '"'))
                    || ($value[0] === "'" && str_ends_with($value, "'")))
            ) {
                $value = substr($value, 1, -1);
            }
            $values[trim($key)] = $value;
        }

        return $values;
    }

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }
}
