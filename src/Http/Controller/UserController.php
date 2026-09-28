<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Core\Request;
use App\Core\Response;
use App\Domain\Service\AccountService;

/**
 * User administration. A caller only sees the department their scope allows.
 */
final class UserController extends Controller
{
    public function index(Request $request): Response
    {
        $page = $this->pagination($request);
        $result = $this->accounts()->listUsers($this->identity($request), $this->scope($request), $request->queryAll(), $page);

        return Response::success($result['items'], $this->meta($page, $result['total']));
    }

    public function store(Request $request): Response
    {
        $input = $this->validator()->validateStrict($request->json(), [
            'email'         => 'required|email|max:190',
            'role'          => 'required|in:student,lecturer,admin',
            'first_name'    => 'required|string|max:80',
            'last_name'     => 'required|string|max:80',
            'phone'         => 'nullable|string|max:30',
            'student_index' => 'nullable|string|max:40',
            'staff_id'      => 'nullable|string|max:40',
            'department_id' => 'nullable|integer',
        ]);

        return Response::created($this->accounts()->createUser($this->identity($request), $input));
    }

    public function show(Request $request): Response
    {
        return Response::success(
            $this->accounts()->showUser($this->identity($request), $this->scope($request), $this->param($request, 'id')),
        );
    }

    public function update(Request $request): Response
    {
        $input = $this->validator()->validateStrict($request->json(), [
            'first_name'    => 'nullable|string|max:80',
            'last_name'     => 'nullable|string|max:80',
            'phone'         => 'nullable|string|max:30',
            'status'        => 'nullable|in:pending,active,suspended,archived',
            'staff_id'      => 'nullable|string|max:40',
            'student_index' => 'nullable|string|max:40',
        ]);

        return Response::success($this->accounts()->updateUser(
            $this->identity($request),
            $this->scope($request),
            $this->param($request, 'id'),
            $input,
        ));
    }

    public function destroy(Request $request): Response
    {
        $this->accounts()->archiveUser(
            $this->identity($request),
            $this->scope($request),
            $this->param($request, 'id'),
        );

        return Response::noContent();
    }

    public function changeRole(Request $request): Response
    {
        $input = $this->validator()->validateStrict($request->json(), [
            'role' => 'required|in:student,lecturer,admin',
        ]);

        return Response::success($this->accounts()->changeRole(
            $this->identity($request),
            $this->scope($request),
            $this->param($request, 'id'),
            (string) $input['role'],
        ));
    }

    public function load(Request $request): Response
    {
        return Response::success($this->accounts()->lecturerLoad(
            $this->identity($request),
            $this->scope($request),
            $this->param($request, 'id'),
        ));
    }

    private function accounts(): AccountService
    {
        return $this->service(AccountService::class);
    }

    /**
     * @param array{page: int, per_page: int, offset: int} $page
     *
     * @return array<string, int>
     */
    private function meta(array $page, int $total): array
    {
        return [
            'page'     => $page['page'],
            'per_page' => $page['per_page'],
            'total'    => $total,
        ];
    }
}
