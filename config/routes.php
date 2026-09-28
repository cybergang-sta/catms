<?php

declare(strict_types=1);

/**
 * The complete API route table.
 *
 * This file is the executable form of the endpoint catalogue in `docs/API.md` §5.
 * Two things follow from it being code rather than prose:
 *
 *  1. The router has exactly one list to consult, so an endpoint cannot exist in
 *     the documentation and be absent from the server, or vice versa.
 *  2. The permission on every route is declared here, next to the path, which is
 *     what makes "authorisation is server-side" (NFR-SEC-02) checkable. A
 *     controller cannot widen its own access: the middleware runs first and the
 *     handler only ever sees an identity whose permission has already been
 *     verified.
 *
 * ENTRY SHAPE
 * -----------
 *   method     string                       one of GET POST PATCH PUT DELETE
 *   path       string                       relative to the version prefix
 *   name       string                       dotted, for logs and route-model binding
 *   handler    string                       "App\Http\Controller\XController@method"
 *   permission string|null                  a key of config/rbac.php; null = public
 *   scope      string                       own | department | any  (read by repositories)
 *   throttle   string|null                  a bucket from RateLimiter
 *   middleware list<string>                 extra middleware, in order
 *
 * PUBLIC ROUTES ARE THE EXCEPTION, NOT THE RULE
 * ---------------------------------------------
 * `permission => null` appears only for health, metrics and the unauthenticated
 * half of the auth flow. Anything added here without a reason in the pull
 * request is a finding in `docs/SECURITY.md` §14.
 *
 * ROUTING NOTES
 * -------------
 *  - Paths are matched anchored, so `/allocations/7` never matches
 *    `/allocations/{id}/conflicts`.
 *  - Routes are ordered by specificity at load time: literal routes before
 *    placeholder routes. That is what makes `GET /allocations/conflicts` win
 *    over `GET /allocations/{id}` without anybody having to remember to order
 *    this file by hand.
 *  - The version prefix comes from Config::apiPrefix() (`/api/v1`). A breaking
 *    change means a new prefix, never an edit to an existing path (NFR-MAINT-04).
 *
 * @return list<array{
 *     method: string,
 *     path: string,
 *     name: string,
 *     handler: string,
 *     permission: string|null,
 *     scope: string,
 *     throttle: string|null,
 *     middleware: list<string>
 * }>
 */

$authenticated = ['RequireAuthentication'];

return [
    // =======================================================================
    // Operations — unauthenticated by design, and nothing else is
    // =======================================================================
    [
        'method'     => 'GET',
        'path'       => '/health',
        'name'       => 'health',
        'handler'    => 'App\Http\Controller\HealthController@show',
        'permission' => null,
        'scope'      => 'own',
        'throttle'   => null,
        'middleware' => [],
    ],
    [
        'method'     => 'GET',
        'path'       => '/metrics',
        'name'       => 'metrics',
        'handler'    => 'App\Http\Controller\HealthController@metrics',
        'permission' => null,
        'scope'      => 'own',
        'throttle'   => null,
        'middleware' => ['DisableWhenMaintenance'],
    ],

    // =======================================================================
    // Authentication  (FR-AUTH-01 … FR-AUTH-05)
    // =======================================================================
    [
        'method'     => 'POST',
        'path'       => '/auth/register',
        'name'       => 'auth.register',
        'handler'    => 'App\Http\Controller\AuthController@register',
        'permission' => null,
        'scope'      => 'own',
        'throttle'   => 'register',
        'middleware' => [],
    ],
    [
        'method'     => 'POST',
        'path'       => '/auth/login',
        'name'       => 'auth.login',
        'handler'    => 'App\Http\Controller\AuthController@login',
        'permission' => null,
        'scope'      => 'own',
        'throttle'   => 'login',
        'middleware' => [],
    ],
    [
        'method'     => 'POST',
        'path'       => '/auth/refresh',
        'name'       => 'auth.refresh',
        'handler'    => 'App\Http\Controller\AuthController@refresh',
        'permission' => null,
        'scope'      => 'own',
        'throttle'   => 'refresh',
        'middleware' => [],
    ],
    [
        'method'     => 'POST',
        'path'       => '/auth/logout',
        'name'       => 'auth.logout',
        'handler'    => 'App\Http\Controller\AuthController@logout',
        'permission' => null,
        'scope'      => 'own',
        'throttle'   => 'refresh',
        'middleware' => $authenticated,
    ],
    [
        'method'     => 'POST',
        'path'       => '/auth/forgot-password',
        'name'       => 'auth.forgotPassword',
        'handler'    => 'App\Http\Controller\AuthController@forgotPassword',
        'permission' => null,
        'scope'      => 'own',
        'throttle'   => 'password_reset',
        'middleware' => [],
    ],
    [
        'method'     => 'POST',
        'path'       => '/auth/reset-password',
        'name'       => 'auth.resetPassword',
        'handler'    => 'App\Http\Controller\AuthController@resetPassword',
        'permission' => null,
        'scope'      => 'own',
        'throttle'   => 'password_reset',
        'middleware' => [],
    ],
    [
        'method'     => 'GET',
        'path'       => '/auth/me',
        'name'       => 'auth.me',
        'handler'    => 'App\Http\Controller\AuthController@me',
        'permission' => null,
        'scope'      => 'own',
        'throttle'   => null,
        'middleware' => $authenticated,
    ],

    // =======================================================================
    // Profile  (FR-PROF-01, Act 843 subject access)
    // =======================================================================
    [
        'method'     => 'GET',
        'path'       => '/profile',
        'name'       => 'profile.show',
        'handler'    => 'App\Http\Controller\ProfileController@show',
        'permission' => 'profile:view_self',
        'scope'      => 'own',
        'throttle'   => null,
        'middleware' => $authenticated,
    ],
    [
        'method'     => 'PATCH',
        'path'       => '/profile',
        'name'       => 'profile.update',
        'handler'    => 'App\Http\Controller\ProfileController@update',
        'permission' => 'profile:update_self',
        'scope'      => 'own',
        'throttle'   => 'write',
        'middleware' => $authenticated,
    ],
    [
        'method'     => 'PATCH',
        'path'       => '/profile/password',
        'name'       => 'profile.updatePassword',
        'handler'    => 'App\Http\Controller\ProfileController@updatePassword',
        'permission' => 'profile:update_self',
        'scope'      => 'own',
        'throttle'   => 'write',
        'middleware' => $authenticated,
    ],

    // =======================================================================
    // User administration  (FR-ADMIN-01, FR-PROF-02, FR-PROF-03)
    // =======================================================================
    [
        'method'     => 'GET',
        'path'       => '/users',
        'name'       => 'users.index',
        'handler'    => 'App\Http\Controller\UserController@index',
        'permission' => 'user:view',
        'scope'      => 'department',
        'throttle'   => 'search',
        'middleware' => $authenticated,
    ],
    [
        'method'     => 'POST',
        'path'       => '/users',
        'name'       => 'users.store',
        'handler'    => 'App\Http\Controller\UserController@store',
        'permission' => 'user:manage',
        'scope'      => 'department',
        'throttle'   => 'write',
        'middleware' => $authenticated,
    ],
    [
        'method'     => 'GET',
        'path'       => '/users/{id}',
        'name'       => 'users.show',
        'handler'    => 'App\Http\Controller\UserController@show',
        'permission' => 'user:view',
        'scope'      => 'department',
        'throttle'   => null,
        'middleware' => $authenticated,
    ],
    [
        'method'     => 'PATCH',
        'path'       => '/users/{id}',
        'name'       => 'users.update',
        'handler'    => 'App\Http\Controller\UserController@update',
        'permission' => 'user:manage',
        'scope'      => 'department',
        'throttle'   => 'write',
        'middleware' => $authenticated,
    ],
    [
        'method'     => 'DELETE',
        'path'       => '/users/{id}',
        'name'       => 'users.destroy',
        'handler'    => 'App\Http\Controller\UserController@destroy',
        'permission' => 'user:manage',
        'scope'      => 'department',
        'throttle'   => 'write',
        'middleware' => $authenticated,
    ],
    [
        'method'     => 'PATCH',
        'path'       => '/users/{id}/role',
        'name'       => 'users.changeRole',
        'handler'    => 'App\Http\Controller\UserController@changeRole',
        'permission' => 'user:change_role',
        'scope'      => 'department',
        'throttle'   => 'write',
        'middleware' => $authenticated,
    ],
    [
        'method'     => 'GET',
        'path'       => '/users/{id}/load',
        'name'       => 'users.load',
        'handler'    => 'App\Http\Controller\UserController@load',
        'permission' => 'user:view',
        'scope'      => 'department',
        'throttle'   => null,
        'middleware' => $authenticated,
    ],

    // =======================================================================
    // Rooms  (FR-ROOM-01 … FR-ROOM-04)
    // =======================================================================
    [
        'method'     => 'GET',
        'path'       => '/rooms',
        'name'       => 'rooms.index',
        'handler'    => 'App\Http\Controller\RoomController@index',
        'permission' => 'room:view',
        'scope'      => 'department',
        'throttle'   => 'search',
        'middleware' => $authenticated,
    ],
    [
        'method'     => 'POST',
        'path'       => '/rooms',
        'name'       => 'rooms.store',
        'handler'    => 'App\Http\Controller\RoomController@store',
        'permission' => 'room:manage',
        'scope'      => 'department',
        'throttle'   => 'write',
        'middleware' => $authenticated,
    ],
    [
        'method'     => 'GET',
        'path'       => '/rooms/compare',
        'name'       => 'rooms.compare',
        'handler'    => 'App\Http\Controller\RoomController@compare',
        'permission' => 'room:view',
        'scope'      => 'department',
        'throttle'   => 'search',
        'middleware' => $authenticated,
    ],
    [
        'method'     => 'GET',
        'path'       => '/rooms/{id}',
        'name'       => 'rooms.show',
        'handler'    => 'App\Http\Controller\RoomController@show',
        'permission' => 'room:view',
        'scope'      => 'department',
        'throttle'   => null,
        'middleware' => $authenticated,
    ],
    [
        'method'     => 'PATCH',
        'path'       => '/rooms/{id}',
        'name'       => 'rooms.update',
        'handler'    => 'App\Http\Controller\RoomController@update',
        'permission' => 'room:manage',
        'scope'      => 'department',
        'throttle'   => 'write',
        'middleware' => $authenticated,
    ],
    [
        'method'     => 'DELETE',
        'path'       => '/rooms/{id}',
        'name'       => 'rooms.destroy',
        'handler'    => 'App\Http\Controller\RoomController@destroy',
        'permission' => 'room:manage',
        'scope'      => 'department',
        'throttle'   => 'write',
        'middleware' => $authenticated,
    ],
    [
        'method'     => 'GET',
        'path'       => '/rooms/{id}/availability',
        'name'       => 'rooms.availability',
        'handler'    => 'App\Http\Controller\RoomController@availability',
        'permission' => 'room:view',
        'scope'      => 'department',
        'throttle'   => null,
        'middleware' => $authenticated,
    ],

    // =======================================================================
    // Courses and cohorts  (FR-ROOM-04, FR-ADMIN-03)
    // =======================================================================
    [
        'method'     => 'GET',
        'path'       => '/courses',
        'name'       => 'courses.index',
        'handler'    => 'App\Http\Controller\CourseController@index',
        'permission' => 'course:view',
        'scope'      => 'department',
        'throttle'   => 'search',
        'middleware' => $authenticated,
    ],
    [
        'method'     => 'POST',
        'path'       => '/courses',
        'name'       => 'courses.store',
        'handler'    => 'App\Http\Controller\CourseController@store',
        'permission' => 'course:manage',
        'scope'      => 'department',
        'throttle'   => 'write',
        'middleware' => $authenticated,
    ],
    [
        'method'     => 'GET',
        'path'       => '/courses/{id}',
        'name'       => 'courses.show',
        'handler'    => 'App\Http\Controller\CourseController@show',
        'permission' => 'course:view',
        'scope'      => 'department',
        'throttle'   => null,
        'middleware' => $authenticated,
    ],
    [
        'method'     => 'PATCH',
        'path'       => '/courses/{id}',
        'name'       => 'courses.update',
        'handler'    => 'App\Http\Controller\CourseController@update',
        'permission' => 'course:manage',
        'scope'      => 'department',
        'throttle'   => 'write',
        'middleware' => $authenticated,
    ],
    [
        'method'     => 'DELETE',
        'path'       => '/courses/{id}',
        'name'       => 'courses.destroy',
        'handler'    => 'App\Http\Controller\CourseController@destroy',
        'permission' => 'course:manage',
        'scope'      => 'department',
        'throttle'   => 'write',
        'middleware' => $authenticated,
    ],
    [
        'method'     => 'GET',
        'path'       => '/courses/{id}/cohorts',
        'name'       => 'courses.cohorts',
        'handler'    => 'App\Http\Controller\CourseController@cohorts',
        'permission' => 'course:view',
        'scope'      => 'department',
        'throttle'   => null,
        'middleware' => $authenticated,
    ],
    [
        'method'     => 'POST',
        'path'       => '/cohorts',
        'name'       => 'cohorts.store',
        'handler'    => 'App\Http\Controller\CourseController@storeCohort',
        'permission' => 'cohort:manage',
        'scope'      => 'department',
        'throttle'   => 'write',
        'middleware' => $authenticated,
    ],
    [
        'method'     => 'GET',
        'path'       => '/cohorts/{id}',
        'name'       => 'cohorts.show',
        'handler'    => 'App\Http\Controller\CourseController@showCohort',
        'permission' => 'course:view',
        'scope'      => 'department',
        'throttle'   => null,
        'middleware' => $authenticated,
    ],
    [
        'method'     => 'PATCH',
        'path'       => '/cohorts/{id}',
        'name'       => 'cohorts.update',
        'handler'    => 'App\Http\Controller\CourseController@updateCohort',
        'permission' => 'cohort:manage',
        'scope'      => 'department',
        'throttle'   => 'write',
        'middleware' => $authenticated,
    ],
    [
        'method'     => 'POST',
        'path'       => '/cohorts/{id}/enrolments',
        'name'       => 'cohorts.enrol',
        'handler'    => 'App\Http\Controller\CourseController@enrol',
        'permission' => 'cohort:manage',
        'scope'      => 'department',
        'throttle'   => 'write',
        'middleware' => $authenticated,
    ],
    [
        'method'     => 'DELETE',
        'path'       => '/cohorts/{id}/enrolments/{userId}',
        'name'       => 'cohorts.unenrol',
        'handler'    => 'App\Http\Controller\CourseController@unenrol',
        'permission' => 'cohort:manage',
        'scope'      => 'department',
        'throttle'   => 'write',
        'middleware' => $authenticated,
    ],

    // =======================================================================
    // Academic calendar  (FR-CAL-01 … FR-CAL-04, FR-TIME-01/05, HC-7/HC-8)
    // =======================================================================
    [
        'method'     => 'GET',
        'path'       => '/semesters',
        'name'       => 'semesters.index',
        'handler'    => 'App\Http\Controller\CalendarController@semesters',
        'permission' => 'semester:view',
        'scope'      => 'department',
        'throttle'   => null,
        'middleware' => $authenticated,
    ],
    [
        'method'     => 'POST',
        'path'       => '/semesters',
        'name'       => 'semesters.store',
        'handler'    => 'App\Http\Controller\CalendarController@storeSemester',
        'permission' => 'semester:manage',
        'scope'      => 'department',
        'throttle'   => 'write',
        'middleware' => $authenticated,
    ],
    [
        'method'     => 'GET',
        'path'       => '/semesters/{id}',
        'name'       => 'semesters.show',
        'handler'    => 'App\Http\Controller\CalendarController@showSemester',
        'permission' => 'semester:view',
        'scope'      => 'department',
        'throttle'   => null,
        'middleware' => $authenticated,
    ],
    [
        'method'     => 'PATCH',
        'path'       => '/semesters/{id}',
        'name'       => 'semesters.update',
        'handler'    => 'App\Http\Controller\CalendarController@updateSemester',
        'permission' => 'semester:manage',
        'scope'      => 'department',
        'throttle'   => 'write',
        'middleware' => $authenticated,
    ],
    [
        'method'     => 'GET',
        'path'       => '/semesters/{id}/timeline',
        'name'       => 'semesters.timeline',
        'handler'    => 'App\Http\Controller\CalendarController@timeline',
        'permission' => 'semester:view',
        'scope'      => 'department',
        'throttle'   => null,
        'middleware' => $authenticated,
    ],
    [
        'method'     => 'GET',
        'path'       => '/semesters/{id}/exceptions',
        'name'       => 'semesters.exceptions',
        'handler'    => 'App\Http\Controller\CalendarController@exceptions',
        'permission' => 'semester:view',
        'scope'      => 'department',
        'throttle'   => null,
        'middleware' => $authenticated,
    ],
    [
        'method'     => 'POST',
        'path'       => '/semesters/{id}/exceptions',
        'name'       => 'semesters.storeException',
        'handler'    => 'App\Http\Controller\CalendarController@storeException',
        'permission' => 'semester:manage',
        'scope'      => 'department',
        'throttle'   => 'write',
        'middleware' => $authenticated,
    ],
    [
        'method'     => 'GET',
        'path'       => '/slots',
        'name'       => 'slots.index',
        'handler'    => 'App\Http\Controller\CalendarController@slots',
        'permission' => 'semester:view',
        'scope'      => 'department',
        'throttle'   => null,
        'middleware' => $authenticated,
    ],
    [
        'method'     => 'POST',
        'path'       => '/slots',
        'name'       => 'slots.store',
        'handler'    => 'App\Http\Controller\CalendarController@storeSlot',
        'permission' => 'semester:manage',
        'scope'      => 'department',
        'throttle'   => 'write',
        'middleware' => $authenticated,
    ],
    [
        'method'     => 'GET',
        'path'       => '/availability/lecturers',
        'name'       => 'availability.lecturers',
        'handler'    => 'App\Http\Controller\CalendarController@lecturerAvailability',
        'permission' => 'semester:view',
        'scope'      => 'department',
        'throttle'   => null,
        'middleware' => $authenticated,
    ],
    [
        'method'     => 'POST',
        'path'       => '/availability/lecturers',
        'name'       => 'availability.storeLecturer',
        'handler'    => 'App\Http\Controller\CalendarController@storeLecturerAvailability',
        'permission' => 'semester:view',
        'scope'      => 'department',
        'throttle'   => 'write',
        'middleware' => $authenticated,
    ],
    [
        'method'     => 'DELETE',
        'path'       => '/availability/lecturers/{id}',
        'name'       => 'availability.deleteLecturer',
        'handler'    => 'App\Http\Controller\CalendarController@deleteLecturerAvailability',
        'permission' => 'semester:view',
        'scope'      => 'department',
        'throttle'   => 'write',
        'middleware' => $authenticated,
    ],
    [
        'method'     => 'POST',
        'path'       => '/availability/rooms',
        'name'       => 'availability.storeRoom',
        'handler'    => 'App\Http\Controller\CalendarController@storeRoomUnavailability',
        'permission' => 'room:manage',
        'scope'      => 'department',
        'throttle'   => 'write',
        'middleware' => $authenticated,
    ],

    // =======================================================================
    // Allocation  (FR-ALLOC-01 … FR-ALLOC-06, FR-BOOK-01, BR-01 … BR-12)
    // =======================================================================
    [
        'method'     => 'GET',
        'path'       => '/allocations',
        'name'       => 'allocations.index',
        'handler'    => 'App\Http\Controller\AllocationController@index',
        'permission' => 'allocation:view',
        'scope'      => 'own',
        'throttle'   => 'search',
        'middleware' => $authenticated,
    ],
    [
        'method'     => 'POST',
        'path'       => '/allocations/generate',
        'name'       => 'allocations.generate',
        'handler'    => 'App\Http\Controller\AllocationController@generate',
        'permission' => 'allocation:generate',
        'scope'      => 'department',
        'throttle'   => 'generate',
        'middleware' => $authenticated,
    ],
    [
        'method'     => 'POST',
        'path'       => '/allocations/repair',
        'name'       => 'allocations.repair',
        'handler'    => 'App\Http\Controller\AllocationController@repair',
        'permission' => 'allocation:repair',
        'scope'      => 'department',
        'throttle'   => 'generate',
        'middleware' => $authenticated,
    ],
    [
        'method'     => 'GET',
        'path'       => '/allocations/conflicts',
        'name'       => 'allocations.conflicts',
        'handler'    => 'App\Http\Controller\AllocationController@conflicts',
        'permission' => 'allocation:view_conflicts',
        'scope'      => 'own',
        'throttle'   => null,
        'middleware' => $authenticated,
    ],
    [
        'method'     => 'POST',
        'path'       => '/allocations/conflicts/{id}/resolve',
        'handler'    => 'App\Http\Controller\AllocationController@resolveConflict',
        'name'       => 'allocations.resolveConflict',
        'permission' => 'allocation:override',
        'scope'      => 'department',
        'throttle'   => 'write',
        'middleware' => $authenticated,
    ],
    [
        'method'     => 'GET',
        'path'       => '/allocations/runs',
        'name'       => 'allocations.runs',
        'handler'    => 'App\Http\Controller\AllocationController@runs',
        'permission' => 'allocation:view',
        'scope'      => 'department',
        'throttle'   => null,
        'middleware' => $authenticated,
    ],
    [
        'method'     => 'GET',
        'path'       => '/allocations/runs/{runId}',
        'name'       => 'allocations.run',
        'handler'    => 'App\Http\Controller\AllocationController@run',
        'permission' => 'allocation:view',
        'scope'      => 'department',
        'throttle'   => null,
        'middleware' => $authenticated,
    ],
    [
        'method'     => 'GET',
        'path'       => '/allocations/{id}',
        'name'       => 'allocations.show',
        'handler'    => 'App\Http\Controller\AllocationController@show',
        'permission' => 'allocation:view',
        'scope'      => 'own',
        'throttle'   => null,
        'middleware' => $authenticated,
    ],
    [
        'method'     => 'PATCH',
        'path'       => '/allocations/{id}',
        'name'       => 'allocations.update',
        'handler'    => 'App\Http\Controller\AllocationController@update',
        'permission' => 'allocation:override',
        'scope'      => 'department',
        'throttle'   => 'write',
        'middleware' => $authenticated,
    ],
    [
        'method'     => 'POST',
        'path'       => '/allocations/{id}/confirm',
        'name'       => 'allocations.confirm',
        'handler'    => 'App\Http\Controller\AllocationController@confirm',
        'permission' => 'allocation:confirm',
        'scope'      => 'department',
        'throttle'   => 'write',
        'middleware' => $authenticated,
    ],
    [
        'method'     => 'POST',
        'path'       => '/allocations/{id}/cancel',
        'name'       => 'allocations.cancel',
        'handler'    => 'App\Http\Controller\AllocationController@cancel',
        'permission' => 'allocation:cancel',
        'scope'      => 'department',
        'throttle'   => 'write',
        'middleware' => $authenticated,
    ],
    [
        'method'     => 'POST',
        'path'       => '/allocations/{id}/reassign',
        'name'       => 'allocations.reassign',
        'handler'    => 'App\Http\Controller\AllocationController@reassign',
        'permission' => 'allocation:override',
        'scope'      => 'department',
        'throttle'   => 'write',
        'middleware' => $authenticated,
    ],
    [
        'method'     => 'GET',
        'path'       => '/allocations/{id}/conflicts',
        'name'       => 'allocations.showConflicts',
        'handler'    => 'App\Http\Controller\AllocationController@showConflicts',
        'permission' => 'allocation:view_conflicts',
        'scope'      => 'own',
        'throttle'   => null,
        'middleware' => $authenticated,
    ],

    // =======================================================================
    // Timetable and search  (FR-TIME-01 … FR-TIME-06, FR-SEARCH-01/02)
    // =======================================================================
    [
        'method'     => 'GET',
        'path'       => '/timetable',
        'name'       => 'timetable.week',
        'handler'    => 'App\Http\Controller\TimetableController@week',
        'permission' => 'timetable:view',
        'scope'      => 'own',
        'throttle'   => null,
        'middleware' => $authenticated,
    ],
    [
        'method'     => 'GET',
        'path'       => '/timetable/day',
        'name'       => 'timetable.day',
        'handler'    => 'App\Http\Controller\TimetableController@day',
        'permission' => 'timetable:view',
        'scope'      => 'own',
        'throttle'   => null,
        'middleware' => $authenticated,
    ],
    [
        'method'     => 'GET',
        'path'       => '/timetable/semester',
        'name'       => 'timetable.semester',
        'handler'    => 'App\Http\Controller\TimetableController@semester',
        'permission' => 'timetable:view',
        'scope'      => 'own',
        'throttle'   => null,
        'middleware' => $authenticated,
    ],
    [
        'method'     => 'GET',
        'path'       => '/timetable/{semesterId}/export.csv',
        'name'       => 'timetable.export',
        'handler'    => 'App\Http\Controller\TimetableController@export',
        'permission' => 'timetable:view',
        'scope'      => 'own',
        'throttle'   => null,
        'middleware' => $authenticated,
    ],
    [
        'method'     => 'GET',
        'path'       => '/search/rooms',
        'name'       => 'search.rooms',
        'handler'    => 'App\Http\Controller\SearchController@rooms',
        'permission' => 'room:view',
        'scope'      => 'department',
        'throttle'   => 'search',
        'middleware' => $authenticated,
    ],
    [
        'method'     => 'GET',
        'path'       => '/search/schedules',
        'name'       => 'search.schedules',
        'handler'    => 'App\Http\Controller\SearchController@schedules',
        'permission' => 'allocation:view',
        'scope'      => 'own',
        'throttle'   => 'search',
        'middleware' => $authenticated,
    ],

    // =======================================================================
    // Notifications  (FR-NOTIF-01 … FR-NOTIF-05)
    // =======================================================================
    [
        'method'     => 'GET',
        'path'       => '/notifications',
        'name'       => 'notifications.index',
        'handler'    => 'App\Http\Controller\NotificationController@index',
        'permission' => 'notification:view_self',
        'scope'      => 'own',
        'throttle'   => null,
        'middleware' => $authenticated,
    ],
    [
        'method'     => 'GET',
        'path'       => '/notifications/unread-count',
        'name'       => 'notifications.unreadCount',
        'handler'    => 'App\Http\Controller\NotificationController@unreadCount',
        'permission' => 'notification:view_self',
        'scope'      => 'own',
        'throttle'   => null,
        'middleware' => $authenticated,
    ],
    [
        'method'     => 'POST',
        'path'       => '/notifications/read-all',
        'name'       => 'notifications.readAll',
        'handler'    => 'App\Http\Controller\NotificationController@readAll',
        'permission' => 'notification:view_self',
        'scope'      => 'own',
        'throttle'   => 'write',
        'middleware' => $authenticated,
    ],
    [
        'method'     => 'POST',
        'path'       => '/notifications/{id}/read',
        'name'       => 'notifications.read',
        'handler'    => 'App\Http\Controller\NotificationController@read',
        'permission' => 'notification:view_self',
        'scope'      => 'own',
        'throttle'   => 'write',
        'middleware' => $authenticated,
    ],

    // =======================================================================
    // Reports and dashboard  (FR-REPORT-01 … FR-REPORT-03, FR-ADMIN-05/06)
    // =======================================================================
    [
        'method'     => 'GET',
        'path'       => '/reports/utilisation',
        'name'       => 'reports.utilisation',
        'handler'    => 'App\Http\Controller\ReportController@utilisation',
        'permission' => 'report:view',
        'scope'      => 'department',
        'throttle'   => null,
        'middleware' => $authenticated,
    ],
    [
        'method'     => 'GET',
        'path'       => '/reports/peak-usage',
        'name'       => 'reports.peakUsage',
        'handler'    => 'App\Http\Controller\ReportController@peakUsage',
        'permission' => 'report:view',
        'scope'      => 'department',
        'throttle'   => null,
        'middleware' => $authenticated,
    ],
    [
        'method'     => 'GET',
        'path'       => '/reports/conflicts',
        'name'       => 'reports.conflicts',
        'handler'    => 'App\Http\Controller\ReportController@conflicts',
        'permission' => 'report:view',
        'scope'      => 'department',
        'throttle'   => null,
        'middleware' => $authenticated,
    ],
    [
        'method'     => 'GET',
        'path'       => '/reports/lecturer-load',
        'name'       => 'reports.lecturerLoad',
        'handler'    => 'App\Http\Controller\ReportController@lecturerLoad',
        'permission' => 'report:view',
        'scope'      => 'department',
        'throttle'   => null,
        'middleware' => $authenticated,
    ],
    [
        'method'     => 'GET',
        'path'       => '/reports/export.csv',
        'name'       => 'reports.export',
        'handler'    => 'App\Http\Controller\ReportController@export',
        'permission' => 'report:export',
        'scope'      => 'department',
        'throttle'   => null,
        'middleware' => $authenticated,
    ],
    [
        'method'     => 'GET',
        'path'       => '/dashboard',
        'name'       => 'dashboard.summary',
        'handler'    => 'App\Http\Controller\ReportController@dashboard',
        'permission' => 'user:view',
        'scope'      => 'department',
        'throttle'   => null,
        'middleware' => $authenticated,
    ],
    [
        'method'     => 'GET',
        'path'       => '/dashboard/heat-map',
        'name'       => 'dashboard.heatMap',
        'handler'    => 'App\Http\Controller\ReportController@heatMap',
        'permission' => 'report:view',
        'scope'      => 'department',
        'throttle'   => null,
        'middleware' => $authenticated,
    ],

    // =======================================================================
    // Audit  (FR-ADMIN-07, NFR-SEC-06)
    // =======================================================================
    [
        'method'     => 'GET',
        'path'       => '/audit',
        'name'       => 'audit.index',
        'handler'    => 'App\Http\Controller\AuditController@index',
        'permission' => 'audit:view',
        'scope'      => 'department',
        'throttle'   => 'search',
        'middleware' => $authenticated,
    ],
];
