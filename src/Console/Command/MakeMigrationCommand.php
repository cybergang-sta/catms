<?php

declare(strict_types=1);

namespace App\Console\Command;

use App\Console\Input;
use App\Console\Kernel;
use App\Console\Output;
use RuntimeException;

/**
 * `bin/console make:migration` — a dated, reversible migration file.
 *
 * The date is generated in UTC because the filename *is* the ordering, and a
 * migration created in Accra at 01:00 the day after a UTC 23:00 deploy would
 * sort into the wrong place. (`APP_TIMEZONE` is for display; storage is UTC.)
 *
 * The template is a real `up()`/`down()` pair, not a comment saying "write
 * these". Ground rule 8 is that the `down` path ships in the same release as
 * the `up` path, and a generator that emitted only an `up()` would make the most
 * common workflow the one that breaks that rule.
 */
final class MakeMigrationCommand extends Command
{
    public function name(): string
    {
        return 'make:migration';
    }

    public function description(): string
    {
        return 'Create a migration file named YYYY_MM_DD_HHMMSS_name.php';
    }

    /** @return list<string> */
    public function aliases(): array
    {
        return ['make:migrations', 'new:migration'];
    }

    /** @return list<string> */
    public function synopsis(): array
    {
        return [
            'php bin/console make:migration add_middle_name_to_users',
            'php bin/console make:migration add_capacity_to_rooms --table=rooms',
        ];
    }

    /** @return array<string, string> */
    public function options(): array
    {
        return [
            'table'    => 'Name the table in the generated up()/down() comments',
            'force'    => 'Overwrite an existing file with the same name',
            'date'     => 'Override the timestamp, e.g. --date=2026_09_15_080000',
            'dry-run'  => 'Print the file and its path; write nothing',
            'json'     => 'Machine-readable output; nothing else is written to stdout',
        ];
    }

    /** @return list<string> */
    public function notes(): array
    {
        return [
            'The name is snake_case without the .php suffix; the date prefix is added for you.',
            'Write both up() and down(). A migration with no down() cannot be reversed in an incident.',
            'up() must be idempotent: MySQL commits DDL implicitly, so a retry after a partial failure',
            'will run it again. Use CREATE TABLE IF NOT EXISTS and ADD COLUMN IF NOT EXISTS.',
            'Also update db/schema.sql (the consolidated view) and docs/DATA_MODEL.md in the same PR.',
            'Deploy code that tolerates both states before running the migration (docs/DEPLOYMENT.md §6.2).',
        ];
    }

    public function run(Input $input, Output $output): int
    {
        $unknown = $input->unknownOptions(['table', 'force', 'date', 'dry-run', 'json']);
        if ($unknown !== []) {
            return $this->invalid($output, 'Unknown option: ' . implode(', ', $unknown));
        }

        $raw = (string) $input->requiredArgument(0, 'name');
        $slug = self::slug($raw);

        if ($slug === '') {
            return $this->invalid($output, 'The name must contain at least one letter or digit.');
        }

        $date = $this->timestamp($input->option('date'));
        $version = $date . '_' . $slug;
        $directory = $this->kernel->basePath('db/migrations');
        $path = $directory . '/' . $version . '.php';

        if (is_file($path) && !$input->boolOption('force')) {
            return $this->invalid($output, sprintf(
                '%s already exists. Use --force to overwrite it (only safe if it has not been applied).',
                $path,
            ));
        }

        $table = (string) $input->option('table', '');
        $contents = $this->template($version, $table);

        if ($input->boolOption('dry-run')) {
            $output->title('Migration (dry run)');
            $output->definitions(['path' => $path, 'version' => $version], 0);
            $output->line();
            $output->line($contents);

            return Kernel::SUCCESS;
        }

        if (!is_dir($directory) && !@mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Cannot create %s.', $directory));
        }

        if (@file_put_contents($path, $contents) === false) {
            throw new RuntimeException(sprintf('Cannot write %s.', $path));
        }

        if ($input->boolOption('json')) {
            $output->json(['path' => $path, 'version' => $version, 'table' => $table === '' ? null : $table]);

            return Kernel::SUCCESS;
        }

        $output->success(sprintf('Created %s', $path));
        $output->definitions([
            'version' => $version,
            'table'   => $table === '' ? '-' : $table,
        ], 0);
        $output->line();
        $output->line('  Next: implement up() and down(), then run');
        $output->line('    php bin/console migrate --pretend');

        return Kernel::SUCCESS;
    }

    private function timestamp(?string $override): string
    {
        $override = $override === null
            ? ''
            : trim($override);

        if ($override !== '') {
            if (preg_match('/^\d{4}_\d{2}_\d{2}_\d{6}$/', $override) === 1) {
                return $override;
            }

            throw new RuntimeException(sprintf(
                '--date must look like 2026_09_15_080000, got "%s".',
                $override,
            ));
        }

        return gmdate('Y_m_d_His');
    }

    private function template(string $version, string $table): string
    {
        $tableComment = $table === ''
            ? 'Describe the change in one sentence, and cite the requirement ID\n * that asks for it (NFR-MAINT-06).'
            : sprintf('Alters `%s`. Cite the requirement ID that asks for this (NFR-MAINT-06).', $table);

        return <<<PHP
			<?php

			declare(strict_types=1);

			/**
			 * {$tableComment}
			 *
			 * UP MUST BE IDEMPOTENT
			 * MySQL commits DDL implicitly, so a migration that fails halfway is
			 * retried from the top by a deploy. `CREATE TABLE IF NOT EXISTS` and
			 * `ADD COLUMN IF NOT EXISTS` are what make that safe.
			 *
			 * DOWN MUST REVERSE UP
			 * Ground rule 8: the down path ships in the same release as the up
			 * path, because writing it during an incident is the worst possible
			 * time (docs/DEPLOYMENT.md §8.3).
			 */

			use App\Core\Database;
			use App\Infrastructure\Persistence\Migration\Migration;

			return new class implements Migration {
				public function name(): string
				{
					return '{$version}';
				}

				public function up(Database \$database): void
				{
					// Example, replace it:
					//   \$database->pdo()->exec(
					//       'ALTER TABLE `rooms`
					//            ADD COLUMN IF NOT EXISTS `accessible` TINYINT(1) NOT NULL DEFAULT 0'
					//   );
				}

				public function down(Database \$database): void
				{
					// Example, replace it:
					//   \$database->pdo()->exec('ALTER TABLE `rooms` DROP COLUMN `accessible`');
				}
			};

			PHP;
    }

    /**
     * `Add Middle Name To Users` and `add-middle-name` both become
     * `add_middle_name`. A migration name ends up in `schema_migrations` and in
     * every log line that mentions it, so it is normalised once here rather than
     * at every read site.
     */
    private static function slug(string $raw): string
    {
        $slug = strtolower(trim($raw));
        $slug = str_replace(['-', ' ', '/', '.', '\\'], '_', $slug);
        $slug = (string) preg_replace('/[^a-z0-9_]+/', '_', $slug);
        $slug = (string) preg_replace('/_+/', '_', $slug);

        return trim($slug, '_');
    }
}
