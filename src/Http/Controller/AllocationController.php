<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Core\Exception\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Domain\Service\AllocationService;

/**
 * Sessions on the timetable, and the engine run that places them.
 */
final class AllocationController extends Controller
{
    public function index(Request $request): Response
    {
        $page = $this->pagination($request, 200, 200);
        $result = $this->allocations()->list(
            $this->identity($request),
            $this->scope($request),
            $request->queryAll(),
            $page,
        );

        return Response::success($result['items'], $this->meta($page, $result['total']));
    }

    public function generate(Request $request): Response
    {
        $input = $this->validatedRun($request, true);

        $result = $this->allocations()->generate($this->identity($request), $input);

        return Response::created($result, ['applied' => $result['applied']]);
    }

    public function repair(Request $request): Response
    {
        $input = $this->validator()->validateStrict($request->json(), [
            'semester_id'          => 'required|integer',
            'department_id'        => 'nullable|integer',
            'apply'                => 'nullable|boolean',
            'force_accuracy'       => 'nullable|boolean',
            'strict'               => 'nullable|boolean',
            'seed'                 => 'nullable|integer',
            'max_iterations'       => 'nullable|integer|min:0',
            'time_budget_seconds'  => 'nullable|numeric|max:30',
            'weight_profile'       => 'nullable|string|max:40',
            'reason'               => 'nullable|string|max:500',
            'scope'                => 'nullable|array',
        ]);

        $result = $this->allocations()->repair($this->identity($request), $input);

        return Response::created($result, ['applied' => $result['applied']]);
    }

    public function conflicts(Request $request): Response
    {
        return Response::success($this->allocations()->conflicts(
            $this->identity($request),
            $this->scope($request),
            $request->queryAll(),
        ));
    }

    public function resolveConflict(Request $request): Response
    {
        $input = $this->validator()->validateStrict($request->json(), [
            'resolution' => 'required|string|max:500',
        ]);

        return Response::success($this->allocations()->resolveConflict(
            $this->identity($request),
            $this->scope($request),
            $this->param($request, 'id'),
            (string) $input['resolution'],
        ));
    }

    public function runs(Request $request): Response
    {
        $page = $this->pagination($request);
        $result = $this->allocations()->runs($this->identity($request), $this->scope($request), $page);

        return Response::success($result['items'], $this->meta($page, $result['total']));
    }

    public function run(Request $request): Response
    {
        return Response::success($this->allocations()->run(
            $this->identity($request),
            $this->scope($request),
            $this->stringParam($request, 'runId'),
        ));
    }

    public function show(Request $request): Response
    {
        return Response::success($this->allocations()->show(
            $this->identity($request),
            $this->scope($request),
            $this->param($request, 'id'),
        ));
    }

    public function update(Request $request): Response
    {
        return Response::success($this->allocations()->update(
            $this->identity($request),
            $this->scope($request),
            $this->param($request, 'id'),
            $this->placement($request),
        ));
    }

    public function confirm(Request $request): Response
    {
        return Response::success($this->allocations()->confirm(
            $this->identity($request),
            $this->scope($request),
            $this->param($request, 'id'),
        ));
    }

    public function cancel(Request $request): Response
    {
        $input = $this->validator()->validateStrict($request->json(), [
            'reason' => 'required|string|max:500',
        ]);
        $this->allocations()->cancel(
            $this->identity($request),
            $this->scope($request),
            $this->param($request, 'id'),
            trim((string) $input['reason']),
        );

        return Response::noContent();
    }

    public function reassign(Request $request): Response
    {
        $input = $this->placement($request, true);

        return Response::success($this->allocations()->reassign(
            $this->identity($request),
            $this->scope($request),
            $this->param($request, 'id'),
            $input,
        ));
    }

    public function showConflicts(Request $request): Response
    {
        return Response::success($this->allocations()->showConflicts(
            $this->identity($request),
            $this->scope($request),
            $this->param($request, 'id'),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedRun(Request $request, bool $withMode): array
    {
        $rules = [
            'semester_id'         => 'required|integer',
            'department_id'       => 'nullable|integer',
            'execution'           => 'nullable|in:sync,async',
            'apply'               => 'nullable|boolean',
            'force_accuracy'      => 'nullable|boolean',
            'strict'              => 'nullable|boolean',
            'seed'                => 'nullable|integer',
            'max_iterations'      => 'nullable|integer|min:0',
            'time_budget_seconds' => 'nullable|numeric|max:30',
            'weight_profile'      => 'nullable|string|max:40',
            'reason'              => 'nullable|string|max:500',
            'scope'               => 'nullable|array',
        ];
        if ($withMode) {
            $rules['mode'] = 'nullable|in:full,repair';
        }

        return $this->validator()->validateStrict($request->json(), $rules);
    }

    /**
     * @return array<string, mixed>
     */
    private function placement(Request $request, bool $withRepair = false): array
    {
        $rules = [
            'room_id'      => 'nullable|integer',
            'time_slot_id' => 'nullable|integer',
            'reason'       => 'required|string|max:500',
            'force'        => 'nullable|boolean',
        ];
        if ($withRepair) {
            $rules['repair'] = 'nullable|boolean';
        }
        $input = $this->validator()->validateStrict($request->json(), $rules);
        if (trim((string) $input['reason']) === '') {
            throw ValidationException::field('reason', 'An override must record a reason (BR-08).');
        }

        return $input;
    }

    private function allocations(): AllocationService
    {
        return $this->service(AllocationService::class);
    }

    /**
     * @param array{page: int, per_page: int, offset: int} $page
     *
     * @return array<string, int>
     */
    private function meta(array $page, int $total): array
    {
        return ['page' => $page['page'], 'per_page' => $page['per_page'], 'total' => $total];
    }
}
