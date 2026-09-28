<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Core\Exception\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Domain\Service\CourseService;

/**
 * Courses, the cohorts that take them, and enrolment.
 */
final class CourseController extends Controller
{
    public function index(Request $request): Response
    {
        $page = $this->pagination($request);
        $result = $this->courses()->list($this->identity($request), $this->scope($request), $request->queryAll(), $page);

        return Response::success($result['items'], [
            'page' => $page['page'], 'per_page' => $page['per_page'], 'total' => $result['total'],
        ]);
    }

    public function store(Request $request): Response
    {
        $input = $this->validator()->validateStrict($request->json(), [
            'code'                => 'required|string|max:20',
            'title'               => 'required|string|max:200',
            'description'         => 'nullable|string|max:4000',
            'credit_hours'        => 'nullable|numeric',
            'meetings_per_week'   => 'nullable|integer|min:1|max:7',
            'duration_minutes'    => 'nullable|integer|min:30|max:480',
            'level'               => 'nullable|integer',
            'default_lecturer_id' => 'nullable|integer',
            'preferred_building'  => 'nullable|string|max:80',
            'department_id'       => 'nullable|integer',
            'features'            => 'nullable|array',
        ]);

        return Response::created($this->courses()->create($this->identity($request), $input));
    }

    public function show(Request $request): Response
    {
        return Response::success($this->courses()->show(
            $this->identity($request),
            $this->scope($request),
            $this->param($request, 'id'),
        ));
    }

    public function update(Request $request): Response
    {
        $input = $this->validator()->validateStrict($request->json(), [
            'code'                => 'nullable|string|max:20',
            'title'               => 'nullable|string|max:200',
            'description'         => 'nullable|string|max:4000',
            'credit_hours'        => 'nullable|numeric',
            'meetings_per_week'   => 'nullable|integer|min:1|max:7',
            'duration_minutes'    => 'nullable|integer|min:30|max:480',
            'level'               => 'nullable|integer',
            'default_lecturer_id' => 'nullable|integer',
            'preferred_building'  => 'nullable|string|max:80',
            'is_active'           => 'nullable|boolean',
            'features'            => 'nullable|array',
        ]);

        return Response::success($this->courses()->update(
            $this->identity($request),
            $this->scope($request),
            $this->param($request, 'id'),
            $input,
        ));
    }

    public function destroy(Request $request): Response
    {
        $this->courses()->delete($this->identity($request), $this->scope($request), $this->param($request, 'id'));

        return Response::noContent();
    }

    public function cohorts(Request $request): Response
    {
        return Response::success($this->courses()->cohorts(
            $this->identity($request),
            $this->scope($request),
            $this->param($request, 'id'),
        ));
    }

    public function storeCohort(Request $request): Response
    {
        $input = $this->validator()->validateStrict($request->json(), [
            'course_id'      => 'required|integer',
            'semester_id'    => 'required|integer',
            'name'           => 'required|string|max:80',
            'lecturer_id'    => 'nullable|integer',
            'capacity_slack' => 'nullable|integer|min:0',
        ]);

        return Response::created($this->courses()->createCohort($this->identity($request), $input));
    }

    public function showCohort(Request $request): Response
    {
        return Response::success($this->courses()->showCohort(
            $this->identity($request),
            $this->scope($request),
            $this->param($request, 'id'),
        ));
    }

    public function updateCohort(Request $request): Response
    {
        $input = $this->validator()->validateStrict($request->json(), [
            'name'           => 'nullable|string|max:80',
            'capacity_slack' => 'nullable|integer|min:0',
        ]);

        return Response::success($this->courses()->updateCohort(
            $this->identity($request),
            $this->scope($request),
            $this->param($request, 'id'),
            $input,
        ));
    }

    public function enrol(Request $request): Response
    {
        $input = $this->validator()->validateStrict($request->json(), [
            'student_ids' => 'required|array',
        ]);
        $ids = [];
        foreach ($input['student_ids'] as $id) {
            if (is_numeric($id)) {
                $ids[] = (int) $id;
            }
        }
        if ($ids === []) {
            throw ValidationException::field('student_ids', 'Pass at least one student id.');
        }

        $added = $this->courses()->enrol(
            $this->identity($request),
            $this->scope($request),
            $this->param($request, 'id'),
            $ids,
        );

        return Response::success(['enrolled' => $added]);
    }

    public function unenrol(Request $request): Response
    {
        $this->courses()->unenrol(
            $this->identity($request),
            $this->scope($request),
            $this->param($request, 'id'),
            $this->param($request, 'userId'),
        );

        return Response::noContent();
    }

    private function courses(): CourseService
    {
        return $this->service(CourseService::class);
    }
}
