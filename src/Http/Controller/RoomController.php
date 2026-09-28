<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Core\Exception\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Domain\Service\RoomService;

/**
 * Classrooms: catalogue, comparison, and which slots are still free.
 */
final class RoomController extends Controller
{
    public function index(Request $request): Response
    {
        $page = $this->pagination($request);
        $result = $this->rooms()->list($this->identity($request), $this->scope($request), $request->queryAll(), $page);

        return Response::success($result['items'], [
            'page'     => $page['page'],
            'per_page' => $page['per_page'],
            'total'    => $result['total'],
        ]);
    }

    public function store(Request $request): Response
    {
        $input = $this->validator()->validateStrict($request->json(), [
            'code'          => 'required|string|max:20',
            'name'          => 'required|string|max:120',
            'building'      => 'required|string|max:80',
            'floor'         => 'nullable|integer',
            'capacity'      => 'required|integer|min:1',
            'room_type'     => 'nullable|in:lecture,seminar,lab,studio,hall,virtual',
            'status'        => 'nullable|in:available,maintenance,out_of_service,reserved',
            'is_bookable'   => 'nullable|boolean',
            'notes'         => 'nullable|string|max:2000',
            'department_id' => 'nullable|integer',
            'features'      => 'nullable|array',
        ]);

        return Response::created($this->rooms()->create(
            $this->identity($request),
            $input,
            $this->codes($input['features'] ?? []),
        ));
    }

    public function compare(Request $request): Response
    {
        $ids = $this->idList($request->query('ids'));
        if ($ids === []) {
            throw ValidationException::field('ids', 'Pass at least one room id.');
        }

        return Response::success($this->rooms()->compare(
            $this->identity($request),
            $this->scope($request),
            $ids,
            $request->queryInt('semester_id'),
            $request->queryInt('week_number'),
        ));
    }

    public function show(Request $request): Response
    {
        return Response::success($this->rooms()->show(
            $this->identity($request),
            $this->scope($request),
            $this->param($request, 'id'),
        ));
    }

    public function update(Request $request): Response
    {
        $input = $this->validator()->validateStrict($request->json(), [
            'code'        => 'nullable|string|max:20',
            'name'        => 'nullable|string|max:120',
            'building'    => 'nullable|string|max:80',
            'floor'       => 'nullable|integer',
            'capacity'    => 'nullable|integer|min:1',
            'room_type'   => 'nullable|in:lecture,seminar,lab,studio,hall,virtual',
            'status'      => 'nullable|in:available,maintenance,out_of_service,reserved',
            'is_bookable' => 'nullable|boolean',
            'notes'       => 'nullable|string|max:2000',
            'features'    => 'nullable|array',
        ]);
        $features = array_key_exists('features', $input) ? $this->codes($input['features']) : null;

        return Response::success($this->rooms()->update(
            $this->identity($request),
            $this->scope($request),
            $this->param($request, 'id'),
            $input,
            $features,
        ));
    }

    public function destroy(Request $request): Response
    {
        $this->rooms()->delete($this->identity($request), $this->scope($request), $this->param($request, 'id'));

        return Response::noContent();
    }

    public function availability(Request $request): Response
    {
        $semester = $request->queryInt('semester_id');
        $week = $request->queryInt('week_number', 1) ?? 1;
        if ($semester === null) {
            throw ValidationException::field('semester_id', 'A semester is required.');
        }

        return Response::success($this->rooms()->availability(
            $this->identity($request),
            $this->scope($request),
            $this->param($request, 'id'),
            $semester,
            $week,
        ));
    }

    private function rooms(): RoomService
    {
        return $this->service(RoomService::class);
    }

    /**
     * @return list<string>
     */
    private function codes(mixed $features): array
    {
        if (!is_array($features)) {
            return [];
        }
        $codes = [];
        foreach ($features as $feature) {
            if (is_string($feature) && $feature !== '') {
                $codes[] = $feature;
            }
        }

        return $codes;
    }

    /**
     * @return list<int>
     */
    private function idList(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return [];
        }
        $ids = [];
        foreach (explode(',', $raw) as $part) {
            if (preg_match('/^[1-9][0-9]*$/', trim($part)) === 1) {
                $ids[] = (int) trim($part);
            }
        }

        return $ids;
    }
}
