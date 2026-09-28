# System Architecture

**CATMS — Classroom Allocation & Timetable Management System**
Version 1.0 · Applies to API `v1`

---

## Table of Contents

1. [Architectural Drivers](#1-architectural-drivers)
2. [Architectural Style](#2-architectural-style)
3. [System Context](#3-system-context)
4. [Container View](#4-container-view)
5. [Component View — Layers](#5-component-view--layers)
6. [Request Lifecycle](#6-request-lifecycle)
7. [Domain Architecture](#7-domain-architecture)
8. [Allocation Engine Placement](#8-allocation-engine-placement)
9. [Data Architecture](#9-data-architecture)
10. [Security Architecture](#10-security-architecture)
11. [Notification Architecture](#11-notification-architecture)
12. [Deployment Topology](#12-deployment-topology)
13. [Cross-Cutting Concerns](#13-cross-cutting-concerns)
14. [Architectural Decisions (ADRs)](#14-architectural-decisions-adrs)
15. [Quality Attribute Scenarios](#15-quality-attribute-scenarios)

---

## 1. Architectural Drivers

Requirements that *force* the architecture (highest priority first):

| # | Driver | Source | Architectural consequence |
| --- | --- | --- | --- |
| 1 | Conflict-free, capacity- and availability-aware allocation, recomputed on change | FR-ALLOC-01…03, §3.7.4 | Isolated, pure, testable **allocation engine**; cannot be smeared across controllers |
| 2 | Response < 3 s at peak for timetable updates and allocation changes | NFR-PERF-01 | Indexed queries; precomputed timetable views; no synchronous heavy recompute on read |
| 3 | Real-time notifications on every timetable change | FR-NOTIF-01/02 | Transactional **outbox** → async dispatcher; never block the write on a channel |
| 4 | End-to-end encryption + RBAC + Act 843 compliance | NFR-SEC-01…04 | Security as a cross-cutting concern; authorisation at the API boundary, not in the UI |
| 5 | Modular; fix/extend without disrupting academic activity | NFR-MAINT-01 | Strict layering with dependency inversion; versioned API; forward-only migrations |
| 6 | Scale across multiple departments | NFR-SCALE-01/02 | Every tenant-scoped row carries `department_id`; no global joins without a scope |
| 7 | Mobile-first, 100 % accessible | UR-5, NFR-UX-01 | PWA, responsive, JSON over HTML; server never depends on the client |
| 8 | Allocation accuracy > 90 % (95 % achieved) | NFR-PERF-04, Table 4.1 | Deterministic engine + invariant verification pass + conflict reporting |

---

## 2. Architectural Style

**Layered, request/response, client–server — with a hexagonal core.**

- Outer rings (HTTP controllers, PDO repositories, notification adapters) are
  *adapters*: they translate between the outside world and the domain.
- The middle ring (**`src/Domain`**) is *pure PHP*. No `$_SERVER`, no PDO, no framework.
  It defines ports (interfaces); infrastructure implements them.
- The dependency rule is strictly inward: **outer → inner, never inner → outer.**

```
        ┌───────────────────────────────────────────────────────────┐
        │  ADAPTERS      src/Http, src/Infrastructure                │  depends on ▼
        ├───────────────────────────────────────────────────────────┤
        │  APPLICATION    controllers, middleware, services          │
        ├───────────────────────────────────────────────────────────┤
        │  DOMAIN         entities, value objects, policies,         │  depends on
        │                 the allocation engine, port interfaces     │  nothing
        └───────────────────────────────────────────────────────────┘
```

**Why not MVC-with-active-record?** Because the allocation engine is the intellectual
core of the system and the part most likely to be tuned, proved and re-run in batch. It
must be testable with plain `new` and no database, and it must be callable from
`bin/generate-timetable.php` exactly as it is from a web request.

---

## 3. System Context

```
                    ┌───────────────┐
                    │    Student    │───┐
                    └───────────────┘   │
                    ┌───────────────┐   │      ┌──────────────────────────┐
                    │    Lecturer   │───┼─────▶│                          │
                    └───────────────┘   │      │      CATMS Platform     │
                    ┌───────────────┐   │      │  (this repository)      │
                    │ Administrator │───┘      │                          │
                    └───────────────┘         └────┬──────────┬──────────┘
                                                  │          │
                                    ┌─────────────▼──┐   ┌───▼─────────────┐
                                    │  E-mail / SMS  │   │  Future: UTAS   │
                                    │  providers     │   │  SIS (out of    │
                                    │  (Act 843)     │   │  scope, §5.2)   │
                                    └────────────────┘   └─────────────────┘
```

External actors and systems:

- **Actors** — students, lecturers, administrators. All authenticate; all interact over
  HTTPS.
- **Notification providers** — e-mail / SMS. Behind the `NotificationChannel` port;
  providers are configuration, not code changes (`Won't-have` in v1: procurement).
- **UTAS SIS** — explicitly **out of scope** for v1, but the design anticipates it:
  `users.student_index` and `courses.code` are the natural import keys, and the
  repository interfaces allow a read-through SIS implementation later.

---

## 4. Container View

```
┌────────────────────────────────────────────────────────────────────────────┐
│  CLIENTS                                                                     │
│  ┌──────────────────────┐  ┌──────────────────────┐  ┌────────────────────┐ │
│  │ Mobile browser (PWA) │  │ Desktop browser      │  │ Admin dashboard    │ │
│  │ installable, offline │  │ responsive           │  │ dense data views   │ │
│  │ shell + cached week  │  │                      │  │                    │ │
│  └──────────┬───────────┘  └──────────┬───────────┘  └─────────┬──────────┘ │
└─────────────┼─────────────────────────┼──────────────────────────┼────────────┘
              │  HTTPS · JSON · /api/v1  │  (Bearer access token)   │
┌─────────────▼─────────────────────────▼──────────────────────────▼────────────┐
│  EDGE                                                                       │
│  ┌────────────────────────────────────────────────────────────────────────┐ │
│  │ Nginx  ·  TLS termination  ·  gzip  ·  static assets  ·  rate limit    │ │
│  └────────────────────────────────────┬───────────────────────────────────┘ │
└───────────────────────────────────────┼────────────────────────────────────┘
                                        │  fastcgi_pass
┌───────────────────────────────────────▼────────────────────────────────────┐
│  APPLICATION — PHP 8.2 FPM                                                  │
│                                                                             │
│   FrontController ─▶ Router ─▶ Middleware pipeline ─▶ Controller            │
│                                                    │                        │
│                                                    ▼                        │
│                            ┌───────────────────────────────┐                │
│                            │      DOMAIN (pure PHP)        │                │
│                            │  AllocationEngine  ◀── core  │                │
│                            │  TimetableService             │                │
│                            │  NotificationService          │                │
│                            │  ReportingService             │                │
│                            │  Entities · Policies · Ports  │                │
│                            └───────────────┬───────────────┘                │
│                                            │ implements ports              │
│   ┌────────────────────────────────────────▼────────────────────────────┐   │
│   │  INFRASTRUCTURE                                                   │   │
│   │  MySQL repositories (PDO)      Notification adapters (in-app,     │   │
│   │  Token store (JWT)             e-mail, SMS)                       │   │
│   └────────────────────────────────────────┬───────────────────────────┘   │
└────────────────────────────────────────────┼───────────────────────────────┘
                                             │
                            ┌────────────────▼─────────────────┐
                            │  MySQL 8                         │
                            │  normalised schema + indexes    │
                            │  daily utilisation rollups      │
                            └──────────────────────────────────┘
```

A **worker** process drains the notification outbox and the daily rollup job. It runs
from the same image with a different entrypoint, so there is one artefact to build and
one version to track.

---

## 5. Component View — Layers

### 5.1 `src/Core` — cross-cutting application services

| Component | Responsibility | Notes |
| --- | --- | --- |
| `Config` | Typed env access, `.env` loading, production guard | No `getenv()` scattered around |
| `Database` | PDO factory, transaction helper, retry on deadlock | Single connection per request |
| `Router` | Method + path → handler, `{param}` binding, groups | No framework dependency |
| `Request` / `Response` | Input normalisation, JSON body, typed output | Envelope `{data, meta, error}` |
| `Validator` | Declarative rules, returns first-error-per-field | Used by every write endpoint |
| `Auth` | Password hashing, JWT issue/verify, refresh rotation | `password_hash` bcrypt cost 12 |
| `Rbac` | Role → permission map, `assert()` | Loads the map from `config/rbac.php`, the single source of truth for `docs/API.md` §3 |
| `RateLimiter` | Token bucket on login / search / generate | Backed by MySQL for multi-worker |
| `Audit` | Append-only before/after records | Never updated or deleted |
| `ExceptionHandler` | Maps domain exceptions → HTTP problem responses | Consistent error contract |

### 5.2 `src/Domain` — pure business logic

| Component | Responsibility |
| --- | --- |
| `Entity\*` | `User`, `Course`, `Room`, `Cohort`, `TimeSlot`, `Allocation`, `Semester`, `Notification` — behaviour-rich, no I/O |
| `Allocation\AllocationEngine` | **The core.** Produces a timetable or a conflict report |
| `Allocation\ConstraintChecker` | Hard constraints (BR-01…BR-07) |
| `Allocation\CostFunction` | Soft preferences → scalar penalty |
| `Allocation\CandidateGenerator` | Feasible `(session, room, slot)` triples — the prefilter |
| `Allocation\OccupancyIndex` | O(1) room / lecturer / cohort occupancy lookup |
| `Allocation\Rng` | Seeded 31-bit xorshift128 — the only source of randomness |
| `Allocation\SchedulingResult` | Value object: assignments, unallocated, metrics, violations |
| `Service\TimetableService` | Day / week / semester views, personalisation |
| `Service\NotificationService` | Fan-out on domain events, outbox writes |
| `Service\ReportingService` | Utilisation, peak usage, conflicts, lecturer load |
| `Service\AllocationService` | Transactional apply/confirm/cancel/override |
| `Repository\*` (interfaces) | Ports: `UserRepository`, `CourseRepository`, `RoomRepository`, `AllocationRepository`, … |
| `Exception\*` | `ConflictException`, `ValidationException`, `NotFoundException`, `ForbiddenException` |

**Domain invariants are enforced here.** A controller can never create a double
booking, because the only write path is `AllocationService`, which delegates to the
engine and the repository's unique index.

### 5.3 `src/Infrastructure` — outbound adapters

| Component | Responsibility |
| --- | --- |
| `Persistence\*Repository` | MySQL implementations of the domain ports; PDO prepared statements only |
| `Notification\InAppChannel` | Writes the in-app notification row |
| `Notification\EmailChannel` | Queues e-mail via the outbox |
| `Notification\SmsChannel` | Queues SMS via the outbox (provider-agnostic) |
| `Reporting\UtilisationRollup` | Nightly aggregation into `room_utilisation_daily` |

### 5.4 `src/Http` — inbound adapters

| Component | Responsibility | State |
| --- | --- | --- |
| `Controller` | abstract base: `Container` accessor, `identity()`, `rbac()`, envelope helpers | done |
| `Controller\HealthController` | `/health` liveness + readiness, unauthenticated | done |
| `Controller\AuthController` | register, login, refresh, logout, forgot/reset | done |
| `Controller\ProfileController` | own profile; admin role changes | ·planned |
| `Controller\CourseController` | CRUD (admin) | ·planned |
| `Controller\RoomController` | CRUD + live availability + comparison | ·planned |
| `Controller\CalendarController` | calendar exceptions and semester timelines | ·planned |
| `Controller\AllocationController` | generate, list, confirm, cancel, reassign/override | ·planned |
| `Controller\TimetableController` | day / week / semester views, scoped by role | ·planned |
| `Controller\SearchController` | rooms and schedules | ·planned |
| `Controller\NotificationController` | list, mark read | ·planned |
| `Controller\ReportController` | utilisation, peak, conflicts, CSV export | ·planned |
| `Controller\DashboardController` | admin summary metrics | ·planned |
| `Controller\AuditController` | read the audit trail | ·planned |
| `Controller\UserController` | user CRUD, role assignment (admin) | ·planned |
| `Middleware\DisableWhenMaintenance` | `storage/maintenance.flag` → 503 | done |
| `Middleware\RequireAuthentication` | verifies the JWT, loads the `Identity` principal | done |

Four points about how this behaves, because each is a deliberate choice rather
than an accident:

- **Only two middleware classes ship.** Everything else that reads like
  middleware — RBAC, validation, throttling, auditing — is *not*. RBAC is
  enforced by the controller calling `Rbac::assert($identity, $permission)`
  against the `route.permission` request attribute that `App::handle()` sets
  from `config/routes.php`; validation is a method on `Validator`; throttling is
  applied by `App` after the response is built, from the route's `throttle` key.
  The reason is that a permission check that a route can forget to declare is
  not a permission check, and putting it in the controller makes the check and
  the handler the same piece of reviewable code.
- **Handler resolution happens inside the terminal closure.** `App::dispatch()`
  calls `resolveHandler()` from the last link of the pipeline, not before it. The
  ordering property this buys is that an unimplemented route answers `401` to an
  anonymous caller and `501` only to an authenticated one — so the route table
  never doubles as a map of which endpoints exist for anyone who asks. The smoke
  check `http-auth-before-handler` asserts exactly this: `GET /timetable/week`
  as anonymous must be `401`, and a `501` there is a security finding, not a
  missing feature.
- **Middleware is a list of small classes, not a framework.**
  `App::pipelineFor()` returns the ordered list for a route, and prepends
  `DisableWhenMaintenance` to every one of them, so it cannot be forgotten at a
  call site. That is why maintenance mode yields `503` before `401`: a locked-out
  instance should say "we are down for maintenance", not "log in".
- **Controllers take the `Container`.** This is a service locator, and it is a
  conscious trade: the wiring is explicit in one file, and the alternative — a
  constructor with eleven ports — buys a testability that the HTTP layer, whose
  integration tests are planned anyway, would not use.

### 5.5 `src/Console` — the second inbound adapter

The console is an inbound adapter of the same layers, not a special case beside
them. This is what makes "the engine runs identically from a request and from a
batch job" (§8) true rather than aspirational.

| Component | Responsibility |
| --- | --- |
| `Kernel` | 12-command registry, resolves aliases, exit codes 0/1/2/127, Levenshtein "did you mean", one `reportThrowable()` |
| `Input` | `--name=value`, `--name value`, bare `--flag`, positionals, `--` terminator. `shifted()` returns a copy with the command name removed |
| `Output` | TTY colour (`NO_COLOR` / `FORCE_COLOR`), `definitions()`, `table()`, `json()` |
| `Launcher` | `main(array $argv, ?string $command = null, ?string $basePath = null)` — the shared `bin/` bootstrap |
| `Command\*` | One class per command; `Command` is the abstract base |

A subcommand is a **positional** argument (`worker drain`), not a flag. The
kernel therefore has to strip the command name before dispatching, which is
`Input::shifted()` — the single reason positional arguments work at all.

`Launcher` exists because seven entry-point scripts would otherwise repeat the
same argument-parsing boilerplate seven times. It cannot be autoloaded, though,
because the autoloader is what loads it, so each `bin/*.php` keeps its own
six-line SAPI guard and `require` of `vendor/autoload.php`.

---

## 6. Request Lifecycle

`POST /api/v1/allocations/42/confirm` as an administrator:

```
 1. Nginx            TLS terminate, static passthrough, coarse rate limit
 2. index.php        front controller: bootstrap Config, Database, ErrorHandler
 3. Router           match method + path pattern  →  route + params
                      no match → 404 here, before any middleware, so a wrong URL
                      is a wrong URL for an anonymous caller too
 4. App::handle      attach route, route.permission, route.scope, param.*,
                      request_id and started_at to the Request
 5. Middleware       DisableWhenMaintenance          (prepended to every route)
                        storage/maintenance.flag present → 503, before auth
 6. Middleware       RequireAuthentication
                        verify JWT signature + exp + nbf
                        reject if revoked (refresh-rotation check)
                        load User principal → Identity, attach to request
 7. Controller       AllocationController::confirm($request)
                        Rbac::assert($identity, 'allocation:confirm')
                        throws ForbiddenException → 403 if denied  (NFR-SEC-02)
                        resolveHandler() runs HERE — the first point at which a
                        missing controller can surface as 501 (§5.4)
 8. Validator        declarative body validation → 422 on the first bad field
 9. Service          DB::transaction {
                          lock allocation FOR UPDATE
                          assert status is not 'cancelled'      (BR-12)
                          transition confirmed → 'confirmed'
                          emit AllocationConfirmed event
                          append audit_log row                (FR-ADMIN-07)
                        }
10. Domain events    outbox: one row per recipient (cohort students + lecturer)
11. Response         200 { "data": { …allocation… } }
                      + X-Request-Id, X-Response-Time-Ms, and the route's
                        rate-limit headers, applied by App after the response
                        is built
12. Worker (async)   drains outbox → in-app rows now, e-mail/SMS by channel
```

Steps 1–6 and 11 are implemented. Steps 7–10 are the shape the remaining
controllers must follow, and step 7's ordering is the one that is easy to get
wrong: the permission check belongs *inside* the action, immediately before the
work, not in a pipeline stage a route can forget to declare.

Failure paths are uniform. `ExceptionHandler` branches on `instanceof`, not on
a code string, so a subclass added later cannot be forgotten in a `match`:

| Exception | HTTP | Body `error.code` | Note |
| --- | --- | --- | --- |
| `MalformedRequestException` | 400 | `MALFORMED_REQUEST` | Unparseable JSON, bad content type |
| `UnauthorizedException` | 401 | `UNAUTHENTICATED` | Adds `WWW-Authenticate: Bearer`. The message is byte-identical whether the caller is anonymous or the token is malformed, expired or revoked — distinguishing them tells an attacker which half of the attack worked, and is a user-enumeration vector through the token endpoint |
| `ForbiddenException` | 403 | `FORBIDDEN` | `error.details.required_permission` names the permission — a real usability win, and safe because the caller is already authenticated |
| `NotFoundException` | 404 | `NOT_FOUND` | |
| `MethodNotAllowedException` | 405 | `METHOD_NOT_ALLOWED` | Carries the `Allow` header RFC 9110 §15.5.6 requires. Separate from `NOT_FOUND` because "wrong verb" and "wrong URL" are different client bugs |
| `ConflictException` | 409 | `CONFLICT`, or `STALE_WRITE` | The second is an optimistic-lock miss, and is distinguished so a client can retry instead of telling the user |
| `ValidationException` | 422 | `VALIDATION_FAILED` | `error.details.fields` is first-error-per-field |
| `RateLimitException` | 429 | `RATE_LIMITED` | `Retry-After` plus the bucket's reset |
| `NotImplementedException` | 501 | `NOT_IMPLEMENTED` | A route documented in `config/routes.php` whose controller is not written yet. Only reachable after authentication (§5.4) |
| `MaintenanceException` | 503 | `MAINTENANCE` | `error.details.retry_after_seconds`, plus a `Retry-After` header |
| `InfeasibleProblemException` | 500 | `INTERNAL_ERROR` | Deliberately **not** a 4xx. It is a `RuntimeException`, not a `CatmsException`: the problem handed to the engine is structurally unusable, which is an operator or configuration fault, not a bad request. Reporting it as 4xx would let a client retry something that will fail identically forever. A timetable that is merely *full* is not this — that is `UnallocatedSession` (FR-ALLOC-05) |
| `ConfigurationException` | — | — | Thrown at boot, never converted to a response |
| anything else | 500 | `INTERNAL_ERROR` | Message suppressed, `X-Request-Id` logged, correlation id returned |

All responses share one envelope:

```json
{ "data": { }, "meta": { "page": 1, "per_page": 25, "total": 132 }, "error": null }
```

---

## 7. Domain Architecture

### 7.1 Aggregate boundaries

| Aggregate | Root | Consistency boundary | Rule |
| --- | --- | --- | --- |
| **Allocation** | `Allocation` | One row + its status + its notifications | Only `AllocationService` may transition status; every transition emits an event and an audit record |
| **Timetable** | *(pseudo-aggregate)* | The set of allocations for one (semester, department) | Generated atomically by the engine; a failed generation leaves the previous timetable untouched (NFR-REL-03) |
| **User** | `User` | Profile + role + status | Role changes are admin-only and audited |
| **Room** | `Room` | Capacity, features, status | Status change to `maintenance` must not orphan a confirmed allocation — the service cancels and notifies instead (BR-06, BR-09) |
| **Semester** | `Semester` | Calendar windows | Allocations must lie inside teaching windows (FR-CAL-04) |

### 7.2 Domain services vs. repositories

- **Repositories** answer *"what is stored?"* — pure queries and simple persistence.
- **Domain services** answer *"what is allowed?"* — `AllocationService`, `TimetableService`,
  `ReportingService` own policy; they orchestrate repositories and the engine.

### 7.3 Value objects

| Value object | Rules |
| --- | --- |
| `EmailAddress` | RFC-shaped validation, normalised lowercase |
| `PasswordHash` | never logged, never returned by a `toArray()` |
| `TimeRange` | `start < end`, overlap detection, duration in minutes |
| `Capacity` | positive integer; `fits(int $headcount)` |
| `RoomFeatures` | set semantics; `satisfies(RoomFeatures $required)` |
| `AllocationScore` | value object carrying `hardViolations`, `softPenalty`, breakdown |

---

## 8. Allocation Engine Placement

The engine lives in the **domain** and is invoked through a port, so it can run:

1. **On demand** — `POST /allocations/generate` (admin, synchronous, ≤ 30 s budget).
2. **From a batch job** — `bin/generate-timetable.php --semester=2026-A --dry-run`.
3. **On change** — `AllocationService` re-runs the engine for the affected day/window
   (FR-ALLOC-03 "recalculating dynamically when changes occur"). Re-runs are
   **incremental**: the engine accepts an existing timetable as a starting solution, so
   a single lecture change is cheap while a full regeneration is exhaustive.

It has **no** dependency on HTTP, PDO or the filesystem. Its inputs are plain
value-object graphs; its output is a `SchedulingResult`. This is what makes
`tests/Unit/Allocation/AllocationEngineTest.php` run in milliseconds with no fixtures.

Full algorithm design: [`ALLOCATION_ENGINE.md`](ALLOCATION_ENGINE.md).

---

## 9. Data Architecture

### 9.1 Principles

- **Normalised** to 3NF for correctness; denormalisation only where measured.
- **Every tenant-scoped table** has `department_id` + a composite index starting with it
  (NFR-SCALE-02).
- **Every timestamp** is `DATETIME` in UTC; presentation converts to
  `Africa/Accra`.
- **No cascade deletes** on historical allocation data — status transitions instead
  (audit requirement NFR-SEC-06).
- **The database is the final conflict guard.** A unique index on
  `(room_id, time_slot_id, week_number, active_guard)` for active allocations makes a
  double booking *impossible* even under concurrent requests, complementing BR-01.
  The `active_guard` generated column is the trick: a cancelled row contributes `NULL`,
  which no other row can collide with, so history is preserved and uniqueness still
  holds for live bookings. See [`DATA_MODEL.md`](DATA_MODEL.md) §3.4.

### 9.2 Read models

Timetable views are the hottest read path. They are served by:

- A **covering index** on `(department_id, semester_id, time_slot_id)`.
- `GET /timetable?scope=week` reads a single week in one indexed query.
- Utilisation analytics read the nightly rollup `room_utilisation_daily`, never the raw
  allocations table (NFR-SCALE-04).

### 9.3 Transaction boundaries

| Operation | Boundary |
| --- | --- |
| Generate timetable | One transaction over the whole (semester, department) — all-or-nothing |
| Confirm / cancel / reassign | One transaction: row lock + status + audit + outbox |
| Register / update profile | One transaction |
| Nightly rollup | Idempotent, re-runnable per day (`INSERT … ON DUPLICATE KEY UPDATE`) |

### 9.4 Schema

See [`DATA_MODEL.md`](DATA_MODEL.md) and [`db/schema.sql`](../db/schema.sql).

---

## 10. Security Architecture

```
   TLS 1.2+ in            bcrypt (cost 12)          parameterised
 ┌──────────────┐        ┌──────────────┐         ┌──────────────┐
 │  transport   │───────▶│  credentials │────────▶│  every query │──▶ MySQL
 │  encrypted   │        │  hashed      │         │  PDO prepared│
 └──────────────┘        └──────────────┘         └──────────────┘
         ▲                        ▲                        ▲
    Nginx TLS              password_hash()          no string SQL
                                                       anywhere
   Authorisation (RBAC) ──────────────────────────────────────┘
   3 roles × 25 permissions, single map in config/rbac.php
   enforced per route; default deny.

   Defence in depth:  TLS → rate limit → authenticate → authorise
                      → validate → audit
```

- **Token strategy** — short-lived JWT access token (15 min) + rotating refresh token
  (30 days, hashed at rest, single-use so replay is detectable).
- **Fail closed** — an unknown route, an unparsable token or an unlisted permission is
  a denial, never a pass.
- **Act 843** — data minimisation (only fields the system needs), retention windows on
  `audit_log` and expired sessions, and documented data-subject request handling.
  See [`SECURITY.md`](SECURITY.md) §6.

---

## 11. Notification Architecture

```
   domain event                     outbox table                  worker
 ┌──────────────────┐          ┌───────────────────┐        ┌─────────────────┐
 │ AllocationCreated│─────────▶│ notification_     │───────▶│ in-app row      │
 │ AllocationUpdated│          │ outbox            │        │ e-mail via SMTP │
 │ AllocationCanceld│          │ (status: pending) │        │ SMS via gateway │
 │ RoomUnavailable  │          └───────────────────┘        └─────────────────┘
 └──────────────────┘                  written in the            retried with
                                       SAME transaction          exponential
                                       as the allocation         backoff
```

**Why an outbox?** Because the allocation must not fail because an SMTP server is slow
(NFR-PERF-01), and because a notification must never be lost if the process dies
mid-commit. At-least-once delivery; the worker is idempotent on
`(allocation_id, recipient_id, channel)`.

Fan-out rules (FR-NOTIF-04): students enrolled in the cohort + the assigned lecturer +
all admins of the department.

---

## 12. Deployment Topology

```
                    ┌──────────────────────┐
   Internet ───────▶│  Load Balancer /     │
                    │  Nginx (TLS, :443)   │
                    └──────────┬───────────┘
                     ┌─────────┴─────────┐
                     ▼                   ▼
            ┌────────────────┐  ┌────────────────┐   stateless
            │  PHP-FPM #1    │  │  PHP-FPM #2    │   → scale horizontally
            └───────┬────────┘  └────────┬───────┘   (NFR-SCALE-03)
                    │                    │
                    └─────────┬──────────┘
                              ▼
                    ┌───────────────────┐        ┌──────────────────┐
                    │   MySQL 8 primary │───────▶│  read replica    │
                    └─────────┬─────────┘  repl └──────────────────┘
                              │
                              ▼
                    ┌───────────────────┐
                    │  outbox worker    │  (same image, different command)
                    │  rollup cron      │
                    └───────────────────┘

   Storage: object store for backups · encrypted volume snapshots
   Secrets: environment-injected, never in the image
```

`docker-compose.yml` reproduces the single-node version of this topology locally.
Environments and promotion: [`DEPLOYMENT.md`](DEPLOYMENT.md).

---

## 13. Cross-Cutting Concerns

| Concern | Implementation | Where |
| --- | --- | --- |
| **Logging** | PSR-3-style `Core\Logger`, JSON lines, request id, never logs credentials | `storage/logs/` |
| **Errors** | `ExceptionHandler` → uniform envelope; 500s get a correlation id | `Core\ExceptionHandler` |
| **Transactions** | `Database::transaction()` with deadlock retry | `Core\Database` |
| **Validation** | Declarative rule arrays, first error per field | `Core\Validator` |
| **Authorisation** | `Rbac` map, default deny | `Core\Rbac` |
| **Auditing** | Append-only `audit_log` for state-changing admin actions | `Core\Audit` |
| **Rate limiting** | Token bucket, MySQL-backed so it is shared across workers | `Core\RateLimiter` |
| **Idempotency** | `Idempotency-Key` honoured on `POST /allocations/generate` | `Http\Controller\AllocationController` |
| **Pagination** | Cursor on time-ordered lists, offset on admin tables | `Http\Request` |
| **i18n / timezone** | All storage UTC; render `Africa/Accra` | `Core\Config` |
| **Observability** | `/api/v1/health` (liveness), `/api/v1/metrics` (Prometheus text) | `Http\Controller\HealthController` |

---

## 14. Architectural Decisions (ADRs)

### ADR-001 — Pure-PHP domain with port interfaces
**Context.** The allocation engine is the core, most-tuned, most-proved part.
**Decision.** `src/Domain` has zero framework or I/O dependencies; infrastructure
implements domain-defined repository interfaces.
**Consequences.** Engine unit-tests run with no database or HTTP; CLI and web share one
code path. Cost: a little more upfront mapping code.

### ADR-002 — Layered, not event-sourced
**Context.** Requirements demand auditability, not a full event-sourced ledger.
**Decision.** Current-state tables plus an append-only `audit_log`; domain events exist
in-process only, to drive the notification outbox.
**Consequences.** Simple queries, simple backups. Cost: historical state is audit-log
granularity, not full temporal replay.

### ADR-003 — Synchronous engine + asynchronous notifications
**Context.** NFR-PERF-01 (< 3 s) must not be hostage to a third-party SMTP provider.
**Decision.** Allocation commits synchronously with outbox rows; the worker delivers.
**Consequences.** Fast, reliable writes; notifications are eventually consistent
(typically < 5 s).

### ADR-004 — PWA rather than a native app
**Context.** UR-5 requires mobile access; §5.2 lists a native app as future work.
**Decision.** Installable, responsive PWA over the same JSON API.
**Consequences.** One codebase, offline shell, add-to-homescreen. Cost: no app-store
presence and no background push on iOS — mitigated by in-app polling and the
`NotificationChannel` port.

### ADR-005 — Greedy + local search, not ILP/MILP
**Context.** §2.8 cites integer linear programming and metaheuristics. No solver
dependency is permitted in the stated stack.
**Decision.** Most-constrained-first greedy seeding, then stochastic local search over a
weighted cost function.
**Consequences.** No external solver, fast enough for a 30 s budget, 95 % accuracy
per Table 4.1. Cost: not provably optimal; mitigated by restarts and by reporting
unallocated sessions rather than hiding them (FR-ALLOC-05).

### ADR-006 — Database uniqueness as the final double-booking guard
**Context.** FR-ALLOC-02 is a hard correctness requirement under concurrency.
**Decision.** The engine is the first line of defence; a unique index on active
allocations is the backstop that makes a double booking impossible.
**Consequences.** Correct even if the engine is bypassed by a future code path.

### ADR-007 — Server-side RBAC, never client-side
**Context.** NFR-SEC-02 and Act 843.
**Decision.** Every route declares a permission; `Rbac` denies by default. The client
hides UI it cannot use, but the server is the authority.
**Consequences.** A modified client gains nothing.

---

## 15. Quality Attribute Scenarios

Quantitative, checkable statements of the architecture doing its job.

### QA-1 — Peak-hour timetable change (performance)

| | |
| --- | --- |
| **Source** | Lecturer's phone, 08:00 on Monday of week 1 |
| **Stimulus** | Admin reassigns a lecture to another room |
| **Environment** | Normal mode, ~1 500 concurrent students, peak academic hours |
| **Artefact** | `PATCH /api/v1/allocations/{id}` → notification fan-out |
| **Response** | Update visible to affected users in **< 3 s**; notification delivered < 5 s |

*Satisfies* NFR-PERF-01, FR-TIME-02, FR-NOTIF-02.

### QA-2 — Concurrent booking of the same room (correctness)

| | |
| --- | --- |
| **Source** | Two administrators, two different browsers |
| **Stimulus** | Simultaneous confirmation of two lectures into the same room and slot |
| **Artefact** | Allocation write path |
| **Response** | Exactly one succeeds; the other receives `409 CONFLICT`. Zero double bookings recorded. |

*Satisfies* FR-ALLOC-02, BR-01, ADR-006.

### QA-3 — Dynamic recalculation on change (adaptability)

| | |
| --- | --- |
| **Stimulus** | A lecturer's enrolment in a course changes from 30 → 70 |
| **Artefact** | `AllocationService` → engine incremental re-run for the affected window |
| **Response** | Allocation is re-evaluated; if the assigned room is now too small it is replaced and all affected users are notified |

*Satisfies* FR-ALLOC-03, BR-04.

### QA-4 — Unschedulable input (transparency)

| | |
| --- | --- |
| **Stimulus** | 120 students need one 2-hour slot; the only room with capacity ≥ 120 is already booked |
| **Artefact** | Engine |
| **Response** | `SchedulingResult` reports the session as unallocated with the specific violated constraints and the count of feasible alternatives (0). Admin sees a conflict report — never a silently wrong timetable |

*Satisfies* FR-ALLOC-05, OBJ-5.

### QA-5 — Security boundary (NFR-SEC-02)

| | |
| --- | --- |
| **Source** | A student using a modified client that calls an admin endpoint directly |
| **Artefact** | `RbacMiddleware` |
| **Response** | `403 FORBIDDEN`; the request is logged. The action never reaches the domain |

---

## Related Documents

- [`REQUIREMENTS.md`](REQUIREMENTS.md) — the specification this architecture serves
- [`ALLOCATION_ENGINE.md`](ALLOCATION_ENGINE.md) — the core algorithm
- [`DATA_MODEL.md`](DATA_MODEL.md) — schema and ER model
- [`API.md`](API.md) — endpoint reference
- [`SECURITY.md`](SECURITY.md) — threat model, RBAC matrix, Act 843
- [`IMPLEMENTATION.md`](IMPLEMENTATION.md) — how to build it
