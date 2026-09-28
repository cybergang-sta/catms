<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Core\Request;
use App\Core\Response;

/**
 * Liveness and readiness. The only two endpoints that need no authentication.
 *
 * `/health` is what a load balancer polls, so it must answer even when the
 * application is broken — which is why it never throws, and why the database
 * check is a `SELECT 1` rather than a query that touches application tables.
 *
 * THE DISTINCTION MATTERS OPERATIONALLY
 *   - `/health` returning 503 means "take me out of the pool". A non-2xx here is
 *     the signal to stop sending traffic.
 *   - `/metrics` returning 503 means "I am up but the database is gone". Do not
 *     restart the process; restarting makes a database outage into a crash loop,
 *     and the distinction is one of the few things a monitor can act on correctly
 *     on the first try.
 */
final class HealthController extends Controller
{
    /**
     * GET /api/v1/health
     */
    public function show(Request $request): Response
    {
        $databaseUp = $this->database()->isHealthy();

        return Response::success(
            [
                'status'      => $databaseUp ? 'ok' : 'degraded',
                'db'          => $databaseUp ? 'up' : 'down',
                'service'     => 'catms',
                'api_version' => 'v1',
                'engine'      => self::engineVersion(),
                'time'        => gmdate('Y-m-d\TH:i:s\Z'),
            ],
            [],
            $databaseUp ? 200 : 503,
        );
    }

    /**
     * GET /api/v1/metrics
     *
     * Deliberately plain JSON rather than Prometheus text format: the deployment
     * target is a Compose stack, and a JSON document is readable with `curl` and
     * with `docker compose exec app php bin/console app:version` while the numbers
     * are still being established. `METRICS_ENABLED=false` shrinks the body to a
     * status flag without removing the route, so an existing monitoring check does
     * not start 404ing the moment the endpoint is quietened.
     */
    public function metrics(Request $request): Response
    {
        $databaseUp = $this->database()->isHealthy();
        $enabled = $this->config()->bool('METRICS_ENABLED', true);

        $body = [
            'enabled' => $enabled,
            'db'      => $databaseUp ? 'up' : 'down',
        ];

        if ($enabled && $databaseUp) {
            $body['allocation_runs'] = $this->recentRuns();
        }

        return Response::success($body, [], $databaseUp ? 200 : 503);
    }

    /**
     * The last few engine runs, so the >90 % accuracy gate of NFR-PERF-04 is
     * observable from outside the application.
     *
     * @return list<array<string, mixed>>
     */
    private function recentRuns(): array
    {
        $rows = $this->database()->select(
            'SELECT `id`, `total_sessions`, `assigned_sessions`, `accuracy`, `duration_ms`, `started_at`
             FROM `allocation_runs`
             ORDER BY `started_at` DESC
             LIMIT 5',
        );

        return array_map(
            static fn (array $row): array => [
                'run_id'      => (string) $row['id'],
                'total'       => (int) $row['total_sessions'],
                'assigned'    => (int) $row['assigned_sessions'],
                'accuracy'    => $row['accuracy'] === null ? null : (float) $row['accuracy'],
                'duration_ms' => (int) $row['duration_ms'],
                'started_at'  => (string) $row['started_at'],
            ],
            $rows,
        );
    }

    /**
     * Bumped whenever the allocation algorithm changes. Recorded in
     * `allocation_runs.engine_version` so a metric can be traced to the code that
     * produced it — a run is not comparable with a run from a different version,
     * and pretending otherwise is how a regression hides inside an average.
     */
    private static function engineVersion(): string
    {
        return '1.0.0';
    }
}
