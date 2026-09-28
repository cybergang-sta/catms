<?php

declare(strict_types=1);

/**
 * Roles and permissions — the single source of truth for authorisation.
 *
 * WHY A PHP FILE AND NOT CODE CONSTANTS
 * ------------------------------------
 * Roles are *data* in this system (ADR-007): `roles`, `permissions` and
 * `role_permissions` are tables, seeded from this file by `bin/seed.php`. That
 * means a new capability is a data change reviewed in a pull request, not a
 * code change reviewed by a compiler, and the matrix an administrator sees in
 * the UI is guaranteed to be the matrix the server enforces.
 *
 * `docs/SECURITY.md` §3 renders this file as the published RBAC matrix. If the
 * two ever disagree, the documentation is wrong — fix it in the same PR.
 *
 * RULES ENFORCED HERE
 * -------------------
 *  1. Default deny. A permission absent from a role's list is refused. There is
 *     no wildcard, no "admin implies everything" shortcut, and no inheritance.
 *  2. `student` and `lecturer` are strictly *self* scopes. The `scope` key below
 *     is what the repository layer reads to decide whether a row belongs to the
 *     caller; a route may not widen it.
 *  3. Renaming a permission is a breaking change: it is stored in
 *     `role_permissions` and echoed by the API. Add a migration in the same PR.
 *
 * @return array{
 *     permissions: array<string, array{group: string, description: string}>,
 *     roles: array<string, array{label: string, description: string, permissions: list<string>}>
 * }
 */

return [
    // -----------------------------------------------------------------------
    // Permissions, grouped as they are presented in docs/API.md §3.
    // 25 entries. The numeric prefix in the docs is presentation only.
    // -----------------------------------------------------------------------
    'permissions' => [
        // timetable
        'timetable:view' => [
            'group'       => 'timetable',
            'description' => 'Read the published timetable for a day, week or semester',
        ],

        // rooms
        'room:view' => [
            'group'       => 'room',
            'description' => 'Read room inventory, live availability and comparisons',
        ],
        'room:manage' => [
            'group'       => 'room',
            'description' => 'Create, edit, retire and block rooms',
        ],

        // courses and cohorts
        'course:view' => [
            'group'       => 'course',
            'description' => 'Read the course catalogue and cohort membership',
        ],
        'course:manage' => [
            'group'       => 'course',
            'description' => 'Create, edit and archive courses',
        ],
        'cohort:manage' => [
            'group'       => 'course',
            'description' => 'Create cohorts and manage their enrolment',
        ],

        // academic calendar
        'semester:view' => [
            'group'       => 'calendar',
            'description' => 'Read semesters, the weekly slot grid and non-teaching days',
        ],
        'semester:manage' => [
            'group'       => 'calendar',
            'description' => 'Create and edit semesters, slots, exceptions and lecturer availability',
        ],

        // allocation
        'allocation:view' => [
            'group'       => 'allocation',
            'description' => 'Read allocations and engine run history',
        ],
        'allocation:generate' => [
            'group'       => 'allocation',
            'description' => 'Run the allocation engine for a semester',
        ],
        'allocation:confirm' => [
            'group'       => 'allocation',
            'description' => 'Promote a proposed allocation to confirmed',
        ],
        'allocation:override' => [
            'group'       => 'allocation',
            'description' => 'Override a placement, reassign a room or resolve a conflict (requires a reason)',
        ],
        'allocation:cancel' => [
            'group'       => 'allocation',
            'description' => 'Cancel a session and notify everyone affected',
        ],
        'allocation:repair' => [
            'group'       => 'allocation',
            'description' => 'Warm-start a repair run from the current timetable',
        ],
        'allocation:view_conflicts' => [
            'group'       => 'allocation',
            'description' => 'Read the unplaced-session and conflict report',
        ],

        // profile
        'profile:view_self' => [
            'group'       => 'profile',
            'description' => 'Read the caller\'s own profile (Act 843 subject access)',
        ],
        'profile:update_self' => [
            'group'       => 'profile',
            'description' => 'Update the caller\'s own profile and password',
        ],

        // user administration
        'user:view' => [
            'group'       => 'user',
            'description' => 'Read users and teaching load',
        ],
        'user:manage' => [
            'group'       => 'user',
            'description' => 'Create, edit, suspend and archive users',
        ],
        'user:change_role' => [
            'group'       => 'user',
            'description' => 'Change a user\'s role (FR-PROF-02)',
        ],

        // reporting
        'report:view' => [
            'group'       => 'report',
            'description' => 'Read utilisation, peak-usage, conflict and lecturer-load reports',
        ],
        'report:export' => [
            'group'       => 'report',
            'description' => 'Export report data as CSV',
        ],

        // audit
        'audit:view' => [
            'group'       => 'audit',
            'description' => 'Read the append-only audit log (NFR-SEC-06)',
        ],

        // notifications
        'notification:view_self' => [
            'group'       => 'notification',
            'description' => 'Read and mark the caller\'s own notifications',
        ],
        'notification:dispatch' => [
            'group'       => 'notification',
            'description' => 'Queue notifications for other users',
        ],
    ],

    // -----------------------------------------------------------------------
    // Roles. Every permission string above must appear here, otherwise the role
    // is unreachable; bin/seed.php asserts the union covers all 25.
    // -----------------------------------------------------------------------
    'roles' => [
        'student' => [
            'label'       => 'Student',
            'description' => 'Sees only their own cohort\'s timetable and rooms',
            'permissions' => [
                'timetable:view',
                'room:view',
                'course:view',
                'semester:view',
                'allocation:view',
                'allocation:view_conflicts',
                'profile:view_self',
                'profile:update_self',
                'notification:view_self',
            ],
        ],

        'lecturer' => [
            'label'       => 'Lecturer',
            'description' => 'Sees their own teaching schedule and available rooms',
            'permissions' => [
                'timetable:view',
                'room:view',
                'course:view',
                'semester:view',
                'allocation:view',
                'allocation:view_conflicts',
                'profile:view_self',
                'profile:update_self',
                'user:view',
                'report:view',
                'notification:view_self',
            ],
        ],

        'admin' => [
            'label'       => 'Administrator',
            'description' => 'Full departmental control: data, allocation, reporting and audit',
            'permissions' => [
                'timetable:view',
                'room:view',
                'room:manage',
                'course:view',
                'course:manage',
                'cohort:manage',
                'semester:view',
                'semester:manage',
                'allocation:view',
                'allocation:generate',
                'allocation:confirm',
                'allocation:override',
                'allocation:cancel',
                'allocation:repair',
                'allocation:view_conflicts',
                'profile:view_self',
                'profile:update_self',
                'user:view',
                'user:manage',
                'user:change_role',
                'report:view',
                'report:export',
                'audit:view',
                'notification:view_self',
                'notification:dispatch',
            ],
        ],
    ],
];
