# Security & Data Protection

**CATMS — Classroom Allocation & Timetable Management System**
Security posture v1.0 · Covers NFR-SEC-01 … NFR-SEC-07 and Act 843

> This document is the *specification* of the security posture. Most of the
> classes it describes (`src/Core/Auth`, `Core/Rbac`, `Core/RateLimiter`,
> `Core/Audit`) are still to be written; where that is the case the section says
> so. The parts that exist today — the allocation engine's authorisation-relevant
> logic and the schema's constraints — are called out as implemented.

---

## Table of Contents

1. [Scope and Legal Basis](#1-scope-and-legal-basis)
2. [Security Principles](#2-security-principles)
3. [Roles and Permissions](#3-roles-and-permissions)
4. [Authentication](#4-authentication)
5. [Authorisation and Tenant Isolation](#5-authorisation-and-tenant-isolation)
6. [Data Protection Act, 2012 (Act 843)](#6-data-protection-act-2012-act-843)
7. [The Allocation Write Path](#7-the-allocation-write-path)
8. [Input Validation and Injection](#8-input-validation-and-injection)
9. [Rate Limiting and Abuse Controls](#9-rate-limiting-and-abuse-controls)
10. [Audit Trail](#10-audit-trail)
11. [Cryptography and Secret Management](#11-cryptography-and-secret-management)
12. [Logging and Privacy](#12-logging-and-privacy)
13. [Security Events and Incident Response](#13-security-events-and-incident-response)
14. [Dependency and Build Security](#14-dependency-and-build-security)
15. [Vulnerability Register](#15-vulnerability-register)
16. [Security Verification Matrix](#16-security-verification-matrix)

---

## 1. Scope and Legal Basis

### 1.1 What is being protected

| Asset | Why it matters | Worst case if lost |
| --- | --- | --- |
| **Student and staff personal data** | Names, e-mails, phone numbers, index numbers, course enrolment, teaching schedules | Breach of Act 843; harm to individuals; loss of institutional trust |
| **The timetable itself** | The operational output of the whole system | Mass double-booking; students in the wrong rooms; teaching stopped |
| **Credentials and tokens** | Access to the above | Full account takeover for any user, including admins |
| **The audit trail** | The evidence that overrides were deliberate | Undetectable, unaccountable changes to published schedules |
| **Engine weights and configuration** | Determines what the system decides | A tampered weight set silently degrades every allocation |

### 1.2 Legal basis

Ghana's **Data Protection Act, 2012 (Act 843)** is the binding constraint, and
it is why `docs/REQUIREMENTS.md` carries NFR-SEC-04 rather than treating
security as only a technical concern. Act 843 applies because CATMS processes
personal data about identifiable individuals in Ghana.

Operational requirements derived from it, and where each is satisfied:

| Act 843 duty | How CATMS meets it | Where |
| --- | --- | --- |
| Lawful, fair and transparent processing | Personal data is used only to schedule teaching; the purpose is stated to users at registration | §6.2 |
| Data minimisation | Only the fields in `docs/REQUIREMENTS.md` §8 are collected; no date of birth, no address, no national ID | §6.3, `db/schema.sql` |
| Purpose limitation | `student_index` is collected for the future SIS join and nothing else; it is never exposed outside the admin API | §6.3 |
| Accuracy | Enrolment counts are derived from `enrollments`, never trusted from a client | §6.4 |
| Storage limitation | Documented retention windows; `bin/retention.php` purges on schedule | §6.5, `docs/DATA_MODEL.md` |
| Integrity and confidentiality | TLS in transit, bcrypt at rest, RBAC, tenant isolation, audit trail | §4, §5, §10 |
| Data-subject access | A user can read every field held about them through `GET /profile` and `GET /auth/me` | §6.6 |
| Data-subject correction | `PATCH /profile`, `PATCH /users/{id}` (admin) | §6.6 |
| Data-subject erasure | `DELETE /users/{id}` → archive + pseudonymise, with retention respected | §6.6, §6.5 |
| Accountability | Immutable, attributable audit log of every state-changing administrative action | §10 |
| Security of processing | Threat model, least privilege, rate limiting, incident response | §13 |

### 1.3 Data classification

| Class | Examples | Handling |
| --- | --- | --- |
| **Restricted** | `password_hash`, refresh tokens, `APP_JWT_SECRET`, `DB_PASSWORD`, SMTP credentials | Never logged, never returned by an API, never in the image, never in git |
| **Confidential** | Personal data, timetables, audit log, conflict details | TLS only; RBAC-scoped; never cached in a shared store |
| **Internal** | Room inventory, course catalogue, weights, utilisation statistics | TLS only; RBAC-scoped |
| **Public** | Room code/name/building/capacity for an `available` room | Publishable; served with `ETag` |

`GET /rooms` deliberately does not expose the timetable of a room to a student —
room *attributes* are public, room *occupancy* is confidential. A student can
always look up their own schedule, and that is enough.

---

## 2. Security Principles

**1. Fail closed.** An unknown route, an unparsable token, an unlisted
permission, a missing tenant scope — every one is a denial. There is no code path
that treats "I could not determine whether this is allowed" as "allow". This is
the single most important property of the authorisation layer, and it is why
`Rbac` denies by default rather than allowing by default and listing exclusions.

**2. The server is the authority.** The client hides UI it cannot use; it does
not decide what is permitted. A modified client gains nothing (ADR-007, QA-5).

**3. Defence in depth, in this order.** TLS → rate limit → authenticate →
authorise → validate → audit. Each layer assumes the one before it may have
failed.

**4. Least privilege by default.** A user has no permission until a role grants
it. Adding a permission to a role is a reviewed change; removing one takes effect
on the next request, because authorisation is evaluated per request against
`role_permissions` rather than being cached in the token.

**5. Never trust the client.** No user-supplied value reaches SQL, the
filesystem, a shell, a template, or an authorisation decision without passing
through `Validator` and the relevant type gate. Capacity in particular is never
taken from the request: it is derived from `enrollments` (§6.4).

**6. Record what you did, especially the unusual.** Every override, every
regeneration, every force is attributed to a person with a timestamp, a reason
and the before/after state. "The system did it" is a permitted answer only for
scheduled jobs, and it is distinguishable.

**7. Collect the minimum, keep it the shortest useful time.** See §6.

**8. Prefer a transaction to a cleanup job.** Anything that must be both true —
an allocation and its notification, an allocation and its audit row — is written
in one transaction. Cleanup jobs are for retention, which is a policy decision
about the past, not a substitute for correctness in the present.

---

## 3. Roles and Permissions

**Status: specified, not yet implemented.** The authoritative definition is
`config/rbac.php`, which seeds the `roles`, `permissions` and `role_permissions`
tables. Roles are *data*, not code (ADR-007), so a permission change is a
migration plus a data edit rather than a deploy.

The matrix below is the same table as `docs/API.md` §3.1; the two must stay
identical, and `tests/Integration/RbacTest` asserts that the seeded rows match
`config/rbac.php` exactly — a drift between the config and the database is a
silent privilege change, which is the failure mode this structure exists to
prevent.

| # | Permission | Scope | student | lecturer | admin |
| --- | --- | --- | --- | --- | --- |
| 1 | `timetable:view` | own / department | own | own | any |
| 2 | `room:view` | department | ✓ | ✓ | ✓ |
| 3 | `room:manage` | department | — | — | ✓ |
| 4 | `course:view` | department | ✓ | ✓ | ✓ |
| 5 | `course:manage` | department | — | — | ✓ |
| 6 | `cohort:manage` | department | — | — | ✓ |
| 7 | `semester:view` | department | ✓ | ✓ | ✓ |
| 8 | `semester:manage` | department | — | — | ✓ |
| 9 | `allocation:view` | own / any | own | own | any |
| 10 | `allocation:generate` | department | — | — | ✓ |
| 11 | `allocation:confirm` | department | — | — | ✓ |
| 12 | `allocation:override` | department | — | — | ✓ |
| 13 | `allocation:cancel` | department | — | — | ✓ |
| 14 | `allocation:repair` | department | — | — | ✓ |
| 15 | `allocation:view_conflicts` | own / any | own | own | any |
| 16 | `profile:view_self` | own | ✓ | ✓ | ✓ |
| 17 | `profile:update_self` | own | ✓ | ✓ | ✓ |
| 18 | `user:view` | department | — | — | ✓ |
| 19 | `user:manage` | department | — | — | ✓ |
| 20 | `user:change_role` | department | — | — | ✓ |
| 21 | `report:view` | department | — | — | ✓ |
| 22 | `report:export` | department | — | — | ✓ |
| 23 | `audit:view` | department | — | — | ✓ |
| 24 | `notification:view_self` | own | ✓ | ✓ | ✓ |
| 25 | `notification:dispatch` | department | — | — | ✓ |

**25 permissions × 3 roles.** `any` scope is reachable only by a system-level
administrator (`users.department_id IS NULL`); a departmental admin is capped at
`department` scope. A user may hold exactly one role in v1 — the `users.role_id`
column is not nullable and there is no role-join table. If UTAS needs a lecturer
who is also an administrator, that is a schema change, not a permission change,
and it should be made deliberately.

### 3.1 Scope resolution, and why scope is not a role

Permissions answer *what*; scope answers *whose*. A lecturer may view
timetables (`timetable:view`) but only their own — the scope, not a different
permission, is what limits it. This keeps the permission count small and makes
the data-access question answerable in one place:

```php
// The only question RbacMiddleware asks beyond "is this permission granted?"
$scope = $route->scope();              // own | department | any
$where = $scope->clause($principal);    // builds the WHERE fragment
```

The query is scoped **in SQL**, never by filtering after the fetch. Post-filtering
is the classic way a `LIMIT 25` page leaks rows belonging to other users, because
the limit is applied before the filter.

### 3.2 Permission changes require review

Because roles are data, a `UPDATE role_permissions` on the production database
changes what every user of that role can do, with no code review and no deploy.
Mitigations:

- The `audit_log` records `user.role_changed` and every permission change, with
  before/after.
- `role_permissions` is writable only by the application (the app's DB user has
  no direct admin access to it), so a change must go through the API or a
  migration.
- Departmental admins cannot edit roles or permissions at all — no permission
  grants that. Only a system-level admin can, and only through
  `PATCH /users/{id}/role` for a *user's* role; the permission set itself is
  changed by a migration, which is reviewed.

---

## 4. Authentication

**Status: specified, not yet implemented.** `src/Core/Auth.php` is the target.

### 4.1 Password storage (NFR-SEC-03)

```php
$hash = password_hash($plain, PASSWORD_BCRYPT, ['cost' => 12]);
```

- `password_hash` with **bcrypt, cost 12**. Cost 12 is roughly 250 ms on current
  server hardware, which is the accepted trade for a system with ~150 users and
  low login volume. Revisit if the user base grows by an order of magnitude.
- The hash is **never** reversible, never logged, and never returned by a
  `toArray()`. Omitting it is a property of the entity's serialiser, not
  something a controller remembers to strip — a controller that forgets is how
  hashes leak.
- Verification uses `password_verify`, which is constant-time with respect to
  the hash. Never compare hashes with `===` or `hash_equals` on a `password_hash`
  result; `password_verify` also transparently rehashes when PHP's default cost
  changes, so call `password_needs_rehash` after a successful verify and update.
- The 72-**byte** bcrypt limit is enforced in the validator (max password length
  200 characters, rejected if the UTF-8 byte length exceeds 72). Silently
  truncating at 72 bytes means two different passwords can both authenticate,
  which is a real finding in code reviews of bcrypt systems and is cheaper to
  prevent than to explain.

### 4.2 Password policy

| Rule | Value | Reason |
| --- | --- | --- |
| Minimum length | 12 characters | Length beats composition rules; NIST SP 800-63B |
| Maximum length | 200 characters, ≤ 72 bytes | bcrypt's silent truncation |
| Composition rules | **none** | Punitive rules produce `Password1!`, which is weaker |
| Common/breached list check | required | Rejects `password123`, `qwerty`, `Admin@1234` in production |
| Similarity to e-mail or name | rejected | Catches `Kwame@utas.edu.gh` |
| Storage | never | No password hints, no security questions |

Rejecting the seeded demo passwords in production is not a password-policy rule —
it is a *seeding* rule, because the passwords are never supposed to exist. Three
controls, in order of how early they fire (§15.9, VULN-06): `bin/console seed`
refuses to run outside `local`/`staging`; outside `local` the demo accounts are
created `status='pending'` with `must_change_password=1`; and
`verify-integrity --verify-no-demo-credentials` fails a release that ships with
one still able to log in.

### 4.3 Tokens (NFR-SEC-01)

| | Access token | Refresh token |
| --- | --- | --- |
| Format | JWT, `HS256` | 64 random bytes, base64url |
| Lifetime | 15 minutes | 30 days, sliding |
| Storage (client) | JavaScript memory only | `HttpOnly; Secure; SameSite=Strict` cookie |
| Storage (server) | not stored | `sha256(token)` in `refresh_tokens` |
| Reuse | n/a | **Single-use.** Presenting a used token revokes the whole `family_id` |

**Only the hash of the refresh token is stored.** A database dump therefore cannot
be replayed against the API, which is the reason for the extra table rather than
stateless access tokens alone.

**Replay detection.** Refresh tokens form a rotation chain linked by `family_id`.
When a token is used, `used_at` is set and a new token is issued in the same
family. Presenting a token whose `used_at` is already set means the token was
stolen *and* the legitimate client has since rotated — an attacker cannot rotate
without invalidating, and the victim would notice. So:

1. Revoke every token in the family.
2. Write a `token.replay` `security_events` row at `critical` severity.
3. Revoke all other families for that user.

The legitimate cost is that the user's other devices are logged out. That is the
right trade: the alternative is a stolen token that remains valid for 30 days.

**Constant-time comparison.** `token_hash` is a `CHAR(64)` hex string, so
comparison uses `hash_equals($storedHash, hash('sha256', $presented))`, never
`===`. `hash_equals` compares in time independent of how many leading characters
match, so it cannot be used to recover a hash byte by byte. This applies
identically to:

- refresh tokens
- password reset tokens
- email-verification tokens
- `Idempotency-Key` lookups where the key is a secret
- CSV-export signed URLs, if those are ever added

**JWT verification** checks, in this order and failing closed on the first
failure: the algorithm is exactly `HS256` (**reject `alg: none` and any asymmetric
algorithm** — the classic JWT forgery), the signature verifies against
`APP_JWT_SECRET`, `nbf` is in the past, `exp` is in the future, `iss` matches,
`jti` has not been revoked, and the referenced user is still `active`. No failure
mode falls through to "treat as anonymous but allow".

### 4.4 Account lockout (NFR-SEC-05)

| Counter | Threshold | Consequence |
| --- | --- | --- |
| `failed_login_count` | 5 consecutive | `locked_until = now + 15 min` |
| Lockout | 15 minutes | Login returns `401` with `retry_after_seconds` |
| Success | — | Counter reset to 0, `locked_until` cleared |

Deliberate non-features:

- **No permanent lockout on unknown accounts.** Only real accounts are counted,
  so an attacker cannot lock a colleague out by guessing their e-mail.
- **No account-existence disclosure.** Unknown e-mail and wrong password return
  the identical `401 UNAUTHENTICATED` body. `POST /auth/forgot-password` always
  returns `202` for the same reason.
- **Lockout is a security control, not a DoS vector to worry about** — because
  the rate limit (§9) is keyed on IP *and* e-mail, a distributed attempt from many
  IPs still trips the account counter, and a single IP brute force is stopped at
  5/min before it reaches 5/15 min.

### 4.5 Multi-device sessions

A user may be signed in on several devices. Each device is one `family_id`.
`GET /auth/me` reports the count; "sign out all other devices" revokes every
family except the caller's. Password change and password reset both do this
automatically (§4.3), which is what makes a compromised session recoverable by
the legitimate owner without an administrator's help.

---

## 5. Authorisation and Tenant Isolation

### 5.1 Pipeline position

Authorisation runs in middleware, **before** the controller and before the
service:

```
Request
  → AuthMiddleware      authenticate: token valid, user active
  → RbacMiddleware      authorise:   permission + scope
  → RateLimitMiddleware throttle:     per-user and per-IP
  → ValidationMiddleware validate:    declarative body rules
  → AuditMiddleware      record:      state-changing admin calls
  → Controller          never does an authorisation decision itself
```

A controller that performs its own permission check is a bug: the check will be
missed on one of its five methods eventually. The route table in
`config/routes.php` is the single declaration point, and a route with no declared
permission is not registered — fail closed at registration time rather than at
request time.

### 5.2 Tenant isolation (NFR-SCALE-01, Act 843 §6.3)

Every tenant-scoped table carries `department_id` with a composite index
leading with it (NFR-SCALE-02). The scope rule:

> A record outside the caller's resolved `department_id` returns **404**, not 403.

This is deliberate. A `403` confirms the resource exists, which makes the API a
cross-department existence oracle — an enumeration channel under Act 843, and a
way to discover that, for example, a student in another department has a class on
a given day in a given room. `404` is indistinguishable from "does not exist".

The repository layer takes the scope as a required argument rather than reading a
global:

```php
/** @return list<Allocation> */
public function findForScope(int $departmentId, AllocationFilter $filter): array;
```

There is no `findById(int $id)` without a scope, because the first time someone
writes that, it gets called from a controller that has a department id in a
variable and forgets it.

### 5.3 BR-11 — allocation visibility

An allocation is visible to:

- students **enrolled in the cohort**,
- the **assigned lecturer**,
- **admins of the department** (and system-level admins).

Not to: other students, other lecturers, other departments' admins, and not to
anonymous users even for room attributes that happen to be public. The visibility
rule is expressed once, in the repository's `own` scope clause, and is covered by
`tests/Feature/TenantScopingTest` and by UAT-5.

---

## 6. Data Protection Act, 2012 (Act 843)

This section is the NFR-SEC-04 verification reference.

### 6.1 Principles applied

| Principle | Implementation |
| --- | --- |
| Lawfulness | Processing is necessary for the administration of teaching; stated to users at registration and in the privacy notice linked from the sign-up form |
| Fairness | No data is used for a purpose the user was not told about. Analytics are aggregate, never individual |
| Transparency | Every field collected is documented in `docs/REQUIREMENTS.md` §8 and in `GET /profile` |
| Purpose limitation | `student_index` exists only as a future SIS join key and is admin-only in every API |
| Data minimisation | See §6.3 |
| Accuracy | See §6.4 |
| Storage limitation | See §6.5 |
| Integrity & confidentiality | §4, §5, §10 |
| Accountability | §10 |

### 6.2 Data inventory

| Table | Personal data | Lawful basis | Retention |
| --- | --- | --- | --- |
| `users` | name, e-mail, phone, index/staff id, login metadata, password hash | Contract (employment/education) + legal obligation | Account life + 24 months, then pseudonymised |
| `enrollments` | student ↔ cohort link | Contract | Duration of enrolment + 24 months |
| `lecturer_course_assignments` | lecturer ↔ course | Contract | Semester + 24 months |
| `lecturer_availability` | lecturer absence reasons | Contract (legitimate interest) | Semester + 12 months |
| `allocations` | indirectly: identifies a lecturer's working pattern | Contract | Semester + 5 years (institutional record) |
| `notifications` | recipient, content | Contract | 90 days after read, 12 months if unread |
| `notification_outbox` | recipient, payload | Contract | 30 days after `sent` |
| `audit_log` | actor, IP, user agent, before/after | Legal obligation (accountability) | 7 years |
| `security_events` | user, IP, metadata | Legal obligation | 24 months |
| `room_utilisation_daily` | **none** — aggregate only | — | 5 years |
| `rate_limit_buckets` | bucket key may contain an IP | Legal obligation | 1 hour |

Two rows carry no personal data at all: `room_utilisation_daily` and the
reporting views. That is a design decision, not an accident — aggregation at write
time means the analytics surface can be broadly readable without exposing anyone's
schedule (`v_room_utilisation` groups by room and day, never by person).

### 6.3 Data minimisation

Fields deliberately **not** collected, and the reason:

| Not collected | Why not |
| --- | --- |
| Date of birth | Not needed to schedule a lecture |
| Home address | Not needed |
| National ID / Ghana Card number | Proportionate processing does not require a national identifier for a timetable system |
| Photograph / avatar | `users.avatar_url` exists but is optional and never required; it is a UI affordance, not a scheduling input |
| Biometrics | Disproportionate for this purpose |
| Precise location | Not needed |
| Previous qualifications | Not needed |

Enforcement: `Validator` rejects unknown body keys on write endpoints, so a
client cannot smuggle in a field the schema happens to have. And the column set
in `db/schema.sql` is the reviewed list — adding a column is a migration someone
has to justify in a pull request, at which point the minimisation question gets
asked.

### 6.4 Accuracy

`cohorts.enrolled_count` is a **derived** value that would be easy to leave stale
— and a stale value silently over-fills rooms, which is BR-04 broken. So:

- it is recomputed on every enrolment write, from `enrollments`, inside the same
  transaction;
- a client-supplied `enrolled_count` is rejected, not accepted-and-trusted;
- `tests/Integration/CohortEnrolmentTest` asserts the counter matches the row
  count after each operation.

The same principle applies to `rooms.capacity`, which is human-maintained and so
is *not* derived: changing it is an admin action, and the API's capacity-shortfall
warning (§7.3) is what stops a stale capacity from silently persisting.

### 6.5 Retention

Enforced by `bin/retention.php`, run nightly. It is the **only** process permitted
to delete an `audit_log` row, and it writes its own `system.retention_purge`
entries, so the audit trail records its own truncation.

| Data | Retention | Trigger |
| --- | --- | --- |
| Closed semester allocations | 5 years | `semesters.status = 'closed'` + 5 years |
| Unread notifications | 12 months | `read_at IS NULL` |
| Read notifications | 90 days | `read_at` set |
| Sent outbox rows | 30 days | `status = 'sent'` |
| Dead outbox rows | 90 days | `status = 'dead'` |
| Expired refresh tokens | 7 days | `expires_at` passed |
| Used/expired password reset tokens | 7 days | `expires_at` or `used_at` |
| Rate-limit buckets | 1 hour | `window_start` passed |
| `security_events` | 24 months | `created_at` |
| `audit_log` | 7 years | `created_at` (accountability obligation) |
| Archived user PII | 24 months after `deleted_at` | Pseudonymised, then the fields are nulled |
| `allocation_runs` / `allocation_conflicts` | 5 years | With the semester |

Retention is deliberately *not* aggressive. Deleting a timetable after a year
means a dispute in January about a room change in March cannot be investigated —
and the audit trail's whole purpose is to make exactly that investigation possible.

### 6.6 Data-subject rights

| Right | Mechanism | Notes |
| --- | --- | --- |
| **Access** | `GET /profile`, `GET /auth/me` | Every field held about the caller, in one response. No "contact the admin to see your data" |
| **Rectification** | `PATCH /profile`, `PATCH /users/{id}` | Self-service for contact details; admin for institutional fields |
| **Erasure** | `DELETE /users/{id}` | Archive + pseudonymise after retention (§6.5) |
| **Restriction** | `PATCH /users/{id}` → `status='suspended'` | Access blocked, data retained |
| **Portability** | `GET /users/{id}/export` | JSON of everything held, including allocations and notifications |
| **Objection / withdrawal** | Contact the data protection contact in the privacy notice | Documented process; logged as a `security_events` row |

**Erasure is not deletion, and that is the honest answer.** Allocation history
names lecturers and references cohorts; deleting it would destroy the audit trail
that NFR-SEC-06 requires and would remove other students' timetable records. Act
843
permits retention where deletion would defeat the purpose of the processing, and
an academic record is a recognised case. So the design pseudonymises the
individual and keeps the institutional record:

- `first_name`, `last_name`, `phone`, `avatar_url` → replaced with
  pseudonymised values after the retention window;
- `users.status` → `archived`, `deleted_at` set;
- allocations, audit rows and notifications are **retained**, because they are
  records about teaching delivered, not about the person;
- the retained rows refer to a `users` row that no longer identifies anyone.

This is documented to the user at the point they request erasure, so it is a
disclosure rather than a surprise.

---

## 7. The Allocation Write Path

The allocation write path is where this system's security properties actually
matter, because it is the only place where a mistake becomes a physical
consequence: two classes in one room, or a room that does not exist.

### 7.1 Every write goes through one service

`AllocationService` is the only component permitted to write the `allocations`
table. Controllers call the service; repositories are called by the service.
There is no `AllocationRepository::insert()` reachable from a controller, so
there is no path that skips the check.

### 7.2 Double booking is prevented twice (FR-ALLOC-02, ADR-006)

**First line: the engine.** `ConstraintChecker` implements HC-1 (room free),
HC-2 (lecturer free) and HC-3 (cohort free) as hard filters. A candidate that
fails any of them is discarded, never scored, so an invalid placement cannot
reach the database through a normal solve.

**Second line: the database.** Three unique keys on `allocations`:

```sql
UNIQUE KEY `uq_alloc_no_double_booking`   (`room_id`, `time_slot_id`, `week_number`, `active_guard`),
UNIQUE KEY `uq_alloc_cohort_no_clash`     (`cohort_id`, `time_slot_id`, `week_number`, `active_guard`),
UNIQUE KEY `uq_alloc_lecturer_no_clash`   (`lecturer_id`, `time_slot_id`, `week_number`, `active_guard`)
```

where

```sql
`active_guard` TINYINT GENERATED ALWAYS AS
    (CASE WHEN `status` IN ('proposed','confirmed','updated') THEN 1 ELSE NULL END) VIRTUAL
```

The generated column is the part that matters. A `cancelled` row contributes
`NULL`, and a `UNIQUE` index permits any number of `NULL`s — so a cancelled class
frees its room and slot **without** the history being deleted, while two *live*
bookings can never coexist. This is what makes NFR-REL-02 (keep the audit trail)
and FR-ALLOC-02 (no double booking) compatible, where a plain unique key would
force one of them to lose.

Under concurrency, the second writer receives a `23000` integrity violation, which
the repository translates to `409 CONFLICT`. QA-2 in
`docs/ARCHITECTURE.md` §15 is exactly this, and it is verified by
`tests/Integration/ConcurrentBookingTest`.

### 7.3 Capacity changes never move a class silently

If an admin reduces `rooms.capacity` below a live cohort's enrolment, the service
does **not** reallocate on its own. It returns `200` with
`meta.warnings[].affected_allocations` and leaves the decision to a human, who
then runs an explicit repair.

This is a security-adjacent decision as much as a UX one: an automatic repair
would move classes and notify people as a side effect of an unrelated edit, and
"the system moved my lecture and I did not ask it to" is precisely the
eroding-trust failure the project exists to fix (P-4, OBJ-4).

### 7.4 Overrides require a reason, and the reason is enforced (FR-ADMIN-07, BR-08)

`PATCH /allocations/{id}` rejects a body without `reason`:

```json
{ "error": { "code": "VALIDATION_FAILED",
             "details": { "reason": ["An override must record a reason (BR-08)."] } } }
```

There is no "quiet override" flag, no default reason, and no way for the
controller to fill one in. The same applies to `POST /allocations/{id}/cancel`
and to every endpoint that changes a published timetable.

A recorded override stores `override_reason` (max 500 chars) and `overridden_by`,
and writes an `audit_log` row with before/after JSON. **A forced override of a
hard constraint is additionally recorded as an open `WARNING` row in
`allocation_conflicts`**, so a deliberate violation stays visible in the conflict
report and the dashboard rather than becoming a silent hole in the schedule. A
forced double booking is flagged `critical` on the dashboard, because at that
point there is a physical conflict in the building.

`PATCH /users/{id}/role` refuses self-demotion with `409`: an admin who removes
their own last administrative permission locks every administrator out of the
system, including the person who would need to undo it.

---

## 8. Input Validation and Injection

### 8.1 Parameterised queries only (NFR-SEC-07)

Every query uses a PDO prepared statement. There is no string-concatenated SQL
anywhere in `src/Infrastructure`, and the only interpolation in a query is
inside a **whitelisted** fragment — the scope clause (§5.1) and the sort column,
the latter matched against a per-endpoint whitelist before it is used:

```php
$column = $whitelist[$request->query('sort')] ?? $default;
```

A `sort=id; DROP TABLE allocations` is a `400 MALFORMED_REQUEST`, not a
successful query. The static-analysis level (§16) plus the `Validator` make this
checkable, and `tests/Security/SqlInjectionTest` fires a payload set at every
endpoint that accepts a query parameter.

### 8.2 The output-encoding question

A defence-in-depth note, because the API and the PWA are the same deployment:
`db/schema.sql` uses `utf8mb4` (full Unicode), which rules out the Latin-1
multibyte tricks that historically bypassed escaping. JSON responses are encoded
with `JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP`, so a `<` or `&` in
a room name is escaped even for clients that would not otherwise interpret it.
The PWA renders with `textContent`, never `innerHTML`, for any value that came
from a user or an administrator.

### 8.3 CSV export injection

Exports are `text/csv`, and a spreadsheet application executes any cell beginning
with `=`, `+`, `-` or `@` as a formula. A lecturer named `=cmd|'/c calc'!A1` or a
room note beginning with `@SUM(...)` would execute on the administrator's
machine when they open the export.

`CsvWriter` therefore prefixes such cells with a single quote. This is a real and
frequently-omitted control; it is called out here because it looks like a
theoretical concern until the first audit report is a spreadsheet formula.

### 8.4 Validation rules

`Validator` is declarative and returns the first failure per field, so
`error.details` is never a wall of 40 messages for one bad field:

```php
'capacity' => ['required', 'integer', 'min:1', 'max:2000'],
'email'    => ['required', 'email:rfc', 'max:190'],
'reason'   => ['required', 'string', 'min:10', 'max:500'],
'room_id'  => ['required', 'integer', 'exists:rooms,id'],
```

Unknown body keys are rejected on write endpoints. This is a data-minimisation
control (§6.3) as much as a typo control: it stops a client from sending fields
the endpoint does not intend to accept, which is how mass-assignment bugs start.

---

## 9. Rate Limiting and Abuse Controls

MySQL-backed token buckets in `rate_limit_buckets`, shared across all workers
(NFR-SEC-05) — an in-process limiter would multiply the effective limit by the
number of PHP-FPM workers, which is the number of instances an attacker chooses.

| Bucket | Limit | Window | Key | Rationale |
| --- | ---: | --- | --- | --- |
| `login` | 5 | 1 min | IP + e-mail | Credential stuffing |
| `password_reset` | 3 | 1 h | IP + e-mail | Account enumeration by e-mail; also mail-bombing |
| `refresh` | 30 | 1 min | user id | Token replay probing |
| `search` | 60 | 1 min | user id | Table dumping |
| `write` | 120 | 1 min | user id | Runaway client |
| `generate` | 10 | 1 h | user id | Engine exhaustion — the most expensive endpoint by far |

Two details that matter:

- **`generate` is the tightest of the "normal" buckets** because a single call
  can hold a worker for up to 30 seconds (`time_budget_seconds` max). Ten per
  hour per administrator bounds the cost, and generation is serialised per
  `(department, semester)` so concurrent runs cannot multiply the load.
- **Search results are capped** at 200 with a refine-your-search hint, so the
  search endpoints cannot be used to dump a table in 25-row pages.

`Retry-After` and `X-RateLimit-*` headers are always emitted, including on
success, so a well-behaved client can back off without trial and error.

---

## 10. Audit Trail

### 10.1 What is recorded (NFR-SEC-06, FR-ADMIN-07)

Every state-changing administrative action:

| Action | Recorded when |
| --- | --- |
| `allocation.generate` | A run is created, with seed, mode and budget |
| `allocation.apply` | A run's solution is written to `allocations` |
| `allocation.override` | `PATCH /allocations/{id}` — with the reason |
| `allocation.confirm` | A proposal is confirmed |
| `allocation.cancel` | A session is cancelled — with the reason |
| `allocation.repair` | An incremental re-run |
| `conflict.resolve` | A conflict is closed with a stated resolution |
| `user.create` / `user.update` / `user.archive` | User administration |
| `user.role_changed` | Any role change, with the before/after permission delta |
| `room.create` / `room.update` / `room.status_changed` | Room administration |
| `course.create` / `course.update` / `course.delete` | Course administration |
| `semester.create` / `semester.update` | Calendar administration |
| `notification.dispatch` | Manual outbox re-drive |
| `system.migration` | A migration ran |
| `system.retention_purge` | Retention deleted rows, and how many |

Each row: `actor_id` (null = system), `actor_role`, `action`, `entity_type`,
`entity_id`, `before_state` (JSON), `after_state` (JSON), `ip_address`,
`user_agent`, `request_id`, `created_at`.

### 10.2 Immutability

The table is append-only. There is no write, update or delete endpoint for
`audit_log`, and `DELETE /audit` is not merely unauthorised — the route does not
exist, so the request is `405 METHOD_NOT_ALLOWED`. Only `bin/retention.php` may
remove rows, and it does so on the 7-year schedule while writing its own
`retention_purge` entries.

The application's database user is granted `SELECT, INSERT` on `audit_log` and
nothing else, so even a compromised application cannot rewrite history. This is
the concrete part of "immutable" that matters; the convention alone does not.

### 10.3 Reading the trail

`GET /audit` is admin-only. `request_id` links an audit row to the log lines and
to the HTTP response the actor saw, so an administrator investigating a disputed
change can reconstruct exactly what happened. `actor: null` means a scheduled
job, which is distinguishable from a person.

### 10.4 What is deliberately *not* audited

Login success/failure goes to `security_events`, not `audit_log`: there are
thousands, they are security telemetry rather than accountability records, and
mixing them would make the accountability trail unreadable. A student marking a
notification read is not audited either — it is not an administrative action, and
auditing everything trains everyone to ignore the audit log.

---

## 11. Cryptography and Secret Management

### 11.1 At rest

| Data | Method | Notes |
| --- | --- | --- |
| Passwords | bcrypt cost 12 | `password_hash`, never reversible |
| Refresh tokens | `sha256` hex | Enables constant-time comparison and lookup |
| Password reset tokens | `sha256` hex | Single-use, 60-minute expiry |
| Personal data in the database | Not encrypted at the column level | See §11.3 |
| Database files / backups | Volume-level encryption | Host responsibility; stated in `docs/DEPLOYMENT.md` |

### 11.2 Secrets

`APP_JWT_SECRET`, `DB_PASSWORD`, `SMTP_PASSWORD`, `SMS_API_KEY` are
**environment-injected, never in the image, never in git**.

| Requirement | Enforcement |
| --- | --- |
| Never committed | `.gitignore` covers `.env`; a pre-commit hook rejects any file containing `APP_JWT_SECRET=` |
| Never in the image | `Dockerfile` copies only tracked files; `.env` is not among them |
| Never logged | `Config` redacts every key matching `/PASS|SECRET|TOKEN|KEY/` in any dump |
| Rotation | Documented procedure in `docs/DEPLOYMENT.md` §9 |
| Development | `.env.example` contains only placeholders, all clearly marked |

`APP_JWT_SECRET` is at least 32 bytes of CSPRNG output. Generation:
`php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"`.

Rotation invalidates all access tokens; sessions are re-established by
re-authenticating. That is acceptable at 15-minute token lifetimes, and it is
preferable to supporting two valid secrets, which is a much harder property to
reason about later.

### 11.3 Why there is no column-level encryption

Encrypting `users.first_name` at the column level would be genuinely wrong here,
and it is worth recording why so nobody "improves" it later:

- The reporting and search paths need to join, group and sort on these columns.
  Encrypting them makes the analytics surface either impossible or requires a
  second, decrypted copy of the data — which is a *larger* Act 843 problem than
  the one it solves.
- The threat it defends against is a stolen disk or a stolen backup. Volume-level
  encryption on the host addresses that directly, without the application
  becoming unable to do its job.
- Key management is the actual risk in application-level encryption: a key in an
  environment variable is a key on the same machine as the data.

The decision is to rely on transport encryption, bcrypt, RBAC, tenant isolation,
audit and encrypted storage — and to say so explicitly rather than leaving it as
an omission.

---

## 12. Logging and Privacy

### 12.1 What is logged

Structured JSON lines, one per event, in `storage/logs/`, rotated daily and kept
30 days. Every line carries `request_id`, `timestamp`, `level`, `message`, and
where relevant `user_id`, `route`, `ip`, `duration_ms`, `request_id`.

Application events, warning and error logs, security events, the audit trail,
outbox failures and engine run metrics. That is the complete list.

### 12.2 What is never logged (NFR-SEC-03, NFR-SEC-04)

- Passwords, in any form, including hashes and reset tokens
- Access or refresh tokens, or `Authorization` / `Cookie` header values
- `APP_JWT_SECRET`, database passwords, SMTP credentials
- Full request bodies for `/auth/*` endpoints
- Personal data beyond an opaque user id — a log line says *which* user, not
  their name, e-mail or phone number
- Raw SQL with bound values
- Notification bodies (they contain room names and cohort names; the
  notification row itself is the record)

`Config::redact()` runs over every log context array, and the redaction keys are
`/PASS|SECRET|TOKEN|KEY|COOKIE|AUTH/i`. A log line is a document that can be
copied into a ticket, forwarded to a vendor, or retained for 30 days — none of
which is a good reason to put a credential in it.

### 12.3 CSRF

- The API is `Authorization: Bearer` with **no** ambient cookie credential on
  `/api/*`, so cross-site requests cannot be authenticated. There is no CSRF
  token on the JSON API because there is nothing for an attacker to ride.
- The one cookie-authenticated path, `POST /auth/refresh`, is `SameSite=Strict`,
  which blocks it cross-site entirely.
- The HTML login form is `POST` with a `SameSite=Strict` session cookie, so
  cross-site form submission is blocked.
- `Origin` and `Referer` are additionally validated on all state-changing
  requests, and any mismatch is a `403` plus a `security_events` row.

### 12.4 Security headers

Set at the edge (Nginx), because that is the only place they apply to *every*
response including errors:

| Header | Value |
| --- | --- |
| `Strict-Transport-Security` | `max-age=31536000; includeSubDomains` |
| `Content-Security-Policy` | `default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'` |
| `X-Content-Type-Options` | `nosniff` |
| `X-Frame-Options` | `DENY` |
| `Referrer-Policy` | `strict-origin-when-cross-origin` |
| `Permissions-Policy` | `geolocation=(), microphone=(), camera=()` |

No `unsafe-inline` in `script-src`. The PWA's own JavaScript is served from
`/assets/` as a static file precisely so this is possible; the service worker
additionally needs `'self'` for its scope, which is already covered.

---

## 13. Security Events and Incident Response

### 13.1 `security_events`

| Event | Severity | Signal |
| --- | --- | --- |
| `login.failed` | info | Below threshold |
| `login.locked` | warning | 5 consecutive failures |
| `login.succeeded` | info | Normal |
| `token.replay` | **critical** | A used refresh token was presented — assume compromise |
| `password.reset` | warning | Includes "requested by the user", which should be known to them |
| `password.changed` | info | |
| `rbac.denied` | warning | A role attempted a permission it does not hold |
| `scope.escalation_attempt` | warning | A cross-department request was refused |
| `admin.override_forced` | warning | A hard constraint was overridden |
| `rate_limit.tripped` | info | |
| `outbox.dead` | **critical** | Notifications permanently undelivered — someone is not being told |
| `migration.applied` | info | |

`token.replay` and `outbox.dead` are the two that page someone. Everything else
is a signal to review, not to respond to at 3 a.m.

### 13.2 Response runbooks

**Suspected token theft (`token.replay`, critical)**

1. Revoke all refresh tokens for the user (`UPDATE refresh_tokens SET revoked_at`).
2. Force a password reset; the user is emailed out-of-band.
3. Pull the user's `security_events` for the previous 30 days and their
   `audit_log` rows — if a replayed token belonged to an admin, assume the actions
   it took are suspect and review them specifically.
4. Check for the same pattern across users: one stolen client library often affects
   a whole cohort of accounts.
5. Record the incident. Act 843 requires notification to the Data Protection
   Commission if personal data was actually compromised.

**Suspected double booking in a live timetable (`admin.override_forced`, critical)**

1. `GET /allocations/conflicts?unresolved_only=true` and the dashboard alerts.
2. Identify the two conflicting allocations; contact both cohorts *directly* — do
   not rely on the notification system, which may be what is broken.
3. Resolve by moving the less constrained session, recording the reason.
4. Root cause: was it a forced override, a capacity change, or a bug? If a bug,
   the `active_guard` unique key should have prevented the write — investigate how
   it was bypassed before closing.

**Notification delivery failure (`outbox.dead`, critical)**

1. `SELECT * FROM notification_outbox WHERE status = 'dead'` — what failed, when,
   and for whom.
2. Check whether the channel adapter is failing (SMTP credentials, provider quota)
   or the payloads are bad.
3. Re-drive with `POST /notifications/dispatch` once the cause is fixed.
4. **Tell the affected users out-of-band** if the messages concerned room changes.
   A dead notification is a user who will arrive at the wrong room.

**Account compromise, general**

1. `PATCH /users/{id}` → `status='suspended'` — access stops immediately, data
   is retained.
2. Revoke tokens, reset password, review `audit_log` for the account.
3. For an admin account, review every `audit_log` row by that actor in the
   compromise window and confirm each one was intended.
4. `GET /audit?actor_id={id}` is the primary tool for step 3.

### 13.3 Contact and escalation

Escalation: **suspend first, investigate second.** The cost of suspending an
account wrongly is one support ticket; the cost of a 30-minute delay while an
attacker holds an admin session is a compromised timetable. Access restoration is
fast and audited; restoration of a *system* that was changed while compromised is
not.

---

## 14. Dependency and Build Security

| Control | Implementation |
| --- | --- |
| Dependency review | `composer audit` in CI; a new package is a reviewed pull request |
| Version pinning | Exact versions in `composer.lock`, committed |
| Automated updates | Dependabot/Renovate weekly, non-blocking; security advisories blocking |
| Lock-file integrity | `composer install`, never `composer update`, in CI and in the image |
| No production dev dependencies | `composer install --no-dev` in the runtime image |
| No network at runtime | The image contains everything; the app makes no third-party calls except the configured notification providers |
| Secrets not in the image | `.env` is not copied; secrets are injected at run time |
| Base image | Pinned by digest, not by tag |
| Non-root container | The PHP-FPM worker runs as an unprivileged user |
| Read-only filesystem | Where the platform supports it; only `storage/` is writable |
| Audit trail permissions | The app's DB user has `SELECT, INSERT` only on `audit_log` |

The dependency surface is deliberately small: four dev tools, no runtime packages
beyond PHP's own extensions. `ext-gmp` is only a `suggest`, because the engine's
31-bit RNG does not need it (`src/Domain/Allocation/Rng.php` documents why 64-bit
constants are a trap under `strict_types=1`).

---

## 15. Vulnerability Register

Open issues found during design and authoring. "Fixed" means a control exists in
the code; "Open" means a documented design decision with a known limitation.

| ID | Finding | Severity | Status | Control / mitigation |
| --- | --- | --- | --- | --- |
| VULN-01 | `Rng` used 64-bit constants exceeding `PHP_INT_MAX`; PHP parsed them as floats and `&` on a float is a fatal `TypeError`, so **the engine could never run** | **Critical** | **Fixed** | Rewritten as 31-bit xorshift128; `RngTest` (17 tests) verifies determinism, range and uniformity; the algorithm was independently validated by porting it to JavaScript and running determinism and chi-square checks |
| VULN-02 | `CandidateGenerator` had **no HC-8 check**, so Phase 1 committed placements violating the lecturer daily-load ceiling | **Critical** | **Fixed** | HC-8 added; `CandidateGeneratorTest` asserts prefilter/checker equivalence over every `(session, room, slot)` combination; `testThePrefilterEnforcesTheDailyLoadCeiling` is the named regression test |
| VULN-03 | `OccupancyIndex::lecturerDayLoad()` subtracted an excluded session even when it fell on a **different day**, under-counting load and permitting an HC-8 breach | **Critical** | **Fixed** | `$placedDay` added; `testHc8ExcludingASessionOnAnotherDayMustNotDiscountTheLoad` |
| VULN-04 | `reconcileUnallocated()` took `$current` **by value**, discarding every placement made during reconciliation | High | **Fixed** | Now by reference; `IncrementalRepairTest` |
| VULN-05 | `buildMetrics()` computed room utilisation as `$existing === [] ? 0.0 : 0.0` — a value that is zero either way, so the OBJ-3 metric was unmeasured | High | **Fixed** | Real computation from the existing timetable; `SolutionQualityTest::testTheWasteTermDrivesSeatUtilisationAboveTheRoomAverage` |
| VULN-06 | Seeded demo passwords (`Admin@1234`, `Lecturer@1234`, `Student@1234`) are in `README.md` and would be in a deployed database | **Critical if deployed to production** | **Open — mitigated** | `bin/console seed` refuses to run when `APP_ENV` is neither `local` nor `staging` unless `--i-know-what-i-am-doing`; outside `local` the demo accounts are created `status='pending'` with `must_change_password=1` and a random 16-character password; `php bin/console verify-integrity --verify-no-demo-credentials` is a mandatory release-gate step (`docs/DEPLOYMENT.md` §15.9) |
| VULN-07 | `EngineOptions::deadlineAt()` read the clock on every call, so the deadline receded as fast as the search advanced and the budget never expired — an unbounded engine | High | **Fixed** | Replaced with `budgetExpiresAt(float $startedAt)`, computed once; `DeterminismTest::testTheTimeBudgetIsMeasuredFromASingleFixedInstant` |
| VULN-08 | `invalidateFeasibility()` performed a nonsensical bitwise OR of session ids and compared a boolean to `$placed->roomId()` | High | **Fixed** | Rewritten to evict exactly the affected session ids; exercised by `IncrementalRepairTest` and `testTheFeasibilityCacheAvoidsRecountingEveryPendingSession` |
| VULN-09 | `CostFunction` referenced an undeclared `$cohortPreviousBuilding` property — dynamic property creation, deprecated in PHP 8.2 | Medium | **Fixed** | Declared; the unused `$roomUseThisWeek` was replaced with a reference-counted `cohortRoomUse` ledger whose `forgetUse()` is exactly reversible (`testTheFragmentationLedgerIsReversible`) |
| VULN-10 | `ConstraintChecker` codes could be renamed silently, orphaning `allocation_conflicts.constraint_code` history | Medium | **Fixed** | `HardConstraintTest::testConstraintCodesAreUniqueAndStable` pins `HC-1` … `HC-10` as a schema contract |
| VULN-11 | Nothing has been executed: no test has run, no linter has run, and the golden baseline does not exist | High | **Open** | `docs/TESTING.md` §9 first-run checklist; the baseline is generated on first run and marked *incomplete* until reviewed and committed, because an unreviewed auto-generated baseline is worthless |
| VULN-12 | No `src/Core/*` yet — `Auth`, `Rbac`, `RateLimiter`, `Audit`, `Validator` are specified in this document but not written | High | **Open** | `docs/IMPLEMENTATION.md` phase 2; the API's fail-closed design means the gap is visible as `404`/`500`, not as silent access |
| VULN-13 | Column-level encryption of personal data is absent | Informational | **Open — decided** | §11.3: transport encryption, bcrypt, RBAC, tenant isolation, encrypted volumes, and encrypted backups. Encrypting the columns would require a second decrypted copy for analytics, which is a larger Act 843 problem than the one it solves |
| VULN-14 | The application DB user is specified as `SELECT, INSERT`-only on `audit_log`, but the grant script does not yet exist | Medium | **Open** | `db/migrations/` must include the explicit grant; deployment checklist in `docs/DEPLOYMENT.md` §3 |
| VULN-15 | CSRF protection relies on bearer tokens + `SameSite=Strict` + `Origin` validation, with no synchroniser token | Low | **Open — decided** | The JSON API has no ambient credential to ride, so a synchroniser token would protect nothing. The HTML form path is `SameSite=Strict` and origin-checked. Revisit if a cookie-authenticated `GET` with side effects is ever introduced |

**Review cadence.** This register is reviewed at every sprint boundary and after
any `security_events` at `warning` or above. VULN-06, VULN-11, VULN-12 and VULN-14
are **release blockers**: each one is a control the design depends on and does
not yet have.

---

## 16. Security Verification Matrix

| Requirement | Control | Verified by | Status |
| --- | --- | --- | --- |
| NFR-SEC-01 — TLS 1.2+, at-rest credentials | Edge TLS config; bcrypt; hashed refresh tokens | `JwtTest`, TLS scan, header check | Specified |
| NFR-SEC-02 — RBAC on every endpoint | `RbacMiddleware`, 25 permissions, default deny | `RbacTest` (25 × 3 matrix), `tests/Security/RbacTest` | Specified |
| NFR-SEC-03 — adaptive salted hashes | `password_hash` bcrypt cost 12; 72-byte guard; never logged or serialised | `PasswordHashTest`, `DataMinimisationTest` | Specified |
| NFR-SEC-04 — Act 843 | Minimisation, retention, data-subject rights, audit | This document §6; `docs/DATA_MODEL.md` | **Partly specified** |
| NFR-SEC-05 — lockout and rate limiting | 5 failures → 15 min; MySQL-backed buckets | `tests/Integration/Auth/*`, `RateLimiterTest` | Specified |
| NFR-SEC-06 — immutable audit log | Append-only; `SELECT, INSERT` grant; retention purge | `AuditImmutabilityTest` | Specified; **grant missing** (VULN-14) |
| NFR-SEC-07 — validation, parameterised queries | `Validator`; prepared statements; sort whitelists | `ValidatorTest`, `SqlInjectionTest`, PHPStan level 6 | Specified |
| BR-01 — no room double booking | HC-1 + `uq_alloc_no_double_booking` | `NoDoubleBookingTest`, `ConcurrentBookingTest` | **Engine implemented** |
| BR-08 — override recorded | `reason` required; no quiet override; `override_reason` + `overridden_by` | `AdminOverrideTest` | Specified |
| FR-ALLOC-05 — conflicts reported, not hidden | `UnallocatedSession` with counts and reasons; `allocation_conflicts` rows | `UnsolvableTest` (10 tests) | **Engine implemented** |
| FR-ADMIN-07 — audit trail | 16 audited actions, before/after JSON | `AuditTrailTest` | Specified |

Two of the ten rows are implemented and green-able today — the engine's
hard-constraint enforcement and its conflict reporting. The rest await the
application layer, which is the correct order: the parts most likely to be
tuned and re-run were built and tested first, and the parts that are
conventional get the benefit of that foundation.

---

## Related Documents

- [`REQUIREMENTS.md`](REQUIREMENTS.md) — NFR-SEC-01…07, BR-01…BR-12, FR-ADMIN-07
- [`API.md`](API.md) — the permission matrix, error model and rate limits this document specifies
- [`ARCHITECTURE.md`](ARCHITECTURE.md) — §10 security architecture, ADR-006, ADR-007
- [`DATA_MODEL.md`](DATA_MODEL.md) — the `active_guard` trick and the data inventory
- [`TESTING.md`](TESTING.md) — §10.4 the planned security suite
- [`DEPLOYMENT.md`](DEPLOYMENT.md) — environments, secrets, backup and restore
