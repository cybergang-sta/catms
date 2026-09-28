# Deployment & Operations

**CATMS — Classroom Allocation & Timetable Management System**
Deployment v1.0 · Targets NFR-REL-01 (> 99 % uptime, 99.5 % reported),
NFR-REL-02 (RPO 24 h / RTO 4 h), NFR-SCALE-03 (stateless API tier)

---

## Table of Contents

1. [Environments](#1-environments)
2. [Runtime Topology](#2-runtime-topology)
3. [Configuration](#3-configuration)
4. [Container Image](#4-container-image)
5. [Local Development with Docker](#5-local-development-with-docker)
6. [Database Migrations in Production](#6-database-migrations-in-production)
7. [Deployment Pipeline](#7-deployment-pipeline)
8. [Release Process](#8-release-process)
9. [Secret Rotation](#9-secret-rotation)
10. [Scheduled Jobs](#10-scheduled-jobs)
11. [Monitoring and Alerting](#11-monitoring-and-alerting)
12. [Backup and Restore](#12-backup-and-restore)
13. [Scaling](#13-scaling)
14. [Uptime Target and Error Budget](#14-uptime-target-and-error-budget)
15. [Runbooks](#15-runbooks)
16. [Disaster Recovery](#16-disaster-recovery)
17. [Decommissioning](#17-decommissioning)

---

## 1. Environments

| | `local` | `staging` | `production` |
| --- | --- | --- | --- |
| Purpose | Development | Pre-production verification | Real teaching |
| Data | Seeded demo data | Anonymised production snapshot | Real |
| URL | `localhost:8080` | `staging.catms.utas.edu.gh` | `catms.utas.edu.gh` |
| PHP | 8.2–8.3, dev extensions on | 8.3, `opcache`, no dev packages | 8.3, `opcache`, no dev packages |
| Debug | `APP_DEBUG=true` | `APP_DEBUG=false`, verbose logs | `APP_DEBUG=false`, errors only |
| TLS | None (localhost) | Let's Encrypt | Let's Encrypt, HSTS |
| Auth demo users | Seeded | Seeded, then deactivated | **Never seeded** (VULN-06) |
| `time_budget_seconds` | 2.5 | 2.5 | 2.5 default, 30 max |
| `max_iterations` | 2 000 | 2 000 | 2 000 |
| Engine seed | 20260801 | fixed per run | fixed, recorded in `allocation_runs` |
| Deploy | manual | on merge to `main` | tagged release, manual approval |
| Backups | none | daily | hourly + daily, encrypted |

**Promotion is by promotion of the same artefact.** The image built in CI is
tested in staging and then tagged for production. Nothing is rebuilt between
environments, because "works on staging" is only a meaningful claim if staging
ran the code that will actually be deployed.

**Production never runs the demo credentials.** This is enforced in three
places rather than one, because the check has to hold at write time *and* at
release time (`docs/SECURITY.md` VULN-06):

1. **Write time.** `bin/console seed` refuses to run at all when `APP_ENV` is
   neither `local` nor `staging`, unless `--i-know-what-i-am-doing` is passed
   (`SeedCommand::SAFE_ENVIRONMENTS`).
2. **Degraded mode.** Even where the seed is permitted, `local` is the only
   environment that receives the published passwords. Everywhere else the demo
   accounts are created with `status='pending'` and
   `must_change_password=1`, so they exist but cannot authenticate until an
   administrator sets a real password.
3. **Release time.** `php bin/console verify-integrity
   --verify-no-demo-credentials` fails the release gate if any seeded demo
   account is present *and* still able to log in. It is a runbook step (§15),
   not a boot-time assertion, because the useful moment to be told is "this
   release is not safe to ship", not "the application will not start".

The production account bootstrap is a separate, one-shot script that reads from a
password manager.

**Staging holds real-shaped data and no real secrets.** Refreshed nightly from a
production snapshot with personal data pseudonymised — names, e-mails and phone
numbers replaced, allocation structure intact, so timetable shapes and engine
behaviour are representative.

---

## 2. Runtime Topology

### 2.1 Production

```
                        Internet
                            │  :443
                  ┌─────────▼──────────┐
                  │  Load balancer     │  TLS passthrough, health checks
                  │  (managed, ≥99.9%)  │  sticky off — the app is stateless
                  └─────────┬──────────┘
              ┌─────────────┴─────────────┐
              ▼                           ▼
      ┌───────────────┐           ┌───────────────┐
      │  web-01       │           │  web-02       │   Ubuntu 24.04
      │  Nginx        │           │  Nginx        │   PHP 8.3 FPM × 8 workers
      │  PHP-FPM      │           │  PHP-FPM      │   non-root service user
      │  opcache      │           │  opcache      │   read-only root fs
      └───────┬───────┘           └───────┬───────┘
              └─────────────┬─────────────┘
                            ▼
              ┌─────────────────────────────┐
              │  db-01  MySQL 8.0 primary   │  8 vCPU / 32 GB / 500 GB SSD
              │          innodb_buffer_pool │
              │          = 20 GB            │
              └──────────┬──────────────────┘
                         │ binlog replication
                         ▼
              ┌─────────────────────────────┐
              │  db-02  MySQL 8.0 replica   │  read-only, for reports
              └─────────────────────────────┘
                         ▲
              ┌──────────┴──────────────────┐
              │  worker-01                   │  bin/worker.php
              │   • outbox drain             │  same image, different command
              │   • utilisation rollup        │
              │   • retention purge           │
              │   • nightly generation        │
              └─────────────────────────────┘

    storage/            encrypted volume, 2 copies per host
    backups/            encrypted object store, 30 days + 7 years monthly
    secrets/            environment-injected, never on disk
```

**Capacity rationale.** The report's pilot was 30 students and 10 lecturers;
NFR-SCALE-01 targets growth across multiple departments. A department of 1 500
students and 150 lecturers generating ~5 requests/minute at peak is a few
hundred requests per minute in total — which is comfortably one small pair of
FPM workers. The database, not the web tier, is what will need scaling first, and
the read replica exists so that utilisation reports (which are the heaviest
queries) never compete with a timetable read.

**The app tier is stateless.** Sessions are JWTs, rate-limit state is in MySQL,
uploads are none, and file state is `storage/` only. Any worker can serve any
request, so scaling out is `docker compose up --scale web=4` or, on a VM,
starting another host behind the load balancer. NFR-SCALE-03.

### 2.2 Single-node (small department or local)

`docker-compose.yml` reproduces a one-machine version of the topology: one
`web` container, one `db` container, one `worker` container. This is the
configuration to use for a pilot with a single department; it satisfies
NFR-REL-01 with a much smaller maintenance budget, because there is nothing to
keep in sync.

---

## 3. Configuration

### 3.1 Environment variables

All configuration is environment-injected. There is no config file in the image
and no `getenv()` outside `Core\Config`.

```dotenv
# ── Application ───────────────────────────────────────────────────────────
APP_ENV=production                 # local | staging | production
APP_DEBUG=false
APP_URL=https://catms.utas.edu.gh
APP_TIMEZONE=Africa/Accra          # display only; storage is UTC
APP_KEY=                           # 32+ random bytes; signs JWTs

# ── Database ──────────────────────────────────────────────────────────────
DB_HOST=db-01
DB_PORT=3306
DB_DATABASE=utas_catms
DB_USERNAME=catms_app
DB_PASSWORD=                       # injected from the secret store
DB_REPLICA_HOST=db-02              # optional; reports prefer it when set

# ── Security ──────────────────────────────────────────────────────────────
PASSWORD_BCRYPT_COST=12              # min 10; 12 in production (NFR-SEC-03)
ACCESS_TOKEN_TTL=900               # 15 minutes, seconds
REFRESH_TOKEN_TTL=2592000          # 30 days, seconds
LOGIN_MAX_ATTEMPTS=5
LOGIN_LOCKOUT_SECONDS=900
CORS_ALLOWED_ORIGINS=https://catms.utas.edu.gh

# ── Allocation engine ─────────────────────────────────────────────────────
ENGINE_VERSION=1.0.0
ENGINE_SEED=20260801
ENGINE_MAX_ITERATIONS=2000
ENGINE_TIME_BUDGET_SECONDS=2.5     # hard cap 30 (NFR-PERF-02)
ENGINE_MAX_LECTURER_SESSIONS_PER_DAY=4
ENGINE_ACCURACY_GATE=0.90          # NFR-PERF-04

# ── Notifications ─────────────────────────────────────────────────────────
NOTIFY_IN_APP=true
NOTIFY_EMAIL=true
MAIL_TRANSPORT=smtp
MAIL_HOST=smtp.utas.edu.gh
MAIL_PORT=587
MAIL_USERNAME=
MAIL_PASSWORD=                     # injected
MAIL_FROM_ADDRESS=timetables@utas.edu.gh
OUTBOX_MAX_ATTEMPTS=5
OUTBOX_BATCH_SIZE=200

# ── Uploads / storage (none in v1, reserved) ──────────────────────────────
STORAGE_PATH=/var/www/storage

# ── Observability ─────────────────────────────────────────────────────────
LOG_LEVEL=warning                  # debug|info|warning|error
METRICS_ENABLED=true
SENTRY_DSN=                        # optional; empty disables
```

### 3.2 Validation at boot

`Config` validates on load and **fails fast**, because an application that starts
misconfigured and fails per-request is far worse than one that refuses to start:

| Check | Failure |
| --- | --- |
| `APP_KEY` present, ≥ 32 bytes | Fatal at boot |
| `APP_ENV=production` with `APP_DEBUG=true` | Fatal at boot |
| `APP_ENV=production` with `LOG_LEVEL=debug` | Fatal at boot |
| Seed with `APP_ENV` ∉ {`local`, `staging`} | Refused unless `--i-know-what-i-am-doing` |
| Any seeded demo account still able to log in | `verify-integrity --verify-no-demo-credentials` fails (VULN-06) |
| `DB_*` complete and reachable | Fatal at boot |
| `ENGINE_TIME_BUDGET_SECONDS > 30` | Fatal at boot (NFR-PERF-02 ceiling) |
| `PASSWORD_BCRYPT_COST < 12` in production | Fatal at boot (NFR-SEC-03) |
| `ACCESS_TOKEN_TTL > 3600` | Fatal at boot |
| `CORS_ALLOWED_ORIGINS` contains `*` in production | Fatal at boot |

The first three rows are `Config::assertProductionSafe()` and are the only
boot-time assertions. The rest are enforced by the command that owns the risk,
at the moment the risk is taken, so a mistake surfaces during the operation that
caused it rather than on a later unrelated deploy.

### 3.3 `config/weights.php`

The cost weights are configuration, not code, so they can be retuned per
department without a deploy — `config/weights.php` is a plain array read at boot,
and the deployed value is recorded alongside every `allocation_runs` row so a
result can always be reproduced. Changing a weight is a pull request that must
regenerate the golden baseline and record before/after accuracy
(`docs/ALLOCATION_ENGINE.md` §10).

---

## 4. Container Image

Multi-stage, one artefact for web and worker (same code, different command).

```dockerfile
# ── Stage 1: dependencies ──────────────────────────────────────────────────
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

# ── Stage 2: application ───────────────────────────────────────────────────
FROM php:8.3-fpm-alpine AS app
RUN apk add --no-cache nginx supervisor icu-dev oniguruma-dev tzdata \
 && docker-php-ext-install -j"$(nproc)" pdo_mysql opcache intl \
 && apk del --no-network .build-deps

COPY --from=vendor /app/vendor /var/www/html/vendor
COPY . /var/www/html

RUN php -r "exit(PHP_VERSION_ID >= 80200 ? 0 : 1);" \
 && mkdir -p storage/logs storage/cache storage/sessions \
 && chown -R www-data:www-data /var/www/html/storage \
 && chmod -R 755 /var/www/html \
 && find /var/www/html -type f -name '*.php' -exec chmod 644 {} + \
 && { echo "*.env"; echo "storage/"; echo ".git/"; } > .dockerignore

# .env is deliberately NOT copied — secrets are injected at run time.
ENTRYPOINT ["docker-php-entrypoint", "php", "public/index.php"]
CMD ["php-fpm", "-F"]
```

Points worth noting:

- **`--no-dev`.** PHPUnit, PHPStan and PHP_CS-Fixer are not in the runtime image.
- **Non-root.** PHP-FPM workers run as `www-data`; the root filesystem is
  mounted read-only in production, with only `storage/` writable.
- **No `.env` in the image.** Secrets come from the environment, so the image is
  identical across all three environments and cannot leak a credential through a
  layer.
- **Pinned base image by digest** in the real pipeline, not by tag.

---

## 5. Local Development with Docker

```bash
git clone <repo-url> catms && cd catms
cp .env.example .env          # the local defaults work as-is
docker compose up -d --build
docker compose exec app php bin/migrate.php
docker compose exec app php bin/seed.php
docker compose exec app php bin/worker.php start
```

Open <http://localhost:8080>. `phpunit.xml` and the engine suite need no
database, so the tightest edit/run loop needs no Docker at all:

```bash
composer install
composer test:unit
```

### Services

| Service | Image | Port | Purpose |
| --- | --- | --- | --- |
| `web` | built from `Dockerfile` | 8080 → 80 | Nginx + PHP-FPM |
| `db` | `mysql:8.0` | 3306 | Database, `utf8mb4_0900_ai_ci` |
| `worker` | same image as `web` | — | `bin/worker.php` |
| `mailpit` | `axllent/mailpit` | 8025 | Catches all e-mail locally; nothing leaves the machine |

`mailpit` exists so a notification change can be observed end to end without
sending real e-mail to real students. The `MAIL_*` settings point at it in
`.env.example`.

### Volumes

| Volume | Mount | Contents |
| --- | --- | --- |
| `db-data` | MySQL data directory | Persistent |
| `app-storage` | `/var/www/html/storage` | Logs, cache, sessions |

`db-data` and `app-storage` are named volumes, so `docker compose down` keeps
them and `docker compose down -v` destroys them. The second one is destructive;
say so in any script that uses it.

---

## 6. Database Migrations in Production

### 6.1 Applied once, in a recorded ledger (NFR-MAINT-05)

`db/migrations/` is a directory of `YYYY_MM_DD_HHMMSS_name.php`. Each file
returns an anonymous class implementing `App\Infrastructure\Persistence\Migration\Migration`
with `name()`, `up(Database)` and `down(Database)`. `up()` **must be idempotent** —
re-running an already-applied migration has to be a no-op, not a duplicate-key
error — because a partially-applied DDL file cannot be rolled back and the only
safe response is to make it safe to repeat.

Two migrations ship:

```
2026_08_01_090000_baseline.php            # execs db/schema.sql
2026_08_01_091500_audit_log_grants.php    # grants on utas_catms.audit_log (VULN-14)
```

`Migrator` owns the `schema_migrations` ledger, takes
`GET_LOCK('catms_migrations', 30)` so two deploys cannot race, sorts by
`strcmp` on the filename, and rolls back by batch. `bin/migrate.php --status`
is read-only apart from creating the ledger table itself — note that
`Migrator::status()` calls `ensureLedger()`, so a status check against an
unmigrated database will create `schema_migrations` and nothing else.

A migration that has been applied is **never edited**. The rule exists because
every environment's `schema_migrations` disagrees with every other environment's
the moment one is edited, and the disagreement is discovered in production.

### 6.2 Reference data: `db/seed.sql`

Demo reference data (departments, rooms, time slots, courses, cohorts) is a
single committed SQL file, not a migration, because it is *data* and must stay
reviewable as data. `bin/console seed` executes it through the same statement
splitter as the schema, in one transaction, after RBAC and demo users.

It is written to be re-runnable. Two traps in it are worth knowing about before
editing it, because both fail silently and both produce duplicates rather than
errors:

- The two unique indexes that `db/seed.sql` feeds (`uq_slot_grid` and
  `uq_lca_lecturer_course_cohort`) contain **nullable** columns. MySQL treats
  `NULL` as distinct inside a unique index, so `ON DUPLICATE KEY UPDATE` never
  fires for a null column and every run appends duplicates. Both inserts use
  `WHERE NOT EXISTS (...)` instead.
- `db/seed.sql` must not rely on `PIPES_AS_CONCAT`: it is **not** in MySQL 8's
  default `sql_mode`, so `||` silently becomes a logical OR. It uses
  `CONCAT()`.

The time-slot `is_active` flag is applied by a separate `UPDATE ... JOIN`
after the insert, so re-seeding does not silently undo an administrator's
decision to disable a slot.

One seed row is deliberately wrong: time slot `E` has `is_active=0`. It exists
to prove the loader filters inactive slots, and a smoke test asserts on it.

### 6.3 Deploy order: code first, then migrate

Migrations must be **backward-compatible with the currently running code**, so
deployment is:

1. Deploy the new code. It works against both the old and the new schema.
2. Run the migration.
3. (Later, in a subsequent release) remove the code path that no longer needs the
   old column.

This "expand / migrate / contract" order is why there is never a moment when the
running application meets a schema it does not understand. A migration that
cannot be made backward-compatible is a migration that needs to be split, and
that is a design conversation, not a deployment problem.

### 6.4 Locking

`GET_LOCK('catms_migrations', 30)` so two instances deploying simultaneously
cannot both apply the same file. Without it, a rolling deploy of two instances
running `bin/migrate.php` concurrently applies half the files twice.

### 6.5 Zero-downtime concerns

| Change | How |
| --- | --- |
| Add a column | Nullable, or with a default, in one statement. MySQL 8 does this in place |
| Add an index | `ALTER TABLE … ADD INDEX … ALGORITHM=INPLACE, LOCK=NONE` |
| Rename a column | New column + dual write + backfill + switch, across two releases |
| Drop a column | Two releases after the last reader is gone |
| Change a column type | Treat as add-and-migrate; never a direct `MODIFY` on a hot table |

`allocations` is the only table large enough for this to matter, and only when a
department accumulates several semesters of history (a few hundred thousand
rows). At pilot scale none of this is a concern; the discipline is recorded so
that it is not rediscovered under pressure.

### 6.6 Audit-log grants (VULN-14)

One migration must issue the grants that make the audit trail tamper-resistant:

```sql
GRANT SELECT, INSERT ON utas_catms.audit_log TO 'catms_app'@'%';
-- deliberately NOT granted: UPDATE, DELETE
```

This ships as `2026_08_01_091500_audit_log_grants.php` and runs *after* the
baseline, because the grant target must already exist.

`bin/retention.php` runs under a *different* database user (`catms_maint`) that
does hold `DELETE`, so retention is possible and the application cannot rewrite
history. This is the concrete part of "immutable" in
`docs/SECURITY.md` §10.2 — the convention alone is not the control. The
`retention` command verifies this grant at startup with
`SHOW GRANTS FOR CURRENT_USER` and refuses to run if the grant is missing,
because a purge that silently does nothing is worse than a purge that stops.

---

## 7. Deployment Pipeline

### 7.1 CI — on every push and pull request

```
lint ──▶ analyse ──▶ unit ──▶ build-image ──▶ scan ──▶ push
                                 │
                                 └──▶ golden-drift (PR only)
```

| Stage | Command | Fails on |
| --- | --- | --- |
| `lint` | `composer lint` | Any PSR-12 or project-rule violation |
| `analyse` | `composer analyse` | Any PHPStan level-6 error |
| `unit` | `composer test:unit` | Any failing test |
| `build-image` | `docker build` | Build failure |
| `scan` | Trivy + `composer audit` | High/Critical CVE |
| `push` | registry | — |
| `golden-drift` | `php bin/regenerate-golden.php` then `git diff --exit-code` | Any change in engine output |

**The golden-drift gate is the interesting one.** Retuning a weight or
reordering a tie-break produces a legal, equally accurate timetable, so no test
fails — but the shape of the output moved, and someone decided that without
meaning to. The gate turns that into an explicit, reviewed decision.

`lint-analyse-test` and `golden-drift` are **required** status checks. `performance`
and `accessibility` run on `main` and nightly, and are advisory on pull requests:
a slow, occasionally flaky required check trains people to re-run until it goes
green, which destroys the signal.

### 7.2 Nightly

```
integration   migrated database + tests/Integration
performance   seeded dataset, NFR-PERF-02 and p95 latency
accessibility Lighthouse + axe-core against the built PWA
restore-drill restore last night's backup into a scratch instance
```

The `restore-drill` is the one people skip, and it is the one that matters: an
unverified backup is a hypothesis, not a backup. It is scheduled precisely so it
cannot be quietly deferred.

### 7.3 CD — staging

On merge to `main`: pull the tested image, run `bin/migrate.php`, restart the FPM
pool, wait for `/health` to report `ok`, run a smoke suite.

### 7.4 CD — production

Manual, on a tagged release, with an explicit approval step.

---

## 8. Release Process

### 8.1 Versioning

`MAJOR.MINOR.PATCH` in `composer.json`, with `ENGINE_VERSION` tracked separately.
The engine version is a separate axis on purpose: a web release that changes no
algorithm still moves the app version, and a pure engine retune moves only the
engine version. `allocation_runs.engine_version` records it, so a change in output
shape can always be attributed to a specific engine release rather than guessed at.

Semver, applied to the API too:

| Change | Version |
| --- | --- |
| New endpoint, new optional field, new permission | MINOR |
| Bug fix, no contract change | PATCH |
| Remove or retype a field, change an error code, change a default | MAJOR, and `/api/v2` (NFR-MAINT-04) |

### 8.2 Deploy steps

```bash
# 1. Confirm the release
git checkout main && git pull --ff-only
git tag -a v1.4.0 -m "Timetable export, ICS support"
git push origin v1.4.0

# 2. Enable maintenance mode (writes nothing; reads still served)
php bin/console app:maintenance --enable --message "Upgrading to 1.4.0"

# 3. Deploy the artefact
docker compose pull web worker
docker compose up -d web worker

# 4. Migrate (forward-only, backward-compatible)
docker compose exec app php bin/migrate.php

# 5. Restart workers so they pick up new code
docker compose restart worker

# 6. Verify
curl -fsS https://catms.utas.edu.gh/api/v1/health | jq .status   # expect "ok"
php bin/console app:smoke                                       # end-to-end checks

# 7. Disable maintenance mode
php bin/console app:maintenance --disable
```

### 8.3 Rollback

| Situation | Action | Time to recover |
| --- | --- | --- |
| Bad web release, schema unchanged | Redeploy the previous tag | ~2 min |
| Bad web release **with** a migration | Redeploy the previous tag, leave the migration | ~2 min, *provided the migration is backward-compatible* — which is why §6.2 mandates it |
| Bad migration | `php bin/migrate.php rollback` (the `down` path) | ~5 min |
| Broken production data | Restore from backup (§12) | ≤ 4 h (RTO) |

Rollback of a migration is the reason ground rule 8 in
`docs/IMPLEMENTATION.md` §1 exists: migrations are deployed forward-only, but the
`down` path ships in the same release, because discovering the need for it during
an incident is the worst possible time to write it.

### 8.4 Zero-downtime deploy

Maintenance mode is not required, because the application is stateless and the
schema change is backward-compatible:

1. Start the new instances alongside the old.
2. Health-check the new pool.
3. Shift load (reload the load balancer, or `docker compose up -d` in place).
4. Drain the old pool: `kill -USR2` the FPM master for a graceful reload.
5. Run the migration.
6. Restart workers.

Existing requests complete on the old pool because `kill -USR2` lets in-flight
requests finish. Maintenance mode exists for the cases where the migration is not
backward-compatible, and it should be rare.

---

## 9. Secret Rotation

| Secret | Rotation | Method | Impact |
| --- | --- | --- | --- |
| `APP_KEY` | Every 90 days, or on suspicion | Update the secret store, restart all instances | All access tokens invalid; users re-authenticate within 15 min |
| `DB_PASSWORD` | Every 180 days | `ALTER USER … IDENTIFIED BY`, update the store, `GRANT`/`FLUSH PRIVILEGES`, rolling restart | None if sequenced |
| `MAIL_PASSWORD` | Provider policy | Update the store, restart the worker | Notifications queue and retry; no loss |
| `SMTP_API_KEY`, `SMS_API_KEY` | Provider policy | Update the store, restart the worker | As above |
| TLS certificate | Automated (Certbot), 30-day renew | None — automatic | None |

`APP_KEY` rotation invalidates all access tokens at once. That is acceptable
because access tokens live 15 minutes and refresh tokens are independent, so the
blip is a single silent re-authentication rather than a logout. Supporting two
valid keys would be a considerably harder property to reason about later.

**On suspected compromise:** rotate `APP_KEY`, then `DB_PASSWORD`, then revoke
all refresh tokens (`UPDATE refresh_tokens SET revoked_at = NOW()`), then
investigate via `security_events` and `audit_log`
(`docs/SECURITY.md` §13.2).

---

## 10. Scheduled Jobs

Run from the same image, scheduled by the host's cron or by Kubernetes
`CronJob`. Times are UTC.

| Job | Schedule | Command | Notes |
| --- | --- | --- | --- |
| Outbox drain | Continuous | `bin/worker.php` | Long-running; supervised, not cron. Sleeps only when the queue is empty |
| Outbox drain (one pass) | Every 30 s | `bin/worker.php drain` | The cron-friendly form of the same work, for hosts without a process supervisor |
| Utilisation rollup | 02:00 | `bin/worker.php rollup` | Idempotent (`INSERT … ON DUPLICATE KEY UPDATE`) |
| Retention purge | 03:00 | `bin/retention.php` | The only process that may delete an `audit_log` row; writes its own audit entries |
| Token cleanup | 04:00 | `bin/worker.php prune` | Expired/used tokens. `--days` controls the window; `security_events` is never pruned here |
| Rate-limit sweep | Every 5 min | `bin/worker.php sweep` | Drops buckets whose window has closed: `--days=1` by default, so the cutoff is 24 h. Only *closed* windows are removed — dropping the live window would hand every rate-limited client a fresh budget |
| Semester rollover | Mondays 05:00 | `bin/worker.php rollover` | Activates a `planning` semester whose start date has arrived |
| **Nightly generation** | Saturdays 02:30 | `bin/generate-timetable.php --exhaustive` | A full re-solve with a 1 h budget and a fixed seed. Advisory: writes an `allocation_runs` row and reports; does **not** apply without an admin decision |
| Backup | Hourly | `mysqldump` + encrypt | §12 |
| Backup verification | 04:30 | restore into scratch | §12.3 |

**The nightly generation is advisory on purpose.** An unattended job that
replaces a published timetable at 02:30 on a Saturday can publish a worse
timetable than the one a human approved, with nobody watching. The job's value is
that Monday morning an administrator can see "a better solution exists, accuracy
0.98 against the current 0.94" and apply it deliberately. Applying it is a
one-click admin action with a full audit entry.

**Overlapping runs are prevented** by `GET_LOCK` on
`(department, semester)`, so two triggers cannot generate at once.

---

## 11. Monitoring and Alerting

### 11.1 Signals

| Signal | Source | Alert when |
| --- | --- | --- |
| Liveness / readiness | `GET /api/v1/health` | 3 consecutive failures |
| Request rate, error rate, p50/p95/p99 | `/api/v1/metrics` | 5xx > 1 % over 5 min |
| Response time | `/api/v1/metrics` histogram | p95 > 3 s for 10 min (NFR-PERF-01) |
| Database connections | `information_schema.processlist` | > 80 % of `max_connections` |
| Replication lag | `SHOW REPLICA STATUS` | > 30 s |
| Slow queries | `slow_query_log` | any query > 1 s |
| **Outbox depth** | `SELECT COUNT(*) … WHERE status='pending'` | > 500 pending or > 60 s old |
| **`outbox` dead rows** | `status='dead'` | **any** — a notification will never be delivered |
| Engine accuracy | `latest allocation_runs.accuracy` | < 0.90 (NFR-PERF-04) |
| Engine duration | `allocation_runs.duration_ms` | > 30 000 (NFR-PERF-02) |
| Failed logins | `security_events` | > 20 in 5 min from distinct IPs |
| Token replay | `security_events` `token.replay` | **any** (critical) |
| Forced overrides | `audit_log` `admin.override_forced` | any outside a maintenance window |
| Locked accounts | `users.locked_until > NOW()` | > 5 in an hour |
| Disk | host metrics | > 80 % on the data volume |
| Certificate | Certbot | < 14 days remaining |
| Backup age | backup manifest | > 26 h since the last successful one |

**Uptime and the two outbox alerts are the ones that page a human.** Everything
else opens a ticket.

The two outbox alerts deserve their emphasis because they are the failure mode
this system exists to prevent. A dead notification means a student or lecturer
was not told about a room change, and they will arrive at the wrong room. It is a
correctness problem wearing an infrastructure costume.

### 11.2 The health endpoint

`GET /api/v1/health` distinguishes three states, because a single boolean cannot
express what a load balancer needs to know:

| State | HTTP | Meaning | Load balancer |
| --- | --- | --- | --- |
| `ok` | 200 | Everything healthy | Keep in rotation |
| `degraded` | 200 | A non-critical check failed (outbox backlog, slow rollup) | Keep in rotation; alert |
| `unhealthy` | 503 | Database or migrations unreachable | **Remove from rotation** |

`degraded` returning `200` is deliberate: an outbox backlog must not take the
timetable offline. The timetable is still correct and still served; somebody just
has not been told yet. Removing a healthy instance from rotation would turn a
notification problem into an availability problem.

### 11.3 Dashboards

| Dashboard | Contents |
| --- | --- |
| **Overview** | Uptime, request rate, error rate, p95, active users |
| **Allocation** | Runs per day, accuracy trend, unallocated count, engine duration, `warm_start_rejected` |
| **Notifications** | Outbox depth by status, delivery latency, dead rows |
| **Database** | Connections, replication lag, slow queries, table sizes |
| **Business** | Utilisation by building, unallocated sessions by constraint code, open conflicts by age |

The **allocation** dashboard is the one that matters for the product. Accuracy
trending down over a semester is a leading indicator of a data problem — a
cohort whose enrolment count was never updated, a room wrongly marked
`maintenance` — long before anybody complains.

---

## 12. Backup and Restore

### 12.1 Policy (NFR-REL-02)

| Target | Value |
| --- | --- |
| **RPO** | 24 h (hourly binlog gives ~1 h in practice) |
| **RTO** | 4 h |
| Frequency | Hourly logical dump + continuous binlog |
| Retention | Hourly: 48 h · daily: 30 d · monthly: 7 y |
| Encryption | AES-256, key in the secret store, never in the backup |
| Location | Object storage, different failure domain from the primary |
| **Restore drill** | Nightly, automated, into a scratch instance |

### 12.2 The backup command

```bash
mysqldump \
  --single-transaction --quick --routines --triggers --events \
  --set-gtid-purged=OFF \
  --default-character-set=utf8mb4 \
  utas_catms | gzip -9 | openssl enc -aes-256-cbc -pbkdf2 -iter 600000 -pass env:BACKUP_KEY \
  | aws s3 cp - "s3://utas-catms-backups/hourly/$(date -u +%Y%m%dT%H%M%SZ).sql.gz.enc"
```

`--single-transaction` gives a consistent snapshot of InnoDB without locking
tables, so a backup cannot itself cause downtime. Combined with binlog shipping,
a restore replays from the last dump to an arbitrary point in time.

### 12.3 Restore drill (nightly, automated)

A backup nobody has restored is a hypothesis. The drill:

1. Restores the most recent hourly dump into a scratch MySQL instance.
2. Applies binlogs to the last 5 minutes.
3. Runs `tests/Integration` against it.
4. Verifies the five invariants in `db/schema.sql`'s footer — no double bookings,
   no capacity violations, no lecturer double-bookings, no missing features,
   accuracy of the last run.
5. Destroys the scratch instance.

**Fails loudly.** A drill that fails and is only noticed in the morning is
marginally better than no drill; one that pages is a real control.

### 12.4 Full restore

```bash
# 1. Stop writes
docker compose stop web worker
php bin/console app:maintenance --enable

# 2. Restore the most recent dump
gunzip -c backup.sql.gz | openssl enc -d -aes-256-cbc -pbkdf2 -iter 600000 -pass env:BACKUP_KEY \
  | mysql utas_catms

# 3. Replay binlogs to the target time
mysqlbinlog --start-datetime="…" /var/lib/mysql-bin.000042 | mysql utas_catms

# 4. Verify integrity before opening up
php bin/console app:verify-integrity      # the five schema-footer queries

# 5. Migrate to the current schema, then open
php bin/migrate.php
php bin/console app:smoke
php bin/console app:maintenance --disable
docker compose up -d web worker
```

Step 4 is not optional. A restore that produces a *working* application with a
*corrupt* timetable is worse than an outage, because it is trusted.

---

## 13. Scaling

### 13.1 Web tier (stateless)

```bash
docker compose up -d --scale web=4
```

No session affinity is needed. Scale when p95 CPU on the FPM workers exceeds 60 %
or p95 latency exceeds 1 s at expected load.

### 13.2 Database — the first thing that will hurt

In order of increasing cost:

1. **Index and query review.** `EXPLAIN` every hot query; the week view must be
   an index range scan, not a scan. The schema is designed for this
   (`ix_alloc_week_view`).
2. **Read replica for reports.** Utilisation and peak-usage reports are the
   heaviest queries and the least latency-sensitive. Point `DB_REPLICA_HOST` at
   the replica and reports stop competing with timetable reads.
3. **`room_utilisation_daily`.** Reports already read the rollup rather than
   scanning `allocations` (NFR-SCALE-04). Keep the rollup current.
4. **Vertical scaling.** `innodb_buffer_pool` to ~60 % of RAM is the single
   highest-leverage change; this is a working set, not a cache.
5. **Read/write split.** Only if 2–3 is insufficient. Adds a correctness
   surface — a read after write may hit the replica — which is why the timetable
   read path is pinned to the primary and only reports go to the replica.
6. **Sharding by `department_id`.** The correct key, because every query is
   already department-scoped. A significant undertaking and unnecessary below
   roughly 100 000 users.

### 13.3 Engine scaling

The engine is time-boxed, so a bigger problem means a bigger budget, not a
timeout. `NFR-SCALE-01` at a realistic department size (~300 sessions) is
comfortably inside the 2.5 s default, and 1 000 sessions is inside the 30 s
NFR-PERF-02 ceiling. Beyond that:

- **Partition by department and run in parallel.** Departments are independent, so
  this is embarrassingly parallel and needs no code change.
- **Reduce `max_iterations` first**, not the time budget. Fewer iterations means
  a slightly worse timetable, predictably; a shorter budget means a *varying*
  timetable, which is worse for everyone reading it.
- **Use `--repair` rather than a full regeneration.** A change affecting one day
  should cost one day of work. `docs/ALLOCATION_ENGINE.md` §7.

---

## 14. Uptime Target and Error Budget

**Target: > 99 % (NFR-REL-01). Report result: 99.5 %.**

| Availability | Downtime allowed | Per month | Per quarter |
| --- | --- | ---: | ---: |
| 99.0 % (requirement) | 1 % | 7 h 12 m | 21 h 41 m |
| **99.5 % (reported)** | **0.5 %** | **3 h 36 m** | **10 h 52 m** |
| 99.9 % (stretch) | 0.1 % | 43 m | 2 h 11 m |

### 14.1 The academic calendar is the real constraint

A fixed 99.5 % says nothing about *when* the downtime falls, and for a
timetable system the distribution matters enormously. Six hours of downtime in
July is nothing. Six hours on the morning of the first week of teaching, when
1 500 students are all looking for the same room at the same time, is a
catastrophe, and it is exactly when the system is under load and therefore most
likely to fail.

So the target is stated twice:

> **99.5 % measured during published teaching weeks (September–December, and the
> January revision period), and 99.0 % across the whole year.**

Plus two hard rules:

1. **No planned maintenance during a teaching week.** Deploys are frozen from
   the Monday of teaching week 1 to the end of exams. Everything that needs a
   deploy waits, or goes to staging.
2. **A planned outage in a teaching week requires the department's written
   agreement in advance.** Not a judgement call at 23:00 on a Sunday.

### 14.2 Error budget

A 30-day budget at 99.5 % is 3 h 36 m. Spending it:

| Slice | Allowance | Rationale |
| --- | ---: | --- |
| Deploys (outside teaching weeks) | 1 h | 30 min, 2 deploys |
| Infrastructure (host, network, provider) | 1 h 30 m | The largest slice, because it is the most likely |
| Application defects | 45 m | Deliberately small: a bug fix is a code change, not an outage |
| Database | 15 m | Backups and replication make this rare |
| **Reserve** | **15 m** | Held for the week that needs it |

When the reserve is consumed, the next feature is not shipped. When the budget is
exhausted, every subsequent deploy is a freeze until it is reviewed. The point
is to make reliability cost something at the point where the decision is made,
rather than discovering it at the end of a semester.

### 14.3 What counts as downtime

| Counts | Does not count |
| --- | --- |
| The API returning 5xx or refusing connections | A single request failing |
| `/health` reporting `unhealthy` | `degraded` (see §11.2) |
| A worker down (notifications late) | Planned maintenance outside teaching weeks |
| Latency above 3 s for > 10 min | Planned maintenance *inside* teaching weeks — **this counts** |
| A failed deploy that is rolled back | A feature that is degraded but correct |

---

## 15. Runbooks

Rehearsed by someone who did not write them, at least once per release
(`docs/IMPLEMENTATION.md` phase 7 definition of done).

### 15.1 `/health` is failing

```bash
curl -sS https://catms.utas.edu.gh/api/v1/health | jq
```

| `checks` field failing | Action |
| --- | --- |
| `database` | `mysql -h db-01 -e "SELECT 1"`. Check the host, then `max_connections`, then disk |
| `migrations` | `php bin/console migrate --status`. A pending migration means the deploy skipped the migrate step |
| `outbox` | Degraded, not unhealthy. Check the worker (§15.3) |

If the database is unreachable and the replica is healthy, point the web tier at
the replica in read-only mode. **The timetable is read-heavy; serving a slightly
stale week is much better than serving nothing.**

### 15.2 5xx spike

```bash
docker compose logs --since 15m web | grep -c ERROR
docker compose logs --since 15m web | grep ERROR | tail -50
```

| Pattern | Cause | Action |
| --- | --- | --- |
| `SQLSTATE[HY000] [2002]` | Database unreachable | §15.1 |
| `SQLSTATE[40001]` | Deadlock | `Database::transaction()` already retries twice; if it persists, the two writers are the app and the worker on the same rows |
| `SQLSTATE[23000] [1062]` | Unique violation on an `allocations` key | **Working as designed** — a double booking was prevented. Find the actor in `audit_log` |
| `Allowed memory size exhausted` | Memory limit too low | Raise to 256 M; check for an unbounded query |
| `Maximum execution time exceeded` | A query is scanning | `SHOW PROCESSLIST`, `EXPLAIN` it. Reports may be hitting the primary — check `DB_REPLICA_HOST` |
| `Class "App\..." not found` | Stale autoloader | `composer dump-autoload -o` |

### 15.3 Notifications not arriving

The most consequential runbook, because the failure is invisible to users.

```sql
SELECT status, COUNT(*), MIN(created_at) AS oldest
FROM notification_outbox GROUP BY status;
```

| Observation | Cause | Action |
| --- | --- | --- |
| `pending` climbing, worker not running | Worker died | `docker compose restart worker`; check `docker compose logs worker` |
| `processing` rows stuck | Worker killed mid-drain | Restart; the worker reclaims rows stuck in `processing` > 5 min |
| `failed` with SMTP errors | Credentials, quota, greylisting | Fix the provider config, then `POST /api/v1/notifications/dispatch` |
| `dead` rows exist | Retries exhausted | **Tell the affected users out-of-band.** Investigate, fix, then re-drive. A dead notification about a room change means someone will arrive at the wrong room |
| Outbox empty, no notifications | Nothing was queued | The domain event did not fire, or fan-out found no recipients. Check `audit_log` for the originating action |

**After any `dead` incident**, identify which allocations the dead messages
concerned (`SELECT allocation_id FROM notification_outbox WHERE status='dead'`)
and contact those cohorts and lecturers directly. Do not rely on the system that
just failed.

### 15.4 Accuracy dropped below 0.90

```sql
SELECT id, accuracy, total_sessions, assigned_sessions, warm_start_rejected,
       warm_start_by_cause, started_at
FROM allocation_runs WHERE is_applied = 1 ORDER BY started_at DESC LIMIT 10;
```

| `warm_start_by_cause` dominant key | Meaning | Action |
| --- | --- | --- |
| `HC-4` (capacity) | Enrolment counts grew without a re-solve | Re-run a full generation |
| `HC-6` (room serviceable) | Rooms marked `maintenance` | Confirm the status is real |
| `HC-5` (features) | A course's required features changed | Re-check `course_feature_requirements` |
| `HC-8` (lecturer availability) | Lecturers blocked days | Expected; check the counts are real |
| Empty, low accuracy | More sessions than feasible capacity | A resourcing decision, not a bug — surface it to the department (OBJ-5) |

```bash
php bin/generate-timetable.php --semester=2026-A --dry-run   # see the plan
php bin/generate-timetable.php --semester=2026-A --seed=20260801   # apply
```

### 15.5 A double booking was reported

**It should be impossible** — the engine filters (HC-1/2/3) and the database
unique keys (`active_guard`) both prevent it (ADR-006). So this is an incident,
not a bug report.

```sql
-- 1. Is it in the data?
SELECT id, room_id, time_slot_id, week_number, status, source,
       override_reason, overridden_by
FROM allocations
WHERE room_id = ? AND time_slot_id = ? AND week_number = ?
  AND status IN ('proposed','confirmed','updated');
```

| Finding | Explanation | Action |
| --- | --- | --- |
| Two rows, both active | The unique key is missing | Apply the latest migration. Then treat every historical row as suspect |
| Two rows, one `source='override'` | A forced override created it deliberately | Contact both cohorts **now**. Root-cause why it was forced |
| One row, and the report is about a *cancelled* class | The `active_guard` is working — a cancelled class is not a booking | No action; fix the report's filter |
| No rows | The report is wrong, not the timetable | Fix the report |

Contact affected students and lecturers directly. Do not rely on the
notification system to tell them their two classes are in one room.

### 15.6 Disk full on the database volume

```bash
df -h /var/lib/mysql
du -sh /var/lib/mysql/*  | sort -rh | head
```

| Consumer | Action |
| --- | --- |
| `ib_logfile` | `innodb_log_file_size` is oversized; resize and restart during a freeze |
| Binlogs | `PURGE BINARY LOGS BEFORE NOW() - INTERVAL 3 DAY` — but only after confirming a backup exists |
| `audit_log` | Retention is not running. Check `bin/retention.php`, and do **not** delete rows by hand |
| `room_utilisation_daily` | Rollup not pruning. Old rollups beyond 5 years can go |
| Slow query log | Rotate it |

### 15.7 Restore from backup

§12.4. Rehearse it quarterly.

### 15.8 Full outage

1. Confirm it is not DNS, and not the load balancer.
2. `docker compose ps` — is anything running?
3. Is it a single host? Fail over to the second one.
4. If the database is lost, restore (§12.4).
5. Post-incident review within 5 working days: timeline, cause, what made
   detection slow, what made recovery slow.

### 15.9 Before promoting a release to production

Run against the **production database**, with the production `APP_ENV`, as the
last step before the tag is moved. Each of these is a separate failure mode, so
none of them is folded into another:

```bash
php bin/console migrate --status
php bin/console verify-integrity
php bin/console verify-integrity --verify-no-demo-credentials
php bin/console smoke
php bin/benchmark.php
```

| Command | What it catches that the others do not |
| --- | --- |
| `migrate --status` | A pending migration — the deploy skipped the migrate step |
| `verify-integrity` | Missing tables, the five `db/schema.sql` footer invariants, a broken `allocations.active_guard`, a lost `audit_log` grant, a drifted ledger |
| `…--verify-no-demo-credentials` | A seeded demo account that is present **and** still able to log in (VULN-06). Opt-in because failing it on every day is noise; mandatory once, here |
| `smoke` | A boot that succeeds but a request path that does not: config, database, schema, reference data, in-process HTTP, and a real engine solve |
| `benchmark.php` | NFR-PERF-01 (solve < 3 s) and NFR-PERF-04 (accuracy ≥ 0.90) against the production-shaped dataset |

`verify-integrity` and `smoke` are read-only by contract, with one caveat worth
knowing before running them against a fresh database: `Migrator::status()` calls
`ensureLedger()`, so either command will create `schema_migrations` and nothing
else. That is why they are ordered after `migrate --status` above.

---

## 16. Disaster Recovery

### 16.1 Scenarios

| Scenario | RTO | Procedure | Data loss |
| --- | --- | --- | --- |
| Single host lost | 30 min | Bring up a replacement from the image; restore the latest dump + binlog | ≤ 1 h |
| Database corruption | 4 h | Restore to a new instance; verify with `app:verify-integrity`; cut over | ≤ 1 h |
| Complete region loss | 8 h | Provision in a second region; restore from off-site backups | ≤ 24 h (RPO) |
| Ransomware / credential compromise | 4 h | Restore to a clean instance; rotate every secret; audit `audit_log` and `security_events` for the window | ≤ 1 h |

### 16.2 Recovery point

Verified monthly, not assumed. `app:verify-integrity` runs the five queries in
`db/schema.sql`'s footer and refuses to open an instance that fails them. An
outage is over when the timetable is *correct*, not when the server responds.

---

## 17. Decommissioning

Departments change, staff leave, semesters close. A clean exit is part of data
protection, not just tidiness.

1. **Stop writes:** `app:maintenance --enable`.
2. **Export** all departmental data (JSON, per data subject) and archive it under
   the retention schedule.
3. **Pseudonymise or delete** personal data per `docs/SECURITY.md` §6.5. An
   institutional allocation record is retained; a person's contact details are
   not.
4. **Stop** the containers, then **destroy** the volumes deliberately —
   `docker compose down -v` — with the retention window respected first.
5. **Revoke** all tokens, rotate the secrets that were specific to that
   deployment.
6. **Record** the decommission in `audit_log` before the database is destroyed,
   and archive that record.

---

## Related Documents

- [`ARCHITECTURE.md`](ARCHITECTURE.md) — §12 deployment topology, ADR-003 (outbox)
- [`REQUIREMENTS.md`](REQUIREMENTS.md) — NFR-REL-01…03, NFR-SCALE-03, NFR-PERF-02
- [`SECURITY.md`](SECURITY.md) — §9 secret rotation, §13 incident response
- [`API.md`](API.md) — §22 the outbox semantics the worker implements
- [`DATA_MODEL.md`](DATA_MODEL.md) — retention windows, the `active_guard` key
- [`IMPLEMENTATION.md`](IMPLEMENTATION.md) — phase 7, deploy and rollback
- [`TESTING.md`](TESTING.md) — §12 the CI pipeline
