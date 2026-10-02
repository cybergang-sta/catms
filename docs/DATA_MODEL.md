# Data Model

The persistence design for the classroom allocation and timetable system: what
is stored, why, and which decisions are irreversible.

Companion documents: [`REQUIREMENTS.md`](REQUIREMENTS.md) for the requirements
these tables serve, [`ARCHITECTURE.md`](ARCHITECTURE.md) for the layers that
read and write them, [`SECURITY.md`](SECURITY.md) for the retention and
encryption rules.

**The schema is [`db/schema.sql`](../db/schema.sql).** This document explains it;
it is not a second copy of it. Where the two disagree, the SQL is correct and
this document is a bug.

---

## Table of Contents

1. [Design Rules](#1-design-rules)
2. [Entity Map](#2-entity-map)
3. [Reference Data](#3-reference-data)
4. [Identity and Access](#4-identity-and-access)
5. [Rooms and Features](#5-rooms-and-features)
6. [Academic Structure](#6-academic-structure)
7. [The Timetable](#7-the-timetable)
8. [Allocation Runs and Conflicts](#8-allocation-runs-and-conflicts)
9. [Notifications](#9-notifications)
10. [Audit and Security Telemetry](#10-audit-and-security-telemetry)
11. [Reporting Views](#11-reporting-views)
12. [Deletion and Retention](#12-deletion-and-retention)
13. [Migrations](#13-migrations)
14. [Data Volume](#14-data-volume)

---

## 1. Design Rules

Five rules, applied in order when a decision is contested.

**1. Never store a derived timetable.** `allocations` is the timetable.
Everything else — utilisation, conflicts, reports — is derived from it. A
materialised summary that can disagree with its source is a bug waiting for a
reporting cycle.

**2. Additive history, not overwrite.** A row is never updated in place to
represent "what changed". Timetable changes are new rows in `allocations_runs`
plus status transitions on `allocations`. The cancelled row stays, because the
question "what was published in week 3" has to remain answerable.

**3. Stable machine-readable codes.** `constraint_code` holds `HC-1` … `HC-10`,
never a message string. Messages are for humans and get reworded; codes are
written to `allocation_conflicts` and read by reports, so they are part of the
contract. Renaming one needs a migration and a note in the release.

**4. The database is the last line of defence, not the first.** The engine
guarantees validity, and so does the schema. A unique index cannot be bypassed
by a code path that forgot to call the engine, which is precisely the failure
worth defending against.

**5. Personal data is minimised and expires.** The Ghana Data Protection Act,
2012 (Act 843) applies. Names and email addresses are needed; national ID,
address and date of birth are not, and are not stored. See
[`SECURITY.md`](SECURITY.md) §4.

---

## 2. Entity Map

```
  departments ──┬──< users ──< refresh_tokens
                │      └──< audit_log
                │
                ├──< rooms ──< room_feature_map >── room_features
                │      └──< room_unavailability
                │
                └──< courses ──< course_feature_requirements >── room_features
                       │         └──< lecturer_course_assignments >── users
                       │
   cohorts ──< enrollments        time_slots
      │                          calendar_exceptions
      │                                 │
      └──────────────┬──────────────────┘
                     │
              allocations ──┬── allocation_conflicts
                             └── (allocation_runs)

  allocation_runs ──< notifications ──< notification_outbox
  rate_limit_buckets        security_events          semesters
```

Solid lines are foreign keys. The dotted line into `allocations` is
`allocation_runs.id`: a run is a *parent* of the rows it produced, and deleting a
run cascades — an allocation without a run has no provenance and cannot be
explained to an auditor.

---

## 3. Reference Data

### `departments`

The unit that owns rooms, courses and cohorts. Every other row that could be
shared between departments carries either a `department_id` or a `shared` flag.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | `INT UNSIGNED` PK | |
| `code` | `VARCHAR(16)` UNIQUE | e.g. `CSC` |
| `name` | `VARCHAR(120)` | |
| `created_at` | `TIMESTAMP` | |

### `semesters`

Scopes every timetable. Without it, "the timetable" is ambiguous and last
year's rows are indistinguishable from this year's.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | `INT UNSIGNED` PK | |
| `name` | `VARCHAR(64)` | e.g. `2026/27 Sem 1` |
| `starts_on` / `ends_on` | `DATE` | |
| `is_published` | `TINYINT(1)` | Gates whether students can see a timetable |
| `teaching_window` | `JSON` | Which days and hours are teachable (HC-7) |

`teaching_window` is JSON because its shape varies by institution and
versioning a normalised alternative for one field is not worth it. It is
validated on write by `CalendarValidator`; MySQL is not asked to understand it.

### `calendar_exceptions`

Public holidays, exam weeks, teaching breaks. A row here removes a slot from
`teaching_window` for a specific date range, which is why HC-7 is expressed
against a calendar rather than a boolean.

### `time_slots`

The recurring weekly grid. `day_of_week` is ISO-8601 (1 = Monday … 7 = Sunday) —
the same convention as [`Room::capacityBand()`](../src/Domain/Allocation/Room.php)
neighbours, and the reason `SchedulingProblem` needs no date arithmetic.

| Column | Type | Notes |
| --- | --- | --- |
| `day_of_week` | `TINYINT UNSIGNED` | 1–7, CHECK constrained |
| `start_time` / `end_time` | `TIME` | |
| `active` | `TINYINT(1)` | An inactive slot is invisible to the engine (HC-7) |

---

## 4. Identity and Access

### `users`, `roles`, `permissions`, `role_permissions`

Standard four-table RBAC. The indirection through `permissions` exists so a new
capability can be granted without a code deploy — required, because the
first support request after go-live will always be "let the scheduler see room
utilisation but not edit rooms".

`users.department_id` is NULL for staff with cross-department visibility. A
non-NULL value is the *home* department, used as the default scope for
department-scoped queries; it is not a limit. Limiting is `roles` plus
`scope` on the role.

### `refresh_tokens`

Opaque, hashed, single-use, with `expires_at` and `revoked_at`. Only the hash is
stored: a database dump must not yield usable sessions, which is the actual
threat under Act 843 §31.

### `password_reset_tokens`

Same, with a single-use constraint and a short expiry. Tokens are compared in
constant time to avoid a timing oracle.

---

## 5. Rooms and Features

### `rooms`

| Column | Type | Notes |
| --- | --- | --- |
| `code` | `VARCHAR(24)` UNIQUE | Human-facing; appears in every conflict message |
| `capacity` | `SMALLINT UNSIGNED` | Hard floor for HC-4 |
| `status` | `ENUM` | `available`, `maintenance`, `closed` |
| `bookable` | `TINYINT(1)` | A second, independent gate — see below |
| `department_id` | `INT UNSIGNED` NULL | |
| `shared` | `TINYINT(1)` | Overrides department scope (HC-10) |

**Why `status` and `bookable` are separate.** A room can be physically open but
administratively reserved for a department (`bookable = 0`) while still being
listed and browsable. Collapsing them into one enum would make "hidden" and
"unusable" indistinguishable, and the room list would either show rooms nobody
may book or hide rooms the scheduler needs to see.

### `room_features` and `room_feature_map`

Features are rows, not a comma-separated column, because `room_feature_map` is
the join that answers "which courses need lab benches, and do we have enough of
them?" — the question behind the allocation of a whole department's practical
sessions. Case-insensitive comparison is enforced in
[`RoomFeatures`](../src/Domain/Allocation/RoomFeatures.php), not by the
collation, so a `utf8mb4_0900_ai_ci` collation change cannot silently alter
which rooms satisfy HC-5.

### `room_unavailability`

Per-room, per-slot maintenance windows (HC-9). Distinct from `room_unavailability`
as a status: a room is simultaneously usable in 40 slots and blocked in 3, which
a single status column cannot express.

---

## 6. Academic Structure

`courses` → `cohorts` → `enrollments`, and `courses` → `lecturer_course_assignments`
→ `users`. The engine reads all of these into a `SchedulingProblem` and never
touches them again; that read is the boundary described in
[`ARCHITECTURE.md`](ARCHITECTURE.md) §5.

`enrollments` is a row per student, not a count on `cohorts`, because
`enrolled_count` drives HC-4 and the waste term, and both need to reflect who has
actually registered — including students who dropped last week. `cohorts.enrolled_count`
is a denormalised cache maintained on enrolment change, used only for display and
never for a hard-constraint decision.

---

## 7. The Timetable

### `allocations`

One row per (cohort, room, time slot) placement. **This is the timetable.**

| Column | Type | Notes |
| --- | --- | --- |
| `id` | `BIGINT UNSIGNED` PK | |
| `semester_id` | `INT UNSIGNED` | |
| `session_id` | `INT UNSIGNED` | A sitting, not a course — see below |
| `cohort_id` | `INT UNSIGNED` | Denormalised for the conflict report |
| `room_id` | `INT UNSIGNED` | |
| `time_slot_id` | `INT UNSIGNED` | |
| `allocation_run_id` | `BIGINT UNSIGNED` | Provenance; cascades with the run |
| `status` | `ENUM` | `proposed`, `confirmed`, `updated`, `cancelled` |
| `score` | `DECIMAL(10,4)` | The weighted penalty |
| `score_breakdown` | `JSON` | Seven weighted terms, for FR-ALLOC-04 |
| `source` | `ENUM` | `engine`, `manual_override`, `import` |

**`session_id`, not `course_id`.** A course meeting twice a week is two
*sessions*, and HC-3 must be able to say "this cohort already has a class then"
while HC-2 says "this lecturer is already teaching". Pointing at the course
would make both constraints ambiguous.

**`score_breakdown` is JSON on purpose.** It is written once, read by one screen,
and never queried. A normalised seven-column breakdown would be seven columns to
migrate every time a term is added, for no query that anyone actually runs.

### The double-booking guard

This is the most important constraint in the schema.

```sql
active_guard TINYINT
    GENERATED ALWAYS AS (
        CASE WHEN status IN ('proposed','confirmed','updated') THEN 1 ELSE NULL END
    ) VIRTUAL,

UNIQUE KEY uq_room_slot_active  (room_id, time_slot_id, active_guard),
UNIQUE KEY uq_cohort_slot_active (cohort_id, time_slot_id, active_guard),
UNIQUE KEY uq_lecturer_slot_active (lecturer_id, time_slot_id, active_guard)
```

Three facts about this design are worth stating plainly.

**It works because MySQL treats NULLs as distinct in a unique index.** A
cancelled row evaluates `active_guard` to NULL, so it never collides with
anything, including another cancelled row. A plain `UNIQUE (room_id,
time_slot_id)` would make it impossible to *keep* history: the second cancelled
booking for the same room and slot would be rejected.

**A partial index is not available in MySQL.** The generated column is the
standard workaround, and it costs one virtual column per row.

**`lecturer_id` is denormalised onto `allocations`.** It duplicates data owned by
`lecturer_course_assignments`, and it is denormalised anyway: the unique index
cannot span a join, and the check has to happen in the database, on every write,
from every code path. This is the one place in the schema where duplication is
the right answer.

Note what this does *not* cover: capacity (HC-4) and features (HC-5) are
per-row properties, not uniqueness properties, and cannot be expressed as an
index. The engine is the only thing enforcing them — which is why
`CapacityTest` is a property test rather than a spot check.

---

## 8. Allocation Runs and Conflicts

### `allocation_runs`

One row per generation, holding the input parameters and the result metrics.
The weights and seed are recorded because a timetable is only reproducible if the
configuration that produced it is recoverable — six months later, when a student
asks why they were moved.

| Column | Notes |
| --- | --- |
| `status` | `running`, `completed`, `failed` |
| `weights` | JSON snapshot of `CostWeights` at run time |
| `random_seed` | |
| `metrics` | JSON: accuracy, penalty, iterations, duration_ms |
| `started_at` / `finished_at` | |

### `allocation_conflicts`

What the engine could not do, and why. Written on every run with a non-empty
`unallocated` list — this is the implementation of FR-ALLOC-05.

| Column | Notes |
| --- | --- |
| `constraint_code` | `HC-1` … `HC-10`; indexed |
| `detail` | The human-readable message |
| `session_id` / `room_id` / `time_slot_id` | NULL where not applicable |
| `occurrences` | How many combinations this constraint eliminated |

`occurrences` is what turns "it did not work" into "it did not work because
these 34 combinations were all rejected by HC-1".

---

## 9. Notifications

### `notifications`

What a user is shown. Rows are retained after reading — a student disputing an
allocation six weeks later needs the message that told them.

### `notification_outbox`

A transactional outbox. The row is written in the *same transaction* as the
change that caused it, and a worker delivers it afterwards.

This is the whole reason the system meets the 3-second response target
(NFR-PERF-01). An SMTP provider that takes four seconds cannot be allowed to sit
inside the request, and retrying inside the request would turn a transient
failure into a failed timetable generation. The trade is that delivery is
eventually consistent: a notification may arrive seconds after the change the
user is looking at. The alternative — synchronous delivery — has a worse failure
mode, and it is the one that loses you the allocation.

---

## 10. Audit and Security Telemetry

### `audit_log`

Who did what to which record, when, from where. Append-only; there is no UPDATE
path in the application. Covers manual overrides (FR-ADMIN-07), which must carry
a reason — see [`SECURITY.md`](SECURITY.md) §3.

### `security_events`

Failed logins, rate-limit trips, token reuse, privilege escalation attempts.
Separate from `audit_log` because the two have different volumes, different
retention, and different readers: `audit_log` is a compliance record, this is
operational telemetry.

### `rate_limit_buckets`

Counter buckets in MySQL rather than Redis, because the deployment target is a
single host and one fewer moving part is worth more than the throughput.

---

## 11. Reporting Views

| View | Answers |
| --- | --- |
| `v_room_utilisation` | Seats committed ÷ seats available, per room per week |
| `v_peak_usage` | Which slots are at capacity — the input to adding a room |
| `v_lecturer_load` | Sessions per lecturer per day, against the HC-8 ceiling |

These are views, not tables, per rule 1. They are noticeably slower than a
materialised table would be on a large `allocations`, and that is the correct
trade: a stale utilisation figure is worse than a slow one.

---

## 12. Deletion and Retention

| Data | Retained | Basis |
| --- | --- | --- |
| Users, cohorts, courses | Indefinite | Academic record |
| `allocations` (cancelled) | Indefinite | Audit of what was published |
| `audit_log` | 7 years | Act 843 § retention for processing records |
| `refresh_tokens` | Until expiry + 30 days | Revocation must be provable |
| `password_reset_tokens` | 30 days | Forensic value only |
| `security_events` | 1 year | Detects slow, low-volume attacks |
| `rate_limit_buckets` | 24 hours | Self-cleaning |

Personal data is deleted on request under Act 843 §14, except where retention is
required — an audit record of *that a user did something* is retained, the
content of the message is not.

This table is the specification; the code follows it. `RetentionCommand` reads
its windows from exactly these numbers, and `php bin/console retention --status`
prints both the window it *would* apply and the `NEVER_PURGED` set — the tables
above are the only ones with a window, and everything else is excluded by
construction rather than by an omission someone can forget. The command is also
the only process permitted to `DELETE` from `audit_log` (it runs as
`catms_maint`), and it writes a `system.retention_purge` audit entry on every
run, including a run that deleted nothing — silence about a purge is
indistinguishable from a purge that silently failed.

---

## 13. Migrations and Seed Data

`db/migrations/` holds ordered **PHP** files named
`YYYY_MM_DD_HHMMSS_name.php`. Each returns an anonymous class implementing
`App\Infrastructure\Persistence\Migration\Migration`, with `name()`,
`up(Database $database)` and `down(Database $database)`. `bin/migrate.php` (or
`php bin/console migrate`) applies them and records each in
`schema_migrations`, under `GET_LOCK('catms_migrations', 30)` so two deploys
cannot race. Rollback happens by batch.

Two ship:

| File | Purpose |
| --- | --- |
| `2026_08_01_090000_baseline.php` | Executes `db/schema.sql` — all 29 tables, 3 views, 5 footer verification queries |
| `2026_08_01_091500_audit_log_grants.php` | `GRANT SELECT, INSERT` on `utas_catms.audit_log` and nothing else (VULN-14) |

Rules:

- **`up()` must be idempotent.** Re-running an applied migration has to be a
  no-op. DDL in MySQL cannot be rolled back, so a file that fails halfway leaves
  a partial schema with no way back; making it repeatable is the only recovery
  route that does not need a restore.
- **`down()` is a courtesy, not a safety net.** It exists to un-apply a migration
  in local development and to roll back a release by batch. A destructive change
  is a new forward migration plus a documented manual step — an automated
  rollback that drops a column is a worse outcome than a manual one.
- **One concern per file.** A migration that adds a column and rewrites history
  cannot be reviewed.
- **Never edit an applied migration.** Every environment's ledger then
  disagrees with every other environment's, and that disagreement is discovered
  in production.
- **`db/schema.sql` is the baseline, and is not hand-edited** after
  `2026_08_01_090000_baseline.php` has been applied anywhere. Subsequent changes
  are new migration files.

### 13.1 `db/seed.sql` — reference data

Demo reference data is a **committed SQL file, not a migration**, because it is
data and should stay reviewable as data. `bin/console seed` runs it through the
same statement splitter as the schema, inside the single transaction that also
installs RBAC and the demo users, and it is written to be re-runnable.

It contains the reference statements: 3 departments, 7 room features, 22 rooms,
`room_feature_map`, 17 time slots, CS and IT semesters, calendar exceptions,
11 courses, `course_feature_requirements`, 11 cohorts, and
`lecturer_course_assignments`. It contains **no** users, **no** roles or
permissions (those come from `config/rbac.php`), and **no** allocations.

Two things in it will bite anyone who edits it casually:

- `uq_slot_grid` and `uq_lca_lecturer_course_cohort` both contain a **nullable**
  column. MySQL treats `NULL` as distinct inside a unique index, so
  `ON DUPLICATE KEY UPDATE` never fires for a null column and re-seeding appends
  duplicates every time. Both inserts use `WHERE NOT EXISTS (...)`.
- `PIPES_AS_CONCAT` is **not** in MySQL 8's default `sql_mode`. Using `||`
  produces a logical OR, not a concatenation — every cohort would be named
  `"0"`. The file uses `CONCAT()`.

The `lecturer_course_assignments` rows are inserted through a subquery on the
demo lecturer's **e-mail** rather than an id, because auto-increment ids are not
portable across a restore and a committed SQL file cannot contain a password
hash. This is why `SeedCommand` runs RBAC, then demo users, then `db/seed.sql`,
in that order.

One row is deliberately wrong: time slot `E` has `is_active=0`. It exists so
the smoke test can prove inactive slots are filtered rather than assumed.

---

## 14. Data Volume

A department of 500 students, on the order of:

| Table | Rows | Notes |
| --- | --- | --- |
| `allocations` | ~4 000/yr | 200 sessions × 20 runs, most superseded |
| `enrollments` | ~2 000 | |
| `audit_log` | ~20 000/yr | Grows fastest; partition by year |
| `security_events` | ~50 000/yr | Self-trimming |

`allocations` and `audit_log` are the only tables expected to need yearly
partitioning, and neither until a single department is generating more than a
few million rows. Add the partition before that, not after.
