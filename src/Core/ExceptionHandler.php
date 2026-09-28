<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Exception\CatmsException;
use App\Domain\Allocation\Exception\InfeasibleProblemException;
use Throwable;

/**
 * Turns any throwable into a documented API response.
 *
 * THE ONE RULE
 * A 500 never returns the exception message, the class name, the file path or
 * the stack trace. It returns `INTERNAL_ERROR` and a `request_id`, and the
 * details go to the log where an operator can correlate them. A stack trace in an
 * API response is a map of the filesystem and of every internal class name.
 *
 * THE SECOND RULE
 * Nothing is swallowed. Every throwable is logged, including the ones that were
 * already translated into a 4xx — a validation failure nobody can reproduce
 * because the log was empty is the hardest class of bug to chase.
 *
 * WHY THE EXPLICIT MAP
 * A `catch (CatmsException $e)` alone would be shorter, but it would leave the
 * engine's InfeasibleProblemException — a documented 422 with a real
 * diagnostic payload — falling through to a 500 and losing the information an
 * administrator needs to fix the timetable (FR-ALLOC-05).
 */
final class ExceptionHandler
{
    public function __construct(
        private readonly Logger $logger,
        private readonly bool $debug = false,
    ) {
    }

    public function render(Throwable $exception, string $requestId = ''): Response
    {
        $response = $this->toResponse($exception, $requestId);

        $context = [
            'request_id' => $requestId,
            'exception'  => $exception::class,
            'status'     => $response->status(),
            'code'       => $this->codeOf($exception),
        ];

        if ($response->status() >= 500) {
            $context['message'] = $exception->getMessage();
            $context['file'] = $exception->getFile() . ':' . $exception->getLine();
            $this->logger->error('Request failed.', $context);
        } else {
            $context['message'] = $exception->getMessage();
            $this->logger->info('Request rejected.', $context);
        }

        return $this->debug ? $this->withDebugDetail($response, $exception) : $response;
    }

    private function toResponse(Throwable $exception, string $requestId): Response
    {
        $meta = $requestId === '' ? [] : ['request_id' => $requestId];

        if ($exception instanceof CatmsException) {
            return Response::error(
                $exception->status(),
                $exception->errorCode(),
                $exception->publicMessage(),
                $exception->details(),
                $meta,
            )->withHeaders($exception->headers());
        }

        if ($exception instanceof InfeasibleProblemException) {
            // The engine refused to produce a partial timetable in strict mode.
            // This is a client-visible, actionable 422, not a server fault. The
            // message names which precondition failed, which is exactly what an
            // operator needs to fix a misconfigured calendar.
            return Response::error(
                422,
                'INFEASIBLE_PROBLEM',
                $exception->getMessage(),
                ['remedy' => 'Fix the reference data, then re-run. See docs/DEPLOYMENT.md §10.'],
                $meta,
            );
        }

        return Response::error(
            500,
            'INTERNAL_ERROR',
            'An unexpected error occurred. Quote the request_id when reporting this.',
            [],
            $meta,
        );
    }

    private function codeOf(Throwable $exception): string
    {
        if ($exception instanceof CatmsException) {
            return $exception->errorCode();
        }

        if ($exception instanceof InfeasibleProblemException) {
            return 'INFEASIBLE_PROBLEM';
        }

        return 'INTERNAL_ERROR';
    }

    /**
     * Development-only. Never enabled in production: Config refuses to boot with
     * APP_DEBUG=true there, so this cannot leak in a deployed environment.
     */
    private function withDebugDetail(Response $response, Throwable $exception): Response
    {
        if ($response->status() < 500) {
            return $response;
        }

        return Response::error(
            $response->status(),
            'INTERNAL_ERROR',
            $response->decoded()['error']['message'] ?? 'An unexpected error occurred.',
            [
                'exception' => $exception::class,
                'file'      => $exception->getFile() . ':' . $exception->getLine(),
                'trace'     => array_slice(explode("\n", $exception->getTraceAsString()), 0, 20),
            ],
            $response->decoded()['meta'] ?? [],
        );
    }
}
