-- ============================================================================
--  CATMS — reference data
--  University of Technology and Applied Science (UTAS)
--
--  Executed by `php bin/console seed` (or `php bin/seed.php`), one statement at
--  a time through `Migrator::split()`. It is also loadable by hand:
--
--      mysql -u catms -p utas_catms < db/seed.sql
--
--  WHAT IS IN HERE, AND WHY NOT MORE
--  Departments, the room catalogue, the weekly time grid, the academic
--  calendar, courses, cohorts and the hard feature requirements. In other
--  words: everything the timetable needs to be *computable*.
--
--  NOT IN HERE:
--    * `roles`, `permissions`, `role_permissions` — those come from
--      config/rbac.php, which is the single source of truth for RBAC
--      (docs/ARCHITECTURE.md ADR-007). Two definitions would drift.
--    * `users` — a committed SQL file containing a password hash is a committed
--      credential (VULN-06). The demo accounts are created by the seed command,
--      which hashes at run time.
--    * `allocations` — a timetable is generated, never seeded. A seeded one would
--      be indistinguishable from a published one.
--
--  IDEMPOTENT
--  Every statement is an INSERT ... SELECT ... WHERE NOT EXISTS or an
--  INSERT ... ON DUPLICATE KEY UPDATE keyed on the table's natural key, so
--  re-running the seed updates the reference data instead of failing on a
--  unique key. A seed that can only run once is a seed that has to be recovered
--  from a backup when it is run twice.
--
--  THE TWO WARNINGS THIS DATA WILL PRODUCE, AND WHY THEY ARE CORRECT
--  Run `php bin/console generate --semester=... --department=...` after seeding
--  and two classes of warning appear. Both are the loader telling the truth
--  about seed data, and both disappear once a real institution loads its own
--  data:
--
--    1. "Cohort #N records enrolled_count = 120 but has 0 enrolled student
--       row(s)". The cohorts here carry realistic headcounts so that room
--       capacity is actually exercised (a 120-student cohort must land in the
--       hall, not a 30-seat seminar room), but a committed SQL file cannot
--       contain 120 real students. A deployment runs the enrolment sync from
--       the student information system; see docs/DEPLOYMENT.md §6.2.
--    2. "N day(s) ... have non-teaching dates but are still taught in at least
--       one week". The two public holidays below fall inside the teaching
--       window. A recurring weekly grid cannot represent "the Tuesday of week
--       6", so the engine leaves the weekday in place and says so. Cancelling
--       the affected week's allocation row is the correct response, and
--       --exclude-day is the blunt one (docs/ALLOCATION_ENGINE.md §4).
--
--  Tracked in docs/DATA_MODEL.md
-- ============================================================================

SET NAMES utf8mb4;

USE `utas_catms`;

-- ---------------------------------------------------------------------------
-- 1. Departments
-- ---------------------------------------------------------------------------

INSERT INTO `departments` (`code`, `name`, `faculty`, `is_active`) VALUES
  ('CS', 'Computer Science',           'Faculty of Computing and Information Sciences', 1),
  ('IT', 'Information Technology',    'Faculty of Computing and Information Sciences', 1),
  ('CE', 'Computer Engineering',       'Faculty of Engineering',                        1)
ON DUPLICATE KEY UPDATE
  `name`     = VALUES(`name`),
  `faculty`  = VALUES(`faculty`),
  `is_active` = VALUES(`is_active`);

-- ---------------------------------------------------------------------------
-- 2. Room feature catalogue
--
--    The codes are the vocabulary of BR-05. A course requires features; a room
--    provides them; HC-5 is "provides is a superset of requires". Comparison is
--    case-insensitive, so `Lab Bench` and `lab_bench` would both match — keep
--    one spelling.
-- ---------------------------------------------------------------------------

INSERT INTO `room_features` (`code`, `label`, `description`) VALUES
  ('projector',        'Data projector',        'Ceiling-mounted projector with VGA and HDMI'),
  ('whiteboard',       'Whiteboard',            'Writable surface at the front of the room'),
  ('lab_bench',        'Laboratory bench',       'Bench seating with power and data points per pair'),
  ('accessible',       'Step-free access',      'Step-free entry, and a desk within reach of the door'),
  ('ac',               'Air conditioning',      'Mechanical cooling'),
  ('video_conf',       'Video conferencing',    'Camera, microphone and a display for hybrid delivery'),
  ('tiered_seating',   'Tiered seating',        'Raked floor; sightlines improve towards the back')
ON DUPLICATE KEY UPDATE
  `label`       = VALUES(`label`),
  `description` = VALUES(`description`);

-- ---------------------------------------------------------------------------
-- 3. Rooms
--
--    BR-06: only `status = 'available'` rooms may be allocated, and the engine
--    also requires `is_bookable = 1`. Two rooms below are deliberately NOT
--    available, so a fresh instance proves the filter is doing something rather
--    than passing by accident.
--
--    Capacity spread matters: CS101-A has 120 students, and the only room that
--    can legally hold it is GH-A1. That is HC-4 doing its job.
-- ---------------------------------------------------------------------------

INSERT INTO `rooms` (`department_id`, `code`, `name`, `building`, `floor`, `capacity`, `room_type`, `status`, `is_bookable`, `notes`) VALUES
  (NULL, 'GH-A1',  'Main Auditorium',        'Great Hall',  1, 250, 'hall',     'available',     1, 'Shared. Reserved for large-cohort lectures only'),
  (NULL, 'LT-B12', 'Lecture Theatre B12',    'Block B',     2, 120, 'lecture',   'available',     1, NULL),
  (NULL, 'LT-B14', 'Lecture Theatre B14',    'Block B',     2, 120, 'lecture',   'available',     1, NULL),
  (NULL, 'LT-C02', 'Lecture Theatre C02',    'Block C',     1,  90, 'lecture',   'available',     1, NULL),
  (NULL, 'LT-C04', 'Lecture Theatre C04',    'Block C',     1,  90, 'lecture',   'available',     1, NULL),
  (NULL, 'SM-D01', 'Seminar Room D01',       'Block D',     1,  40, 'seminar',   'available',     1, NULL),
  (NULL, 'SM-D02', 'Seminar Room D02',       'Block D',     1,  40, 'seminar',   'available',     1, NULL),
  (NULL, 'SM-D03', 'Seminar Room D03',       'Block D',     2,  30, 'seminar',   'available',     1, NULL),
  (NULL, 'LB-E01', 'Computer Laboratory E01', 'Block E',    1,  60, 'lab',       'available',     1, 'Bench seating, 30 workstations'),
  (NULL, 'LB-E02', 'Computer Laboratory E02', 'Block E',    1,  60, 'lab',       'available',     1, 'Bench seating, 30 workstations'),
  (NULL, 'LB-E03', 'Computer Laboratory E03', 'Block E',    2,  48, 'lab',       'available',     1, 'Bench seating, 24 workstations'),
  (NULL, 'ST-F01', 'Studio F01',              'Block F',     1,  35, 'studio',    'available',     1, 'Raked seating, used for project work'),
  (NULL, 'VC-G01', 'Virtual Classroom G01',   'Block G',     1,  70, 'virtual',   'available',     1, 'Hybrid delivery room'),
  (NULL, 'LT-B16', 'Lecture Theatre B16',    'Block B',     2, 100, 'lecture',   'maintenance',   1, 'Ceiling repair, unavailable until further notice'),
  (NULL, 'SM-D05', 'Seminar Room D05',       'Block D',     2,  35, 'seminar',   'out_of_service',1, 'Decommissioned in 2025'),
  (NULL, 'CR-H01', 'Common Room H01',        'Block H',     1,  20, 'seminar',   'available',     0, 'Not bookable: used for student society meetings')
ON DUPLICATE KEY UPDATE
  `name`        = VALUES(`name`),
  `building`    = VALUES(`building`),
  `floor`       = VALUES(`floor`),
  `capacity`    = VALUES(`capacity`),
  `room_type`   = VALUES(`room_type`),
  `status`      = VALUES(`status`),
  `is_bookable` = VALUES(`is_bookable`),
  `notes`       = VALUES(`notes`);

-- ---------------------------------------------------------------------------
-- 4. Room features per room
--
--    `valid_from`/`valid_to` are NULL: a portable projector fitted to LT-C02
--    "from 2026-09-01" is expressible here, and the loader's JOIN honours it
--    against the semester's teaching window.
-- ---------------------------------------------------------------------------

INSERT INTO `room_feature_map` (`room_id`, `feature_id`)
SELECT r.`id`, f.`id`
  FROM `rooms` r
  JOIN `room_features` f
WHERE (r.`code` IN ('GH-A1', 'LT-B12', 'LT-B14', 'LT-C02', 'LT-C04', 'VC-G01', 'ST-F01', 'LB-E01', 'LB-E02', 'LB-E03')
       AND f.`code` = 'projector')
   OR (r.`code` IN ('LT-B12', 'LT-C02', 'SM-D01', 'ST-F01', 'VC-G01')
       AND f.`code` = 'video_conf')
   OR (r.`code` IN ('GH-A1', 'LT-B12', 'LT-B14', 'LT-C02', 'LT-C04',
                    'SM-D01', 'SM-D02', 'SM-D03', 'ST-F01', 'VC-G01')
       AND f.`code` = 'whiteboard')
   OR (r.`code` IN ('LB-E01', 'LB-E02', 'LB-E03')
       AND f.`code` IN ('lab_bench', 'whiteboard'))
   OR (r.`code` IN ('LB-E01', 'LB-E02')
       AND f.`code` = 'ac')
   OR (r.`code` IN ('GH-A1', 'LT-B12', 'ST-F01')
       AND f.`code` = 'tiered_seating')
   OR (r.`code` IN ('SM-D01', 'SM-D02', 'LT-C02', 'VC-G01')
       AND f.`code` = 'accessible')
ON DUPLICATE KEY UPDATE `feature_id` = VALUES(`feature_id`);

-- ---------------------------------------------------------------------------
-- 5. The weekly time grid
--
--    `department_id IS NULL` means institution-wide, which is how a shared grid
--    is expressed: every department sees it, and a slot id is stable across
--    semesters, so a published allocation and its warm start keep pointing at
--    the same row.
--
--    Five days, four sittings, 08:00 to 16:00 with a break between each. The
--    grid is the *shape* of a teaching week, not the calendar — which dates in
--    that week actually teach is section 6's business.
--
--    The final row is INACTIVE. It is in the grid and not teachable, which is
--    the difference `smoke` and `SchedulingProblemLoader` both count on. A slot
--    withdrawn for a term is still shown in the diagnostics of whatever can no
--    longer be placed; a slot deleted from the table is not.
--
--    NOTE ON THE STATEMENT SHAPE. This section uses WHERE NOT EXISTS rather than
--    ON DUPLICATE KEY UPDATE, and that is not a style preference.
--    `uq_slot_grid` includes `department_id`, which is NULL for an
--    institution-wide grid, and MySQL does not treat NULL as equal to NULL in a
--    unique index. Those rows can therefore never collide, so
--    ON DUPLICATE KEY UPDATE would never fire and every re-run would append a
--    second copy of the entire grid. The same trap applies to
--    `lecturer_course_assignments.cohort_id` in section 10.
-- ---------------------------------------------------------------------------

INSERT INTO `time_slots` (`department_id`, `label`, `day_of_week`, `start_time`, `end_time`, `sort_order`, `is_active`)
SELECT NULL, g.`label`, g.`day_of_week`, g.`start_time`, g.`end_time`, g.`sort_order`, g.`is_active`
  FROM (
    SELECT 'A' AS `label`, 1 AS `day_of_week`, '08:00:00' AS `start_time`, '10:00:00' AS `end_time`, 10 AS `sort_order`, 1 AS `is_active`
    UNION ALL SELECT 'B', 1, '10:20:00', '12:20:00', 20, 1
    UNION ALL SELECT 'C', 1, '14:00:00', '16:00:00', 30, 1
    UNION ALL SELECT 'A', 2, '08:00:00', '10:00:00', 10, 1
    UNION ALL SELECT 'B', 2, '10:20:00', '12:20:00', 20, 1
    UNION ALL SELECT 'C', 2, '14:00:00', '16:00:00', 30, 1
    UNION ALL SELECT 'A', 3, '08:00:00', '10:00:00', 10, 1
    UNION ALL SELECT 'B', 3, '10:20:00', '12:20:00', 20, 1
    UNION ALL SELECT 'C', 3, '14:00:00', '16:00:00', 30, 1
    UNION ALL SELECT 'A', 4, '08:00:00', '10:00:00', 10, 1
    UNION ALL SELECT 'B', 4, '10:20:00', '12:20:00', 20, 1
    UNION ALL SELECT 'C', 4, '14:00:00', '16:00:00', 30, 1
    UNION ALL SELECT 'A', 5, '08:00:00', '10:00:00', 10, 1
    UNION ALL SELECT 'B', 5, '10:20:00', '12:20:00', 20, 1
    UNION ALL SELECT 'C', 5, '14:00:00', '16:00:00', 30, 1
    UNION ALL SELECT 'D', 5, '16:20:00', '18:20:00', 40, 1
    -- Withdrawn for the 2026/2027 academic year: evening make-up sessions only.
    UNION ALL SELECT 'E', 5, '18:40:00', '20:40:00', 50, 0
  ) g
 WHERE NOT EXISTS (
       SELECT 1
         FROM `time_slots` t
        WHERE t.`department_id` IS NULL
          AND t.`day_of_week` = g.`day_of_week`
          AND t.`start_time` = g.`start_time`
          AND t.`end_time`   = g.`end_time`
 );

-- Bring the labels, the ordering and the active flag of slots that already
-- exist back in line with this file. Split out from the INSERT because
-- `is_active` is a value an administrator may legitimately change, and folding
-- it into an upsert would make re-seeding silently undo their decision.
UPDATE `time_slots` t
  JOIN (
    SELECT 'A' AS `label`, 1 AS `day_of_week`, '08:00:00' AS `start_time`, '10:00:00' AS `end_time`, 10 AS `sort_order`, 1 AS `is_active`
    UNION ALL SELECT 'B', 1, '10:20:00', '12:20:00', 20, 1
    UNION ALL SELECT 'C', 1, '14:00:00', '16:00:00', 30, 1
    UNION ALL SELECT 'A', 2, '08:00:00', '10:00:00', 10, 1
    UNION ALL SELECT 'B', 2, '10:20:00', '12:20:00', 20, 1
    UNION ALL SELECT 'C', 2, '14:00:00', '16:00:00', 30, 1
    UNION ALL SELECT 'A', 3, '08:00:00', '10:00:00', 10, 1
    UNION ALL SELECT 'B', 3, '10:20:00', '12:20:00', 20, 1
    UNION ALL SELECT 'C', 3, '14:00:00', '16:00:00', 30, 1
    UNION ALL SELECT 'A', 4, '08:00:00', '10:00:00', 10, 1
    UNION ALL SELECT 'B', 4, '10:20:00', '12:20:00', 20, 1
    UNION ALL SELECT 'C', 4, '14:00:00', '16:00:00', 30, 1
    UNION ALL SELECT 'A', 5, '08:00:00', '10:00:00', 10, 1
    UNION ALL SELECT 'B', 5, '10:20:00', '12:20:00', 20, 1
    UNION ALL SELECT 'C', 5, '14:00:00', '16:00:00', 30, 1
    UNION ALL SELECT 'D', 5, '16:20:00', '18:20:00', 40, 1
    UNION ALL SELECT 'E', 5, '18:40:00', '20:40:00', 50, 0
  ) g ON g.`day_of_week` = t.`day_of_week`
          AND g.`start_time` = t.`start_time`
          AND g.`end_time`   = t.`end_time`
   SET t.`label`      = g.`label`,
       t.`sort_order` = g.`sort_order`,
       t.`is_active`  = g.`is_active`
 WHERE t.`department_id` IS NULL;

-- ---------------------------------------------------------------------------
-- 6. Academic calendar
--
--    `semesters` is keyed per department, so CS and IT can run different
--    teaching windows. BR-07 requires every allocation to fall inside
--    [teaching_start, teaching_end]; the CHECK constraint keeps the range sane.
--
--    2026-A is the active term and the one to point --semester at. 2027-A is
--    left in `planning` so the generate command can be exercised against a
--    future term without editing this file.
-- ---------------------------------------------------------------------------

INSERT INTO `semesters`
  (`department_id`, `name`, `academic_year`, `start_date`, `end_date`,
   `registration_start`, `registration_end`, `teaching_start`, `teaching_end`,
   `exam_start`, `exam_end`, `total_weeks`, `status`)
SELECT d.`id`, s.`name`, s.`academic_year`, s.`start_date`, s.`end_date`,
       s.`registration_start`, s.`registration_end`, s.`teaching_start`, s.`teaching_end`,
       s.`exam_start`, s.`exam_end`, s.`total_weeks`, s.`status`
  FROM `departments` d
  JOIN (
    SELECT 'CS' AS `code`,
           '2026-A' AS `name`, '2026/2027' AS `academic_year`,
           '2026-09-01' AS `start_date`,  '2027-01-30' AS `end_date`,
           '2026-08-10' AS `registration_start`, '2026-08-28' AS `registration_end`,
           '2026-09-07' AS `teaching_start`, '2026-12-18' AS `teaching_end`,
           '2027-01-04' AS `exam_start`, '2027-01-23' AS `exam_end`,
           15 AS `total_weeks`, 'active' AS `status`
    UNION ALL
    SELECT 'CS', '2027-A', '2027/2028',
           '2027-02-01', '2027-06-30',
           '2027-01-11', '2027-01-29',
           '2027-02-08', '2027-05-21',
           '2027-06-07', '2027-06-25',
           15, 'planning'
  ) s ON s.`code` = d.`code`
ON DUPLICATE KEY UPDATE
  `academic_year`      = VALUES(`academic_year`),
  `start_date`         = VALUES(`start_date`),
  `end_date`           = VALUES(`end_date`),
  `registration_start` = VALUES(`registration_start`),
  `registration_end`   = VALUES(`registration_end`),
  `teaching_start`     = VALUES(`teaching_start`),
  `teaching_end`       = VALUES(`teaching_end`),
  `exam_start`         = VALUES(`exam_start`),
  `exam_end`           = VALUES(`exam_end`),
  `total_weeks`        = VALUES(`total_weeks`),
  `status`             = VALUES(`status`);

-- Non-teaching dates inside the 2026-A teaching window. HC-7 consults this
-- table, and the loader removes a weekday only when *every* occurrence of it in
-- the semester is listed here — see the second warning in the file header.
INSERT INTO `calendar_exceptions` (`semester_id`, `exception_date`, `type`, `label`)
SELECT s.`id`, e.`exception_date`, e.`type`, e.`label`
  FROM `semesters` s
  JOIN (
    SELECT '2026-09-21' AS `exception_date`, 'holiday' AS `type`, 'Kwame Nkrumah Memorial Day' AS `label`
    UNION ALL
    SELECT '2026-12-25', 'holiday', 'Christmas Day'
    UNION ALL
    SELECT '2026-12-28', 'break', 'End of semester break'
  ) e
 WHERE s.`name` = '2026-A'
ON DUPLICATE KEY UPDATE
  `type`  = VALUES(`type`),
  `label` = VALUES(`label`);

-- ---------------------------------------------------------------------------
-- 7. Courses
--
--    `meetings_per_week` drives how many sittings the loader turns a cohort
--    into, and `duration_minutes` is the length each one occupies. `level` is
--    the year of study. `preferred_building` is the W_pref soft term, so a
--    cohort that cannot be placed in its preferred building is still placed.
--
--    `default_lecturer_id` is left NULL: teaching is assigned per course and
--    per cohort in section 9, which is where an institution actually records it.
-- ---------------------------------------------------------------------------

INSERT INTO `courses`
  (`department_id`, `code`, `title`, `description`, `credit_hours`, `meetings_per_week`,
   `duration_minutes`, `level`, `preferred_building`, `is_active`)
SELECT d.`id`, c.`code`, c.`title`, c.`description`, c.`credit_hours`, c.`meetings_per_week`,
       c.`duration_minutes`, c.`level`, c.`preferred_building`, 1
  FROM `departments` d
  JOIN (
    SELECT 'CS' AS `dept`, 'CS101' AS `code`, 'Introduction to Programming' AS `title`,
           'Procedural programming, control flow, functions and arrays' AS `description`,
           3.0 AS `credit_hours`, 2 AS `meetings_per_week`, 120 AS `duration_minutes`,
           100 AS `level`, 'Block B' AS `preferred_building`
    UNION ALL
    SELECT 'CS', 'CS201', 'Data Structures and Algorithms',
           'Lists, trees, graphs, sorting and searching with complexity analysis',
           3.0, 2, 120, 200, 'Block C'
    UNION ALL
    SELECT 'CS', 'CS301', 'Database Systems',
           'Relational design, SQL, normalisation, transactions and indexing',
           3.0, 1, 120, 300, 'Block E'
    UNION ALL
    SELECT 'CS', 'CS401', 'Operating Systems',
           'Processes, scheduling, memory management and concurrency',
           3.0, 1, 120, 400, 'Block C'
    UNION ALL
    SELECT 'IT', 'IT105', 'Introduction to Information Technology',
           'Hardware, software and networking fundamentals',
           3.0, 2, 120, 100, 'Block B'
    UNION ALL
    SELECT 'IT', 'IT305', 'Web Systems Development',
           'HTTP, client-side scripting, and server-side application development',
           3.0, 2, 180, 300, 'Block E'
  ) c ON c.`dept` = d.`code`
ON DUPLICATE KEY UPDATE
  `title`              = VALUES(`title`),
  `description`        = VALUES(`description`),
  `credit_hours`       = VALUES(`credit_hours`),
  `meetings_per_week`  = VALUES(`meetings_per_week`),
  `duration_minutes`   = VALUES(`duration_minutes`),
  `level`              = VALUES(`level`),
  `preferred_building` = VALUES(`preferred_building`),
  `is_active`          = 1;

-- ---------------------------------------------------------------------------
-- 8. Hard feature requirements (BR-05 / HC-5)
--
--    `mandatory = 1` is the only value the loader reads, so every row here is a
--    hard constraint: a room without the feature cannot host the session. A
--    preference belongs in `courses.preferred_building`, which is soft.
-- ---------------------------------------------------------------------------

INSERT INTO `course_feature_requirements` (`course_id`, `feature_id`, `mandatory`)
SELECT c.`id`, f.`id`, 1
  FROM `courses` c
  JOIN `room_features` f
 WHERE (c.`code` IN ('CS101', 'CS201', 'CS401') AND f.`code` = 'projector')
    OR (c.`code` = 'CS301' AND f.`code` IN ('projector', 'lab_bench'))
    OR (c.`code` = 'IT305' AND f.`code` IN ('projector', 'lab_bench'))
ON DUPLICATE KEY UPDATE `mandatory` = VALUES(`mandatory`);

-- ---------------------------------------------------------------------------
-- 9. Cohorts
--
--    One cohort per course in the active term, plus one in each of the other
--    departments so a fresh database is not a single-department database.
--
--    `enrolled_count` is the BR-04 denominator and drives room capacity, so it
--    is realistic rather than tidy, and every cohort is sized so that the
--    default run is actually solvable. The headcount the engine uses is
--    `enrolled_count + capacity_slack`:
--
--      CS101-A  130  only the 250-seat auditorium qualifies (HC-4)
--      CS201-A   93  GH-A1, LT-B12, LT-B14 all have a projector
--      CS301-A   55  requires projector AND lab_bench: LB-E01 or LB-E02
--      CS401-A   44  any projector room of 44 seats or more
--      IT105-A  103  GH-A1, LT-B12, LT-B14
--      IT305-A   50  requires projector AND lab_bench: LB-E01 or LB-E02
--
--    CS301-A is the interesting one: a headcount above 58 would leave it with
--    no legal room at all, because LB-E03 is the smallest laboratory. That is
--    the kind of mistake a real enrolment import makes, and the engine reports
--    it as an unallocated session rather than booking a 48-seat lab for 60
--    students.
--
--    `capacity_slack` is the buffer for late registration and is added to the
--    headcount, not reported separately.
--
--    There are no `enrollments` rows, which is deliberate — see the first
--    warning in the file header.
-- ---------------------------------------------------------------------------

--    CONCAT, not `||`: PIPES_AS_CONCAT is not in MySQL 8's default sql_mode, so
--    `||` would be a logical OR here and every cohort would be named "0".
INSERT INTO `cohorts` (`department_id`, `course_id`, `semester_id`, `name`, `enrolled_count`, `capacity_slack`)
SELECT d.`id`, c.`id`, s.`id`, CONCAT(c.`code`, '-', g.`group`), g.`enrolled`, g.`slack`
  FROM `departments` d
  JOIN `semesters` s      ON s.`department_id` = d.`id` AND s.`name` = '2026-A'
  JOIN `courses` c        ON c.`department_id` = d.`id`
  JOIN (
    SELECT 'CS' AS `dept`, 'CS101' AS `course`, 'A' AS `group`, 120 AS `enrolled`, 10 AS `slack`
    UNION ALL SELECT 'CS', 'CS201', 'A',  85,  8
    UNION ALL SELECT 'CS', 'CS301', 'A',  50,  5
    UNION ALL SELECT 'CS', 'CS401', 'A',  40,  4
    UNION ALL SELECT 'IT', 'IT105', 'A',  95,  8
    UNION ALL SELECT 'IT', 'IT305', 'A',  45,  5
  ) g ON g.`course` = c.`code` AND g.`dept` = d.`code`
ON DUPLICATE KEY UPDATE
  `enrolled_count` = VALUES(`enrolled_count`),
  `capacity_slack` = VALUES(`capacity_slack`);

-- ---------------------------------------------------------------------------
-- 10. Teaching assignments
--
--    The one thing in this file that depends on `users`, and therefore the one
--    statement that needs the seed command to have created the demo accounts
--    first. It is written as INSERT ... SELECT against a subquery on the e-mail
--    address rather than a literal id, because:
--
--      * auto-increment ids are not portable between a developer's machine, a
--        CI service container and a restored production dump;
--      * if the demo accounts were not created (`--no-demo-users`, or a
--        `--reference-only` seed), the subquery matches nothing and the
--        statement inserts nothing — instead of failing on a foreign key.
--
--    A real institution has one row per lecturer, with `cohort_id` set for a
--    cohort-specific assignment and NULL for the course default.
--    `SchedulingProblemLoader::lecturers()` reads both and lets the cohort-level
--    row win.
--
--    WHERE NOT EXISTS rather than ON DUPLICATE KEY UPDATE, for the same reason
--    as section 5: `uq_lca_lecturer_course_cohort` includes the nullable
--    `cohort_id`, and a NULL never equals a NULL in a MySQL unique index, so an
--    upsert could not deduplicate these rows and would add a second assignment
--    per course on every run.
-- ---------------------------------------------------------------------------

INSERT INTO `lecturer_course_assignments` (`lecturer_id`, `course_id`, `cohort_id`, `is_primary`)
SELECT u.`id`, c.`id`, NULL, 1
  FROM `courses` c
  JOIN `users` u ON u.`email` = 'lecturer@utas.edu.gh'
 WHERE c.`code` IN ('CS101', 'CS201', 'CS301', 'CS401', 'IT105', 'IT305')
   AND NOT EXISTS (
         SELECT 1
           FROM `lecturer_course_assignments` lca
          WHERE lca.`lecturer_id` = u.`id`
            AND lca.`course_id`  = c.`id`
            AND lca.`cohort_id` IS NULL
   );

-- ---------------------------------------------------------------------------
-- 11. What a fresh instance can now do
--
--    php bin/console verify-integrity          # schema, privileges, migrations
--    php bin/console generate --semester=2026-A --department=CS --dry-run
--    php bin/console generate --semester=2026-A --department=CS
--    php bin/console generate --semester=2026-A --department=IT
--    php bin/console smoke
--
--    WHAT THE DATA LOOKS LIKE
--      3 departments · 6 courses · 6 cohorts · 10 sittings
--      17 time slots, 16 of them active
--      16 rooms, 13 of them both available and bookable
--      3 room features required by 5 courses
--      3 non-teaching dates in the 2026-A teaching window
--
--    WHAT `--department=CS` SHOULD REPORT
--      4 cohorts, 6 sittings, accuracy 1.0000 against a 0.90 gate,
--      no HC-1..HC-10 violations, no unallocated sessions.
--
--    WHAT IT WILL ALSO REPORT, AND WHY
--      A headcount warning per cohort and one about partially non-teaching
--      weekdays — the two explained at the top of this file. Both are the
--      loader being accurate about seed data. If you have edited
--      `db/seed.sql` to match a real cohort list, the first warning goes away
--      once the enrolment sync runs and writes `enrollments` rows.
-- ---------------------------------------------------------------------------
