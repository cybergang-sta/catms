# Implementation Guide

**How to build CATMS.** Ordered phases, each with a definition of done, so work can be
split across a team and tracked in parallel.

---

## Table of Contents

1. [Ground Rules](#1-ground-rules)
2. [Environment Setup](#2-environment-setup)
3. [Phase Plan](#3-phase-plan)
4. [Phase Details](#4-phase-details)
5. [Conventions and Standards](#5-conventions-and-standards)
6. [Working Agreements](#6-working-agreements)
7. [Definition of Done](#7-definition-of-done)
8. [Common Tasks](#8-common-tasks)
9. [Troubleshooting](#9-troubleshooting)
10. [Extension Guide](#10-extension-guide)

---

## 1. Ground Rules

These come straight from the report and constrain every decision below.

| # | Rule | Source | Consequence in practice |
| --- | --- | --- | --- |
| 1 | **Conflict-free scheduling is non-negotiable** | FR-ALLOC-02, BR-01 | Hard constraints are filters, never penalties. No "good enough" double booking. |
| 2 | **The domain layer is pure PHP** | ADR-001 | No `$_GET`, no PDO, no `echo` in `src/Domain`. The engine must run from the CLI. |
| 3 | **Authorisation is server-side** | NFR-SEC-02 | Hiding a button is not access control. |
| 4 | **Nothing blocks on a third party** | NFR-PERF-01 | E-mail/SMS go through the outbox, never inline in a request. |
| 5 | **Show your conflicts** | FR-ALLOC-05 | An incomplete timetable must say so. Never fake completeness. |
| 6 | **Schema changes are forward-only** | NFR-MAINT-05 | Never edit an applied migration. Add a new one. |
| 7 | **Every feature cites a requirement ID** | NFR-MAINT-06 | In the PR description and, where useful, in a code comment. |
| 8 | **Migrations are reversible in the same release** | NFR-REL-02 | Deploy forward-only, but ship the `down` path for the same version. |

---

## 2. Environment Setup

### 2.1 Required

| Tool | Version | Check |
| --- | --- | --- |
| PHP | 8.2+ with `pdo_mysql`, `mbstring`, `json`, `openssl`, `tokenizer` | `php -v && php -m` |
| MySQL | 8.0+ | `mysql --version` |
| Composer | 2.x | `composer --version` |
| Node *(optional)* | 20+ — only for asset minification | `node -v` |

### 2.2 First run

There is no `db:create` step. The baseline migration executes `db/schema.sql`,
so the schema, the views and the grant statements all arrive together.

```bash
git clone <repo-url> catms
cd catms

cp .env.example .env
$EDITOR .env                     # DB_DATABASE, DB_USERNAME, DB_PASSWORD, APP_KEY

composer install                 # autoloader + dev tooling
php bin/console migrate          # create schema (wraps bin/migrate.php)
php bin/console seed             # RBAC, demo users, then db/seed.sql reference data

php -S localhost:8080 -t public public/router.php
# open http://localhost:8080
```

Then confirm the install before writing any code against it:

```bash
php bin/console smoke            # config, database, schema, data, http, engine
php bin/console verify-integrity # invariants, runs, privileges, migrations
php -S localhost:8080 -t public public/router.php & # then, in another shell:
curl -sS http://localhost:8080/api/v1/health
```

`smoke` is the useful one. It is the only command that exercises the whole stack
in one pass, including a real engine solve, so it fails loudly on the mistakes
above rather than leaving them to be discovered later as a blank page.

**The golden baseline does not exist on a fresh clone.**
`tests/Unit/Allocation/GoldenFileTest.php` calls `markTestIncomplete()` when
`tests/Unit/Allocation/Fixture/golden/*.json` is absent, so the suite is green
rather than red — an incomplete test is honest, a failing one is misleading,
because it looks like an engine regression. To create it:

```bash
composer golden                  # == php bin/regenerate-golden.php
```

Read the generated file before committing it. It is a snapshot of engine output
that every later run is compared against, so a baseline generated without
reading it converts a mistake into a permanent, load-bearing expectation. If a
change is intentional, regenerate and say so in the commit message.

### 2.3 Docker

```bash
cp .env.example .env
docker compose up -d --build
docker compose exec app php bin/console migrate
docker compose exec app php bin/console seed
docker compose exec app php bin/console smoke
docker compose exec app php bin/console worker start   # notification dispatcher
```

The database is published on host port **3307**, not 3306, so it cannot collide
with a MySQL already running on the machine. Mailpit catches all outbound mail
on <http://localhost:8025>, so a notification change can be observed end to end
without sending anything to a real student.

### 2.4 Generate an `APP_KEY`

```bash
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
# APP_KEY=<paste>
```

Used to sign JWTs. Never commit it; never reuse it across environments.

---

## 3. Phase Plan

Eight phases, ordered by dependency. Each ends in a demonstrable increment, matching
the report's agile/DSRM approach (§3.9.4, §4.3).

| Phase | Deliverable | Depends on | Sprint target |
| --- | --- | --- | --- |
| **0** | Foundation: repo, config, DB, CI, error handling | — | 1 |
| **1** | Identity: registration, login, tokens, RBAC, profiles | 0 | 2–3 |
| **2** | Reference data: departments, rooms, courses, cohorts, calendar | 0 | 3 |
| **3** | **Allocation engine** + unit tests | 2 | 4–5 |
| **4** | Timetable module: day / week / semester views, search | 1, 2 | 6 |
| **5** | Notifications + outbox worker | 3, 4 | 7 |
| **6** | Admin dashboard, reports, analytics | 3, 4, 5 | 8 |
| **7** | Hardening: security review, performance, UAT, deploy | all | 9–10 |

Phases 1 and 2 can run in parallel with different owners. Phase 3 is the long pole and
should start as soon as the room/course/cohort schema in phase 2 is stable — the engine
depends on those value objects, not on HTTP.

---

## 4. Phase Details

### Phase 0 — Foundation

**Tasks**

- [ ] Composer project, PSR-4 `App\` → `src/`
- [ ] `Config` (typed, `.env`, production guard), `Database` (PDO, transactions, deadlock retry)
- [ ] `Router`, `Request`, `Response` (uniform `{data, meta, error}` envelope)
- [ ] `ExceptionHandler`, `Logger`, `Audit`
- [ ] Front controller `public/index.php`, `.htaccess`, Nginx config
- [ ] `db/schema.sql` + `bin/migrate.php` + `bin/seed.php`
- [ ] PHPUnit configured with `tests/Unit` and `tests/Integration` suites
- [ ] CI: lint (PSR-12), PHPStan level 6, PHPUnit, migrations run against a MySQL service

**Definition of done**

- `GET /api/v1/health` returns `200 {"status":"ok","db":"up"}`
- An unknown route returns a JSON `404`, not an HTML error page
- `composer test` runs green with no database required for `tests/Unit`
- A failed migration leaves the schema unchanged

---

### Phase 1 — Identity

**Tasks**

- [ ] Migrations: `users`, `password_reset_tokens`, `refresh_tokens`, `roles`, `permissions`
- [ ] `Core\Auth`: `password_hash` (bcrypt, cost 12), JWT issue/verify, refresh rotation
- [ ] `Core\Rbac`: role → permission map, `assert()`, default deny
- [ ] `RbacMiddleware`, `AuthMiddleware`
- [ ] `Core\RateLimiter` + middleware (login: 5 / 15 min / IP + e-mail; register: 3 / hour)
- [ ] `AuthController`: `register`, `login`, `refresh`, `logout`, `forgot-password`, `reset-password`
- [ ] `ProfileController`: read/update own profile; admin role assignment
- [ ] `Audit` on every state-changing auth and profile action
- [ ] Tests: `AuthTest`, `RbacTest`, `ProfileTest`

**Design notes**

- **Refresh tokens are single-use.** Replay of a used token revokes the whole family —
  it is a theft signal.
- **Forgot-password never reveals whether an account exists.** Identical response and
  comparable timing for both cases (NFR-SEC-04, minimisation).
- **Roles are data, not code.** `roles` and `permissions` are tables seeded from
  `config/rbac.php`; the matrix in `docs/SECURITY.md` §3 is their source of truth.

**Definition of done**

- Register → verify e-mail (dev: token logged) → login → `/users/me` returns the profile
- A `student` calling an admin endpoint gets `403`, with or without a modified client
- A 6th login attempt inside 15 minutes returns `429` with `Retry-After`
- A reused refresh token revokes the family and logs the event
- Passwords appear in no log line and in no `toArray()` output

---

### Phase 2 — Reference Data

**Tasks**

- [ ] Migrations: `departments`, `rooms`, `room_features`, `courses`, `course_features`,
      `cohorts`, `enrollments`, `lecturer_availability`, `time_slots`, `semesters`
- [ ] Value objects in `src/Domain/ValueObject/`: `Capacity`, `RoomFeatures`, `TimeRange`,
      `EmailAddress`, `PasswordHash`
- [ ] Entities: `Room`, `Course`, `Cohort`, `Semester`, `TimeSlot`
- [ ] Repositories (PDO) + interfaces
- [ ] Controllers: `RoomController`, `CourseController`, `SemesterController` (admin CRUD)
- [ ] `FR-ROOM-01/02`: live availability endpoint and room comparison
- [ ] `FR-CAL-01…04`: calendar CRUD + teaching-window validation
- [ ] Seed: realistic UTAS reference data (buildings, rooms, a CS department course set)

**Definition of done**

- An admin can CRUD a room, a course and a semester
- `GET /rooms/available?slot=…&headcount=…&features=…` returns only feasible rooms
- An allocation outside the teaching window is rejected with `422`
- Foreign keys and the `(department_id, …)` composite indexes are in place
- `RoomController` tests cover the capacity and feature filters

---

### Phase 3 — Allocation Engine  ★ critical path

**Tasks**

- [ ] `Rng` (seeded, self-contained, deterministic)
- [ ] `ConstraintChecker` — HC-1 … HC-10
- [ ] `CostFunction` + `CostBreakdown` + `CostWeights` (from `config/weights.php`)
- [ ] `CandidateGenerator` — feasible `(slot, room)` pairs, occupancy index
- [ ] `AllocationEngine::solve()` — seed → improve → verify
- [ ] `SchedulingResult`, `Assignment`, `UnallocatedSession`
- [ ] `AllocationService` — the single write path: generate, apply, confirm, cancel, reassign
- [ ] Atomic application in one transaction; previous timetable preserved on failure (NFR-REL-03)
- [ ] `bin/generate-timetable.php` — `--semester`, `--department`, `--dry-run`, `--seed`
- [ ] Tests: the full matrix in `docs/ALLOCATION_ENGINE.md` §13

**Implementation order** (each step is independently testable — do not batch them)

1. `Rng` + a test that the same seed yields the same sequence.
2. `ConstraintChecker`, one test per HC.
3. `CandidateGenerator` with a hand-built problem from §6 of the engine doc.
4. `CostFunction` + a test pinning each term's value.
5. Phase 1 seeding only — this alone should already exceed 90 % accuracy.
6. Phase 2 local search — assert only that it never increases the penalty.
7. Phase 3 verification — assert `violations` is always empty.
8. Incremental repair (§7).
9. `AllocationService` and the CLI.
10. Golden file + benchmark.

**Definition of done**

- Property test: no `(room, slot)` pair repeats, over randomised problems
- Property test: every assignment satisfies `room.capacity >= enrolled`
- Accuracy ≥ 0.90 on the reference dataset; 1 000 sessions within the 30 s budget
- Same seed ⇒ byte-identical result
- An unsolvable problem reports every session unallocated and explains each
- `AllocationService` is the only code path that writes an allocation
- Rolling back a failed generation leaves the previous timetable intact

---

### Phase 4 — Timetable and Search

**Tasks**

- [ ] `TimetableService`: day, week, semester scopes; filtered by role (BR-11)
- [ ] `TimetableController`
- [ ] Index-backed week query; verify with `EXPLAIN` that no full scan occurs
- [ ] `SearchController`: rooms, schedules (FR-SEARCH-01/02)
- [ ] PWA shell: `manifest.webmanifest`, service worker, offline week cache (NFR-UX-04)
- [ ] Views: login, my timetable (day/week), room search, notifications, admin timetable grid
- [ ] Responsive + accessible: 44 px targets, no horizontal scroll at 360 px, AA contrast
- [ ] Tests: `TimetableTest`, `SearchTest`, `AccessibilityTest`

**Definition of done**

- A student sees only their own cohort's sessions; a lecturer their own; an admin all
- Week view loads in < 1 s at p95 on the reference dataset
- The PWA installs on Android and iOS and shows the cached week offline
- Keyboard-only navigation reaches every interactive element; axe reports no violations
- No allocation leaks across departments

---

### Phase 5 — Notifications

**Tasks**

- [ ] Migration: `notifications`, `notification_outbox`
- [ ] `NotificationChannel` port + `InAppChannel`, `EmailChannel`, `SmsChannel`
- [ ] Events: `AllocationCreated`, `AllocationUpdated`, `AllocationCancelled`, `RoomUnavailable`
- [ ] Fan-out to cohort students + lecturer + department admins (FR-NOTIF-04)
- [ ] `NotificationService`, `NotificationController` (list, mark read, unread count)
- [ ] `bin/worker.php` — outbox drain, exponential backoff, idempotency
- [ ] Templates for each notification type
- [ ] Tests: `NotificationTest`, `OutboxWorkerTest`

**Definition of done**

- A confirmation produces exactly one notification per recipient and per channel
- The HTTP response does not wait for SMTP
- Killing the worker mid-drain and restarting loses nothing (at-least-once + idempotent)
- Cancelling an allocation notifies students and lecturer (BR-09)
- A `student` never sees another student's notifications

---

### Phase 6 — Admin Dashboard and Reports

**Tasks**

- [ ] `DashboardController`: active users, pending allocations, room-utilisation status,
      recent alerts (FR-ADMIN-05)
- [ ] `ReportingService`: utilisation (BR-10), peak usage, room usage, conflicts, lecturer load
- [ ] Nightly rollup into `room_utilisation_daily` (idempotent, re-runnable)
- [ ] CSV export (FR-REPORT-03)
- [ ] Utilisation heat map (FR-ADMIN-06)
- [ ] Lecturer and course management screens (report figures 4.9, 4.11, 4.12)
- [ ] Tests: `ReportTest`, `DashboardTest`

**Definition of done**

- Utilisation % is reproducible from `db/seed.sql` fixtures
- Reports read the rollup, not the raw allocation table (`EXPLAIN` verified)
- Re-running the rollup for the same day changes nothing
- An export contains no row the caller is not entitled to see
- Dashboard aggregates match the underlying counts

---

### Phase 7 — Hardening

**Tasks**

- [ ] Security review against `docs/SECURITY.md` §2 (threat model)
- [ ] Act 843 review: minimisation, retention, subject-access, breach handling (§6)
- [ ] Load/stress test at 1 500 concurrent users (the scenario in QA-1)
- [ ] Tune allocation weights; re-run the golden file
- [ ] UAT with the pilot cohort (30 students, 10 lecturers — report §4.4.1)
- [ ] Production runbook, backup and restore drill
- [ ] Progressive rollout: pilot department → CS Department → university-wide

**Definition of done**

- No open high/critical findings
- p95 response < 3 s under peak load (NFR-PERF-01); the report measured 1.8 s
- UAT satisfaction ≥ 80 % (the report measured 88 %)
- A backup restores to a working state within the RTO
- `docs/DEPLOYMENT.md` runbooks rehearsed by someone who did not write them

---

## 5. Conventions and Standards

### 5.1 Code

| Rule | Convention |
| --- | --- |
| Language level | PHP 8.2 — typed properties, constructor promotion, `readonly`, enums, `match` |
| Standard | PSR-12 + the Slevomat sniffs, enforced by **PHP_CodeSniffer** (`phpcs.xml`) |
| Auto-fix | `php-cs-fixer` via `.php-cs-fixer.php`, run locally and by `composer lint:fix` — never the CI gate |
| Static analysis | **PHPStan level 6 across `src/`, `tests/` and `db/`** — one level, no per-directory exceptions |
| Naming | `PascalCase` classes · `camelCase` methods/vars · `SCREAMING_SNAKE` constants |
| Files | One class per file, PSR-4, `App\` → `src/` |
| Imports | Always fully qualified at the top; no global-namespace classes |
| Comments | DocBlocks on public methods (`@param`, `@return`, `@throws`). Comments explain **why**, not **what** |
| Errors | Throw domain exceptions; never `die`, `echo`, or `var_dump` |
| SQL | PDO prepared statements only. No string interpolation of user input, ever |
| Times | Stored UTC; rendered `Africa/Accra`. Timestamps `DATETIME`, never `TIMESTAMP` |

**Why phpcs is the gate and the fixer is not.** `php-cs-fixer` rewrites files,
so a disagreement between two people about formatting produces a diff instead of
an error, and every local run can mask a violation the CI would catch. A linter
that only reports cannot mask anything. The fixer is still there — it is just not
what decides whether a commit is acceptable.

**Why one PHPStan level.** A per-directory level looks more rigorous and is
strictly worse: a file at level 8 that passes is not more correct than a level-6
file that passes, and the extra rules are applied inconsistently, so nobody
learns them. If level 6 is insufficient for a given file, raise it for the whole
project in the same pull request.

#### Determinism rules (`phpcs.xml`, two `ForbiddenFunctions` sniffs)

These are not stylistic. They are the automated half of "the engine is
reproducible", and they are scoped differently on purpose:

| Forbidden | Where | Why |
| --- | --- | --- |
| `rand`, `mt_rand`, `srand`, `mt_srand`, `shuffle`, `array_rand`, `uniqid` | everywhere **except** `tests/` | The engine must be reproducible from a seed. A global PRNG anywhere in `src/` makes a golden-file run unreproducible, and an unreproducible golden file is a golden file nobody trusts. `tests/` is exempt because a test that needs entropy is testing something other than the engine |
| `time`, `date`, `gmdate`, `microtime`, `hrtime` | `src/Domain/Allocation/*` **except** `SystemClock.php` | The domain must be a pure function of its inputs. A wall-clock read inside the engine makes a solve depend on when it ran, which is the same defect as a global PRNG. Clock access goes through the injected `Clock`; `SystemClock` is the one file allowed to call the real one |

The split matters. One blanket rule covering both would fail CI on the ~15
legitimate files that must read a clock — `App.php` (request timing),
`Jwt.php` (`iat`/`exp`), `RateLimiter.php`, `Logger.php`, `Migrator.php` and
the rest. The point of the domain rule is purity of `src/Domain/Allocation`, not
purity of the process, and a rule that fails on correct code gets disabled
wholesale within a week.

One more exemption is deliberate: `DisallowEmptyFunction` runs with
`ignoreConstructors=true`, because eight classes use constructor property
promotion with an empty body (`Command`, `Controller`, `RateLimiter`,
`WeightProfile`, `CostFunction`, `DisableWhenMaintenance`,
`MysqlRefreshTokenRepository`, `MysqlUserRepository`). An empty constructor
that only assigns promoted properties is not dead code.

`bin/console` has no `.php` extension, so both tools would skip it — the entry
point is exactly the file you least want unchecked. `phpcs.xml` declares
`<arg name="extensions" value="php,console"/>` and `phpstan.neon` declares
`fileExtensions: [php, console]` for the same reason.

### 5.2 Git

```
<type>(<scope>): <subject>

types:    feat | fix | docs | refactor | test | perf | build | ci | chore | db
scopes:   auth, alloc, timetable, rooms, courses, calendar, notify, report,
          dashboard, core, ui, infra
```

Examples:

```
feat(alloc): add equity term to cost function            # docs/ALLOCATION_ENGINE.md §4
fix(auth): revoke refresh family on token replay         # NFR-SEC-01
perf(timetable): add covering index for week view        # NFR-PERF-03
db: add lecturer_availability table                      # FR-ALLOC-03
```

- `main` is protected; work lands via pull request.
- One concern per commit; `git rebase` before requesting review.
- Commit bodies reference requirement IDs so `git log --grep FR-ALLOC-02` is a
  traceability query.

### 5.3 API conventions

- Version in the path: `/api/v1/…`. A breaking change means `/api/v2` (NFR-MAINT-04).
- Resource nouns, plural, kebab-case: `/allocation-overrides`, not `/doOverride`.
- `GET` list endpoints accept `page`, `per_page`, and `sort`.
- Every write accepts `Idempotency-Key` where a retry could duplicate work.
- Errors use the envelope in `docs/ARCHITECTURE.md` §6 and a stable machine-readable
  `error.code`.
- `snake_case` for JSON field names throughout.

### 5.4 Frontend

- Mobile-first CSS; base styles for small screens, then `@media (min-width: …)`.
- Design tokens as CSS custom properties in `:root` (`--color-*`, `--space-*`, `--radius-*`).
- No CSS framework — the report specifies HTML, CSS and JavaScript.
- ES modules, no build step required to develop; optional minifier for production.
- Every interactive element reachable by keyboard; visible focus ring.
- Respect `prefers-reduced-motion` and `prefers-color-scheme`.

---

## 6. Working Agreements

Because the report commits to a stakeholder-involved agile process (§4.3), the
following are part of the definition of done, not optional extras:

- **Stakeholder review of user stories** before a phase starts. Stories that no
  stakeholder recognises are cut.
- **Demo at the end of every sprint** to a real user (lecturer, admin or student).
  Feedback is triaged into the next sprint before new work begins.
- **Prototype before polish.** The report's cycle was prototype → test → integrate
  feedback → iterate. Screens from report figures 4.4–4.12 are the reference, not
  the target.
- **Decisions are recorded.** Anything that changes the architecture becomes an ADR in
  `docs/ARCHITECTURE.md` §14 in the same PR.
- **Unallocated sessions are a conversation, not a bug.** When the engine cannot place
  something, that is information for the administrator, surfaced in the dashboard and
  in the weekly summary — not silently patched.

---

## 7. Definition of Done

A task is done when **all** of these hold:

- [ ] Acceptance criteria from the requirement ID are met
- [ ] Unit tests cover the new logic; property tests cover the allocation engine
- [ ] Integration tests cover every new endpoint, including the authorisation failure
- [ ] No new PHPStan or PSR-12 violations
- [ ] Migrations exist with a working `down`, if schema changed
- [ ] `audit_log` written for new state-changing admin operations
- [ ] RBAC permission added for new endpoints; default-deny preserved
- [ ] API documented in `docs/API.md`
- [ ] Requirement ID referenced in the PR description
- [ ] Works on a 360 px viewport and with the keyboard only
- [ ] Verified against the acceptance targets in `docs/TESTING.md` §6

---

## 8. Common Tasks

### The console

Every operation goes through one kernel. `php bin/console <command>` is the
canonical spelling; the `bin/*.php` scripts are thin aliases for the operations a
person runs during a deploy or an incident.

| Command | Purpose |
| --- | --- |
| `list` | Every command, its description, its aliases and its options |
| `migrate` | Apply / roll back / `--status` / `--pretend` |
| `seed` | RBAC from `config/rbac.php`, demo users, then `db/seed.sql` |
| `generate` | Solve and (optionally) apply a timetable |
| `worker` | Outbox drain, utilisation rollup, prune, sweep, semester rollover |
| `retention` | The only process that may `DELETE` from `audit_log` |
| `maintenance` | On / off / status — a file, never a database row |
| `routes` | The route table, its permission and its handler status |
| `verify-integrity` | Schema invariants, grants, migration ledger, demo credentials |
| `smoke` | End-to-end check of a whole installation |
| `make:migration` | Scaffold a migration file |
| `make:controller` | Scaffold a controller, print the routes to paste |

`bin/console` plus `bin/migrate.php`, `bin/seed.php`,
`bin/generate-timetable.php`, `bin/worker.php`, `bin/retention.php` and
`bin/benchmark.php` are the entry points. Each of the `bin/*.php` wrappers keeps
its own SAPI guard and `require` of the autoloader — six lines that cannot be
deferred to an autoloadable class, because the class cannot be loaded until the
autoloader is loaded. The shared part is
`App\Console\Launcher::main(array $argv, ?string $command = null, ?string $basePath = null)`,
which only `bin/console` calls, with a command name; the wrappers call it with
`null` because their own filename already *is* the command.

**`Input::shifted()` is load-bearing.** The kernel strips the command name from
the argument list before dispatching, via `Input::shifted()`. Without it,
`worker drain` reads `argument(0)` as `"worker"` and no subcommand is ever
recognised, and `make:migration add_middle_name` generates a file called
`add_middle_name.php`-with-`worker`-in-it. It is a one-line call in
`Kernel::run()` and it is the reason positional arguments work at all; if a
command ever sees the command's own name in `argument(0)`, this is what to look
at.

A subcommand is a **positional** argument, not a flag: `worker drain`, not
`worker --drain`.

### Add a new endpoint

1. `php bin/console make:controller ReportsExportController`
2. Add the route in `config/routes.php` with its permission
3. Declare the permission in `config/rbac.php` and seed it
4. Implement in the **service** layer, not the controller; keep the controller thin
5. Document in `docs/API.md`
6. Tests: success, unauthorised (`401`), forbidden (`403`), validation failure (`422`)

`make:controller` prints the `config/routes.php` lines to paste rather than
editing the file, because that file is a 917-line table of deliberate ordering
and a generator that rewrites it is a generator that eventually reorders it.

### Change the allocation cost model

1. Edit `src/Domain/Allocation/CostFunction.php` and `config/weights.php`
2. Add a test pinning the new term's contribution
3. `composer golden` and `php bin/benchmark.php`
4. Record the before/after accuracy and penalty in the PR description
5. Confirm accuracy is still ≥ 0.90 (the `ENGINE_ACCURACY_GATE`, NFR-PERF-04)

### Add a database column

1. `php bin/console make:migration add_middle_name_to_users --table=users`
2. Implement `up()` **and** `down()`; make `up()` idempotent
3. Update `docs/DATA_MODEL.md`
4. If it is tenant-scoped, include `department_id` in the index (NFR-SCALE-02)
5. Deploy code that tolerates both states before running the migration in production

Do **not** also edit `db/schema.sql` once the baseline migration has run
anywhere. `db/schema.sql` is executed by
`2026_08_01_090000_baseline.php`, so editing it changes nothing on an
existing database and creates a permanent divergence between the file and every
environment. The consolidated view is regenerated by squashing, deliberately
(`docs/DATA_MODEL.md` §13).

### Regenerate a timetable safely

```bash
# report the current published timetable without solving
php bin/console generate --status --semester=2026-A --department=CS

# dry run — writes nothing at all, not even a run row
php bin/console generate --semester=2026-A --department=CS --dry-run

# apply, with an explicit seed so the run is reproducible
php bin/console generate --semester=2026-A --department=CS --seed=42

# record the run and its conflicts without replacing the timetable
php bin/console generate --semester=2026-A --department=CS --advisory

# repair one weekday after a room or course change
php bin/console generate --semester=2026-A --repair-day=1
```

`--semester` and `--department` accept an **id or a code**, because `--semester=3`
is how it is written in code and `--semester=2026-A` is how it is written in a
runbook, and an operator should not have to remember which.

`--dry-run` first, always. The apply is transactional; a failure restores the
previous timetable (NFR-REL-03). Below the accuracy gate the run is **still
recorded** and the apply is **still refused** — the record is the evidence that
the gate fired.

---

## 9. Troubleshooting

| Symptom | Likely cause | Action |
| --- | --- | --- |
| `SQLSTATE[HY000] [1045] Access denied` | Wrong `.env` credentials | Check `DB_*`; confirm the MySQL user exists |
| `could not find driver` | `pdo_mysql` missing | Install the extension; verify with `php -m` |
| `Class "App\…" not found` | Autoloader stale | `composer dump-autoload -o` |
| `401` on every request | Clock skew invalidates `nbf`/`iat` | Check NTP; ensure the server clock is correct |
| `419` / silent logout | Refresh token rotated or family revoked | Expected after a replay; user signs in again. Check `audit_log` for the reason |
| `429` during UAT | Rate limit too tight for a shared NAT | Raise the limit for the demo range, or raise the limit **with a rationale** — do not disable it |
| Allocation engine returns everything unallocated | Room features or capacity data missing | Verify the reference data; run `--dry-run` and read the cited constraint IDs |
| Generation is slow | Large `max_iterations`, or an unindexed `EXPLAIN` | `EXPLAIN` the week query; reduce iterations; check the occupancy index |
| Duplicate bookings in a dev database | Migrations run without the unique index | Apply the latest migration; the index is the backstop (ADR-006) |
| Notifications not arriving | Worker not running | Start `bin/console worker`; inspect `notification_outbox.status` and `attempts` |
| PWA will not install | Served over plain HTTP | Service workers require HTTPS (or `localhost`) |
| Timezone confusion | Data stored UTC, expected local | Render with `Africa/Accra`; never store local time |
| A subcommand is never recognised | A positional argument was consumed as the subcommand | Check that `Kernel::run()` dispatches with `$input->shifted()` (§8) — the symptom is `worker` being read as the subcommand name |
| `Unknown command "app:foo"` | Guessed a legacy name | `php bin/console list`. Aliases are shown there; a near-miss gets a "did you mean" suggestion |
| `smoke` fails on `schema` after a partial migrate | A DDL file failed halfway | MySQL cannot roll back DDL. Re-run `migrate` — `up()` is idempotent precisely so this is safe |
| `retention` refuses to start | Running as `catms_app`, which has no `DELETE` on `audit_log` | Run it as `catms_maint`; that separation is the control (VULN-14) |
| Engine output changed but no test failed | Expected on any cost-model change | `composer golden` and read the diff — the CI `golden-drift` gate does this for you |

---

## 10. Extension Guide

Ordered by expected value. Items marked *(future work)* are explicitly out of scope for
v1 per report §5.2.

### Near-term (Should / Could-have)

1. **Timetable export** — PDF and ICS. `FR-TIME-*`, high student value, low risk.
2. **Advanced analytics** — utilisation heat maps, demand forecasting. FR-ADMIN-06.
3. **Personalisation** — notification preferences per user. MoSCoW "Could-have".
4. **Offline week with conflict resolution** — cache more aggressively, reconcile on
   reconnect. NFR-UX-04.

### Medium-term

5. **Drag-and-drop timetable editing** with server-side re-validation through the same
   `ConstraintChecker`, so a drag can never create a double booking. Report §5.2(iii).
6. **Push notifications** — a Web Push provider behind the existing
   `NotificationChannel` port. No architectural change required.
7. **Multi-semester planning** and room-booking requests from outside the department.

### Future work *(out of scope for v1)*

8. **UTAS SIS integration** — report §5.2(i). The highest-value item. The seam already
   exists: implement `UserRepository` / `CourseRepository` as read-through caches
   backed by SIS, with this database remaining the system of record for allocations.
   Suggested first step: a nightly import job into `users` and `courses`, keyed on
   `student_index` and `course code`, with change detection and a reconciliation report.
9. **Native mobile app** — report §5.2(iii). The REST API is already the contract; only
   a new client is needed.
10. **Load and stress testing programme** — report §5.2(v). Recommended before
    university-wide rollout.

---

## Related Documents

- [`ARCHITECTURE.md`](ARCHITECTURE.md) — structure and rationale
- [`REQUIREMENTS.md`](REQUIREMENTS.md) — what to build, and why
- [`ALLOCATION_ENGINE.md`](ALLOCATION_ENGINE.md) — the core algorithm
- [`DATA_MODEL.md`](DATA_MODEL.md) — schema
- [`API.md`](API.md) — endpoint reference
- [`TESTING.md`](TESTING.md) — test strategy and acceptance
- [`SECURITY.md`](SECURITY.md) — threat model, RBAC matrix, Act 843
- [`DEPLOYMENT.md`](DEPLOYMENT.md) — environments and operations
