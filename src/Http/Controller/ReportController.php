<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Core\Request;
use App\Core\Response;
use App\Domain\Service\ReportService;

/**
 * Utilisation, load, conflicts, and the department dashboard.
 */
final class ReportController extends Controller
{
    public function utilisation(Request $request): Response
    {
        return Response::success($this->reports()->utilisation(
            $this->identity($request),
            $this->scope($request),
            $request->queryAll(),
        ));
    }

    public function peakUsage(Request $request): Response
    {
        return Response::success($this->reports()->peakUsage(
            $this->identity($request),
            $this->scope($request),
            $request->queryAll(),
        ));
    }

    public function conflicts(Request $request): Response
    {
        return Response::success($this->reports()->conflicts(
            $this->identity($request),
            $this->scope($request),
            $request->queryAll(),
        ));
    }

    public function lecturerLoad(Request $request): Response
    {
        return Response::success($this->reports()->lecturerLoad(
            $this->identity($request),
            $this->scope($request),
            $request->queryAll(),
        ));
    }

    public function export(Request $request): Response
    {
        $kind = (string) ($request->query('report', 'utilisation') ?? 'utilisation');
        $export = $this->reports()->export(
            $this->identity($request),
            $this->scope($request),
            $kind,
            $request->queryAll(),
        );

        return Response::csv('catms-' . $kind . '.csv', $export['header'], $export['rows']);
    }

    public function dashboard(Request $request): Response
    {
        return Response::success($this->reports()->dashboard($this->identity($request), $this->scope($request)));
    }

    public function heatMap(Request $request): Response
    {
        return Response::success($this->reports()->heatMap(
            $this->identity($request),
            $this->scope($request),
            $request->queryAll(),
        ));
    }

    private function reports(): ReportService
    {
        return $this->service(ReportService::class);
    }
}
