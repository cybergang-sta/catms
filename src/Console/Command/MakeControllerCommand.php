<?php

declare(strict_types=1);

namespace App\Console\Command;

use App\Console\Input;
use App\Console\Kernel;
use App\Console\Output;
use RuntimeException;

/**
 * `bin/console make:controller` — a controller stub wired to the route table.
 *
 * WHY A GENERATOR WHEN config/routes.php ALREADY NAMES EVERY ENDPOINT
 * Because the route table is the specification and the controller is the
 * implementation, and the specification is written down first. That ordering is
 * deliberate: docs/IMPLEMENTATION.md §8 asks for the route, its permission, its
 * rate-limit bucket and its data scope to be agreed *before* any code exists, so
 * that "who may see this" is a decision rather than an afterthought found in a
 * diff. The routes in `config/routes.php` that point at controllers which do not
 * exist are therefore not an oversight — they are the build list, and they
 * return 501 so a client can tell "unfinished" from "does not exist".
 *
 * A generator earns its place for that build list because the boilerplate is not
 * trivial and getting it wrong is quiet. A controller that returns
 * `Response::success([])` where an error was meant ships a 200 with an empty
 * body. One that reads `$request->query('id')` instead of `$this->param($request,
 * 'id')` silently ignores the route parameter. One whose action takes a second
 * argument fails at request time, because `App::resolveHandler` is what notices
 * and that is a bad place to find out. The template below is the shape that
 * passes those checks.
 *
 * THE TEMPLATE USES THE SERVICE LOCATOR, NOT CONSTRUCTOR INJECTION
 * This is the one place where matching the surrounding code matters more than
 * matching anyone's preference. `Controller` takes a `Container` and exposes
 * `$this->database()`, `$this->users()`, `$this->identity($request)`. A generated
 * controller using constructor injection would be the only one in the tree, would
 * need a new `App::registerCoreServices()` entry for every service it wanted, and
 * would read as an inconsistency in review.
 *
 * IT DOES NOT EDIT config/routes.php
 * The route entries are printed for the developer to paste. A generator that
 * rewrote the route table could put the wrong permission in the `permission`
 * column, and that is a security defect nothing in the suite currently covers. The
 * snippet ships `permission => null` with a `TODO`, which is the most visible
 * possible placeholder.
 */
final class MakeControllerCommand extends Command
{
    /**
     * The actions `--resource` generates, mapped to a one-line purpose.
     *
     * These are the method names the route table already uses, so a generated
     * handler string and a documented one are the same string and can be
     * diffed against each other.
     */
    private const RESOURCE_ACTIONS = [
        'index'   => 'List, paginated, within the caller\'s data scope',
        'show'    => 'One record, by id',
        'store'   => 'Create',
        'update'  => 'Partial update',
        'destroy' => 'Delete or archive',
    ];

    /**
     * HTTP verb per action.
     */
    private const RESOURCE_VERBS = [
        'index'   => 'GET',
        'show'    => 'GET',
        'store'   => 'POST',
        'update'  => 'PATCH',
        'destroy' => 'DELETE',
    ];

    /**
     * Per-action guidance, rendered as a `TODO` in the generated body.
     *
     * The advice is specific to the action because the mistakes are: a list that
     * forgets the scope leaks another cohort's timetable, a PATCH that replaces
     * the record is data loss, and a DELETE on a table holding personal data is
     * a data-protection incident rather than a cleanup.
     */
    private const GUIDANCE = [
        'index' => [
            '// TODO: replace with one repository or service call.',
            '//   Pass $this->scope($request) so the repository applies the data scope —',
            '//   a student must not be able to read another cohort, and this is the',
            '//   line that guarantees it. Use $this->pagination($request) rather than a',
            '//   raw LIMIT: per_page is clamped there, and ?per_page=100000 is a DoS.',
        ],
        'show' => [
            '// TODO: replace with one repository or service call, using the scope:',
            '//   $id    = $this->param($request, $paramName);',
            '//   $scope = $this->scope($request);',
            '// Throw NotFoundException when the row is missing or out of scope. Do not',
            '// distinguish the two: "exists but not yours" is an enumeration oracle.',
        ],
        'store' => [
            '// TODO: validate with $this->validator()->validateStrict($request->json(), [...])',
            '// and then create. Return Response::created($data), not Response::success().',
            '// Honour $request->idempotencyKey() if the route declares an Idempotency-Key.',
        ],
        'update' => [
            '// TODO: validate, then apply only the fields that were actually sent.',
            '// A PATCH that writes the whole record is data loss wearing a partial-update',
            '// verb, and it will not be noticed until someone clears a field by accident.',
        ],
        'destroy' => [
            '// TODO: for anything holding personal data, archive and pseudonymise rather',
            '// than DELETE (docs/SECURITY.md §6.5). Either way, write an audit_log entry:',
            '//   $this->audit($request, $entityType, $id, "entity.deleted", $before);',
            '// Append-only means a correction is a new row, not an edit of the old one.',
        ],
    ];

    public function name(): string
    {
        return 'make:controller';
    }

    public function description(): string
    {
        return 'Create a controller stub in the service-locator style of Controller';
    }

    /** @return list<string> */
    public function aliases(): array
    {
        return ['new:controller', 'make:api'];
    }

    /** @return list<string> */
    public function synopsis(): array
    {
        return [
            'php bin/console make:controller Timetable',
            'php bin/console make:controller AllocationController',
            'php bin/console make:controller Timetable --resource',
            'php bin/console make:controller Report --actions=index,show',
            'php bin/console make:controller Course --param=code',
            'php bin/console make:controller Timetable --resource --dry-run',
        ];
    }

    /** @return array<string, string> */
    public function options(): array
    {
        return [
            'resource' => 'Generate index, show, store, update and destroy',
            'actions'  => 'Comma-separated subset of: ' . implode(', ', array_keys(self::RESOURCE_ACTIONS)),
            'param'    => 'Route parameter name, e.g. --param=course_id. Default id',
            'force'    => 'Overwrite an existing controller',
            'dry-run'  => 'Print the file and the route entries; write nothing',
            'json'     => 'Machine-readable output; nothing else is written to stdout',
        ];
    }

    /** @return list<string> */
    public function notes(): array
    {
        return [
            'The name may be given with or without the Controller suffix; both write the same file.',
            'The stub uses the service-locator accessors from Controller, not constructor',
            'injection, because every other controller in this tree does and an exception',
            'needs an exception.',
            'An action may take only the Request. App::resolveHandler enforces that at',
            'request time with a 500, which is the worst place to find out.',
            'The generated body throws NotImplementedException, so the endpoint is honestly',
            'unfinished rather than a 200 with an empty body.',
            'The permission column is left as a TODO. A generator that filled it in could',
            'put the wrong one there silently, and no test covers it yet.',
        ];
    }

    public function run(Input $input, Output $output): int
    {
        $unknown = $input->unknownOptions(['resource', 'actions', 'param', 'force', 'dry-run', 'json']);
        if ($unknown !== []) {
            return $this->invalid($output, 'Unknown option: ' . implode(', ', $unknown));
        }

        $raw = (string) $input->requiredArgument(0, 'name');
        $class = self::className($raw);

        if ($class === '') {
            return $this->invalid($output, 'The name must contain at least one letter or digit.');
        }

        $param = $this->paramName($input->option('param'));
        $actions = $this->actions($input);

        if ($actions === null) {
            return $this->invalid($output, sprintf(
                'Unknown action. Valid actions: %s.',
                implode(', ', array_keys(self::RESOURCE_ACTIONS)),
            ));
        }

        $directory = $this->kernel->basePath('src/Http/Controller');
        $path = $directory . '/' . $class . '.php';

        if (is_file($path) && !$input->boolOption('force')) {
            return $this->invalid($output, sprintf(
                '%s already exists. Use --force to overwrite it.',
                $path,
            ));
        }

        $contents = $this->template($class, $actions, $param);
        $snippet = $this->routeSnippet($class, $actions, $param);

        if ($input->boolOption('dry-run')) {
            $output->title(sprintf('%s (dry run)', $class));
            $output->definitions([
                'path'    => $path,
                'actions' => implode(', ', $actions),
            ], 0);
            $output->line();
            $output->line($contents);
            $output->line();
            $output->line('  Then add to config/routes.php:');
            $output->line();
            $output->line($snippet);

            return Kernel::SUCCESS;
        }

        if (!is_dir($directory) && !@mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Cannot create %s.', $directory));
        }

        if (@file_put_contents($path, $contents) === false) {
            throw new RuntimeException(sprintf('Cannot write %s.', $path));
        }

        if ($input->boolOption('json')) {
            $output->json([
                'path'          => $path,
                'class'         => 'App\\Http\\Controller\\' . $class,
                'actions'       => array_keys($actions),
                'route_entries' => $snippet,
            ]);

            return Kernel::SUCCESS;
        }

        $output->success(sprintf('Created %s', $path));
        $output->definitions([
            'class'   => 'App\\Http\\Controller\\' . $class,
            'actions' => implode(', ', $actions),
        ], 0);
        $output->line();
        $output->line('  Every action now returns 501, which is the documented state for an endpoint');
        $output->line('  that is written but not finished. Implement them one at a time.');
        $output->line();
        $output->line('  Then add these to config/routes.php, and fill in the permission:');
        $output->line();
        $output->line($snippet);
        $output->line();
        $output->line('  Keep a route out of the table until its permission is set. A null permission');
        $output->line('  means the route is authenticated but not authorised, and nothing fails loudly.');
        $output->line();
        $output->line('  Confirm the handler resolves:');
        $output->line(
            '    php bin/console routes | grep -i ' . lcfirst((string) preg_replace('/Controller$/', '', $class))
        );

        return Kernel::SUCCESS;
    }

    // -----------------------------------------------------------------------
    // Naming
    // -----------------------------------------------------------------------

    /**
     * A route parameter name, rejected if it is not a plain identifier.
     *
     * It ends up inside a `/{name}` pattern and inside `$this->param($request,
     * 'name')`. A name with a brace, a slash or an uppercase letter produces a
     * route that either cannot be matched or cannot be read at the call site, and
     * both are worse than a loud rejection here.
     */
    private function paramName(?string $override): string
    {
        $param = $override === null
            ? ''
            : trim($override);

        if ($param === '') {
            return 'id';
        }

        if (preg_match('/^[a-z][a-z0-9_]*$/', $param) !== 1) {
            throw new RuntimeException(sprintf(
                '--param must be a lowercase identifier such as id or course_id, got "%s".',
                $param,
            ));
        }

        return $param;
    }

    /**
     * Which actions to generate.
     *
     * `--actions` wins over `--resource`, and asking for both is reported rather
     * than resolved silently: a typo in a flag should stop the command, not
     * quietly produce a different set of methods than the operator asked for.
     *
     * With neither flag, the stub gets a single `index`. A controller with no
     * action does not compile usefully, and a developer who wanted the other four
     * would have said so.
     *
     * @return list<string>|null Null when an unknown action was named.
     */
    private function actions(Input $input): ?array
    {
        $requested = $input->option('actions');

        if ($requested !== null && trim($requested) !== '') {
            if ($input->boolOption('resource')) {
                throw new RuntimeException('--resource and --actions contradict each other; pass only one.');
            }

            $wanted = [];
            foreach (explode(',', $requested) as $part) {
                $name = strtolower(trim($part));
                if ($name === '') {
                    continue;
                }

                if (!isset(self::RESOURCE_ACTIONS[$name])) {
                    return null;
                }

                $wanted[] = $name;
            }

            if ($wanted === []) {
                return null;
            }

            // Ordered by RESOURCE_ACTIONS, not by the order they were typed, so a
            // resource stub always reads index/show/store/update/destroy.
            $ordered = [];
            foreach (array_keys(self::RESOURCE_ACTIONS) as $name) {
                if (!in_array($name, $wanted, true)) {
                    continue;
                }

                $ordered[] = $name;
            }

            return $ordered;
        }

        if ($input->boolOption('resource')) {
            return array_keys(self::RESOURCE_ACTIONS);
        }

        return ['index'];
    }

    // -----------------------------------------------------------------------
    // The template
    // -----------------------------------------------------------------------

    /**
     * Built line by line rather than from a heredoc.
     *
     * A heredoc would have to interpolate already-indented method bodies, and
     * PHP 7.3 heredoc de-indenting strips the closing marker's indentation from
     * every line — which silently mangles exactly the part that matters most in
     * generated code. An explicit array of lines has no such rule.
     *
     * @param list<string> $actions
     */
    private function template(string $class, array $actions, string $param): string
    {
        $base = self::basePath($class);
        $subject = strtolower((string) preg_replace('/Controller$/', '', $class));
        $lines = [
            '<?php',
            '',
            'declare(strict_types=1);',
            '',
            'namespace App\\Http\\Controller;',
            '',
            'use App\\Core\\Exception\\NotFoundException;',
            'use App\\Core\\Exception\\NotImplementedException;',
            'use App\\Core\\Request;',
            'use App\\Core\\Response;',
            '',
            '/**',
            sprintf(' * %s — the %s endpoints.', $class, $subject),
            ' *',
            ' * Cite the requirement IDs these endpoints satisfy (FR-…, NFR-…) on each action.',
            ' * An endpoint with no requirement behind it is scope nobody agreed to.',
            ' *',
            ' * WHAT A CONTROLLER MAY DO (docs/IMPLEMENTATION.md §8)',
            ' * Read the request, call one service, return a Response. No SQL, no business',
            ' * rules, and no `if` that decides what a caller may see — authorisation was',
            ' * settled by RequireAuthentication before this ran, and the data scope is applied',
            ' * by the repository when you hand it $this->scope($request).',
            ' *',
            sprintf(' * Generated by `php bin/console make:controller %s`.', $subject),
            ' */',
            sprintf('final class %s extends Controller', $class),
            '{',
        ];

        foreach ($actions as $action) {
            foreach ($this->method($class, $base, $action, $param) as $line) {
                $lines[] = $line;
            }
        }

        $lines[] = '}';
        $lines[] = '';

        return implode("\n", $lines) . "\n";
    }

    /**
     * One action, as lines, already indented for its position in the class.
     *
     * @return list<string>
     */
    private function method(string $class, string $base, string $action, string $param): array
    {
        $verb = self::RESOURCE_VERBS[$action] ?? 'GET';
        $path = $this->pathFor($base, $action, $param);
        $purpose = self::RESOURCE_ACTIONS[$action] ?? 'Endpoint';

        $lines = [
            '    /**',
            sprintf('     * %s %s', $verb, $path),
            sprintf('     * %s.', rtrim($purpose, '.')),
            '     *',
            '     * @throws NotImplementedException Always, until this action is implemented.',
            '     */',
            sprintf('    public function %s(Request $request): Response', $action),
            '    {',
        ];

        foreach (self::GUIDANCE[$action] ?? [] as $comment) {
            $lines[] = '        ' . $comment;
        }

        $lines[] = '';
        $lines[] = sprintf("        throw new NotImplementedException('%s::%s');", $class, $action);
        $lines[] = '    }';
        $lines[] = '';

        return $lines;
    }

    /**
     * `index` and `store` are the collection; everything else is a member of it.
     */
    private function pathFor(string $base, string $action, string $param): string
    {
        return match ($action) {
            'index', 'store' => $base,
            default          => $base . '/{' . $param . '}',
        };
    }

    // -----------------------------------------------------------------------
    // The route entries
    // -----------------------------------------------------------------------

    /**
     * The entries to paste into `config/routes.php`.
     *
     * `permission => null` is the loudest thing on the page and it is deliberate.
     * A null permission means the route is authenticated but not authorised —
     * `RequireAuthentication` will still refuse an anonymous caller, so it is not
     * an auth bypass, but it is also not access control, and a generated file
     * should not make that look finished.
     *
     * `scope => 'own'` for every action, because that is the answer that leaks
     * least when nobody changes it. Widening it is a decision with a permission
     * behind it, not a default.
     *
     * @param list<string> $actions
     */
    private function routeSnippet(string $class, array $actions, string $param): string
    {
        $base = self::basePath($class);
        $entries = [];

        foreach ($actions as $action) {
            $verb = self::RESOURCE_VERBS[$action] ?? 'GET';
            $path = match ($action) {
                'index', 'store' => $base,
                default          => $base . '/{' . $param . '}',
            };

            $bucket = in_array($action, ['store', 'update', 'destroy'], true)
                ? 'write'
                : 'search';

            $entries[] = implode("\n", [
                '    [',
                sprintf("        'method'     => '%s',", $verb),
                sprintf("        'path'       => '%s',", $path),
                sprintf("        'name'       => '%s.%s',", $base, $action),
                sprintf("        'handler'    => 'App\\\\Http\\\\Controller\\\\%s@%s',", $class, $action),
                '        // TODO: choose a permission from config/rbac.php and delete this comment.',
                '        //   null means authenticated but NOT authorised: RequireAuthentication',
                '        //   still refuses an anonymous caller, but nothing limits which records',
                '        //   this action can reach. See docs/SECURITY.md §4.',
                "        'permission' => null,",
                "        'middleware' => ['RequireAuthentication'],",
                "        'scope'      => 'own',",
                sprintf("        'throttle'   => '%s',", $bucket),
                sprintf('        // %s.', rtrim(self::RESOURCE_ACTIONS[$action] ?? '', '.')),
                '    ],',
            ]);
        }

        return implode("\n", $entries);
    }

    /**
     * `timetable`, `Timetable`, `TimetableController` and `timetable-controller`
     * all become `TimetableController`.
     *
     * The name appears three times — as a file name, as a class name, and as a
     * *string* in the `handler` column of `config/routes.php` — and the third is
     * data in an array rather than something the compiler can check. That is
     * exactly the duplication a generator exists to remove.
     */
    private static function className(string $raw): string
    {
        $name = trim($raw);
        $name = (string) preg_replace('/\.php$/i', '', $name);
        $name = (string) preg_replace('/Controller$/i', '', $name);
        $name = (string) preg_replace('/[^A-Za-z0-9]+/', ' ', $name);
        $name = trim($name);

        if ($name === '') {
            return '';
        }

        return str_replace(' ', '', ucwords(strtolower($name))) . 'Controller';
    }

    /**
     * The collection path segment, `/api/v1/course` for `CourseController`.
     */
    private static function basePath(string $class): string
    {
        return '/' . strtolower((string) preg_replace('/Controller$/', '', $class));
    }
}
