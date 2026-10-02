# Classroom Allocation & Timetable Management System (CATMS)

**Mobile-friendly classroom allocation and timetable management platform for the
University of Technology and Applied Science (UTAS), Ghana.**

> This repository is the implementation blueprint and working scaffold for the system
> specified in the project report *"Design and Implementation of a Mobile-Friendly
> Classroom Allocation and Timetable Management System for the University of Technology
> and Applied Science"* (Agyapong Junior, 2026).

## Status

| Area | State |
| --- | --- |
| **Done and tested** | The allocation engine (21 classes, 124 unit tests, no database required), the full 29-table schema, migrations and a re-runnable seed, the console kernel with 12 commands, RBAC, JWT auth, rate limiting, health checks, and the Docker/CI configuration |
| **Scaffolded, not implemented** | 11 of 14 HTTP controllers. `config/routes.php` declares the whole API; missing controllers return `501 NOT_IMPLEMENTED` **after** authentication, so they never reveal which endpoints exist to an anonymous caller. Run `php bin/console routes --missing` to see what is left |
| **Planned** | PWA frontend (`public/assets`), `tests/Integration`, `tests/Feature`, `tests/Performance`, `src/Domain/Service` |
| **Scope** | Nothing in the report is deferred. The gap is the web layer, not the design — every requirement is traced in [`docs/REQUIREMENTS.md`](docs/REQUIREMENTS.md) |

Everything needed to migrate, seed, verify and benchmark is present. The
first-run checklist in [§7.4](#74-first-run-checklist) walks through it.

---

## 1. Problem Statement

UTAS allocates lecture rooms for face-to-face classes manually, using notice boards and
spreadsheets. The report documents the consequences:

| Problem | Impact |
| --- | --- |
| Timetable clashes / overlaps | Missed lectures, reduced attendance |
| Classroom double-booking | Physical conflicts, wasted teaching space |
| Inefficient use of limited space | Under-utilised rooms, overcrowding |
| Slow dissemination of changes | Last-minute confusion, students and lecturers arrive at the wrong venue |
| No data for planning | No basis for infrastructure or workload decisions |

The system automates allocation using room capacity, class size, and lecturer
availability, and pushes changes to students and lecturers in real time.

---

## 2. System Goals

Six core objectives (report §3.3):

1. **Automate classroom allocation** — assign rooms from class size, room capacity and
   lecturer availability. *Eliminate double bookings and timetable overlaps.*
2. **Real-time updates and notifications** — alerts for cancellations and room
   reallocations. *Timely communication, less last-minute confusion.*
3. **Improve resource utilisation** — optimise use of available classrooms.
   *Target ≥ 90 % classroom-utilisation efficiency.*
4. **Transparency and accessibility** — one shared digital timetable for students,
   lecturers and administrators. *Improve trust, reduce disputes.*
5. **Support administrative decision-making** — reports and analytics on usage,
   lecturer schedules and peak demand. *Data-driven planning.*
6. **Mobile-first delivery** — accessible from any device, anywhere.

---

## 3. Technology Stack

Selected per report §3.9.2 and §4.2.1 — chosen for reliability, support and ease of
integration between the frontend, backend and database layers in a client–server
architecture.

| Layer | Technology | Version |
| --- | --- | --- |
| Presentation | HTML5, CSS3, Vanilla JavaScript (ES2020) | — |
| Mobile shell | Progressive Web App (installable, offline shell) | — |
| Application / API | PHP (REST, layered MVC) | 8.2+ |
| Data | MySQL | 8.0+ |
| Auth | `password_hash` (bcrypt) + JWT access / refresh tokens | — |
| Notifications | In-app store + pluggable e-mail / SMS adapters | — |
| Deployment | Docker Compose, Nginx + PHP-FPM | — |

---

## 4. Architecture at a Glance

```
┌──────────────────────────────────────────────────────────────────────┐
│  PRESENTATION  ·  Mobile-first PWA (HTML / CSS / JS)                 │
│  Login · Timetable · Room Search · Notifications · Admin Dashboard   │
└───────────────────────────────┬──────────────────────────────────────┘
                                │  HTTPS · JSON (REST /api/v1)
┌───────────────────────────────▼──────────────────────────────────────┐
│  APPLICATION  ·  PHP 8 REST API                                      │
│  Router · Auth · RBAC · Validation · Error Handling · Rate Limiting  │
└───────────────────────────────┬──────────────────────────────────────┘
┌───────────────────────────────▼──────────────────────────────────────┐
│  DOMAIN  ·  Pure PHP, framework-free, unit-testable                  │
│  Allocation Engine · Scheduling · Timetable · Reporting · Notification│
└───────────────────────────────┬──────────────────────────────────────┘
┌───────────────────────────────▼──────────────────────────────────────┐
│  PERSISTENCE  ·  Repositories + PDO prepared statements              │
│  MySQL 8                                                              │
└──────────────────────────────────────────────────────────────────────┘
```

Key design rules:

- **The domain layer is pure.** It has no dependency on HTTP, PDO, or superglobals —
  this is what makes the allocation engine unit-testable and reusable from a CLI
  batch job.
- **Hard constraints vs soft preferences.** Feasibility (capacity, no double booking,
  features) is a *filter*; quality (wasted seats, student movement, churn) is a
  *scored cost function*. See [`docs/ALLOCATION_ENGINE.md`](docs/ALLOCATION_ENGINE.md).
- **Every allocation is auditable.** Overrides, regenerations and confirmations write
  to an append-only audit log.
- **RBAC at the API boundary.** `student`, `lecturer`, `admin` — enforced server-side
  on every request; the client only reflects the UI.

Full detail: [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md).

---

## 5. Feature Modules

Mapped to report §3.7 (functional requirements) and §3.9 (software development).

| # | Module | Key capability | Spec |
| --- | --- | --- | --- |
| 1 | **User Registration & Authentication** | E-mail + password login for all three roles; sign-up; forgot/reset password | §3.7.1 |
| 2 | **User Profile Management** | Update name, contact details, role | §3.7.2 |
| 3 | **Class & Timetable Management** | Students view allocation + timetable; lecturers manage schedule; admins create/edit | §3.7.3 |
| 4 | **Allocation Optimization Engine** | Auto-assign by class size, capacity, lecturer availability; recompute on change | §3.7.4 |
| 5 | **Course & Classroom Selection** | Live room availability; compare by capacity/availability/suitability; admin override | §3.7.5 |
| 6 | **Booking & Confirmation** | Confirmation notification on allocation; propagate updates | §3.7.6 |
| 7 | **Tracking & Notifications** | In-app alerts on timetable change; status tracking (confirmed / updated / cancelled) | §3.7.7 |
| 8 | **Academic Calendar Integration** | Align allocations to official calendar; semester timelines | §3.7.8 |
| 9 | **Resource Management** | Utilisation statistics; peak-usage / room / conflict reports | §3.7.9 |
| 10 | **Admin Dashboard** | Manage users, courses, rooms; platform analytics; heat maps | §3.7.10 |

---

## 6. Repository Layout

Existing files are plain; directories and files that are **planned but not yet
written** are marked `·planned`. The engine and everything needed to run,
migrate, seed, verify and benchmark the system is present; most HTTP
controllers are not.

```
.
├── README.md                     ← you are here
├── composer.json                 Dependencies, PSR-4 autoload, scripts
├── phpcs.xml                     PSR-12 + project rules — the CI gate
├── phpstan.neon                  Level 6 over src/, tests/, db/, config/, bin/
├── phpunit.xml                   Three suites: unit, integration, feature
├── .php-cs-fixer.php             Auto-fix only, never the gate
├── Dockerfile                    One multi-stage image for web + worker
├── docker-compose.yml            app, worker, db (host 3307), mailpit
├── .env.example                  Every variable the code reads
├── .github/workflows/ci.yml      lint → analyse → unit → build → scan → push
├── config/                       Plain PHP arrays, read at boot, no classes
│   ├── rbac.php                  25 permissions × 3 roles (the single source)
│   ├── routes.php                The complete route table, 917 lines
│   └── weights.php               Cost-weight profiles
├── docs/
│   ├── ARCHITECTURE.md           Layers, request lifecycle, ADRs
│   ├── REQUIREMENTS.md           Extracted & traceable requirements spec
│   ├── ALLOCATION_ENGINE.md      Algorithm, cost model, complexity
│   ├── API.md                    REST endpoint reference
│   ├── DATA_MODEL.md             ER model, entities, indexes, retention
│   ├── IMPLEMENTATION.md         Build guide, phases, conventions
│   ├── TESTING.md                Strategy, 124 tests, acceptance criteria
│   ├── SECURITY.md               Threat model, RBAC matrix, Act 843
│   ├── DEPLOYMENT.md             Environments, CI/CD, runbooks
│   └── source/                   Extracted report text (traceability)
├── db/
│   ├── schema.sql                29 tables, 3 views, 5 verification queries
│   ├── seed.sql                  14 statements of reference data, re-runnable
│   └── migrations/               PHP, YYYY_MM_DD_HHMMSS_name.php
│       ├── …_baseline.php            execs schema.sql
│       └── …_audit_log_grants.php   VULN-14: SELECT, INSERT only
├── public/                       ← web server document root
│   ├── index.php                 Front controller (API + SPA entry)
│   ├── router.php                Router for `php -S`
│   ├── .htaccess                 Apache rewrite to index.php
│   └── assets/{css,js,img}       ·planned
├── src/
│   ├── Core/                     Config, Database, Router, Request, Response,
│   │                             Validator, Jwt, Rbac, RateLimiter, Logger,
│   │                             Authenticator, ExceptionHandler, Container
│   ├── Console/                  Kernel, Input, Output, Launcher
│   │   └── Command/              12 commands
│   ├── Domain/                   Pure business logic — no HTTP, no PDO, no clock
│   │   ├── Allocation/           ★ The engine: 21 classes
│   │   ├── Entity/               User
│   │   ├── Repository/           Interfaces
│   │   ├── Service/              ·planned  Timetable, Notification, Reporting
│   │   └── Exception/
│   ├── Infrastructure/
│   │   └── Persistence/
│   │       ├── Migration/        Migration interface + Migrator
│   │       └── Mysql/            Loader, writer, repositories
│   └── Http/
│       ├── Controller/           Controller (base), Health, Auth
│       │                         ·planned  11 more (see `routes --missing`)
│       └── Middleware/
├── storage/
│   └── logs/  cache/             maintenance.flag lives at the root ·planned
│                                 sessions/
├── tests/
│   ├── bootstrap.php
│   └── Unit/Allocation/          ★ 124 tests, 12 files
│       └── Fixture/              ProblemBuilder, ProblemFactory
│   ├── Integration/              ·planned  empty
│   └── Feature/                  ·planned  empty
├── docker/                       nginx.conf, php-fpm.conf
└── bin/
    ├── console                   The kernel — canonical entry point
    ├── migrate.php  seed.php
    ├── generate-timetable.php  worker.php  retention.php
    ├── benchmark.php             NFR-PERF-01 / NFR-PERF-04 gate, no DB
    └── regenerate-golden.php    Writes the golden baseline
```

The `tests/Unit/Allocation/Fixture/golden/` directory is absent by design and is
created by `composer golden` — see §7.4.

---

## 7. Quick Start

### 7.1 Prerequisites

- PHP **8.2+** with `pdo_mysql`, `mbstring`, `json`, `openssl`
- MySQL **8.0+**
- Composer **2.x**
- (Optional) Docker Desktop

### 7.2 Docker (recommended)

```bash
git clone <repo-url> catms && cd catms
cp .env.example .env

docker compose up -d --build
docker compose exec app php bin/console migrate
docker compose exec app php bin/console seed
docker compose exec app php bin/console smoke      # confirms the install
```

Open <http://localhost:8080>. Mailpit catches every outbound message on
<http://localhost:8025> — nothing leaves the machine. The database is published
on host port **3307** so it cannot collide with a MySQL you already run.

### 7.3 Local (no Docker)

```bash
cp .env.example .env          # then edit DB_* and APP_KEY
composer install
php bin/console migrate
php bin/console seed
php bin/console smoke
php -S localhost:8080 -t public public/router.php   # PHP dev server
```

Generate `APP_KEY` with:

```bash
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

### 7.4 First-run checklist

Work down this list before writing any code against the installation. Each step
verifies one layer, so a failure names the layer instead of the whole system.

| # | Command | Passing means |
| --- | --- | --- |
| 1 | `composer install` | Autoloader and dev tooling present. `Class "App\…" not found` means a stale autoloader: `composer dump-autoload -o` |
| 2 | `php -r "echo bin2hex(random_bytes(32));"` | `APP_KEY` set in `.env`, ≥ 32 characters |
| 3 | `php bin/console migrate --status` | Ledger exists, nothing pending |
| 4 | `php bin/console seed` | 3 departments, 22 rooms, 17 time slots, 11 courses, 11 cohorts, 25 permissions × 3 roles, 3 demo users |
| 5 | `php bin/console routes` | Route table loads. Controllers not yet written list as `501`, which is expected, not an error — `--missing` shows only those, and is the review tool for what is left to build |
| 6 | `php bin/console smoke` | All six groups green: `config`, `database`, `schema`, `data`, `http`, `engine`. The `engine` group is a real solve, not a stub |
| 7 | `php bin/console verify-integrity` | Schema invariants, the five `db/schema.sql` footer queries, the `audit_log` grant, the migration ledger |
| 8 | `php -S localhost:8080 -t public public/router.php` then `curl -sS http://localhost:8080/api/v1/health` | Boot path end to end |
| 9 | `composer test:unit` | 124 engine tests green — no database required |
| 10 | `php bin/benchmark.php` (or `composer bench`) | Solve < 3 s (NFR-PERF-01) and accuracy ≥ 0.90 (NFR-PERF-04) |
| 11 | `composer golden` | Creates the golden baseline — **new clones have none**, see below |

> `composer test` runs *all* three suites, including `integration` and `feature`.
> Those directories are empty on a fresh clone, so use `composer test:unit` until
> they are populated. `composer check` (`lint` → `analyse` → `test:unit`) is the
> same three gates CI runs, in the order that fails fastest.

**The golden baseline is not in the repository.**
`tests/Unit/Allocation/GoldenFileTest.php` calls `markTestIncomplete()` when
`tests/Unit/Allocation/Fixture/golden/*.json` is missing, so a fresh clone is
green instead of red — an incomplete test is honest, a failing one looks like an
engine regression. Step 11 creates it. **Read the generated file before
committing it**: it is the reference every later run is compared against, so a
baseline generated without being read turns a mistake into a permanent
expectation. The CI job `Unit tests` → *Verify the golden baseline is current*
(`git diff --exit-code` after regenerating) is the guard against that; it will
tell you to run `composer golden` and commit the result.

`verify-integrity` and `smoke` are read-only by contract, with one caveat:
`Migrator::status()` calls `ensureLedger()`, so either command will create
`schema_migrations` and nothing else when run against an empty database. This is
why they are ordered after `migrate` above.

### 7.5 Demo accounts

| Role | E-mail | Password |
| --- | --- | --- |
| Administrator | `admin@utas.edu.gh` | `Admin@1234` |
| Lecturer | `lecturer@utas.edu.gh` | `Lecturer@1234` |
| Student | `student@utas.edu.gh` | `Student@1234` |

> **These passwords are shorter than the 12-character policy minimum**, which is
> why seeding bypasses `PasswordPolicy` rather than weakening it. They are only
> ever usable when `APP_ENV=local`:
>
> - `bin/console seed` refuses to run when `APP_ENV` is neither `local` nor
>   `staging`, unless `--i-know-what-i-am-doing` is passed.
> - Outside `local`, the accounts are still created but with `status='pending'`,
>   `must_change_password=1` and a random 16-character password printed once, so
>   a leaked password still cannot be used.
> - `php bin/console verify-integrity --verify-no-demo-credentials` is a
>   mandatory release gate that fails if any demo account is present *and* still
>   able to log in.
>
> See [`docs/SECURITY.md`](docs/SECURITY.md) (VULN-06) and
> [`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md) §15.9.

---

## 8. Quality Targets

Acceptance criteria from report Table 4.1, wired into the test suite
([`docs/TESTING.md`](docs/TESTING.md)).

| Criterion | Target | Report result | Where verified |
| --- | --- | --- | --- |
| Accuracy of allocation | > 90 % | 95 % | `SolutionQualityTest`, `GoldenFileTest`, `bin/benchmark.php` |
| System response time | < 3 s | 1.8 s | `PerformanceTest` (engine) and `bin/benchmark.php`; `tests/Performance/` ·planned for HTTP p95 |
| User satisfaction | > 80 % | 88 % | Pilot UAT, `docs/TESTING.md` §6 |
| Uptime | > 99 % | 99.5 % | [`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md) |
| Mobile accessibility | 100 % | 100 % | Responsive PWA, Lighthouse CI |

Non-functional requirements (§3.8):

- **Security** — end-to-end encryption, role-based access control, compliant with
  Ghana's Data Protection Act, 2012 (Act 843).
- **Scalability** — supports growth across multiple departments without performance
  degradation.
- **Performance** — timetable updates and allocation changes returned in **< 3 s** at
  peak academic hours.
- **Maintainability** — modular design; features and fixes ship without disrupting
  ongoing academic activity.

---

## 9. Documentation Map

| Document | Read it when you need to… |
| --- | --- |
| [`docs/REQUIREMENTS.md`](docs/REQUIREMENTS.md) | Know *what* to build and trace every feature to the report |
| [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) | Understand *how* the system is structured |
| [`docs/ALLOCATION_ENGINE.md`](docs/ALLOCATION_ENGINE.md) | Work on the scheduling algorithm — the hard part |
| [`docs/DATA_MODEL.md`](docs/DATA_MODEL.md) | Change the schema or add an entity |
| [`docs/API.md`](docs/API.md) | Integrate a client or write a controller |
| [`docs/IMPLEMENTATION.md`](docs/IMPLEMENTATION.md) | Plan the build, sprints and milestones |
| [`docs/TESTING.md`](docs/TESTING.md) | Write or run tests; check acceptance |
| [`docs/SECURITY.md`](docs/SECURITY.md) | Review auth, RBAC and compliance posture |
| [`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md) | Ship to staging or production |

---

## 10. Methodology Traceability

Built with **Design Science Research Methodology (DSRM)** — problem identification,
objectives, design & development, demonstration, evaluation, communication — combined
with an **Agile** iterative lifecycle. Theoretical grounding uses the **TOE**
(Technology–Organization–Environment) framework for technological readiness.

| Phase | Artefact in this repo |
| --- | --- |
| Problem identification | `docs/REQUIREMENTS.md` §1–2 |
| Objectives | `README.md` §2 |
| Design & development | `docs/ARCHITECTURE.md`, `src/` |
| Demonstration | `docs/API.md`, `public/` PWA |
| Evaluation | `tests/`, `docs/TESTING.md` §6 |
| Communication | `README.md`, `docs/` |

---

## 11. Licence & Attribution

Specification derived from the UTAS project report by
**Stephen Kwame Agyapong Junior (20210412166)**, supervised by
**Prof. Peter Awon-Natemi Agbedemnab**, School of Computing and Information Sciences,
Department of Information Systems and Technology, UTAS — August 2026.

Code released under the MIT licence. See [`LICENSE`](LICENSE).
