-- ============================================================================
--  CATMS — Classroom Allocation & Timetable Management System
--  University of Technology and Applied Science (UTAS)
--
--  Consolidated schema for MySQL 8.0+
--  Encoding utf8mb4 / utf8mb4_0900_ai_ci  (full Unicode: student names, course
--  titles, and any non-Latin content entered by administrators)
--
--  Conventions
--    * All timestamps are DATETIME in UTC. Rendering converts to Africa/Accra.
--    * Every tenant-scoped table carries department_id and indexes it first.
--    * No ON DELETE CASCADE on historical allocation data: status transitions
--      instead, so the audit trail stays meaningful (NFR-SEC-06).
--    * Money is not in this domain; no DECIMAL currency columns.
--
--  Tracked in docs/DATA_MODEL.md
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE DATABASE IF NOT EXISTS `utas_catms`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_0900_ai_ci;
USE `utas_catms`;

-- ---------------------------------------------------------------------------
-- 1. Identity & access
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `departments` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`          VARCHAR(20)     NOT NULL                COMMENT 'e.g. CS, IT',
  `name`          VARCHAR(150)    NOT NULL,
  `faculty`       VARCHAR(150)    NULL,
  `is_active`     TINYINT(1)      NOT NULL DEFAULT 1,
  `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_departments_code` (`code`)
) ENGINE=InnoDB;

-- Roles/permissions are data, not code (docs/ARCHITECTURE.md ADR-007).
-- Seeded from config/rbac.php so docs/SECURITY.md §3 stays the source of truth.
CREATE TABLE IF NOT EXISTS `roles` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`        VARCHAR(30)  NOT NULL COMMENT 'student | lecturer | admin',
  `label`       VARCHAR(60)  NOT NULL,
  `description` VARCHAR(255) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_roles_name` (`name`)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `permissions` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`        VARCHAR(80)  NOT NULL COMMENT 'e.g. allocation:override',
  `group_name`  VARCHAR(40)  NOT NULL,
  `description` VARCHAR(255) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_permissions_name` (`name`),
  KEY `ix_permissions_group` (`group_name`)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `role_permissions` (
  `role_id`       BIGINT UNSIGNED NOT NULL,
  `permission_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`role_id`, `permission_id`),
  KEY `ix_role_permissions_permission` (`permission_id`),
  CONSTRAINT `fk_rp_role`       FOREIGN KEY (`role_id`)       REFERENCES `roles` (`id`)       ON DELETE CASCADE,
  CONSTRAINT `fk_rp_permission` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `users` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `role_id`        BIGINT UNSIGNED NOT NULL,
  `department_id`  BIGINT UNSIGNED NULL                COMMENT 'NULL only for system-level admins',
  -- Personal
  `first_name`     VARCHAR(80)  NOT NULL,
  `last_name`      VARCHAR(80)  NOT NULL,
  `email`          VARCHAR(190) NOT NULL                COMMENT 'login identity; normalised lowercase',
  `phone`          VARCHAR(30)  NULL,
  -- Institutional identifiers
  `student_index`  VARCHAR(40)  NULL                    COMMENT 'UTAS index number; future SIS join key',
  `staff_id`       VARCHAR(40)  NULL                    COMMENT 'UTAS staff ID',
  -- Security (NFR-SEC-03: bcrypt via password_hash, never reversible)
  `password_hash`  VARCHAR(255) NOT NULL,
  `must_change_password` TINYINT(1) NOT NULL DEFAULT 0,
  `failed_login_count`   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `locked_until`         DATETIME     NULL,
  `last_login_at`        DATETIME     NULL,
  `email_verified_at`    DATETIME     NULL,
  -- State
  `status`         ENUM('pending','active','suspended','archived') NOT NULL DEFAULT 'pending',
  `avatar_url`     VARCHAR(255) NULL,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`     DATETIME NULL                         COMMENT 'soft delete; PII retained per Act 843 policy',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_email` (`email`),
  UNIQUE KEY `uq_users_student_index` (`student_index`),
  KEY `ix_users_department_role` (`department_id`, `role_id`),
  KEY `ix_users_status` (`status`),
  KEY `ix_users_name` (`last_name`, `first_name`),
  CONSTRAINT `fk_users_role`       FOREIGN KEY (`role_id`)       REFERENCES `roles` (`id`),
  CONSTRAINT `fk_users_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`)
) ENGINE=InnoDB;

-- Rotating refresh tokens. Single-use: a replay revokes the whole family
-- (NFR-SEC-01). Only the hash is stored, so a database leak cannot be replayed.
CREATE TABLE IF NOT EXISTS `refresh_tokens` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     BIGINT UNSIGNED NOT NULL,
  `family_id`   CHAR(36)     NOT NULL COMMENT 'groups a rotation chain for replay detection',
  `token_hash`  CHAR(64)     NOT NULL COMMENT 'sha256 of the opaque token',
  `user_agent`  VARCHAR(255) NULL,
  `ip_address`  VARBINARY(16) NULL,
  `expires_at`  DATETIME     NOT NULL,
  `used_at`     DATETIME     NULL                     COMMENT 'set on rotation; reuse of a used row is theft',
  `revoked_at`  DATETIME     NULL,
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_refresh_token_hash` (`token_hash`),
  KEY `ix_refresh_user` (`user_id`),
  KEY `ix_refresh_family` (`family_id`),
  KEY `ix_refresh_expiry` (`expires_at`),
  CONSTRAINT `fk_refresh_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `password_reset_tokens` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    BIGINT UNSIGNED NOT NULL,
  `token_hash` CHAR(64)     NOT NULL,
  `expires_at` DATETIME     NOT NULL,
  `used_at`    DATETIME     NULL,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pwreset_hash` (`token_hash`),
  KEY `ix_pwreset_user` (`user_id`),
  CONSTRAINT `fk_pwreset_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- 2. Academic calendar  (FR-CAL-01 … FR-CAL-04)
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `semesters` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `department_id`  BIGINT UNSIGNED NOT NULL,
  `name`           VARCHAR(40)  NOT NULL                COMMENT 'e.g. 2026-A',
  `academic_year`  VARCHAR(20)  NOT NULL                COMMENT 'e.g. 2026/2027',
  `start_date`     DATE         NOT NULL,
  `end_date`       DATE         NOT NULL,
  `registration_start` DATE     NULL,
  `registration_end`   DATE     NULL,
  `teaching_start` DATE         NOT NULL                COMMENT 'BR-07: allocations must fall inside',
  `teaching_end`   DATE         NOT NULL,
  `exam_start`     DATE         NULL,
  `exam_end`       DATE         NULL,
  `total_weeks`    SMALLINT UNSIGNED NOT NULL DEFAULT 14,
  `status`         ENUM('planning','active','closed','archived') NOT NULL DEFAULT 'planning',
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_semesters_dept_name` (`department_id`, `name`),
  KEY `ix_semesters_status` (`department_id`, `status`),
  KEY `ix_semesters_dates` (`start_date`, `end_date`),
  CONSTRAINT `fk_semesters_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`),
  CONSTRAINT `ck_semesters_range` CHECK (`end_date` >= `start_date` AND `teaching_end` >= `teaching_start`)
) ENGINE=InnoDB;

-- Days that are not teachable: public holidays, mid-semester break, exams.
-- HC-7 consults this table.
CREATE TABLE IF NOT EXISTS `calendar_exceptions` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `semester_id`    BIGINT UNSIGNED NOT NULL,
  `exception_date` DATE         NOT NULL,
  `type`           ENUM('holiday','break','exam','makeup','non_teaching') NOT NULL DEFAULT 'non_teaching',
  `label`          VARCHAR(120) NOT NULL,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_calendar_exception` (`semester_id`, `exception_date`),
  KEY `ix_calendar_date` (`exception_date`),
  CONSTRAINT `fk_calendar_semester` FOREIGN KEY (`semester_id`) REFERENCES `semesters` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Weekly recurring time grid, e.g. 08:00-10:00. Global per department, reused
-- across semesters so a slot id is stable.
CREATE TABLE IF NOT EXISTS `time_slots` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `department_id` BIGINT UNSIGNED NULL              COMMENT 'NULL = shared institution-wide grid',
  `label`       VARCHAR(40)  NOT NULL                COMMENT 'e.g. A, B, C or 08:00-10:00',
  `day_of_week` TINYINT UNSIGNED NOT NULL            COMMENT '1 = Monday … 7 = Sunday (ISO-8601)',
  `start_time`  TIME         NOT NULL,
  `end_time`    TIME         NOT NULL,
  `sort_order`  SMALLINT      NOT NULL DEFAULT 0,
  `is_active`   TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_slot_grid` (`department_id`, `day_of_week`, `start_time`, `end_time`),
  KEY `ix_slot_day` (`day_of_week`, `start_time`),
  CONSTRAINT `fk_slot_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`),
  CONSTRAINT `ck_slot_times` CHECK (`end_time` > `start_time`),
  CONSTRAINT `ck_slot_dow` CHECK (`day_of_week` BETWEEN 1 AND 7)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- 3. Courses, cohorts, rooms
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `room_features` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`        VARCHAR(30)  NOT NULL                COMMENT 'projector, whiteboard, lab, accessible, ac …',
  `label`       VARCHAR(60)  NOT NULL,
  `description` VARCHAR(255) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_room_feature_code` (`code`)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `rooms` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `department_id` BIGINT UNSIGNED NULL              COMMENT 'NULL = shared across departments',
  `code`          VARCHAR(20)  NOT NULL              COMMENT 'e.g. L1',
  `name`          VARCHAR(120) NOT NULL              COMMENT 'e.g. Computer Lab 1',
  `building`      VARCHAR(80)  NOT NULL,
  `floor`         SMALLINT      NULL,
  `capacity`      SMALLINT UNSIGNED NOT NULL         COMMENT 'BR-04: must be >= cohort enrolment',
  `room_type`     ENUM('lecture','seminar','lab','studio','hall','virtual') NOT NULL DEFAULT 'lecture',
  -- BR-06: only 'available' rooms may be allocated
  `status`        ENUM('available','maintenance','out_of_service','reserved') NOT NULL DEFAULT 'available',
  `is_bookable`   TINYINT(1)   NOT NULL DEFAULT 1,
  `notes`         TEXT         NULL,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_rooms_code` (`code`),
  KEY `ix_rooms_dept_status` (`department_id`, `status`),
  KEY `ix_rooms_capacity` (`capacity`),
  KEY `ix_rooms_building` (`building`),
  CONSTRAINT `fk_rooms_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`),
  CONSTRAINT `ck_rooms_capacity` CHECK (`capacity` > 0)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `room_feature_map` (
  `room_id`        BIGINT UNSIGNED NOT NULL,
  `feature_id`     BIGINT UNSIGNED NOT NULL,
  -- Rooms occasionally gain a feature temporarily (e.g. portable units)
  `valid_from`     DATE NULL,
  `valid_to`       DATE NULL,
  PRIMARY KEY (`room_id`, `feature_id`),
  KEY `ix_rfm_feature` (`feature_id`),
  CONSTRAINT `fk_rfm_room`    FOREIGN KEY (`room_id`)    REFERENCES `rooms` (`id`)         ON DELETE CASCADE,
  CONSTRAINT `fk_rfm_feature` FOREIGN KEY (`feature_id`) REFERENCES `room_features` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `courses` (
  `id`                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `department_id`        BIGINT UNSIGNED NOT NULL,
  `code`                 VARCHAR(20)  NOT NULL          COMMENT 'e.g. CS201',
  `title`                VARCHAR(200) NOT NULL,
  `description`          TEXT         NULL,
  `credit_hours`         DECIMAL(3,1) NOT NULL DEFAULT 3.0,
  `meetings_per_week`    TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `duration_minutes`     SMALLINT UNSIGNED NOT NULL DEFAULT 120  COMMENT 'typical single sitting',
  `level`                SMALLINT UNSIGNED NOT NULL DEFAULT 100    COMMENT '100/200/300 …',
  `default_lecturer_id`  BIGINT UNSIGNED NULL,
  `preferred_building`   VARCHAR(80)  NULL              COMMENT 'W_pref soft preference',
  `is_active`            TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_courses_dept_code` (`department_id`, `code`),
  KEY `ix_courses_dept_active` (`department_id`, `is_active`),
  KEY `ix_courses_lecturer` (`default_lecturer_id`),
  CONSTRAINT `fk_courses_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`),
  CONSTRAINT `fk_courses_lecturer`   FOREIGN KEY (`default_lecturer_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `ck_courses_meetings`   CHECK (`meetings_per_week` BETWEEN 1 AND 7),
  CONSTRAINT `ck_courses_duration`   CHECK (`duration_minutes` BETWEEN 30 AND 480)
) ENGINE=InnoDB;

-- A course's hard feature requirements (BR-05 / HC-5)
CREATE TABLE IF NOT EXISTS `course_feature_requirements` (
  `course_id`  BIGINT UNSIGNED NOT NULL,
  `feature_id` BIGINT UNSIGNED NOT NULL,
  `mandatory`  TINYINT(1) NOT NULL DEFAULT 1 COMMENT '0 = preferred only, downgraded to a soft term',
  PRIMARY KEY (`course_id`, `feature_id`),
  KEY `ix_cfr_feature` (`feature_id`),
  CONSTRAINT `fk_cfr_course`  FOREIGN KEY (`course_id`)  REFERENCES `courses` (`id`)        ON DELETE CASCADE,
  CONSTRAINT `fk_cfr_feature` FOREIGN KEY (`feature_id`) REFERENCES `room_features` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- A cohort is a group of students taking a course in a given semester.
CREATE TABLE IF NOT EXISTS `cohorts` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `department_id` BIGINT UNSIGNED NOT NULL,
  `course_id`     BIGINT UNSIGNED NOT NULL,
  `semester_id`   BIGINT UNSIGNED NOT NULL,
  `name`          VARCHAR(80)  NOT NULL              COMMENT 'e.g. CS201-A',
  `enrolled_count` SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'BR-4 denominator; kept in sync with enrollments',
  `capacity_slack` SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'buffer for late registration',
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cohorts_semester_name` (`semester_id`, `name`),
  KEY `ix_cohorts_dept_course` (`department_id`, `course_id`),
  KEY `ix_cohorts_semester` (`semester_id`),
  CONSTRAINT `fk_cohorts_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`),
  CONSTRAINT `fk_cohorts_course`     FOREIGN KEY (`course_id`)     REFERENCES `courses` (`id`)   ON DELETE CASCADE,
  CONSTRAINT `fk_cohorts_semester`   FOREIGN KEY (`semester_id`)   REFERENCES `semesters` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `enrollments` (
  `cohort_id`   BIGINT UNSIGNED NOT NULL,
  `student_id`  BIGINT UNSIGNED NOT NULL,
  `enrolled_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `status`      ENUM('enrolled','dropped','completed') NOT NULL DEFAULT 'enrolled',
  PRIMARY KEY (`cohort_id`, `student_id`),
  KEY `ix_enrollments_student` (`student_id`),
  CONSTRAINT `fk_enroll_cohort`  FOREIGN KEY (`cohort_id`)  REFERENCES `cohorts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_enroll_student` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`)  ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `lecturer_course_assignments` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `lecturer_id` BIGINT UNSIGNED NOT NULL,
  `course_id`   BIGINT UNSIGNED NOT NULL,
  `cohort_id`   BIGINT UNSIGNED NULL              COMMENT 'NULL = course-level default',
  `is_primary`  TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_lca_lecturer_course_cohort` (`lecturer_id`, `course_id`, `cohort_id`),
  KEY `ix_lca_course` (`course_id`),
  CONSTRAINT `fk_lca_lecturer` FOREIGN KEY (`lecturer_id`) REFERENCES `users` (`id`)   ON DELETE CASCADE,
  CONSTRAINT `fk_lca_course`   FOREIGN KEY (`course_id`)   REFERENCES `courses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_lca_cohort`   FOREIGN KEY (`cohort_id`)   REFERENCES `cohorts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- HC-8: explicit unavailability and daily load ceiling
CREATE TABLE IF NOT EXISTS `lecturer_availability` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `lecturer_id` BIGINT UNSIGNED NOT NULL,
  `semester_id` BIGINT UNSIGNED NOT NULL,
  `day_of_week` TINYINT UNSIGNED NULL             COMMENT 'NULL = all days of the week (whole-day block)',
  `time_slot_id` BIGINT UNSIGNED NULL            COMMENT 'NULL = the entire day',
  `date`        DATE         NULL                 COMMENT 'specific one-off date, for a single day of absence',
  `reason`      VARCHAR(150) NULL,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_avail_lecturer` (`lecturer_id`, `semester_id`),
  KEY `ix_avail_slot` (`time_slot_id`),
  KEY `ix_avail_date` (`date`),
  CONSTRAINT `fk_avail_lecturer` FOREIGN KEY (`lecturer_id`) REFERENCES `users` (`id`)       ON DELETE CASCADE,
  CONSTRAINT `fk_avail_semester` FOREIGN KEY (`semester_id`) REFERENCES `semesters` (`id`)   ON DELETE CASCADE,
  CONSTRAINT `fk_avail_slot`     FOREIGN KEY (`time_slot_id`) REFERENCES `time_slots` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- HC-9: a room blocked for maintenance for a specific slot
CREATE TABLE IF NOT EXISTS `room_unavailability` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `room_id`      BIGINT UNSIGNED NOT NULL,
  `semester_id`  BIGINT UNSIGNED NOT NULL,
  `time_slot_id` BIGINT UNSIGNED NOT NULL,
  `date`         DATE         NULL                 COMMENT 'specific occurrence within the semester',
  `reason`       VARCHAR(150) NOT NULL,
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_roomunavail_room` (`room_id`, `semester_id`),
  KEY `ix_roomunavail_slot` (`time_slot_id`),
  CONSTRAINT `fk_roomunavail_room`    FOREIGN KEY (`room_id`)      REFERENCES `rooms` (`id`)       ON DELETE CASCADE,
  CONSTRAINT `fk_roomunavail_semester` FOREIGN KEY (`semester_id`) REFERENCES `semesters` (`id`)   ON DELETE CASCADE,
  CONSTRAINT `fk_roomunavail_slot`    FOREIGN KEY (`time_slot_id`) REFERENCES `time_slots` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- 4. Scheduling — the heart of the system
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `allocations` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `department_id`  BIGINT UNSIGNED NOT NULL,
  `semester_id`    BIGINT UNSIGNED NOT NULL,
  `cohort_id`      BIGINT UNSIGNED NOT NULL,
  `course_id`      BIGINT UNSIGNED NOT NULL,
  `lecturer_id`    BIGINT UNSIGNED NOT NULL,
  `room_id`        BIGINT UNSIGNED NOT NULL,
  `time_slot_id`   BIGINT UNSIGNED NOT NULL,
  `week_number`    TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '1..total_weeks; recurring weeks share one row set',
  `effective_date` DATE         NOT NULL COMMENT 'first date this sitting occurs — the timetable anchor',
  -- FR-ALLOC-06 tracking
  `status`         ENUM('draft','proposed','confirmed','updated','cancelled') NOT NULL DEFAULT 'proposed',
  -- How this assignment came to exist
  `source`         ENUM('auto','manual','override','import') NOT NULL DEFAULT 'auto',
  `engine_score`   DECIMAL(10,4) NULL COMMENT 'total soft penalty from CostFunction',
  `score_breakdown` JSON        NULL COMMENT 'per-term penalty, so an admin can see why this room',
  -- FR-ALLOC-04 / FR-ADMIN-07 / BR-08
  `override_reason` VARCHAR(500) NULL             COMMENT 'mandatory when source = override',
  `overridden_by`   BIGINT UNSIGNED NULL,
  `cancelled_reason` VARCHAR(500) NULL,
  `cancelled_at`    DATETIME     NULL,
  `previous_room_id` BIGINT UNSIGNED NULL          COMMENT 'set on reassignment, for churn analytics',
  `notes`           TEXT         NULL,
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  -- Hot path: the week view (NFR-PERF-03)
  KEY `ix_alloc_week_view` (`department_id`, `semester_id`, `time_slot_id`, `week_number`),
  KEY `ix_alloc_room`  (`room_id`, `time_slot_id`, `week_number`, `status`),
  KEY `ix_alloc_cohort`(`cohort_id`, `time_slot_id`, `status`),
  KEY `ix_alloc_lecturer` (`lecturer_id`, `time_slot_id`, `status`),
  KEY `ix_alloc_status` (`department_id`, `status`),
  KEY `ix_alloc_date` (`effective_date`),

  -- BR-01 / ADR-006: the final, unforgeable guard against a double booking.
  -- Generated-column trick: a cancelled row contributes NULL, so it never
  -- collides and history is preserved rather than deleted.
  `active_guard` TINYINT GENERATED ALWAYS AS
      (CASE WHEN `status` IN ('proposed','confirmed','updated') THEN 1 ELSE NULL END) VIRTUAL,

  UNIQUE KEY `uq_alloc_no_double_booking` (`room_id`, `time_slot_id`, `week_number`, `active_guard`),
  UNIQUE KEY `uq_alloc_cohort_no_clash`  (`cohort_id`, `time_slot_id`, `week_number`, `active_guard`),
  UNIQUE KEY `uq_alloc_lecturer_no_clash`(`lecturer_id`, `time_slot_id`, `week_number`, `active_guard`),

  CONSTRAINT `fk_alloc_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`),
  CONSTRAINT `fk_alloc_semester`   FOREIGN KEY (`semester_id`)   REFERENCES `semesters` (`id`),
  CONSTRAINT `fk_alloc_cohort`     FOREIGN KEY (`cohort_id`)     REFERENCES `cohorts` (`id`)    ON DELETE CASCADE,
  CONSTRAINT `fk_alloc_course`     FOREIGN KEY (`course_id`)     REFERENCES `courses` (`id`),
  CONSTRAINT `fk_alloc_lecturer`   FOREIGN KEY (`lecturer_id`)   REFERENCES `users` (`id`),
  CONSTRAINT `fk_alloc_room`       FOREIGN KEY (`room_id`)       REFERENCES `rooms` (`id`),
  CONSTRAINT `fk_alloc_slot`       FOREIGN KEY (`time_slot_id`)  REFERENCES `time_slots` (`id`),
  CONSTRAINT `fk_alloc_overridden_by` FOREIGN KEY (`overridden_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_alloc_prev_room`  FOREIGN KEY (`previous_room_id`) REFERENCES `rooms` (`id`) ON DELETE SET NULL,
  CONSTRAINT `ck_alloc_week` CHECK (`week_number` >= 1)
) ENGINE=InnoDB;

-- One row per session the engine could not place, with the reason.
-- FR-ALLOC-05: unschedulable input must be reported, never hidden.
CREATE TABLE IF NOT EXISTS `allocation_conflicts` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `run_id`            CHAR(36)     NOT NULL COMMENT 'ties a whole generation together',
  `department_id`     BIGINT UNSIGNED NOT NULL,
  `semester_id`       BIGINT UNSIGNED NOT NULL,
  `cohort_id`         BIGINT UNSIGNED NOT NULL,
  `course_id`         BIGINT UNSIGNED NOT NULL,
  `severity`          ENUM('warning','error') NOT NULL DEFAULT 'error',
  `constraint_code`   VARCHAR(20)  NOT NULL COMMENT 'HC-1 … HC-10 or a domain rule id',
  `message`           VARCHAR(500) NOT NULL,
  `details`           JSON         NULL COMMENT 'feasible counts, rejected candidates, candidate rooms',
  `resolved_at`       DATETIME     NULL,
  `resolved_by`       BIGINT UNSIGNED NULL,
  `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_conflict_run` (`run_id`),
  KEY `ix_conflict_open` (`department_id`, `semester_id`, `resolved_at`),
  KEY `ix_conflict_cohort` (`cohort_id`),
  CONSTRAINT `fk_conflict_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_conflict_semester`   FOREIGN KEY (`semester_id`)   REFERENCES `semesters` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_conflict_cohort`     FOREIGN KEY (`cohort_id`)     REFERENCES `cohorts` (`id`)   ON DELETE CASCADE,
  CONSTRAINT `fk_conflict_resolved_by` FOREIGN KEY (`resolved_by`) REFERENCES `users` (`id`)  ON DELETE SET NULL
) ENGINE=InnoDB;

-- History of engine runs: metrics make the 95% accuracy figure auditable
-- (NFR-PERF-04) and make tuning decisions evidence-based.
CREATE TABLE IF NOT EXISTS `allocation_runs` (
  `id`                   CHAR(36)     NOT NULL,
  `department_id`        BIGINT UNSIGNED NOT NULL,
  `semester_id`          BIGINT UNSIGNED NOT NULL,
  `triggered_by`         BIGINT UNSIGNED NULL COMMENT 'NULL = scheduled job',
  `mode`                 ENUM('full','repair') NOT NULL DEFAULT 'full',
  `random_seed`          BIGINT UNSIGNED NOT NULL,
  `engine_version`       VARCHAR(20)  NOT NULL,
  `total_sessions`       INT UNSIGNED NOT NULL DEFAULT 0,
  `assigned_sessions`    INT UNSIGNED NOT NULL DEFAULT 0,
  `unallocated_sessions` INT UNSIGNED NOT NULL DEFAULT 0,
  `accuracy`             DECIMAL(6,4) NULL COMMENT 'assigned / total; gate is >= 0.90',
  `total_penalty`        DECIMAL(14,4) NULL,
  `iterations`           INT UNSIGNED NOT NULL DEFAULT 0,
  `duration_ms`          INT UNSIGNED NOT NULL DEFAULT 0,
  `is_feasible`          TINYINT(1) NOT NULL DEFAULT 0,
  `is_applied`           TINYINT(1) NOT NULL DEFAULT 0,
  `metrics`              JSON         NULL,
  `started_at`           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `finished_at`          DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `ix_runs_semester` (`department_id`, `semester_id`, `started_at`),
  CONSTRAINT `fk_runs_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_runs_semester`   FOREIGN KEY (`semester_id`)   REFERENCES `semesters` (`id`)   ON DELETE CASCADE,
  CONSTRAINT `fk_runs_user`       FOREIGN KEY (`triggered_by`)  REFERENCES `users` (`id`)       ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- 5. Notifications  (FR-NOTIF-01 … FR-NOTIF-05)
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `notifications` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `recipient_id`     BIGINT UNSIGNED NOT NULL,
  `department_id`    BIGINT UNSIGNED NULL,
  `type`             VARCHAR(40)  NOT NULL COMMENT 'allocation.confirmed | allocation.updated | …',
  `title`            VARCHAR(160) NOT NULL,
  `body`             TEXT         NOT NULL,
  `allocation_id`    BIGINT UNSIGNED NULL,
  `action_url`       VARCHAR(255) NULL,
  `severity`         ENUM('info','warning','critical') NOT NULL DEFAULT 'info',
  -- FR-NOTIF-04: scoped so a student can never read another student's mail
  `audience`         ENUM('student','lecturer','admin') NOT NULL DEFAULT 'student',
  `read_at`          DATETIME     NULL,
  `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_notif_inbox` (`recipient_id`, `read_at`, `created_at`),
  KEY `ix_notif_allocation` (`allocation_id`),
  KEY `ix_notif_dept_unread` (`department_id`, `read_at`),
  CONSTRAINT `fk_notif_recipient`  FOREIGN KEY (`recipient_id`)  REFERENCES `users` (`id`)      ON DELETE CASCADE,
  CONSTRAINT `fk_notif_allocation` FOREIGN KEY (`allocation_id`) REFERENCES `allocations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Transactional outbox (ADR-003). Written in the SAME transaction as the
-- allocation, so a notification can never be lost or fire for a rolled-back
-- change. Drained asynchronously by bin/worker.php.
CREATE TABLE IF NOT EXISTS `notification_outbox` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `channel`          ENUM('in_app','email','sms','push') NOT NULL DEFAULT 'in_app',
  `recipient_id`     BIGINT UNSIGNED NOT NULL,
  `notification_id`  BIGINT UNSIGNED NULL COMMENT 'set once the in-app row exists',
  `allocation_id`    BIGINT UNSIGNED NULL,
  `department_id`    BIGINT UNSIGNED NULL,
  `payload`          JSON         NOT NULL,
  `status`           ENUM('pending','processing','sent','failed','dead') NOT NULL DEFAULT 'pending',
  `attempts`         TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `max_attempts`     TINYINT UNSIGNED NOT NULL DEFAULT 5,
  `next_attempt_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_error`       VARCHAR(500) NULL,
  `processed_at`     DATETIME NULL,
  `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  -- The drain query
  KEY `ix_outbox_drain` (`status`, `next_attempt_at`),
  KEY `ix_outbox_recipient` (`recipient_id`),
  CONSTRAINT `fk_outbox_recipient`  FOREIGN KEY (`recipient_id`)  REFERENCES `users` (`id`)      ON DELETE CASCADE,
  CONSTRAINT `fk_outbox_allocation` FOREIGN KEY (`allocation_id`) REFERENCES `allocations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- 6. Audit, analytics, operations
-- ---------------------------------------------------------------------------

-- Append-only (NFR-SEC-06). Never UPDATE or DELETE except by the retention job.
CREATE TABLE IF NOT EXISTS `audit_log` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `actor_id`     BIGINT UNSIGNED NULL COMMENT 'NULL = system',
  `actor_role`   VARCHAR(30)  NULL,
  `action`       VARCHAR(80)  NOT NULL COMMENT 'allocation.override, user.role_changed, …',
  `entity_type`  VARCHAR(40)  NOT NULL,
  `entity_id`    BIGINT UNSIGNED NULL,
  `before_state` JSON         NULL,
  `after_state`  JSON         NULL,
  `ip_address`   VARBINARY(16) NULL,
  `user_agent`   VARCHAR(255) NULL,
  `request_id`   CHAR(36)     NULL,
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_audit_entity` (`entity_type`, `entity_id`, `created_at`),
  KEY `ix_audit_actor` (`actor_id`, `created_at`),
  KEY `ix_audit_action` (`action`, `created_at`)
) ENGINE=InnoDB;

-- BR-10, NFR-SCALE-04. Nightly rollup so reports never scan raw allocations.
CREATE TABLE IF NOT EXISTS `room_utilisation_daily` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `room_id`         BIGINT UNSIGNED NOT NULL,
  `department_id`   BIGINT UNSIGNED NOT NULL,
  `semester_id`     BIGINT UNSIGNED NOT NULL,
  `stat_date`       DATE         NOT NULL,
  `booked_minutes`  INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'sum of allocated slot durations',
  `available_minutes` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'teachable minutes in the window',
  `utilisation_pct` DECIMAL(6,2) NOT NULL DEFAULT 0 COMMENT 'BR-10: booked / available * 100',
  `seat_hours`      DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'enrolled * hours, for the equity term',
  `session_count`   INT UNSIGNED NOT NULL DEFAULT 0,
  `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_util_room_date` (`room_id`, `stat_date`),
  KEY `ix_util_dept_date` (`department_id`, `stat_date`),
  KEY `ix_util_semester` (`semester_id`, `stat_date`),
  CONSTRAINT `fk_util_room`       FOREIGN KEY (`room_id`)       REFERENCES `rooms` (`id`)     ON DELETE CASCADE,
  CONSTRAINT `fk_util_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`),
  CONSTRAINT `fk_util_semester`   FOREIGN KEY (`semester_id`)   REFERENCES `semesters` (`id`)   ON DELETE CASCADE
) ENGINE=InnoDB;

-- Distributed rate limiting, shared across all workers (NFR-SEC-05).
CREATE TABLE IF NOT EXISTS `rate_limit_buckets` (
  `bucket_key`   VARCHAR(190) NOT NULL COMMENT 'e.g. login:203.0.113.7',
  `window_start` DATETIME NOT NULL,
  `hits`         INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`bucket_key`, `window_start`),
  KEY `ix_ratelimit_sweep` (`window_start`)
) ENGINE=InnoDB;

-- Login/auth events feed the dashboard and the security review.
CREATE TABLE IF NOT EXISTS `security_events` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     BIGINT UNSIGNED NULL,
  `event`       VARCHAR(50) NOT NULL COMMENT 'login.failed, token.replay, password.reset …',
  `severity`    ENUM('info','warning','critical') NOT NULL DEFAULT 'info',
  `ip_address`  VARBINARY(16) NULL,
  `user_agent`  VARCHAR(255) NULL,
  `metadata`    JSON         NULL,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_secevent_user` (`user_id`, `created_at`),
  KEY `ix_secevent_type` (`event`, `created_at`),
  CONSTRAINT `fk_secevent_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- 7. Reporting views
-- ---------------------------------------------------------------------------

-- BR-10: classroom utilisation, per room per day within a semester.
CREATE OR REPLACE VIEW `v_room_utilisation` AS
SELECT
  r.`id`            AS `room_id`,
  r.`code`          AS `room_code`,
  r.`name`          AS `room_name`,
  r.`building`      AS `building`,
  r.`capacity`      AS `capacity`,
  a.`department_id` AS `department_id`,
  a.`semester_id`   AS `semester_id`,
  a.`week_number`   AS `week_number`,
  ts.`day_of_week`  AS `day_of_week`,
  COUNT(DISTINCT a.`id`)                                          AS `session_count`,
  COALESCE(SUM(TIME_TO_SEC(TIMEDIFF(ts.`end_time`, ts.`start_time`)) / 60), 0) AS `booked_minutes`,
  COALESCE(SUM(c.`enrolled_count` *
      (TIME_TO_SEC(TIMEDIFF(ts.`end_time`, ts.`start_time`)) / 3600)), 0)    AS `seat_hours`
FROM `allocations` a
JOIN `rooms`      r  ON r.`id` = a.`room_id`
JOIN `time_slots` ts ON ts.`id` = a.`time_slot_id`
JOIN `cohorts`    c  ON c.`id` = a.`cohort_id`
WHERE a.`status` IN ('proposed','confirmed','updated')
GROUP BY r.`id`, a.`department_id`, a.`semester_id`, a.`week_number`, ts.`day_of_week`, ts.`id`;

-- Peak demand by day and hour band — report §3.7.9 / §3.3(v).
CREATE OR REPLACE VIEW `v_peak_usage` AS
SELECT
  a.`department_id` AS `department_id`,
  a.`semester_id`   AS `semester_id`,
  ts.`day_of_week`  AS `day_of_week`,
  HOUR(ts.`start_time`) AS `start_hour`,
  COUNT(DISTINCT a.`id`)        AS `session_count`,
  COUNT(DISTINCT a.`room_id`)   AS `rooms_used`,
  COUNT(DISTINCT a.`cohort_id`) AS `cohorts_scheduled`,
  SUM(c.`enrolled_count`)       AS `total_students`
FROM `allocations` a
JOIN `time_slots` ts ON ts.`id` = a.`time_slot_id`
JOIN `cohorts`    c  ON c.`id` = a.`cohort_id`
WHERE a.`status` IN ('proposed','confirmed','updated')
GROUP BY a.`department_id`, a.`semester_id`, ts.`day_of_week`, HOUR(ts.`start_time`);

-- Lecturer teaching load — report §3.7.9 / figure 4.11 (FR-PROF-03).
CREATE OR REPLACE VIEW `v_lecturer_load` AS
SELECT
  u.`id`            AS `lecturer_id`,
  CONCAT(u.`first_name`, ' ', u.`last_name`) AS `lecturer_name`,
  a.`department_id` AS `department_id`,
  a.`semester_id`   AS `semester_id`,
  COUNT(DISTINCT a.`id`)  AS `session_count`,
  COUNT(DISTINCT co.`id`) AS `distinct_cohorts`,
  SUM(co.`enrolled_count`) AS `students_taught`,
  COUNT(DISTINCT a.`time_slot_id`) AS `distinct_slots`
FROM `allocations` a
JOIN `users`   u  ON u.`id` = a.`lecturer_id`
JOIN `cohorts` co ON co.`id` = a.`cohort_id`
WHERE a.`status` IN ('proposed','confirmed','updated')
GROUP BY u.`id`, a.`department_id`, a.`semester_id`;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================================
--  Verification queries — run after seeding; all must return the expected shape
-- ============================================================================
--
-- 1. No double bookings (must return 0 rows) — BR-01
-- SELECT room_id, time_slot_id, week_number, COUNT(*) c
-- FROM allocations WHERE status IN ('proposed','confirmed','updated')
-- GROUP BY room_id, time_slot_id, week_number HAVING c > 1;
--
-- 2. Capacity never violated (must return 0 rows) — BR-04
-- SELECT a.id FROM allocations a JOIN rooms r ON r.id = a.room_id
-- JOIN cohorts c ON c.id = a.cohort_id
-- WHERE a.status IN ('proposed','confirmed','updated') AND r.capacity < c.enrolled_count;
--
-- 3. Lecturer double-booked (must return 0 rows) — BR-02
-- SELECT lecturer_id, time_slot_id, week_number, COUNT(*) c FROM allocations
-- WHERE status IN ('proposed','confirmed','updated')
-- GROUP BY lecturer_id, time_slot_id, week_number HAVING c > 1;
--
-- 4. Required features missing (must return 0 rows) — BR-05
-- SELECT DISTINCT a.id FROM allocations a
-- JOIN course_feature_requirements cfr ON cfr.course_id = a.course_id AND cfr.mandatory = 1
-- LEFT JOIN room_feature_map rfm ON rfm.room_id = a.room_id AND rfm.feature_id = cfr.feature_id
-- WHERE a.status IN ('proposed','confirmed','updated') AND rfm.room_id IS NULL;
--
-- 5. Allocation accuracy per run (NFR-PERF-04 gate: accuracy >= 0.90)
-- SELECT id, total_sessions, assigned_sessions, accuracy, duration_ms
-- FROM allocation_runs WHERE is_applied = 1 ORDER BY started_at DESC LIMIT 10;
-- ============================================================================
