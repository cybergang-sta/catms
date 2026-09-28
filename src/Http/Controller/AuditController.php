<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Core\Request;
use App\Core\Response;
use App\Domain\Service\ReportService;

/**
 * The append-only audit trail. Read only.
 */
final class AuditController extends Controller
{
    public function index(Request $request): Response
    {
        $page = $this->pagination($request);
        $result = $this->service(ReportService::class)->audit($request->queryAll(), $page);

        return Response::success($result['items'], [
            'page'     => $page['page'],
            'per_page' => $page['per_page'],
            'total'    => $result['total'],
        ]);
    }
}
