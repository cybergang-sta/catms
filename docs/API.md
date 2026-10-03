# API Reference

**CATMS — Classroom Allocation & Timetable Management System**
API version `v1` · Applies to `src/Http`

> Every endpoint declares the requirement IDs it satisfies. If an endpoint and
> `docs/REQUIREMENTS.md` disagree, the requirement wins and the endpoint is a bug.

---

## Table of Contents

1. [Conventions](#1-conventions)
2. [Authentication](#2-authentication)
3. [Authorisation (RBAC)](#3-authorisation-rbac)
4. [Error Model](#4-error-model)
5. [Pagination, Filtering, Sorting](#5-pagination-filtering-sorting)
6. [Rate Limits](#6-rate-limits)
7. [Endpoint Catalogue](#7-endpoint-catalogue)
8. [Health & Observability](#8-health--observability)
9. [Authentication Endpoints](#9-authentication-endpoints)
10. [Profile Endpoints](#10-profile-endpoints)
11. [User Management](#11-user-management)
12. [Rooms & Classrooms](#12-rooms--classrooms)
13. [Courses, Cohorts & Enrolment](#13-courses-cohorts--enrolment)
14. [Semesters & Academic Calendar](#14-semesters--academic-calendar)
15. [Allocations](#15-allocations)
16. [Timetables](#16-timetables)
17. [Search](#17-search)
18. [Notifications](#18-notifications)
19. [Reports & Export](#19-reports--export)
20. [Dashboard](#20-dashboard)
21. [Audit Trail](#21-audit-trail)
22. [Notification Outbox Semantics](#22-notification-outbox-semantics)
23. [Requirement → Endpoint Traceability](#23-requirement--endpoint-traceability)

---

## 1. Conventions

### 1.1 Base URL and versioning

```
https://{host}/api/v1
```

`v1` is frozen for the life of the major version (NFR-MAINT-04). Breaking changes
require `/api/v2`; additive fields do not. Fields are never removed or retyped
inside `v1` — clients must ignore unknown keys.

| Item | Value |
| --- | --- |
| Request content type | `application/json` (exceptions below) |
| Response content type | `application/json; charset=utf-8` |
| CSV responses | `text/csv; charset=utf-8` |
| Authentication | `Authorization: Bearer <access_token>` |
| Transport | TLS 1.2+ mandatory in production (NFR-SEC-01) |
| Character encoding | UTF-8 throughout; `utf8mb4` at rest |

Non-JSON request bodies are accepted on exactly two endpoints, both documented
in place:

- `POST /api/v1/auth/login` and `POST /api/v1/auth/refresh` also accept
  `application/x-www-form-urlencoded`, so the plain HTML login form works without JavaScript.
- `POST /api/v1/auth/logout` accepts an empty body.

### 1.2 Response envelope

Every response — success or failure — uses the same three top-level keys.

**Success**

```json
{
  "data":     { "id": 42, "room_id": 3, "status": "confirmed" },
  "meta":     { "request_id": "0f3d1c9e-2b7a-4f1e-9c5b-8a0d1e2f3c4b" },
  "error":    null
}
```

**Failure**

```json
{
  "data":  null,
  "meta":  { "request_id": "0f3d1c9e-2b7a-4f1e-9c5b-8a0d1e2f3c4b" },
  "error": {
    "code":    "VALIDATION_FAILED",
    "message": "The submitted data is not valid.",
    "details": { "email": ["This value is not a valid email address."] }
  }
}
```

`data` is `null` on failure. `meta` is always present so a support ticket can be
traced with nothing but the response body. `request_id` is also returned as the
`X-Request-Id` response header and written to every log line for that request.

### 1.3 Dates, times and time zones

- All timestamps on the wire are ISO-8601 with an explicit offset: `2026-09-04T08:00:00+00:00`.
- All storage is UTC `DATETIME`; the default presentation zone is
  `Africa/Accra` (`UTC+00:00`, no DST).
- A **weekly recurring slot** is a day-of-week + local time pair (`day_of_week`
  1 = Monday per ISO-8601, `start_time`, `end_time`), never an absolute instant.
  A timetables response supplies both the recurring slot and the resolved
  `date` for a requested week.
- Time-of-day values are `HH:MM:SS`; durations are integer minutes.
- Decimal money is absent from this domain. Scores and penalties are decimal
  fractions (`0.9400`) or weighted penalty units, rounded to 4 dp.

### 1.4 Idempotency

Non-GET endpoints that create a resource or trigger a run accept an
`Idempotency-Key` header (a client-generated UUID):

```http
POST /api/v1/allocations/generate
Idempotency-Key: 3f6b1c2a-9d4e-4a17-b0c8-51e2d7a9c331
```

The first request with a given key is executed and its status code + body are
stored for 24 hours. A repeat within that window replays the stored response and
adds `meta.idempotent_replay: true`, without re-running the engine. Required for
`POST /allocations/generate`, `POST /auth/refresh` and recommended for every
non-GET write, because the PWA retries aggressively on flaky mobile networks.

### 1.5 Caching

Authenticated GET responses are `Cache-Control: private, no-store` — a timetable
is per-user and must never land in a shared cache. Static assets under
`/assets/` are fingerprinted and served `immutable, max-age=31536000`.
`ETag` is emitted on all GETs; send `If-None-Match` to receive `304`.

### 1.6 Concurrency

Optimistic concurrency is available on the mutable aggregates via `ETag`/`If-Match`.
A mismatch returns `409 CONFLICT` with `error.code = STALE_WRITE` rather than
silently clobbering an administrator's change. Pessimistic row locking
(`SELECT … FOR UPDATE`) is used on the allocation write path — see
`docs/ARCHITECTURE.md` §9.3.

### 1.7 Tenant scoping

Every tenant-scoped table carries `department_id` (NFR-SCALE-02). The scope is
resolved in this order:

1. an explicit `department_id` in the path, if the route has one;
2. `X-Department-Id` header, honoured only for a user who is an admin in more
   than one department;
3. the caller's own `department_id` otherwise.

A record outside the resolved scope returns `404 NOT_FOUND`, not `403` — a
student must not be able to probe for the existence of other departments'
timetables (Act 843 minimisation, `docs/SECURITY.md` §6).

---

## 2. Authentication

Two-token scheme (NFR-SEC-01, FR-AUTH-04).

| Token | Lifetime | Storage | Rotation |
| --- | --- | --- | --- |
| **Access** | 15 minutes | Client memory only (never `localStorage`) | New access token on every refresh |
| **Refresh** | 30 days, sliding | `refresh_tokens` table, **hashed** at rest | Single-use; replay revokes the whole family |

Access tokens are JWT, `HS256`, signed with `APP_JWT_SECRET`. Required claims:
`sub` (user id), `role`, `dept` (department id), `iat`, `exp`, `nbf`, `jti`.
Refresh tokens are 64 bytes of CSPRNG output; only `sha256(token)` is stored,
so a database dump cannot be replayed against the API.

Token rotation is chained by `family_id`. Presenting a refresh token whose
`used_at` is already set is treated as theft: the entire family is revoked, a
`token.replay` `security_events` row is written, and every active session for
that user is invalidated. See `docs/SECURITY.md` §4.

Session expiry after 15 minutes of inactivity is enforced client-side by a
timer; the server independently rejects an expired access token with `401` and
`WWW-Authenticate: Bearer error="invalid_token"`.

---

## 3. Authorisation (RBAC)

Server-side, default-deny, enforced by `RbacMiddleware` before the controller
runs (ADR-007, NFR-SEC-02). The client hides UI it cannot use, but the client is
never the authority.

Each route declares exactly one permission and, where relevant, a **scope**:

| Scope | Meaning |
| --- | --- |
| `own` | Only rows the caller is personally attached to (own allocations, own cohort, own profile) |
| `department` | Any row inside the resolved `department_id` |
| `any` | Cross-department; admin-only |

### 3.1 Permission matrix

Roles are data, seeded from [`config/rbac.php`](../config/rbac.php); the matrix
below is the single source of truth and `docs/SECURITY.md` §3 mirrors it.

| # | Permission | Scope | student | lecturer | admin | Group |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | `timetable:view` | own / department | own | own | any | timetable |
| 2 | `room:view` | department | ✓ | ✓ | ✓ | room |
| 3 | `room:manage` | department | — | — | ✓ | room |
| 4 | `course:view` | department | ✓ | ✓ | ✓ | course |
| 5 | `course:manage` | department | — | — | ✓ | course |
| 6 | `cohort:manage` | department | — | — | ✓ | course |
| 7 | `semester:view` | department | ✓ | ✓ | ✓ | calendar |
| 8 | `semester:manage` | department | — | — | ✓ | calendar |
| 9 | `allocation:view` | own / department | own | own | any | allocation |
| 10 | `allocation:generate` | department | — | — | ✓ | allocation |
| 11 | `allocation:confirm` | department | — | — | ✓ | allocation |
| 12 | `allocation:override` | department | — | — | ✓ | allocation |
| 13 | `allocation:cancel` | department | — | — | ✓ | allocation |
| 14 | `allocation:repair` | department | — | — | ✓ | allocation |
| 15 | `allocation:view_conflicts` | own / department | own | own | any | allocation |
| 16 | `profile:view_self` | own | ✓ | ✓ | ✓ | profile |
| 17 | `profile:update_self` | own | ✓ | ✓ | ✓ | profile |
| 18 | `user:view` | department | — | — | ✓ | user |
| 19 | `user:manage` | department | — | — | ✓ | user |
| 20 | `user:change_role` | department | — | — | ✓ | user |
| 21 | `report:view` | department | — | — | ✓ | report |
| 22 | `report:export` | department | — | — | ✓ | report |
| 23 | `audit:view` | department | — | — | ✓ | audit |
| 24 | `notification:view_self` | own | ✓ | ✓ | ✓ | notification |
| 25 | `notification:dispatch` | department | — | — | ✓ | notification |

**25 permissions × 3 roles.** `any` scope is reachable only by `admin` with
`department_id IS NULL` in `users` (a system-level administrator); a
departmental `admin` is capped at `department` scope.

The client's own role determines which controls it renders. A modified client
calling an endpoint outside its role receives `403 FORBIDDEN` and the attempt is
recorded in `security_events` (QA-5, `docs/ARCHITECTURE.md` §15).

---

## 4. Error Model

| HTTP | `error.code` | Domain exception | When |
| --- | --- | --- | --- |
| `400` | `MALFORMED_REQUEST` | — | Unparseable JSON body, bad query parameter type |
| `401` | `UNAUTHENTICATED` | `UnauthorizedException` | Missing, malformed, expired or revoked token |
| `403` | `FORBIDDEN` | `ForbiddenException` | Authenticated but lacking the route permission |
| `404` | `NOT_FOUND` | `NotFoundException` | No such resource **or** outside the tenant scope |
| `409` | `CONFLICT` | `ConflictException` | State conflict: room already booked, illegal status transition, `If-Match` mismatch (`STALE_WRITE`) |
| `422` | `VALIDATION_FAILED` | `ValidationException` | Body failed declarative validation; `error.details` maps field → messages |
| `422` | `INFEASIBLE_PROBLEM` | `InfeasibleProblemException` | `strict` generate mode: the problem has no usable slot at all |
| `429` | `RATE_LIMITED` | `RateLimitException` | Rate limit hit; `Retry-After` and `X-RateLimit-*` headers set |
| `500` | `INTERNAL_ERROR` | any unhandled | Message suppressed, `meta.request_id` logged for correlation |
| `503` | `MAINTENANCE` | — | Read-only mode during deploy/migration |

Field errors use the validator's first-failure-per-field rule, so
`error.details` always has at least one message per offending field:

```json
{
  "error": {
    "code": "VALIDATION_FAILED",
    "message": "The submitted data is not valid.",
    "details": {
      "capacity":   ["The capacity must be at least 1."],
      "code":       ["The code must be 20 characters or fewer."],
      "time_slot":  ["The selected time slot is not inside the teaching window (HC-7)."]
    }
  }
}
```

Validation errors that correspond to a hard constraint are prefixed with the
constraint code, so a client can map `HC-7` straight onto the conflict UI.

---

## 5. Pagination, Filtering, Sorting

**Offset pagination** for admin tables (bounded, deep-pageable, countable):

```http
GET /api/v1/allocations?page=2&per_page=50
```

```json
"meta": { "page": 2, "per_page": 50, "total": 132, "total_pages": 3 }
```

`per_page` default 25, maximum 200 (`422` above that). Hot time-ordered lists use
a cursor instead, because offset pagination on a large allocation table degrades
and can skip rows:

```http
GET /api/v1/timetable?semester_id=7&scope=week&cursor=eyJpZCI6MTAyNH0
```

```json
"meta": { "next_cursor": "eyJpZCI6MTAyNX0", "has_more": true }
```

**Filtering** — any listed field is a query parameter; repeated values mean *in*.

| Parameter | Applies to | Example |
| --- | --- | --- |
| `semester_id` | most list endpoints | `?semester_id=7` |
| `department_id` | admin only | `?department_id=3` |
| `status` | allocations | `?status=confirmed,updated` |
| `room_id`, `lecturer_id`, `cohort_id`, `course_id` | allocations | `?lecturer_id=12` |
| `day_of_week` | timetable, allocations | `?day_of_week=1` |
| `week_number` | allocations, timetable | `?week_number=3` |
| `from`, `to` | reports, audit | `?from=2026-09-01&to=2026-09-30` |
| `building` | rooms, reports | `?building=Block+A` |
| `unresolved_only` | conflicts | `?unresolved_only=true` |

**Sorting** — `?sort=field` ascending, `?sort=-field` descending. Whitelisted
per endpoint; an unknown field is `400 MALFORMED_REQUEST`, never interpolated
into SQL (NFR-SEC-07).

---

## 6. Rate Limits

MySQL-backed token buckets (`rate_limit_buckets`), shared across all workers
(NFR-SEC-05). Every response carries the current state:

```http
X-RateLimit-Limit: 5
X-RateLimit-Remaining: 3
X-RateLimit-Reset: 1756953600
```

| Bucket | Limit | Window | Key |
| --- | --- | --- | --- |
| `login` | 5 | 1 min | IP + e-mail |
| `password_reset` | 3 | 1 h | IP + e-mail |
| `refresh` | 30 | 1 min | user id |
| `search` | 60 | 1 min | user id |
| `write` | 120 | 1 min | user id |
| `generate` | 10 | 1 h | user id |

Authentication buckets are deliberately strict, and a login bucket that trips
5 times also increments `users.failed_login_count`; the account locks for 15
minutes after 5 consecutive failures (NFR-SEC-05).

---

## 7. Endpoint Catalogue

| Method | Path | Permission | Scope | Requirements |
| --- | --- | --- | --- | --- |
| `GET` | `/health` | — | — | NFR-REL-01 |
| `GET` | `/metrics` | — | — | NFR-PERF-01 |
| `POST` | `/auth/register` | — | — | FR-AUTH-01 |
| `POST` | `/auth/login` | — | — | FR-AUTH-02 |
| `POST` | `/auth/refresh` | — | — | FR-AUTH-04 |
| `POST` | `/auth/logout` | authenticated | own | FR-AUTH-04 |
| `POST` | `/auth/forgot-password` | — | — | FR-AUTH-03 |
| `POST` | `/auth/reset-password` | — | — | FR-AUTH-03 |
| `GET` | `/auth/me` | authenticated | own | FR-AUTH-05 |
| `GET` | `/profile` | `profile:view_self` | own | FR-PROF-01 |
| `PATCH` | `/profile` | `profile:update_self` | own | FR-PROF-01 |
| `PATCH` | `/profile/password` | `profile:update_self` | own | NFR-SEC-03 |
| `GET` | `/users` | `user:view` | department | FR-ADMIN-01 |
| `POST` | `/users` | `user:manage` | department | FR-ADMIN-01 |
| `GET` | `/users/{id}` | `user:view` | department | FR-ADMIN-01 |
| `PATCH` | `/users/{id}` | `user:manage` | department | FR-ADMIN-01, FR-PROF-01 |
| `DELETE` | `/users/{id}` | `user:manage` | department | FR-ADMIN-01, Act 843 |
| `PATCH` | `/users/{id}/role` | `user:change_role` | department | FR-PROF-02, FR-ADMIN-01 |
| `GET` | `/users/{id}/load` | `user:view` | department | FR-PROF-03 |
| `GET` | `/rooms` | `room:view` | department | FR-SEARCH-01 |
| `POST` | `/rooms` | `room:manage` | department | FR-ROOM-03, FR-ADMIN-02 |
| `GET` | `/rooms/{id}` | `room:view` | department | FR-ROOM-01 |
| `PATCH` | `/rooms/{id}` | `room:manage` | department | FR-ROOM-03 |
| `DELETE` | `/rooms/{id}` | `room:manage` | department | FR-ROOM-03 |
| `GET` | `/rooms/{id}/availability` | `room:view` | department | FR-ROOM-01 |
| `GET` | `/rooms/compare` | `room:view` | department | FR-ROOM-02 |
| `GET` | `/courses` | `course:view` | department | FR-ROOM-04 |
| `POST` | `/courses` | `course:manage` | department | FR-ADMIN-03 |
| `GET` | `/courses/{id}` | `course:view` | department | FR-ADMIN-03 |
| `PATCH` | `/courses/{id}` | `course:manage` | department | FR-ADMIN-03 |
| `DELETE` | `/courses/{id}` | `course:manage` | department | FR-ADMIN-03 |
| `GET` | `/courses/{id}/cohorts` | `course:view` | department | FR-ROOM-04 |
| `POST` | `/cohorts` | `cohort:manage` | department | FR-ROOM-04 |
| `GET` | `/cohorts/{id}` | `course:view` | department | FR-ROOM-04 |
| `PATCH` | `/cohorts/{id}` | `cohort:manage` | department | FR-ROOM-04 |
| `POST` | `/cohorts/{id}/enrolments` | `cohort:manage` | department | FR-ROOM-04 |
| `DELETE` | `/cohorts/{id}/enrolments/{userId}` | `cohort:manage` | department | FR-ROOM-04, FR-ALLOC-03 |
| `GET` | `/semesters` | `semester:view` | department | FR-CAL-02 |
| `POST` | `/semesters` | `semester:manage` | department | FR-CAL-03 |
| `GET` | `/semesters/{id}` | `semester:view` | department | FR-CAL-02 |
| `PATCH` | `/semesters/{id}` | `semester:manage` | department | FR-CAL-03 |
| `GET` | `/semesters/{id}/timeline` | `semester:view` | department | FR-CAL-02, FR-TIME-06 |
| `GET` | `/semesters/{id}/exceptions` | `semester:view` | department | FR-CAL-02, HC-7 |
| `POST` | `/semesters/{id}/exceptions` | `semester:manage` | department | FR-CAL-03 |
| `GET` | `/slots` | `semester:view` | department | FR-TIME-01 |
| `POST` | `/slots` | `semester:manage` | department | FR-TIME-05 |
| `GET` | `/availability/lecturers` | `semester:view` | department | HC-8 |
| `POST` | `/availability/lecturers` | `semester:manage` | department | HC-8 |
| `DELETE` | `/availability/lecturers/{id}` | `semester:manage` | department | HC-8 |
| `POST` | `/availability/rooms` | `room:manage` | department | HC-9 |
| `GET` | `/allocations` | `allocation:view` | own / any | FR-ALLOC-06 |
| `POST` | `/allocations/generate` | `allocation:generate` | department | FR-ALLOC-01, FR-ALLOC-03, NFR-PERF-02/04 |
| `GET` | `/allocations/{id}` | `allocation:view` | own / any | FR-ALLOC-06 |
| `PATCH` | `/allocations/{id}` | `allocation:override` | department | FR-ALLOC-04, FR-TIME-02, BR-08, BR-12 |
| `POST` | `/allocations/{id}/confirm` | `allocation:confirm` | department | FR-BOOK-01, FR-ALLOC-06 |
| `POST` | `/allocations/{id}/cancel` | `allocation:cancel` | department | FR-NOTIF-02, BR-09 |
| `POST` | `/allocations/{id}/reassign` | `allocation:override` | department | FR-TIME-02, FR-ALLOC-03 |
| `GET` | `/allocations/{id}/conflicts` | `allocation:view_conflicts` | own / any | FR-ALLOC-05 |
| `GET` | `/allocations/conflicts` | `allocation:view_conflicts` | own / any | FR-ALLOC-05 |
| `POST` | `/allocations/conflicts/{id}/resolve` | `allocation:override` | department | FR-ALLOC-05 |
| `GET` | `/allocations/runs` | `allocation:view` | department | NFR-PERF-04 |
| `GET` | `/allocations/runs/{runId}` | `allocation:view` | department | NFR-PERF-04 |
| `POST` | `/allocations/repair` | `allocation:repair` | department | FR-ALLOC-03 |
| `GET` | `/timetable` | `timetable:view` | own / any | FR-TIME-01, FR-TIME-03/04 |
| `GET` | `/timetable/day` | `timetable:view` | own / any | FR-TIME-01 |
| `GET` | `/timetable/semester` | `timetable:view` | own / any | FR-TIME-06 |
| `GET` | `/timetable/{semesterId}/export.csv` | `timetable:view` | own / any | FR-TIME-01 |
| `GET` | `/search/rooms` | `room:view` | department | FR-SEARCH-01 |
| `GET` | `/search/schedules` | `allocation:view` | own / any | FR-SEARCH-02 |
| `GET` | `/notifications` | `notification:view_self` | own | FR-NOTIF-01, FR-NOTIF-03 |
| `GET` | `/notifications/unread-count` | `notification:view_self` | own | FR-NOTIF-03 |
| `POST` | `/notifications/{id}/read` | `notification:view_self` | own | FR-NOTIF-03 |
| `POST` | `/notifications/read-all` | `notification:view_self` | own | FR-NOTIF-03 |
| `GET` | `/reports/utilisation` | `report:view` | department | FR-REPORT-01, BR-10 |
| `GET` | `/reports/peak-usage` | `report:view` | department | FR-REPORT-02 |
| `GET` | `/reports/conflicts` | `report:view` | department | FR-REPORT-02 |
| `GET` | `/reports/lecturer-load` | `report:view` | department | FR-REPORT-02, FR-PROF-03 |
| `GET` | `/reports/export.csv` | `report:export` | department | FR-REPORT-03 |
| `GET` | `/dashboard` | `user:view` | department | FR-ADMIN-05 |
| `GET` | `/dashboard/heat-map` | `report:view` | department | FR-ADMIN-06 |
| `GET` | `/audit` | `audit:view` | department | FR-ADMIN-07, NFR-SEC-06 |

---

## 8. Health & Observability

### `GET /health`

Liveness/readiness probe. No authentication, no database write.

```json
{
  "data": {
    "status": "ok",
    "version": "1.0.0",
    "engine_version": "1.0.0",
    "environment": "production",
    "uptime_seconds": 41322,
    "checks": {
      "database":  { "ok": true, "latency_ms": 2 },
      "outbox":    { "ok": true, "pending": 0, "dead": 0 },
      "migrations":{ "ok": true, "current": "2026_09_01_120000" }
    }
  },
  "meta": { "request_id": "…" },
  "error": null
}
```

`status` is `"degraded"` (HTTP `200`) when only a non-critical check fails, and
`"unhealthy"` (HTTP `503`) when the database or migrations fail — the load
balancer removes the instance on `503`.

### `GET /metrics`

Prometheus text exposition (`text/plain; version=0.0.4`), no authentication but
restricted to the internal network at the edge. Exposes
`http_requests_total{method,route,status}`,
`http_request_duration_seconds{route}` (histogram),
`allocation_runs_total{mode,outcome}`,
`allocation_accuracy_ratio` (gauge, rolling mean),
`allocation_run_duration_seconds` (histogram),
`notification_outbox_depth{status}`, `db_query_duration_seconds`.

> Route labels are the **route pattern**, never the raw path, so cardinality stays
> bounded and user ids never reach the metrics store.

---

## 9. Authentication Endpoints

### `POST /auth/register` — FR-AUTH-01

Public. Creates a `pending` account; a verification link is queued in-app and by
e-mail. Role may only be `student` or `lecturer`; `admin` is grantable only by
an existing admin (FR-PROF-02).

**Body**

```json
{
  "email": "kwame.agyapong@utas.edu.gh",
  "password": "correct horse battery staple",
  "password_confirmation": "correct horse battery staple",
  "role": "student",
  "first_name": "Kwame",
  "last_name": "Agyapong",
  "phone": "+233241234567",
  "student_index": "20210412166"
}
```

| Field | Rules |
| --- | --- |
| `email` | required, RFC-shaped, max 190, normalised to lowercase, must be unique |
| `password` | required, min 12 chars, max 200 (bcrypt truncates at 72 **bytes**), not in the breached list |
| `role` | required, `student` \| `lecturer` |
| `first_name`, `last_name` | required, max 80 |
| `phone` | optional, E.164-ish, max 30 |
| `student_index` | required when `role=student`, unique |

`201` → `{"data": {"id": 88, "email": "…", "status": "pending", "email_verified_at": null}}`
`409` → e-mail already registered (message deliberately does not reveal *which* field).
`422` → validation errors.

`password_confirmation` is required so a mistyped password is caught client-side;
it is never stored or logged.

### `POST /auth/login` — FR-AUTH-02

```json
{ "email": "admin@utas.edu.gh", "password": "Admin@1234", "remember": true }
```

**`200`**

```json
{
  "data": {
    "access_token":  "eyJhbGciOiJIUzI1NiIs…",
    "token_type":    "Bearer",
    "expires_in":    900,
    "refresh_token": "0Zb1cR3nK9x…",
    "user": {
      "id": 1, "role": "admin", "department": { "id": 3, "code": "CS", "name": "Computer Science" },
      "first_name": "Adwoa", "last_name": "Mensah", "email": "admin@utas.edu.gh",
      "must_change_password": false
    }
  }
}
```

| Failure | Status | Notes |
| --- | --- | --- |
| Bad credentials | `401 UNAUTHENTICATED` | One message for unknown e-mail and wrong password — no user enumeration |
| Account locked | `401` + `error.details.retry_after_seconds` | 15-minute lock after 5 consecutive failures |
| Not verified | `403 FORBIDDEN` | `email_verified_at IS NULL` |
| Suspended/archived | `403 FORBIDDEN` | `users.status` gate |
| Rate limited | `429` | `Retry-After` |

The access token is returned in the body **and** set as an
`HttpOnly; Secure; SameSite=Strict` cookie `catms_refresh` for the browser
client, so the refresh token never touches JavaScript. A native or scripted
client may use the body value instead.

A successful login writes a `login.success` `security_events` row and updates
`users.last_login_at`.

### `POST /auth/refresh` — FR-AUTH-04

Body or `catms_refresh` cookie. Rotates: the presented token is marked used and a
new one is issued in the same family.

**`200`** → new `access_token` + new `refresh_token`.
**`401`** → expired, revoked, or **replayed** (a used token revokes the family).

Honours `Idempotency-Key` so a PWA that retries after a dropped response does
not accidentally trigger the replay detector. That is the single most likely way
a legitimate mobile client logs itself out.

### `POST /auth/logout` — FR-AUTH-04

Revokes the presented refresh token and its family, and clears the cookie.
`204 No Content`. Safe to call with an already-invalid token (idempotent).

### `POST /auth/forgot-password` — FR-AUTH-03

```json
{ "email": "kwame.agyapong@utas.edu.gh" }
```

Always `202 Accepted` with the same body, whether or not the address exists —
responding differently would turn this endpoint into an account enumerator.
When the account exists, a single-use, 60-minute token is stored hashed in
`password_reset_tokens` and an e-mail is queued **in the outbox**. Rate limited
to 3/hour per IP + e-mail.

### `POST /auth/reset-password` — FR-AUTH-03

```json
{ "token": "…", "password": "…", "password_confirmation": "…" }
```

`200` on success; `400 MALFORMED_REQUEST` for an unknown/expired/used token, and
deliberately the *same* message for all three. On success: the new hash is set,
`must_change_password` is cleared, **every refresh token family for the user is
revoked**, a `password.reset` `security_events` row is written, and the user is
notified by e-mail that their password changed.

### `GET /auth/me`

Returns the authenticated principal plus the permission list the client uses to
render its navigation, and the resolved tenant scope. Cheap; the PWA calls it on
every cold start.

```json
{
  "data": {
    "user": { "id": 1, "role": "admin", "email": "admin@utas.edu.gh",
              "first_name": "Adwoa", "last_name": "Mensah", "phone": null,
              "department_id": 3, "status": "active",
              "must_change_password": false, "last_login_at": "2026-09-04T07:58:11+00:00" },
    "scope":  { "department_id": 3, "can_cross_departments": false },
    "permissions": ["timetable:view", "room:view", "…"],
    "unread_notifications": 2
  }
}
```

---

## 10. Profile Endpoints

### `GET /profile` — FR-PROF-01

Own profile only. Includes role, department, and — for a lecturer — the assigned
courses and a summary teaching load (FR-PROF-03).

```json
{
  "data": {
    "id": 12, "role": "lecturer",
    "first_name": "Kwabena", "last_name": "Owusu", "email": "lecturer@utas.edu.gh",
    "phone": "+233201112233", "staff_id": "UTAS-STAFF-0042",
    "department": { "id": 3, "code": "CS", "name": "Computer Science" },
    "status": "active", "must_change_password": false,
    "courses":  [ { "course_id": 4, "code": "CS201", "title": "Data Structures", "is_primary": true } ],
    "weekly_sessions": 6, "distinct_slots": 6,
    "last_login_at": "2026-09-03T14:02:00+00:00"
  }
}
```

`password_hash`, `failed_login_count`, `locked_until` and `refresh_tokens` are
never serialised — the entity's `toArray()` omits them by construction, not by
the controller remembering to strip them.

### `PATCH /profile` — FR-PROF-01

Partial update of `first_name`, `last_name`, `phone`. **Not** updatable here:
`email` (identity change needs re-verification), `role` (admin-only, FR-PROF-02),
`password` (dedicated endpoint), `status`, `department_id`.

`200` → updated profile. `422` → validation errors.

### `PATCH /profile/password` — NFR-SEC-03

```json
{ "current_password": "…", "password": "…", "password_confirmation": "…" }
```

Requires the current password even when a session token is present — a stolen
bearer token must not be enough to take over the account permanently. On success
all other refresh-token families are revoked, so other devices are logged out and
the attacker's session dies with it. The user's own current session is kept.

---

## 11. User Management

Admin only (FR-ADMIN-01). Every mutation writes an `audit_log` row (FR-ADMIN-07).

### `GET /users`

Filters: `role`, `status`, `department_id`, `q` (name / e-mail / index), `page`,
`per_page`, `sort`. Returns a minimal user representation (id, name, role,
e-mail, status, department, last login) — no password or lockout internals.

### `POST /users`

Creates a user directly, including `role: "admin"` and an explicit
`status: "active"` (skipping e-mail verification). `must_change_password`
defaults to `true` for admin-created accounts: an administrator should never know
a user's long-term password.

```json
{ "email": "new.lecturer@utas.edu.gh", "role": "lecturer",
  "first_name": "Efua", "last_name": "Ansah", "department_id": 3,
  "status": "active" }
```

`201` → the user. No password is set; the user must use
`POST /auth/forgot-password` to set one, and a notification is queued telling
them so.

### `PATCH /users/{id}`

Updates `first_name`, `last_name`, `phone`, `student_index`, `staff_id`,
`status`. `status` transitions: `pending → active|suspended`, `active ⇄ suspended`,
`any → archived`. `archived` is terminal in the API — it is a data-subject
erasure state, not a soft delete you can undo (Act 843, `docs/SECURITY.md` §6).

Deactivating a **lecturer** who holds future allocations returns `409 CONFLICT`
with `error.details.affected_allocations` listing the session ids, rather than
silently leaving an unstaffed lecture on the timetable.

### `DELETE /users/{id}` — Act 843 erasure

Soft-deletes: sets `status='archived'` and `deleted_at`, keeps allocation history
intact (the audit trail must remain meaningful — NFR-SEC-06), and pseudonymises
the directly identifying fields (`first_name`, `last_name`, `phone`, `avatar_url`)
after the configured retention window. An account with future allocations is
rejected with `409` and the list of conflicts.

### `PATCH /users/{id}/role` — FR-PROF-02

```json
{ "role": "admin" }
```

Admin only, audited, and the response includes the permission delta so the UI
can explain what changed. **Self-demotion is refused** with `409` — an admin who
removes their own last admin permission locks every administrator out of the
system. Promote the successor first.

### `GET /users/{id}/load` — FR-PROF-03

```json
{
  "data": {
    "lecturer_id": 12, "semester_id": 7, "week_number": 3,
    "daily_sessions": [ { "day_of_week": 1, "count": 3, "ceiling": 4 } ],
    "weekly_sessions": 6, "distinct_slots": 6,
    "students_taught": 128, "overlaps": []
  }
}
```

`overlaps` is the FR-PROF-04 double-booking check surfaced as data: it is
expected to be empty, and a non-empty list is a genuine defect, not a
configuration warning.

---

## 12. Rooms & Classrooms

### `GET /rooms`

Filters: `building`, `min_capacity`, `max_capacity`, `room_type`, `status`,
`features` (repeatable, all must match), `available_on` + `time_slot_id`,
`q`.

**`200`**

```json
{
  "data": [
    { "id": 3, "code": "L1", "name": "Computer Lab 1", "building": "Block A",
      "floor": 1, "capacity": 60, "room_type": "lab", "status": "available",
      "is_bookable": true,
      "features": ["projector", "lab_benches", "ac", "accessible"],
      "is_available": true, "next_free_slot": { "time_slot_id": 5, "starts_at": "13:00:00" } }
  ],
  "meta": { "page": 1, "per_page": 25, "total": 1, "total_pages": 1 },
  "error": null
}
```

`is_available` and `next_free_slot` are only computed when `available_on` /
`time_slot_id` are supplied; otherwise the field is `null` rather than a guess.

### `POST /rooms` — FR-ROOM-03

```json
{
  "code": "L2", "name": "Computer Lab 2", "building": "Block A", "floor": 1,
  "capacity": 60, "room_type": "lab", "status": "available", "is_bookable": true,
  "features": ["projector", "lab_benches", "ac"],
  "department_id": 3, "notes": "Bench maintenance scheduled for August."
}
```

`201` → the room. `409` → duplicate `code` (codes are institution-wide unique, so
a room is unambiguous in a notification body without an id lookup).

### `PATCH /rooms/{id}` — FR-ROOM-03, BR-06

Any field is updatable, with two consequences the service handles transactionally:

- **`capacity` reduced below a live cohort's enrolment** — the service does *not*
  silently reallocate. It returns `200` with the room plus
  `meta.warnings: [{ "code": "ROOM_CAPACITY_SHORTFALL", "affected_allocations": [...] }]`
  and the next step is an explicit `POST /allocations/repair`. Silent repair
  would move classes without telling anyone, which is the exact failure mode the
  project exists to remove (P-4).
- **`status` → `maintenance` | `out_of_service`** while confirmed allocations
  exist — the service cancels them, sets `cancelled_reason`, and queues
  `allocation.cancelled` notifications to the affected students and lecturer
  (BR-09). The response lists what was cancelled in `meta.cancelled`.

### `DELETE /rooms/{id}`

Refused with `409` while the room appears in any allocation — including cancelled
ones, because history must stay referentially meaningful. Set
`status = 'out_of_service'` instead. The only hard delete allowed is for a room
that has never been allocated.

### `GET /rooms/{id}/availability` — FR-ROOM-01

Real-time availability for a date or a whole teaching week.

```http
GET /api/v1/rooms/3/availability?semester_id=7&week_number=3
```

```json
{
  "data": {
    "room_id": 3, "semester_id": 7, "week_number": 3,
    "days": [
      { "date": "2026-09-14", "day_of_week": 1, "slots": [
          { "time_slot_id": 1, "label": "A", "start_time": "08:00:00", "end_time": "10:00:00",
            "available": false,
            "occupied_by": { "allocation_id": 210, "cohort": "CS201-A", "course_code": "CS201" } },
          { "time_slot_id": 5, "label": "E", "start_time": "13:00:00", "end_time": "15:00:00",
            "available": true, "occupied_by": null } ] }
    ],
    "blocked_slots": [ { "time_slot_id": 9, "date": "2026-09-16", "reason": "Projector replacement" } ]
  }
}
```

`available: false` because of `room_unavailability` (HC-9) carries
`occupied_by: null` and the reason appears in `blocked_slots` — a maintenance
block must not look like a class booking.

### `GET /rooms/compare` — FR-ROOM-02

```http
GET /api/v1/rooms/compare?ids=3,4,5&semester_id=7&week_number=3
```

Side-by-side suitability for one cohort: fits/not, features satisfied, cost to
switch if the cohort already has a room, walking distance proxy from the cohort's
current room, and free-slot count. The result is **advisory** — the engine's
`CostFunction` output is authoritative for what the system will actually do
(`docs/ALLOCATION_ENGINE.md` §4).

---

## 13. Courses, Cohorts & Enrolment

### `GET /courses` · `POST /courses` · `GET /courses/{id}` · `PATCH` · `DELETE` — FR-ADMIN-03

```json
{
  "code": "CS201", "title": "Data Structures & Algorithms",
  "description": "…", "credit_hours": 3.0, "meetings_per_week": 2,
  "duration_minutes": 120, "level": 200,
  "default_lecturer_id": 12, "preferred_building": "Block A",
  "required_features":  [ { "feature": "projector", "mandatory": true } ],
  "preferred_features": [ { "feature": "ac", "mandatory": false } ],
  "department_id": 3, "is_active": true
}
```

`required_features[].mandatory = true` becomes **HC-5** (hard filter).
`mandatory = false` is a *soft* term in the cost function. Getting that
distinction wrong is how a "preference" ends up silently dropping a class, so
it is explicit in the payload.

`meetings_per_week` (1–7) is the source of the number of sessions the generator
creates per cohort. `DELETE` requires `?force=true` when the course has
allocations, and returns the list it would affect rather than cascading
(`ON DELETE RESTRICT` semantics are enforced in the service, not the schema,
so the error message is actionable).

### `GET /courses/{id}/cohorts` · `POST /cohorts` · `GET /cohorts/{id}` · `PATCH /cohorts/{id}` — FR-ROOM-04

```json
{ "course_id": 4, "semester_id": 7, "name": "CS201-A",
  "enrolled_count": 58, "capacity_slack": 5, "lecturer_id": 12 }
```

`enrolled_count` is the BR-04 denominator and is the number the capacity check
uses. It is derived from `enrollments`, and the API **recomputes it on every
write** rather than trusting the client to keep the counter honest — a stale
counter over-fills rooms.

### `POST /cohorts/{id}/enrolments` · `DELETE /cohorts/{id}/enrolments/{userId}` — FR-ALLOC-03

```json
{ "student_ids": [ 55, 56, 57 ] }
```

Adding students grows `enrolled_count`, which can push a cohort past its
assigned room's capacity. The service therefore re-evaluates the affected
allocation and, when the room no longer fits, returns:

```json
{
  "data": { "cohort": { "id": 9, "enrolled_count": 63 }, "reallocation_required": true },
  "meta": { "warnings": [ { "code": "CAPACITY_EXCEEDED",
                            "message": "Room L1 (60 seats) no longer seats cohort CS201-A (63).",
                            "allocation_id": 210,
                            "suggested_rooms": [ { "room_id": 7, "name": "Lecture Hall 1", "capacity": 120 } ] } ] },
  "error": null
}
```

This is FR-ALLOC-03's "recalculating dynamically when changes occur" made visible
at the API boundary. The engine's incremental repair then runs with the
enrolment change as its trigger (QA-3, `docs/ARCHITECTURE.md` §15).

### `GET /availability/lecturers` · `POST` · `DELETE /availability/lecturers/{id}` — HC-8

```json
{ "lecturer_id": 12, "semester_id": 7,
  "day_of_week": 3, "time_slot_id": null, "date": "2026-09-16", "reason": "Conference" }
```

`day_of_week` + `time_slot_id` `NULL` blocks a whole day; a bare `date` blocks
one occurrence (a single day's absence). These rows are HC-8 input, so a
lecturer's stated unavailability is a **hard** constraint the engine cannot
override.

### `POST /availability/rooms` — HC-9

```json
{ "room_id": 3, "semester_id": 7, "time_slot_id": 9, "date": null, "reason": "Bench maintenance" }
```

`reason` is required (unlike the lecturer variant) because it appears verbatim
in the room-availability response and often in a notification.

---

## 14. Semesters & Academic Calendar

### `GET /semesters` · `POST /semesters` · `GET /semesters/{id}` · `PATCH /semesters/{id}` — FR-CAL-02/03

```json
{
  "name": "2026-A", "academic_year": "2026/2027",
  "start_date": "2026-09-01", "end_date": "2027-01-29",
  "registration_start": "2026-08-24", "registration_end": "2026-08-31",
  "teaching_start": "2026-09-07", "teaching_end": "2026-12-18",
  "exam_start": "2026-12-21", "exam_end": "2027-01-16",
  "total_weeks": 14, "status": "active"
}
```

`CHECK (end_date >= start_date AND teaching_end >= teaching_start)` is enforced
by the schema *and* re-checked in the validator so the API returns a field-level
`422` rather than a driver error.

`status` transitions: `planning → active → closed → archived`. Only a
`planning` semester may have allocations created in it; generating against
`active`, `closed` or `archived` returns `409 CONFLICT`. FR-CAL-01 requires
allocations to align with the official calendar, so a closed calendar must not
grow new bookings.

### `GET /semesters/{id}/timeline` — FR-CAL-02, FR-TIME-06

```json
{
  "data": {
    "semester_id": 7, "name": "2026-A", "total_weeks": 14,
    "phases": [
      { "key": "registration", "label": "Registration", "from": "2026-08-24", "to": "2026-08-31", "teaching": false },
      { "key": "teaching",    "label": "Teaching",    "from": "2026-09-07", "to": "2026-12-18", "teaching": true },
      { "key": "exam",        "label": "Examinations","from": "2026-12-21", "to": "2027-01-16", "teaching": false }
    ],
    "weeks": [
      { "week_number": 1, "starts_on": "2026-09-07", "ends_on": "2026-09-11", "teaching": true, "exceptions": [] },
      { "week_number": 7, "starts_on": "2026-10-19", "ends_on": "2026-10-23", "teaching": true,
        "exceptions": [ { "date": "2026-10-21", "type": "break", "label": "Mid-semester break" } ] }
    ],
    "current_week": 2
  }
}
```

`current_week` is `null` outside the teaching window — the client must not
assume a week is teachable.

### `GET /semesters/{id}/exceptions` · `POST /semesters/{id}/exceptions` — FR-CAL-02/03, HC-7

```json
{ "exception_date": "2026-10-21", "type": "break", "label": "Mid-semester break" }
```

`type` ∈ `holiday | break | exam | makeup | non_teaching`. HC-7 rejects a
candidate whose `effective_date` matches a non-`makeup` exception. Adding an
exception that invalidates existing allocations returns `200` with
`meta.warnings` listing them; it does not delete them.

### `GET /slots` · `POST /slots` — FR-TIME-01/05

The weekly recurring grid, reused across semesters so a slot id is stable.

```json
{ "label": "A", "day_of_week": 1, "start_time": "08:00:00", "end_time": "10:00:00", "sort_order": 1 }
```

Slots must not overlap within a day. The overlap check runs inside the
allocation transaction, because two slots that overlap on the grid make
HC-1/HC-2/HC-3 ambiguous — a room could be "free" in `A` and "free" in `B` and
still be double-booked. `409 CONFLICT` with the conflicting slot id.

---

## 15. Allocations

### The allocation resource

```json
{
  "id": 210,
  "department_id": 3,
  "semester_id": 7,
  "cohort":      { "id": 9,  "name": "CS201-A", "enrolled_count": 58 },
  "course":      { "id": 4,  "code": "CS201", "title": "Data Structures & Algorithms" },
  "lecturer":    { "id": 12, "name": "Kwabena Owusu" },
  "room":        { "id": 3,  "code": "L1", "name": "Computer Lab 1",
                   "building": "Block A", "capacity": 60, "floor": 1 },
  "time_slot":   { "id": 1,  "label": "A", "day_of_week": 1,
                   "start_time": "08:00:00", "end_time": "10:00:00" },
  "week_number": 1,
  "effective_date": "2026-09-07",
  "status": "confirmed",
  "source": "auto",
  "engine_score": 0.3182,
  "score_breakdown": {
    "waste":         0.0000, "movement": 0.0000, "churn":         0.3182,
    "equity":        0.0000, "preference":  0.0000, "tightness":    0.0000,
    "fragmentation": 0.0000, "total": 0.3182
  },
  "override_reason": null, "overridden_by": null,
  "cancelled_reason": null, "cancelled_at": null,
  "previous_room_id": null,
  "notes": null,
  "created_at": "2026-09-02T10:14:03+00:00",
  "updated_at": "2026-09-02T10:14:03+00:00"
}
```

`status` ∈ `draft | proposed | confirmed | updated | cancelled` (FR-ALLOC-06).
`source` ∈ `auto | manual | override | import`.

`score_breakdown` is the seven weighted soft terms from `CostFunction`
(`docs/ALLOCATION_ENGINE.md` §4) plus `total`. It is `null` when `source` is
`manual` or `override` — a human choice has no engine score, and inventing one
would be a lie an administrator could not detect. `waste` and `tightness` are
`0.0000` whenever the room fits exactly or has slack, which is why a perfect fit
scores low on both.

### `GET /allocations` — FR-ALLOC-06

Filters: `semester_id`, `cohort_id`, `course_id`, `lecturer_id`, `room_id`,
`time_slot_id`, `week_number`, `status`, `source`, `from`/`to`, plus
`sorted_by=start_time`. A student or lecturer sees only their own rows —
enforced by a `WHERE` clause built from the principal, not by filtering after
the query.

Default excludes `cancelled` rows; add `include_cancelled=true` for history.
Cursor pagination; default 200 per page (a week of a department's timetable fits
in one page).

### `POST /allocations/generate` — FR-ALLOC-01, FR-ALLOC-03

The engine entry point. `Idempotency-Key` required.

**Body**

```json
{
  "semester_id": 7,
  "department_id": 3,
  "mode": "full",
  "execution": "sync",
  "apply": false,
  "seed": 20260801,
  "max_iterations": 2000,
  "time_budget_seconds": 2.5,
  "strict": false,
  "scope": { "cohort_ids": [9, 10], "days": [1, 3], "room_ids": null }
}
```

| Field | Default | Notes |
| --- | --- | --- |
| `mode` | `full` | `full` ignores the current timetable; `repair` warm-starts from it (FR-ALLOC-03) |
| `execution` | `sync` | `async` enqueues to the worker and returns `202` with a `run_id` to poll |
| `apply` | `false` | `false` = dry run: nothing is written, the proposal is returned (see also `bin/generate-timetable.php --dry-run`) |
| `seed` | `20260801` | Same seed + same problem ⇒ the same Phase 1 solution, always |
| `max_iterations` | `2000` | `0` disables Phase 2 (greedy only) |
| `time_budget_seconds` | `2.5` | Max 30 (NFR-PERF-02) |
| `strict` | `false` | `true` throws `422 INFEASIBLE_PROBLEM` instead of returning every session unallocated |
| `scope` | all | Restrict to specific cohorts / days / rooms — the incremental repair path |

**`201` (sync, dry run)** — the proposal plus metrics, nothing persisted to
`allocations` (an `allocation_runs` row *is* written, so the run is auditable):

```json
{
  "data": {
    "run": {
      "id": "6c1f0a2e-8b3d-4a17-9f45-2d7e51b0c8a9",
      "mode": "full", "random_seed": 20260801, "engine_version": "1.0.0",
      "total_sessions": 312, "assigned_sessions": 301, "unallocated_sessions": 11,
      "accuracy": 0.9647, "total_penalty": 128.4417,
      "iterations": 1840, "duration_ms": 2310, "is_feasible": true,
      "is_applied": false, "timed_out": false,
      "metrics": {
        "seat_utilisation": 0.9126,
        "warm_start_rejected": 0,
        "warm_start_by_cause": {}
      },
      "started_at": "2026-09-02T10:00:00+00:00",
      "finished_at": "2026-09-02T10:00:02+00:00"
    },
    "assignments": [
      { "session_id": 1001, "room_id": 3, "time_slot_id": 1, "cost": 0.3182 }
    ],
    "unallocated": [
      { "session_id": 1187, "cohort_id": 12, "course_id": 6,
        "considered_combinations": 42,
        "blocking_constraints": { "HC-4": 28, "HC-1": 14 },
        "primary_constraint": "HC-4",
        "summary": "No room with capacity >= 120 is free in any of the 6 remaining slots." }
    ],
    "violations": []
  }
}
```

`accuracy` is `assigned / total` — the NFR-PERF-04 metric, and the > 0.90 gate.
`violations` is always `[]` on a successful run: Phase 3 independently re-checks
every committed assignment, and a non-empty list means the run **must not** be
applied (QA-4).

`unallocated[].summary` is written for an administrator, not a developer: it
names the constraint that eliminated the most candidates and the number of
slot/room combinations examined. `blocking_constraints` sums to more than
`considered_combinations` because one combination can fail several constraints.

**`201` (sync, `apply: true`)** — same body, plus `meta.applied: true`. The
whole `(semester, department)` timetable is replaced in **one transaction**
(NFR-REL-03): a failure anywhere leaves the previous timetable intact, and the
previous allocations are `cancelled` with `cancelled_reason = 'superseded by
run <id>'` rather than deleted.

**`202` (async)** — `{"data": {"run_id": "…", "status": "queued"}}` with
`Location: /api/v1/allocations/runs/{run_id}`. Poll that endpoint.

**Errors**

| Status | Condition |
| --- | --- |
| `409 CONFLICT` | The semester is not `planning`; or another `apply: true` run is already in flight for the same `(department, semester)` — generation is serialised per timetable |
| `422 INFEASIBLE_PROBLEM` | `strict: true` and no usable slot exists |
| `422 VALIDATION_FAILED` | `time_budget_seconds` > 30, `max_iterations` negative, unknown `scope` id |
| `429 RATE_LIMITED` | 10 generation runs per hour |

### `GET /allocations/{id}` — FR-ALLOC-06

One allocation with the full `score_breakdown`. `404` for a student or lecturer
who is not attached to it — never `403`, so the endpoint is not a probe for
existence (§1.7).

### `PATCH /allocations/{id}` — FR-ALLOC-04, BR-08, BR-12

Administrative reassignment. **`reason` is mandatory** (FR-ADMIN-07, BR-08): a
request without it is `422 VALIDATION_FAILED` with
`details.reason = ["An override must record a reason (BR-08)."]`. There is no
"quiet override" path, by design.

```json
{ "room_id": 7, "time_slot_id": 5, "reason": "Lab 1 projector failed; B12 is free and adjacent." }
```

**`200`**

- The row's `status` transitions to `updated` (BR-12), `source` to `override`,
  `override_reason` and `overridden_by` are set, `previous_room_id` records the
  old room, and an `allocation.updated` notification is queued for the cohort's
  students and the lecturer (FR-NOTIF-02, BR-09).
- An `audit_log` row records before/after JSON.
- Feasibility is re-checked inside the transaction. If the requested placement
  is infeasible the response is `409 CONFLICT` with
  `error.details.violations` listing every hard constraint that failed:

```json
{
  "error": {
    "code": "CONFLICT",
    "message": "The requested placement is not feasible.",
    "details": {
      "violations": [
        { "code": "HC-1", "message": "Room L1 is already booked in slot A on Monday." },
        { "code": "HC-4", "message": "Room capacity 45 is below the cohort enrolment of 58." }
      ]
    }
  }
}
```

All violations are reported, not just the first, so an administrator can see the
whole picture before choosing a different room.

An optional `force: true` allows the write anyway (it is a *reasoned* human
override, which BR-08 permits). The allocation is stored with `status='updated'`
and a `WARNING` `allocation_conflicts` row recording which hard constraints are
open, so the deliberate violation is visible in reports and in the audit trail
rather than being a silent hole in the schedule. A forced override of HC-1
(a real double booking) is additionally flagged in the dashboard alert count.

### `POST /allocations/{id}/confirm` — FR-BOOK-01, FR-ALLOC-06

```http
POST /api/v1/allocations/210/confirm
```

Transitions `proposed` → `confirmed` and fans out the confirmation notification
(FR-BOOK-01, FR-BOOK-02): the cohort's students, the lecturer, and the
department's admins. Idempotent — confirming an already-confirmed allocation
returns `200` with `"already_confirmed": true` rather than `409`, because the
client may retry after a dropped response.

`409` if `status` is `cancelled`. Audited.

### `POST /allocations/{id}/cancel` — FR-NOTIF-02, BR-09

```json
{ "reason": "Lecturer on study leave for weeks 5-6." }
```

`reason` is required. Sets `status='cancelled'`, `cancelled_at`, and
`cancelled_reason`; the row is **kept** (NFR-SEC-06) and the `active_guard`
generated column releases the unique key, so the room/slot becomes free without
history being lost. Queues `allocation.cancelled` to students and lecturer.

### `POST /allocations/{id}/reassign` — FR-TIME-02, FR-ALLOC-03

```json
{ "reason": "Cohort grew to 63; Lab 1 no longer fits." }
```

A semantic alias of `PATCH /allocations/{id}` that additionally triggers the
incremental engine re-run for the affected day when the caller passes
`"repair": true`. Returns the updated allocation plus, if the repair moved
anything else, the list of co-affected allocations and notifications queued.
This is the endpoint the enrolments flow (§13) and the room-capacity warning
(§12) link to.

### `GET /allocations/conflicts` — FR-ALLOC-05

```http
GET /api/v1/allocations/conflicts?semester_id=7&unresolved_only=true
```

```json
{
  "data": [
    { "id": 55, "run_id": "6c1f0a2e-…", "cohort_id": 12, "course_id": 6,
      "severity": "error", "constraint_code": "HC-4",
      "message": "No room with capacity >= 120 is free in any remaining slot.",
      "details": { "considered_combinations": 42, "rejected_rooms": [ 3, 4, 5 ],
                   "required_capacity": 120, "cohort_enrolled_count": 118,
                   "capacity_slack": 0 },
      "resolved_at": null, "resolved_by": null,
      "created_at": "2026-09-02T10:00:02+00:00" }
  ]
}
```

`details` is the actionable part: it distinguishes "no room is big enough"
(rejected for `HC-4`) from "a big enough room is always booked" (rejected for
`HC-1`) — the same admin action resolves the first by adding a room and the
second by changing the timetable. A student or lecturer sees only conflicts for
their own cohorts or their own sessions.

`POST /allocations/conflicts/{id}/resolve` takes
`{ "resolution": "assigned_room_added" | "cohort_split" | "timetable_changed" | "accepted" }`
and marks the row resolved. **Resolving is not the same as fixing** — the row is
closed with the stated reason and stays in the table for the report
(`FR-REPORT-02`); the underlying allocation must actually change for the
timetable to be correct.

### `GET /allocations/runs` · `GET /allocations/runs/{runId}` — NFR-PERF-04

```json
{
  "data": {
    "id": "6c1f0a2e-8b3d-4a17-9f45-2d7e51b0c8a9",
    "department_id": 3, "semester_id": 7, "triggered_by": 1,
    "mode": "full", "random_seed": 20260801, "engine_version": "1.0.0",
    "total_sessions": 312, "assigned_sessions": 301, "unallocated_sessions": 11,
    "accuracy": 0.9647, "total_penalty": 128.4417,
    "iterations": 1840, "duration_ms": 2310,
    "is_feasible": true, "is_applied": true,
    "metrics": { "seat_utilisation": 0.9126, "timed_out": false,
                 "warm_start_rejected": 4,
                 "warm_start_by_cause": { "HC-4": 3, "HC-1": 1 } },
    "started_at": "2026-09-02T10:00:00+00:00", "finished_at": "2026-09-02T10:00:02+00:00"
  }
}
```

`triggered_by: null` means a scheduled job, not a person. This endpoint is what
makes the report's 95 % accuracy figure **auditable** rather than asserted: the
numbers behind it are rows. `GET /allocations/runs` supports `?min_accuracy=`,
`?since=`, `?applied_only=true` and returns runs newest-first, which is also how
a weight-tuning regression is caught after a `config/weights.php` change
(`docs/ALLOCATION_ENGINE.md` §10).

`422` when `accuracy < 0.90` is *not* an error for this endpoint — the run is
still recorded so the failure is visible. The gate is enforced by
`bin/regenerate-golden.php` and by CI, not by the read API.

### `POST /allocations/repair` — FR-ALLOC-03

```json
{ "semester_id": 7, "scope": { "days": [1] }, "apply": true, "reason": "Room L1 offline for Monday." }
```

Warm-starts from the current timetable, so only the affected window is
recomputed — a single change is cheap, a full regeneration is exhaustive. This is
the same code path as `bin/generate-timetable.php --repair`. Returns the same
`run` object as `generate`, plus `warm_start_rejected` and
`warm_start_by_cause` so an administrator can see *which* existing allocations
were discarded and why — the most common surprise in a repair is that a
previously feasible room stopped being feasible.

---

## 16. Timetables

The hottest read path in the system (NFR-PERF-01/03). Served from `allocations`
through the covering index `ix_alloc_week_view`, in **one** query for a week.

### `GET /timetable` — FR-TIME-01, FR-TIME-03, FR-TIME-04

```http
GET /api/v1/timetable?semester_id=7&week_number=3&scope=week
```

`scope` ∈ `week` (default) | `day` | `semester`.

A student gets their own enrolled cohorts; a lecturer their assigned sessions; an
admin everything in the department. The difference is the `WHERE` clause, built
from the principal — the response shape is identical for all three, so the PWA
has one renderer.

**`200`**

```json
{
  "data": {
    "semester_id": 7, "semester_name": "2026-A", "week_number": 3,
    "week_starts_on": "2026-09-21", "scope": "week",
    "generated_at": "2026-09-02T10:00:02+00:00", "is_current": true,
    "days": [
      { "date": "2026-09-21", "day_of_week": 1, "day_label": "Monday",
        "slots": [
          { "time_slot_id": 1, "label": "A", "start_time": "08:00:00", "end_time": "10:00:00",
            "sessions": [
              { "allocation_id": 210, "status": "confirmed", "source": "auto",
                "course": { "id": 4, "code": "CS201", "title": "Data Structures & Algorithms" },
                "cohort": { "id": 9, "name": "CS201-A" },
                "lecturer": { "id": 12, "name": "Kwabena Owusu" },
                "room": { "id": 3, "code": "L1", "name": "Computer Lab 1",
                          "building": "Block A", "floor": 1, "capacity": 60 },
                "changed": true, "changed_at": "2026-09-18T07:31:00+00:00" }
            ] },
          { "time_slot_id": 3, "label": "C", "start_time": "11:00:00", "end_time": "13:00:00",
            "sessions": [] }
        ] }
    ]
  },
  "meta": { "has_more": false, "next_cursor": null },
  "error": null
}
```

`changed: true` marks a row whose `status` is `updated` since the client's last
successful fetch, or whose room differs from the value the client holds
(`previous_room_id`). The PWA highlights these — that highlight *is* OBJ-2
(real-time update) and the fix for P-4 (late arrival at the wrong venue), so it
is part of the contract rather than a client-side guess.

Empty slots are present with `"sessions": []`. A missing slot would be
indistinguishable from a rendering bug, and "you have nothing then" is
information.

`generated_at` is the `finished_at` of the run that produced the timetable, so
the client can say "timetable as of 02 Sep 10:00" instead of implying live data
it does not have.

### `GET /timetable/day` — FR-TIME-01

```http
GET /api/v1/timetable/day?semester_id=7&day_of_week=1
```

The `day` scope of the endpoint above, hoisted for the mobile home screen. Same
response shape, `days` has exactly one entry.

### `GET /timetable/semester` — FR-TIME-06

```http
GET /api/v1/timetable/semester?semester_id=7
```

Every week of the semester, cursor-paginated by week, with `calendar_exceptions`
attached so holiday weeks are visibly empty rather than mysteriously blank. Used
by the "semester timeline" view.

### `GET /timetable/{semesterId}/export.csv` — FR-TIME-01

`text/csv`, RFC 4180, UTF-8 with BOM so Excel opens accented African names
correctly. One row per session-week. `?week=N` exports that week's grid only;
without it, a stored week-1 pattern is written once per teaching week with that
week's dates.

```csv
Week,Day,Date,Start,End,Course,Title,Cohort,Lecturer,Room,Building,Capacity,Status,Source,Changed
3,Monday,2026-09-21,08:00,10:00,CS201,Data Structures & Algorithms,CS201-A,Kwabena Owusu,L1,Block A,60,confirmed,auto,no
```

A student or lecturer exports only their own rows. Permission `timetable:view`
is the same as the JSON view — the export is not a way around the scope, only a
different rendering of the same query.

---

## 17. Search

### `GET /search/rooms` — FR-SEARCH-01

```http
GET /api/v1/search/rooms?q=lab&building=Block+A&min_capacity=40&features=projector,ac
```

Full-text on code, name and building plus structured filters. Matching rooms are
returned with `match_reasons` so the UI can highlight *why* a result matched.
Supports `page`/`per_page`; a `LIMIT` of 200 with a "refine your search" hint
beyond it, because a search endpoint must not be able to dump a table.

`min_capacity` is the room's seated capacity. `features` is AND — a room missing
any listed feature is excluded (matching HC-5 semantics).

### `GET /search/schedules` — FR-SEARCH-02

```http
GET /api/v1/search/schedules?q=CS201&day_of_week=1&lecturer_id=12
```

Searches course code, course title, cohort name, lecturer name and room code.
Scoped exactly like `/allocations`: a student searching `q=CS201` sees only
their own sessions. Results include a `conflicts` array so an administrator sees
"this course meets in 2 rooms this week" (FR-REPORT-02) directly in the results.

---

## 18. Notifications

### `GET /notifications` — FR-NOTIF-01, FR-NOTIF-03

```http
GET /api/v1/notifications?unread_only=true&page=1
```

```json
{
  "data": [
    { "id": 901, "type": "allocation.updated", "severity": "warning",
      "title": "CS201-A moved to Lab 2 (Wednesday, 13:00)",
      "body": "The projector in Lab 1 failed. Your Wednesday class moves to Lab 2, Block A.",
      "allocation_id": 210, "action_url": "/timetable?semester=7&week=3",
      "read_at": null, "created_at": "2026-09-18T07:31:00+00:00" }
  ],
  "meta": { "page": 1, "per_page": 25, "total": 1, "total_pages": 1,
            "unread_count": 1 },
  "error": null
}
```

`type` is namespaced by domain event:

| `type` | Emitted when | Requirements |
| --- | --- | --- |
| `allocation.confirmed` | an allocation is confirmed | FR-BOOK-01 |
| `allocation.proposed` | a generation produces a new proposal | FR-ALLOC-01 |
| `allocation.updated` | room/slot/lecturer changed | FR-NOTIF-02, BR-12 |
| `allocation.cancelled` | a session is cancelled | FR-NOTIF-02, BR-09 |
| `allocation.unresolved` | a session could not be placed | FR-ALLOC-05 (admins only) |
| `account.registered` | a user registers | FR-AUTH-01 |
| `account.password_reset` | a password is reset | FR-AUTH-03 |
| `account.role_changed` | an admin changes a role | FR-PROF-02 |
| `room.unavailable` | a room goes to maintenance | BR-06 |

`severity` drives the badge colour: `info`, `warning` for a room or time
change, `critical` for a cancellation. FR-NOTIF-04 scoping is a `WHERE
recipient_id = :me` — there is no request parameter that can widen it, and no
endpoint returns another user's notifications.

### `GET /notifications/unread-count`

```json
{ "data": { "unread_count": 2, "critical_unread": 1, "latest_at": "2026-09-18T07:31:00+00:00" } }
```

Cheap, cacheable for 15 seconds, and the only notification endpoint the service
worker polls. `304` is returned when `If-None-Match` matches.

### `POST /notifications/{id}/read` · `POST /notifications/read-all` — FR-NOTIF-03

`204 No Content`. Marking another user's notification `read` is a `404`, not a
`403`. `read-all` is scoped to the caller by construction.

### `POST /notifications/dispatch` — FR-NOTIF-05

`notification:dispatch`, department scope. Re-drives the outbox for a
department — the manual trigger for the `notification.dispatch` cron. Body:

```json
{ "channel": "email", "notification_type": "allocation.updated", "since": "2026-09-18T00:00:00+00:00" }
```

`channel` is required, because FR-NOTIF-05 asks for a **pluggable channel
interface** and this endpoint is how an operator exercises a new channel without
a deployment. Unknown channels are `422`; only `in_app | email | sms | push` are
registered, and `sms`/`push` return `409` with "provider not configured" until a
provider exists (Won't-have in v1, `docs/REQUIREMENTS.md` §9).

---

## 19. Reports & Export

All four read the nightly `room_utilisation_daily` rollup where a rollup exists,
and `allocations` directly for windows newer than the last rollup
(NFR-SCALE-04). `meta.data_through` always states which is which, so a report
never silently presents stale numbers as current.

### `GET /reports/utilisation` — FR-REPORT-01, BR-10

```http
GET /api/v1/reports/utilisation?semester_id=7&from=2026-09-01&to=2026-09-30&group_by=room
```

```json
{
  "data": {
    "group_by": "room",
    "from": "2026-09-01", "to": "2026-09-30",
    "rows": [
      { "room_id": 3, "room_code": "L1", "room_name": "Computer Lab 1", "building": "Block A",
        "capacity": 60, "booked_minutes": 9840, "available_minutes": 10800,
        "utilisation_pct": 91.11, "seat_hours": 508.00, "session_count": 82 }
    ],
    "summary": { "overall_utilisation_pct": 87.40, "rooms_considered": 24,
                 "underused_rooms": 3, "overused_rooms": 1 },
    "data_through": "2026-09-30"
  }
}
```

`utilisation_pct` is BR-10 verbatim: `booked_minutes / available_minutes × 100`.
`underused_rooms` (`< 50 %`) and `overused_rooms` (`> 90 %`) are the actionable
summary that turns a table into a decision (OBJ-5) — an administrator who needs
to know *which* rooms to add does not want to sort a table.

`group_by` ∈ `room | building | day_of_week | week_number | course`.

### `GET /reports/peak-usage` — FR-REPORT-02

Backed by the `v_peak_usage` view.

```json
{
  "data": {
    "rows": [
      { "day_of_week": 1, "start_hour": 8, "session_count": 24, "rooms_used": 9,
        "cohorts_scheduled": 24, "total_students": 1420, "capacity_available": 1500,
        "saturation_pct": 94.67 }
    ],
    "peak": { "day_of_week": 1, "start_hour": 8 },
    "bottlenecks": [ { "day_of_week": 1, "start_hour": 8, "saturation_pct": 94.67,
                       "recommendation": "Two more 60+ seat rooms at 08:00 on Mondays would clear this." } ]
  }
}
```

### `GET /reports/conflicts` — FR-REPORT-02

```json
{
  "data": {
    "rows": [
      { "constraint_code": "HC-1", "label": "Room double-booked", "count": 3,
        "semesters": [ 7 ], "last_seen": "2026-09-02T10:00:02+00:00",
        "open": true, "examples": [ { "allocation_id": 210, "cohort_id": 9 } ] },
      { "constraint_code": "HC-4", "label": "Capacity exceeded", "count": 0,
        "open": false, "examples": [] }
    ],
    "forced_overrides": 2
  }
}
```

`forced_overrides` counts allocations stored with an open hard constraint
because an administrator forced them (§15). A non-zero value is a standing
physical conflict in the timetable and belongs on the dashboard, not buried.

### `GET /reports/lecturer-load` — FR-REPORT-02, FR-PROF-03

Backed by `v_lecturer_load`. `max_sessions_per_day` is echoed so a load figure
can be read against the HC-8 ceiling; `at_ceiling` marks lecturers who hit it,
which is a workload-planning signal (OBJ-5) rather than an error.

### `GET /reports/export.csv` — FR-REPORT-03

```http
GET /api/v1/reports/export.csv?report=utilisation&semester_id=7&from=2026-09-01&to=2026-09-30
```

`report` ∈ `utilisation | peak-usage | conflicts | lecturer-load | allocations`.

`text/csv; charset=utf-8` with a UTF-8 BOM, RFC 4180 quoting, and
`Content-Disposition: attachment; filename="utilisation_2026-09-01_2026-09-30.csv"`.
Cells beginning with `=`, `+`, `-` or `@` are prefixed with a single quote —
otherwise a room code or a lecturer name can execute as a formula in Excel when
an administrator opens the export. Exports are capped at 50 000 rows; beyond
that `422 VALIDATION_FAILED` with a message telling the administrator to narrow
the date range rather than offering an unbounded export.

---

## 20. Dashboard

### `GET /dashboard` — FR-ADMIN-05

Everything on one screen, in one round trip.

```json
{
  "data": {
    "semester": { "id": 7, "name": "2026-A", "status": "active", "current_week": 3 },
    "metrics": {
      "active_users": 148, "users_by_role": { "student": 128, "lecturer": 18, "admin": 2 },
      "pending_allocations": 11, "conflicts_open": 3, "forced_overrides": 2,
      "rooms_total": 24, "rooms_available": 22, "rooms_out_of_service": 2,
      "utilisation_pct": 87.40, "accuracy_last_run": 0.9647,
      "notifications_unread": 41, "outbox_pending": 0, "outbox_dead": 0,
      "last_run": { "run_id": "6c1f0a2e-…", "at": "2026-09-02T10:00:02+00:00",
                    "duration_ms": 2310, "accuracy": 0.9647 }
    },
    "alerts": [
      { "severity": "critical", "code": "FORCED_DOUBLE_BOOKING",
        "message": "Allocation 210 (Lab 1, Mon 08:00) overlaps allocation 244.",
        "action_url": "/admin/allocations/210" },
      { "severity": "warning", "code": "ROOM_OUT_OF_SERVICE",
        "message": "Lab 3 has been out of service for 9 days with 4 upcoming sessions at risk.",
        "action_url": "/admin/rooms/9" }
    ],
    "quick_actions": [ { "action": "generate_timetable", "enabled": true },
                       { "action": "run_repair", "enabled": true } ]
  }
}
```

`outbox_dead` is the single most important number on this screen: it counts
notifications that exhausted their retries and will never be delivered. A
non-zero value means somebody is not getting told about a room change — the
exact failure P-4 describes. The dashboard surfaces it in `alerts` at `critical`.

### `GET /dashboard/heat-map` — FR-ADMIN-06

```json
{
  "data": {
    "grid": { "rows": [ { "room_id": 3, "room_code": "L1", "building": "Block A",
                          "capacity": 60, "values": { "1": 100.0, "2": 87.5, "3": 62.5,
                                                       "4": 50.0, "5": 75.0, "6": 0.0 } } ],
              "columns": [ { "key": "1", "label": "Mon" }, { "key": "2", "label": "Tue" },
                           { "key": "3", "label": "Wed" }, { "key": "4", "label": "Thu" },
                           { "key": "5", "label": "Fri" }, { "key": "6", "label": "Sat" } ] },
    "max": 100.0
  }
}
```

Values are utilisation percentages, `null` where a room was out of service all
day (distinct from `0.0` = bookable but unused, which is the number an
administrator needs to see). The grid is `room × day`; the building dimension is
a client-side grouping. Colour is applied client-side with a
colourblind-safe sequential ramp (NFR-UX-03), so the API returns numbers, not
colours.

---

## 21. Audit Trail

### `GET /audit` — FR-ADMIN-07, NFR-SEC-06

```http
GET /api/v1/audit?action=allocation.override&from=2026-09-01&to=2026-09-30
```

```json
{
  "data": [
    { "id": 8812, "actor": { "id": 1, "name": "Adwoa Mensah", "role": "admin" },
      "action": "allocation.override",
      "entity_type": "allocation", "entity_id": 210,
      "before_state": { "room_id": 3, "time_slot_id": 1, "status": "confirmed" },
      "after_state":  { "room_id": 7, "time_slot_id": 5, "status": "updated",
                        "override_reason": "Lab 1 projector failed." },
      "ip_address": "198.51.100.24", "user_agent": "Mozilla/5.0 …",
      "request_id": "0f3d1c9e-…", "created_at": "2026-09-18T07:31:00+00:00" }
  ]
}
```

`actor: null` means the system acted (a scheduled generation, a nightly rollup).
The table is append-only: there is no write, update or delete endpoint, and
`DELETE /audit` is not defined at all — attempting it is `405 METHOD_NOT_ALLOWED`,
not `403`. Retention is enforced by `bin/retention.php` per the schedule in
`docs/DATA_MODEL.md`, which is the only process permitted to delete a row and
which writes its own `system.retention_purge` audit entries.

Actions recorded include: `allocation.generate`, `allocation.apply`,
`allocation.override`, `allocation.confirm`, `allocation.cancel`,
`allocation.repair`, `user.create`, `user.update`, `user.archive`,
`user.role_changed`, `room.create`, `room.update`, `room.status_changed`,
`course.create`, `course.update`, `course.delete`, `semester.create`,
`semester.update`, `conflict.resolve`, `notification.dispatch`,
`system.migration`, `system.retention_purge`.

---

## 22. Notification Outbox Semantics

The API contract for notifications has one non-obvious property, and it is the
reason for ADR-003: **a successful write response does not mean the e-mail has
been sent.** It means the message is durably queued.

```
POST /allocations/210/confirm
   │
   ├─ BEGIN
   │    UPDATE allocations SET status='confirmed' …
   │    INSERT INTO notifications        … one row per recipient   (in-app, immediate)
   │    INSERT INTO notification_outbox  … one row per recipient × channel
   │    INSERT INTO audit_log            …
   ├─ COMMIT
   │
   200 ────────────────────────────────────────────────────────────  fast, NFR-PERF-01
   │
   ▼  bin/worker.php, asynchronously, typically < 5 s
   SELECT * FROM notification_outbox WHERE status='pending' AND next_attempt_at <= NOW() … FOR UPDATE
   for each: deliver → status='sent'
             failure → attempts++ , next_attempt_at = NOW() + backoff, retry
             attempts == max_attempts → status='dead'  →  dashboard alert
```

Consequences the client must respect:

| Property | Behaviour |
| --- | --- |
| **Delivery is at-least-once** | A worker that dies after sending but before marking `sent` will resend. Clients must de-duplicate by `notification.id`, not by arrival order. |
| **In-app is synchronous with the write** | The `notifications` row exists the instant the allocation commit succeeds, so a user who refreshes sees the change even if every e-mail channel is down. |
| **e-mail/SMS are eventually consistent** | Typically < 5 s. A read-your-writes requirement is met by the in-app channel, not by the e-mail. |
| **Failures never roll back the allocation** | The timetable is the source of truth. A dead e-mail is an operations problem (`outbox_dead` on the dashboard), not a data-integrity problem. |
| **Fan-out** | Cohort students + assigned lecturer + department admins (FR-NOTIF-04). Admin fan-out is what makes `allocation.unresolved` reach someone who can act on it. |
| **Idempotency** | The worker is idempotent on `(allocation_id, recipient_id, channel)`. |

The reason for the outbox rather than calling SMTP inline: NFR-PERF-01 requires
allocation changes to return in < 3 s, and a single SMTP round trip to a provider
can exceed that on its own. The reason it is not simply "fire and forget": a
notification lost in a process crash after the allocation committed is precisely
the late-arrival-at-the-wrong-room problem the project exists to solve.

---

## 23. Requirement → Endpoint Traceability

| Requirement | Endpoints |
| --- | --- |
| FR-AUTH-01 | `POST /auth/register` |
| FR-AUTH-02 | `POST /auth/login` |
| FR-AUTH-03 | `POST /auth/forgot-password`, `POST /auth/reset-password` |
| FR-AUTH-04 | `POST /auth/refresh`, `POST /auth/logout` |
| FR-AUTH-05 | `GET /auth/me` + `AuthMiddleware` on every other route |
| FR-PROF-01 | `GET/PATCH /profile`, `PATCH /users/{id}` |
| FR-PROF-02 | `PATCH /users/{id}/role` |
| FR-PROF-03 | `GET /profile` (lecturer block), `GET /users/{id}/load` |
| FR-PROF-04 | `GET /users/{id}/load` → `overlaps`; enforced by HC-2/HC-3 |
| FR-ALLOC-01 | `POST /allocations/generate` |
| FR-ALLOC-02 | enforced by HC-1/2/3 + `allocations` unique keys (ADR-006) |
| FR-ALLOC-03 | `POST /allocations/repair`, `POST /allocations/{id}/reassign`, enrolments endpoints |
| FR-ALLOC-04 | `PATCH /allocations/{id}` |
| FR-ALLOC-05 | `GET /allocations/conflicts`, `GET /allocations/{id}/conflicts`, `POST /allocations/conflicts/{id}/resolve` |
| FR-ALLOC-06 | `allocations.status` on every allocation endpoint |
| FR-TIME-01 | `GET /timetable`, `/timetable/day`, `/timetable/{id}/export.csv` |
| FR-TIME-02 | `POST /allocations/{id}/reassign`, `POST /allocations/repair` |
| FR-TIME-03 | `GET /timetable` (student scope) |
| FR-TIME-04 | `GET /timetable` (lecturer scope) |
| FR-TIME-05 | `POST /slots`, `PATCH /semesters/{id}` |
| FR-TIME-06 | `GET /semesters/{id}/timeline`, `GET /timetable/semester` |
| FR-ROOM-01 | `GET /rooms`, `GET /rooms/{id}/availability` |
| FR-ROOM-02 | `GET /rooms/compare` |
| FR-ROOM-03 | `POST/PATCH/DELETE /rooms` |
| FR-ROOM-04 | cohort endpoints + engine `CostFunction` equity/tightness terms |
| FR-BOOK-01 | `POST /allocations/{id}/confirm` |
| FR-BOOK-02 | outbox fan-out on every allocation mutation |
| FR-NOTIF-01 | `GET /notifications` |
| FR-NOTIF-02 | `allocation.cancelled` / `allocation.updated` on `POST /allocations/{id}/cancel`, `PATCH /allocations/{id}` |
| FR-NOTIF-03 | `GET /notifications`, `POST /notifications/{id}/read`, `POST /notifications/read-all` |
| FR-NOTIF-04 | `recipient_id = :me` on every notification endpoint |
| FR-NOTIF-05 | `POST /notifications/dispatch`; `NotificationChannel` port |
| FR-CAL-01 | semester teaching window + HC-7 |
| FR-CAL-02 | `GET /semesters/{id}/timeline`, `GET /semesters/{id}/exceptions` |
| FR-CAL-03 | `POST/PATCH /semesters`, `POST /semesters/{id}/exceptions` |
| FR-CAL-04 | `422` with `HC-7` on out-of-window placements |
| FR-SEARCH-01 | `GET /search/rooms` |
| FR-SEARCH-02 | `GET /search/schedules` |
| FR-REPORT-01 | `GET /reports/utilisation` |
| FR-REPORT-02 | `GET /reports/peak-usage`, `/reports/conflicts`, `/reports/lecturer-load` |
| FR-REPORT-03 | `GET /reports/export.csv` |
| FR-ADMIN-01 | `/users` endpoints |
| FR-ADMIN-02 | `/rooms` write endpoints |
| FR-ADMIN-03 | `/courses` write endpoints |
| FR-ADMIN-04 | `/users` write endpoints (role `lecturer`) |
| FR-ADMIN-05 | `GET /dashboard` |
| FR-ADMIN-06 | `GET /dashboard/heat-map` |
| FR-ADMIN-07 | `GET /audit`; `reason` mandatory on every override path |
| NFR-PERF-01 | `GET /metrics`; every mutation bounded by a transaction with no synchronous channel call |
| NFR-PERF-02 | `POST /allocations/generate` `time_budget_seconds` ≤ 30 |
| NFR-PERF-03 | cursor pagination + `ix_alloc_week_view` |
| NFR-PERF-04 | `metrics.accuracy` on `POST /allocations/generate`, `GET /allocations/runs` |
| NFR-SEC-02 | `RbacMiddleware` on every route except `/health` and `/metrics` |
| NFR-SEC-05 | §6 rate limits, `users.locked_until`, `security_events` |
| NFR-SEC-06 | `GET /audit`; append-only by construction |
| NFR-MAINT-04 | `/api/v1` version prefix |

---

## Related Documents

- [`REQUIREMENTS.md`](REQUIREMENTS.md) — the specification this API serves
- [`ARCHITECTURE.md`](ARCHITECTURE.md) — request lifecycle, layers, error mapping
- [`ALLOCATION_ENGINE.md`](ALLOCATION_ENGINE.md) — what `POST /allocations/generate` actually does
- [`DATA_MODEL.md`](DATA_MODEL.md) — the tables behind every resource
- [`SECURITY.md`](SECURITY.md) — threat model, RBAC matrix, Act 843
- [`TESTING.md`](TESTING.md) — the contract tests for this surface
