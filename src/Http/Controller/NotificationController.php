<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Core\Request;
use App\Core\Response;
use App\Domain\Service\NotificationService;

/**
 * The caller's own inbox. Another person's notification is a 404.
 */
final class NotificationController extends Controller
{
    public function index(Request $request): Response
    {
        $page = $this->pagination($request);
        $unread = $request->query('unread') === '1' || $request->query('unread') === 'true';
        $result = $this->notifications()->list($this->identity($request), $page, $unread);

        return Response::success($result['items'], [
            'page' => $page['page'], 'per_page' => $page['per_page'], 'total' => $result['total'],
        ]);
    }

    public function unreadCount(Request $request): Response
    {
        return Response::success($this->notifications()->unreadCount($this->identity($request)));
    }

    public function readAll(Request $request): Response
    {
        $this->notifications()->readAll($this->identity($request));

        return Response::noContent();
    }

    public function read(Request $request): Response
    {
        $this->notifications()->read($this->identity($request), $this->param($request, 'id'));

        return Response::noContent();
    }

    private function notifications(): NotificationService
    {
        return $this->service(NotificationService::class);
    }
}
