<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Core\Request;
use App\Core\Response;
use App\Domain\Service\CalendarService;

/**
 * Semesters, the weekly grid, and the days people or rooms are unavailable.
 */
final class CalendarController extends Controller
{
    public function semesters(Request $request): Response
    {
        return Response::success($this->calendar()->semesters($this->identity($request), $this->scope($request)));
    }

    public function storeSemester(Request $request): Response
    {
        $input = $this->validator()->validateStrict($request->json(), [
            'department_id'  => 'nullable|integer',
            'name'           => 'required|string|max:40',
            'academic_year'  => 'required|string|max:20',
            'start_date'     => 'required|date',
            'end_date'       => 'required|date',
            'teaching_start' => 'nullable|date',
            'teaching_end'   => 'nullable|date',
            'total_weeks'    => 'nullable|integer|min:1|max:52',
            'status'         => 'nullable|in:planning,active,closed,archived',
        ]);

        return Response::created($this->calendar()->createSemester($this->identity($request), $input));
    }

    public function showSemester(Request $request): Response
    {
        return Response::success($this->calendar()->showSemester(
            $this->identity($request),
            $this->scope($request),
            $this->param($request, 'id'),
        ));
    }

    public function updateSemester(Request $request): Response
    {
        $input = $this->validator()->validateStrict($request->json(), [
            'name'           => 'nullable|string|max:40',
            'academic_year'  => 'nullable|string|max:20',
            'start_date'     => 'nullable|date',
            'end_date'       => 'nullable|date',
            'teaching_start' => 'nullable|date',
            'teaching_end'   => 'nullable|date',
            'total_weeks'    => 'nullable|integer|min:1|max:52',
            'status'         => 'nullable|in:planning,active,closed,archived',
        ]);

        return Response::success($this->calendar()->updateSemester(
            $this->identity($request),
            $this->scope($request),
            $this->param($request, 'id'),
            $input,
        ));
    }

    public function timeline(Request $request): Response
    {
        return Response::success($this->calendar()->timeline(
            $this->identity($request),
            $this->scope($request),
            $this->param($request, 'id'),
        ));
    }

    public function exceptions(Request $request): Response
    {
        return Response::success($this->calendar()->exceptions(
            $this->identity($request),
            $this->scope($request),
            $this->param($request, 'id'),
        ));
    }

    public function storeException(Request $request): Response
    {
        $input = $this->validator()->validateStrict($request->json(), [
            'exception_date' => 'required|date',
            'type'           => 'nullable|in:holiday,break,exam,makeup,non_teaching',
            'label'          => 'required|string|max:120',
        ]);

        return Response::created($this->calendar()->addException(
            $this->identity($request),
            $this->scope($request),
            $this->param($request, 'id'),
            $input,
        ));
    }

    public function slots(Request $request): Response
    {
        return Response::success($this->calendar()->slots($this->identity($request)));
    }

    public function storeSlot(Request $request): Response
    {
        $input = $this->validator()->validateStrict($request->json(), [
            'label'       => 'required|string|max:40',
            'day_of_week' => 'required|integer|min:1|max:7',
            'start_time'  => 'required|time',
            'end_time'    => 'required|time',
            'sort_order'  => 'nullable|integer',
        ]);

        return Response::created($this->calendar()->createSlot($this->identity($request), $input));
    }

    public function lecturerAvailability(Request $request): Response
    {
        return Response::success($this->calendar()->lecturerAvailability(
            $this->identity($request),
            $request->queryInt('semester_id'),
        ));
    }

    public function storeLecturerAvailability(Request $request): Response
    {
        $input = $this->validator()->validateStrict($request->json(), [
            'lecturer_id'  => 'nullable|integer',
            'semester_id'  => 'required|integer',
            'day_of_week'  => 'nullable|integer|min:1|max:7',
            'time_slot_id' => 'nullable|integer',
            'date'         => 'nullable|date',
            'reason'       => 'nullable|string|max:150',
        ]);

        return Response::created($this->calendar()->storeLecturerAvailability($this->identity($request), $input));
    }

    public function deleteLecturerAvailability(Request $request): Response
    {
        $this->calendar()->deleteLecturerAvailability($this->identity($request), $this->param($request, 'id'));

        return Response::noContent();
    }

    public function storeRoomUnavailability(Request $request): Response
    {
        $input = $this->validator()->validateStrict($request->json(), [
            'room_id'      => 'required|integer',
            'semester_id'  => 'required|integer',
            'time_slot_id' => 'required|integer',
            'date'         => 'nullable|date',
            'reason'       => 'required|string|max:150',
        ]);

        return Response::created($this->calendar()->storeRoomUnavailability($this->identity($request), $input));
    }

    private function calendar(): CalendarService
    {
        return $this->service(CalendarService::class);
    }
}
