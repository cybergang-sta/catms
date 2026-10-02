<?php

declare(strict_types=1);

namespace App\Console\Command;

use App\Console\Input;
use App\Console\Kernel;
use App\Console\Output;
use App\Core\Router;

/**
 * `bin/console routes` — the route table with its permissions.
 *
 * EXISTENCE OF THIS COMMAND IS A SECURITY CONTROL, NOT A CONVENIENCE
 * `config/routes.php` is written as the complete table the API will expose,
 * including the endpoints whose controllers are not written yet. The Router
 * authorises *before* it resolves a handler, so an unwritten endpoint is
 * permission-checked and then 501 — never open. But that property is only
 * auditable if somebody can read the table, and this is what they read. It also
 * answers "which permission does this endpoint need?" without reading PHP.
 *
 * `--missing` is the review tool: it lists only the routes that return 501, and
 * `docs/API.md` can be reconciled against it instead of by hand.
 */
final class RouteListCommand extends Command
{
    public function name(): string
    {
        return 'routes';
    }

    public function description(): string
    {
        return 'List the API routes, their permissions and whether they are implemented';
    }

    /** @return list<string> */
    public function aliases(): array
    {
        return ['route:list', 'app:routes'];
    }

    /** @return list<string> */
    public function synopsis(): array
    {
        return [
            'php bin/console routes',
            'php bin/console routes --missing',
            'php bin/console routes --name=allocations --json',
        ];
    }

    /** @return array<string, string> */
    public function options(): array
    {
        return [
            'missing' => 'Only routes whose controller does not exist yet (they return 501)',
            'name'    => 'Filter by route name substring, e.g. --name=allocations',
            'public'  => 'Only routes callable without authentication (no permission)',
            'json'    => 'Machine-readable output; nothing else is written to stdout',
        ];
    }

    public function run(Input $input, Output $output): int
    {
        $unknown = $input->unknownOptions(['missing', 'name', 'public', 'json']);
        if ($unknown !== []) {
            return $this->invalid($output, 'Unknown option: ' . implode(', ', $unknown));
        }

        $router = $this->router();
        $filter = (string) $input->option('name', '');
        $onlyMissing = $input->boolOption('missing');
        $onlyPublic = $input->boolOption('public');

        $rows = [];
        $missing = 0;
        $unauthorised = 0;

        foreach ($router->all() as $route) {
            $permission = $route['permission'];
            $permission = is_string($permission) && $permission !== ''
                ? $permission
                : null;

            if ($permission === null) {
                $unauthorised++;
            }

            [$class, $method] = array_pad(explode('@', (string) $route['handler'], 2), 2, '__invoke');
            $implemented = class_exists($class) && method_exists($class, $method);
            if (!$implemented) {
                $missing++;
            }

            if ($filter !== '' && stripos((string) $route['name'], $filter) === false) {
                continue;
            }

            if ($onlyMissing && $implemented) {
                continue;
            }

            if ($onlyPublic && $permission !== null) {
                continue;
            }

            $rows[] = [
                (string) $route['method'],
                (string) $route['path'],
                (string) $route['name'],
                $permission ?? '-',
                $implemented ? 'yes' : '501',
            ];
        }

        if ($input->boolOption('json')) {
            $output->json([
                'total'    => $router->count(),
                'missing'  => $missing,
                'unprotected' => $unauthorised,
                'prefix'   => $this->kernel->config()->apiPrefix(),
                'routes'   => array_map(
                    static fn (array $row): array => [
                        'method'     => $row[0],
                        'path'       => $row[1],
                        'name'       => $row[2],
                        'permission' => $row[3] === '-' ? null : $row[3],
                        'implemented' => $row[4] === 'yes',
                    ],
                    $rows,
                ),
            ]);

            return Kernel::SUCCESS;
        }

        $output->title('API routes');

        $output->definitions([
            'prefix'         => $this->kernel->config()->apiPrefix(),
            'routes'         => $router->count(),
            'unauthenticated' => $unauthorised,
            'not implemented' => $missing,
        ], 0);

        $output->line();
        $output->line('  ' . $output->paint(Output::DIM, sprintf(
            '%d of %d routes have no controller yet and return 501. They are still',
            $missing,
            $router->count(),
        )));
        $output->line('  ' . $output->paint(Output::DIM, 'permission-checked before the handler is resolved.'));
        $output->line();

        $output->table(['method', 'path', 'name', 'permission', 'impl'], $rows, 2);

        $output->line();

        return Kernel::SUCCESS;
    }

    private function router(): Router
    {
        $config = $this->kernel->config();

        /** @var list<array<string, mixed>> $routes */
        $routes = require $config->basePath('config/routes.php');

        return new Router($routes, $config->apiPrefix());
    }
}
