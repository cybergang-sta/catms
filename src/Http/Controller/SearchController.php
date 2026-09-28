<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Core\Exception\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Domain\Service\AllocationService;
use App\Domain\Service\RoomService;

/**
 * Cross-catalogue search. Rooms and sessions stay inside the caller's scope.
 */
final class SearchController extends Controller
{
    public function rooms(Request $request): Response
    {
        $page = $this->pagination($request);
        $result = $this->service(RoomService::class)->list(
            $this->identity($request),
            $this->scope($request),
            $request->queryAll(),
            $page,
        );

        return Response::success($result['items'], [
            'page' => $page['page'], 'per_page' => $page['per_page'], 'total' => $result['total'],
        ]);
    }

    public function schedules(Request $request): Response
    {
        $q = trim((string) $request->query('q', ''));
        if ($q === '') {
            throw ValidationException::field('q', 'Type a course code or title to search.');
        }

        $page = $this->pagination($request);
        $result = $this->service(AllocationService::class)->search(
            $this->identity($request),
            $this->scope($request),
            $request->queryAll(),
            $page,
        );

        return Response::success($result['items'], [
            'page' => $page['page'], 'per_page' => $page['per_page'], 'total' => $result['total'],
        ]);
    }
}
