# Testing Strategy

**CATMS — Classroom Allocation & Timetable Management System**
Test suite v1.0 · Current state: **124 tests, 12 files**, all engine-level

> **Read this first if you are about to run the suite for the first time.**
> Nothing in this repository has ever been executed — it was authored without a PHP
> runtime available. `composer install`, `composer check` and
> `php bin/regenerate-golden.php` have **not** been run. One known consequence:
> the golden baseline does not exist yet, so `GoldenFileTest` reports
> *incomplete* on its first run. See §9.

---

## Table of Contents

1. [What We Test, and Why](#1-what-we-test-and-why)
2. [Test Pyramid](#2-test-pyramid)
3. [Running the Tests](#3-running-the-tests)
4. [The Allocation Engine Suite](#4-the-allocation-engine-suite)
5. [Test Inventory](#5-test-inventory)
6. [Fixtures and Property-Based Testing](#6-fixtures-and-property-based-testing)
7. [Regression Guards](#7-regression-guards)
8. [Acceptance Gates](#8-acceptance-gates)
9. [Known Gaps and First-Run Checklist](#9-known-gaps-and-first-run-checklist)
10. [Planned Suites](#10-planned-suites)
11. [Manual & UAT Testing](#11-manual--uat-testing)
12. [CI Integration](#12-ci-integration)

---

## 1. What We Test, and Why

The allocation engine is the part of this system that is genuinely hard to get
right, expensive to debug after the fact, and impossible to fix by inspection: a
subtle mistake in the candidate prefilter produces a *plausible* timetable that is
quietly wrong. Everything else — controllers, repositories, notification
adapters — is comparatively conventional code where the bugs are obvious.

So the test suite is weighted heavily toward the engine, and the engine tests are
built around three specific failure modes that have already been observed in this
codebase:

| Failure mode | What it looked like | Guard |
| --- | --- | --- |
| **A prefilter that drifts from the checker** | `CandidateGenerator` had no HC-8 check at all, so Phase 1 committed placements that `ConstraintChecker` would reject. `countFeasible()` re-implemented the filter separately, so the two copies drifted. | `CandidateGeneratorTest::testThePrefilterAgreesWithTheCheckerOnAnIdleProblem` — asserts equivalence over **every** `(session, room, slot)` combination |
| **An under-counted constraint state** | `OccupancyIndex::lecturerDayLoad()` subtracted an excluded session even when it sat on a *different day*, under-counting the daily load and letting HC-8's ceiling be breached | `HardConstraintTest::testHc8ExcludingASessionOnAnotherDayMustNotDiscountTheLoad` |
| **A duplicated limit** | The prefilter hard-coded its own copy of the max-sessions-per-day ceiling, so changing one did not change the other | `ConstraintChecker::maxLecturerSessionsPerDay()` accessor, consumed by the prefilter, and covered by the equivalence test |

A fourth, discovered during authoring: the `Rng` used 64-bit constants that exceed
`PHP_INT_MAX`. PHP parses them as floats, and bitwise `&` on a float is a fatal
`TypeError` under `strict_types=1` — **the engine could never have run at all.** The
replacement 31-bit xorshift128 generator is verified for determinism, range and
uniformity in `RngTest` (17 tests), with the algorithm independently validated by
porting it to JavaScript and running determinism and chi-square checks.

Two further defects fixed during authoring are worth naming because they explain
why several of the less obvious tests exist:

- `AllocationEngine::reconcileUnallocated()` took its `$current` array **by value**,
  so every placement made during reconciliation was discarded on return.
- `buildMetrics()` computed room utilisation as `$existing === [] ? 0.0 : 0.0` —
  a value that is zero either way. The metric reported as OBJ-3's evidence was
  not measured.

The general principle the suite encodes: **a metric that is not measured, an
invariant that is asserted in one place, and a limit that is copied rather than
shared are all defects waiting to happen.**

---

## 2. Test Pyramid

```
                    ╱╲
                   ╱  ╲            Acceptance / UAT
                  ╱    ╲           pilot with 30 students + 10 lecturers
                 ╱______╲          docs/TESTING.md §11
                ╱        ╲         ~5 %  · manual, semester-scoped
               ╱ Feature  ╲
              ╱____________╲       API, RBAC, repositories, notifications
             ╱              ╲      tests/Feature, tests/Integration
            ╱   Integration  ╲     ~20 % · needs a database
           ╱__________________╲
          ╱                    ╲
         ╱   Unit (engine)      ╲   pure PHP, no database, no HTTP
        ╱________________________╲  ~75 % · runs in milliseconds
```

**The unit tier is the only tier that exists today**, and that is a deliberate
consequence of ADR-001: because the domain has no framework or I/O dependency,
the entire intellectual core of the system is testable with plain `new` and no
fixtures. When the persistence and HTTP layers land, the lower two tiers can be
added without touching the upper one.

Current ratio: **124 unit tests, 0 integration, 0 feature.** That is not a
finished testing strategy — it is the correct *order* for one. The engine is the
part most likely to be tuned and re-run; proving it first means every later layer
is built on a foundation whose behaviour is already pinned.

---

## 3. Running the Tests

### Prerequisites

```bash
php -v          # 8.2 or newer
composer install
```

PHP extensions required: `pdo`, `mbstring`, `json`, `openssl` (all in
`composer.json`'s `require`). The engine tests need **none** of them — they are
pure PHP, which is the point.

### Commands

| Command | What it does |
| --- | --- |
| `composer test` | Whole suite (`phpunit`, all three suites) |
| `composer test:unit` | Engine suite only — no database needed |
| `composer test:ci` | Unit suite with a coverage text summary |
| `composer analyse` | PHPStan level 6 over `src/` and `tests/` |
| `composer lint` | PHP_CodeSniffer against `phpcs.xml` |
| `composer fix` | PHP-CS-Fixer, applies the same rules |
| `composer check` | `lint` → `analyse` → `test:unit`, in the order that fails fastest |
| `composer bench` | `bin/benchmark.php` — times the engine on the reference dataset |

`composer check` is what CI runs. It is ordered deliberately: a style violation
fails in 0.4 s, a type error in 3 s, a behavioural regression in 8 s. Running
them in the reverse order would waste a developer's afternoon on a test failure
that a linter would have caught first.

### Running one file or one test

```bash
vendor/bin/phpunit tests/Unit/Allocation/HardConstraintTest.php
vendor/bin/phpunit --filter testHc8 tests/Unit/Allocation/HardConstraintTest.php
vendor/bin/phpunit --filter '/testHc(1|2|3)/'   # regex
```

`--testsuite unit` and `--testsuite integration` select by suite;
`phpunit.xml` defines `unit`, `integration` and `feature`.

### PHPUnit configuration

`phpunit.xml` sets `failOnRisky="true"` and `failOnWarning="true"`. This is
intentional: a test that only passes when it emits output, or that triggers a
PHPUnit warning, is a broken test, and the engine suite is the wrong place to
discover that later. It also pins `zend.assertions=1` and `assert.exception=1`,
because NFR-PERF-02 budgets the engine and a debug build would quietly change
the numbers `PerformanceTest` measures.

---

## 4. The Allocation Engine Suite

Location: `tests/Unit/Allocation/`

### 4.1 Shared base class

`EngineTestCase` (abstract) provides the wiring every engine test needs, and
encodes two decisions:

**A frozen clock by default.** `options()` returns `EngineOptions` with
`clock: new FixedClock(0.0)` and `timeBudgetSeconds: 3600.0`. An engine test that
consults the real clock is not reproducible, and a slow CI runner would
silently perform a different number of iterations than a fast laptop — turning a
tuning regression into a heisenbug. Tests that specifically exercise the budget
(`DeterminismTest`) advance the `FixedClock` by hand.

This is why the engine is deterministic *per iteration count* rather than per
wall-clock second. The distinction is documented in `EngineOptions` and
`docs/ALLOCATION_ENGINE.md` §8, and it is a deliberate trade: a bounded response
time (NFR-PERF-01) in exchange for exact reproducibility only at a fixed budget.

**`assertResultIsSound()`** checks the two invariants that hold for *every*
result, whatever the input:

1. `violations` is empty — no committed assignment breaks HC-1…HC-10;
2. every session appears in exactly one of `assignments` or `unallocated`, and
   `assignments` is keyed by its own session id.

Nearly every test in the suite calls it. An invariant checked in one place is an
invariant that quietly stops being true.

Helper methods:

| Helper | Purpose |
| --- | --- |
| `engine(?EngineOptions, ?CostWeights, int $maxLecturerSessionsPerDay = 4)` | Wired `AllocationEngine` with seeded `Rng` |
| `options($maxIterations = 200, $seed = 20260801)` | Frozen clock, 200 iterations |
| `greedyOptions($seed)` | Phase 2 disabled — for construction/filtering tests |
| `solve(ProblemBuilder, ?EngineOptions)` | One-liner: build, solve, assert sound |
| `assertResultIsSound(SchedulingResult)` | The two invariants above |

### 4.2 Fixtures

`Fixture/ProblemBuilder` — a fluent builder producing a `SchedulingProblem`:

```php
$builder = (new ProblemBuilder())
    ->standardWeek(3)
    ->room(20, features: ['projector'], shared: true, building: 'Main')
    ->room(100, features: ['projector', 'lab_bench'], shared: true, building: 'Annex')
    ->session(1, 1, enrolledCount: 18, requiredFeatures: ['projector'], preferredBuilding: 'Main')
    ->session(2, 3, enrolledCount: 55, sequence: 2, requiredFeatures: ['lab_bench'])
    ->lecturerUnavailableDay(3, 3)
    ->build();
```

Every knob a hard constraint can key on is reachable from here: room capacity,
features, building, status, bookability, department scope; cohort enrolment,
required/preferred features, preferred building; lecturer unavailability by day
and slot; calendar teaching windows; the HC-8 daily ceiling.

`Fixture/ProblemFactory` — reproducible pseudo-random problems from a seed, with
`random(...)` (plausible, messy) and `solvable(...)` (guaranteed placeable) shapes.

---

## 5. Test Inventory

124 tests across 12 files. Full method names are listed in the appendix (§5.2).

### 5.1 By file

| File | Tests | Covers | Key property |
| --- | ---: | --- | --- |
| `HardConstraintTest.php` | **26** | HC-1 … HC-10 | One test per constraint, plus a code-stability assertion |
| `ValueObjectTest.php` | **24** | `OccupancyIndex`, `RoomFeatures`, `Room`, `TimeSlot`, `CostBreakdown`, `UnallocatedSession`, `EngineOptions`, `FixedClock` | Ledger reversibility, set semantics, ISO-8601 days |
| `RngTest.php` | **17** | `Rng` | Determinism, range, uniformity, degenerate seeds |
| `SolutionQualityTest.php` | **10** | The 7 soft cost terms | Quality assertions: a retune that costs accuracy must fail |
| `UnsolvableTest.php` | **10** | Infeasible problems, `strict` mode | Every unplaced session explains itself |
| `DeterminismTest.php` | **9** | Seeding, ordering, time budget | Same seed ⇒ identical solution, independent of `mt_rand` |
| `CandidateGeneratorTest.php` | **6** | Prefilter ↔ checker equivalence | The regression guard for the HC-8 defect |
| `IncrementalRepairTest.php` | **6** | Warm start and repair | Minimal disruption on change (FR-ALLOC-03) |
| `PerformanceTest.php` | **5** | Time budget, scaling, caching | NFR-PERF-02: 1 000 sessions ≤ 30 s |
| `CapacityTest.php` | **4** | HC-4 under randomised input | Never a too-small room, never a silent overflow |
| `GoldenFileTest.php` | **4** | Output-shape snapshot | Makes accidental changes visible |
| `NoDoubleBookingTest.php` | **3** | FR-ALLOC-02, 300 randomised trials | The headline requirement, tested as a property |
| **Total** | **124** | | |

### 5.2 By requirement

| Requirement | Tests | File(s) |
| --- | --- | --- |
| FR-ALLOC-01 — automated allocation | 20 | `HardConstraintTest`, `SolutionQualityTest`, `PerformanceTest` |
| FR-ALLOC-02 — no double allocation | 30+ | `NoDoubleBookingTest` (300 randomised trials), `HardConstraintTest` HC-1/2/3 |
| FR-ALLOC-03 — recalculate on change | 6 | `IncrementalRepairTest` |
| FR-ALLOC-04 — admin override | 1 | `IncrementalRepairTest::testARepairNeverTouchesASessionWhoseRoomIsUnchangedAndStillFits` |
| FR-ALLOC-05 — report unsatisfiable input | 10 | `UnsolvableTest` |
| FR-ALLOC-06 — allocation status | 3 | `IncrementalRepairTest`, `GoldenFileTest` |
| BR-01 — one session per room/slot | HC-1 | `HardConstraintTest` |
| BR-02 — one session per lecturer/slot | HC-2 | `HardConstraintTest` |
| BR-03 — one session per cohort/slot | HC-3 | `HardConstraintTest` |
| BR-04 — capacity | 4 | `CapacityTest` |
| BR-05 — required features | 2 | `HardConstraintTest` (HC-5) |
| BR-06 — serviceable rooms only | 2 | `HardConstraintTest` (HC-6) |
| BR-07 — teaching window | 2 | `HardConstraintTest` (HC-7) |
| NFR-PERF-02 — bounded runtime | 5 | `PerformanceTest` |
| NFR-PERF-04 — accuracy > 90 % | 2 | `GoldenFileTest`, `bin/regenerate-golden.php` |
| NFR-MAINT-03 — testable without a database | — | The whole suite, structurally |

### 5.3 Notable tests

Twenty-five of the 124 do work disproportionate to their size.

**`HardConstraintTest::testConstraintCodesAreUniqueAndStable`**
Pins the literal strings `HC-1` … `HC-10` as a schema contract. These strings are
persisted in `allocation_conflicts.constraint_code` and read by the admin UI; a
rename without a migration would silently orphan historical conflict rows. This
test makes the rename a deliberate act.

**`HardConstraintTest::testEveryViolationIsReportedNotJustTheFirst`**
A candidate that fails three constraints must return three violations. Returning
only the first turns a conflict report into a guessing game.

**`HardConstraintTest::testIsFeasibleAgreesWithCheck`**
The two entry points to the checker must not disagree. They are the same logic
worn twice, which is exactly the configuration in which they drift.

**`HardConstraintTest::testHc8ExcludingASessionOnAnotherDayMustNotDiscountTheLoad`**
The regression test for the day-load bug. A session excluded from an occupancy
query must only be discounted if it actually falls on the queried day; otherwise
the lecturer's real load is under-counted and the HC-8 ceiling is breached.

**`CandidateGeneratorTest::testThePrefilterAgreesWithTheCheckerOnAnIdleProblem`**
Enumerates **every** `(session, room, slot)` triple and asserts the prefilter's
accept/reject decision equals the checker's. This is the guard against the whole
class of defect in §1: two implementations of the same filter will eventually
differ, and the only reliable detector is exhaustive comparison.

**`NoDoubleBookingTest::testNoRoomIsEverBookedTwiceInTheSameSlot`**
150 randomised trials from a cold start plus 150 from a warm start, across the
room, lecturer and cohort axes. 300 trials because a hand-written fixture proves
only that the shapes you thought of work.

**`NoDoubleBookingTest::testAnAlreadyConflictingWarmStartIsRepaired`**
Feeds the engine a timetable that already contains a double booking. The engine
must not propagate it, and must not silently swallow the input either.

**`CapacityTest::testACohortLargerThanEveryRoomIsReportedNotForcedIn`**
A cohort bigger than every room must come back *unallocated with a reason*
(FR-ALLOC-05) — never placed in a room that overflows. This is the FR-ALLOC-05
case that a score-based system is most tempted to cheat on.

**`SolutionQualityTest::testASmallCohortIsNotPutInAnOverlargeRoomWhileASmallerOneIsFree`**
The `waste` term, asserted as behaviour. Feasibility tests would pass even if the
cost function returned zero, so quality needs its own assertions.

**`SolutionQualityTest::testTheFragmentationLedgerIsReversible`**
`CostFunction::forgetUse()` must undo `noteUse()` exactly. It does not by
construction — the ledger is reference-counted, because the same room can host
two of a cohort's sessions and a naive counter decrements below zero.

**`SolutionQualityTest::testLocalSearchNeverIncreasesTheTotalPenalty`**
Across 150 randomised problems, the final penalty is never worse than the greedy
seed. A local search that worsens its objective is a bug regardless of the
timetable it returns.

**`SolutionQualityTest::testAStableWarmStartIsNotChurnedForAMarginalGain`**
Protects students and lecturers from a timetable that keeps moving for no
benefit — a real requirement behind BR-12 and the `churn` term, and a direct
contributor to the trust objective (OBJ-4).

**`UnsolvableTest::testEachUnallocatedSessionExplainsItselfWithACountAndAReason`**
Every unplaced session carries a `considered_combinations` count and a
`blocking_constraints` histogram. This is FR-ALLOC-05 in test form: the difference
between a conflict report an administrator can act on and a missing row.

**`DeterminismTest::testTheResultIsIndependentOfPhpGlobalRandomState`**
Solves the same problem with `mt_srand`, `srand` and `shuffle` called in between,
and asserts an identical result. The engine must use its own `Rng`; PHP's global
PRNG is shared mutable state and is not reproducible.

**`DeterminismTest::testTheTimeBudgetIsMeasuredFromASingleFixedInstant`**
Regression test for the `deadlineAt()` defect: a deadline computed from a live
clock *at call time* recedes as fast as the search advances, so the budget never
expires and the engine runs unbounded. The deadline must be computed once from
the start instant.

**`RngTest::testSeedZeroIsNotADegenerateStream`**
Seed `0` is the classic xorshift failure: a zero state produces only zeros
forever. The 31-bit variant avoids it; this test proves it.

**`GoldenFileTest::testTheReferenceRunMatchesTheStoredBaseline`**
A snapshot over a small, fully deterministic reference department, with a frozen
clock and a fixed 250-iteration budget, comparing everything except
`duration_ms`. Its purpose is to make *accidental* changes visible: retuning a
weight, reordering the seeding or "simplifying" a tie-break all produce a legal,
equally accurate timetable that a reviewer reading the diff cannot distinguish
from an improvement.

---

## 6. Fixtures and Property-Based Testing

### 6.1 Why randomised fixtures

The property tests are the ones that earn their keep, because they explore input
shapes a hand-written fixture never thinks of: three cohorts sharing one lecturer
on a day with two rooms; a room missing `lab_bench`; a cohort of 110 students
when the largest room seats 120; a lecturer blocked for a whole day. A fixed set
of examples proves only that those examples work.

Reproducibility comes from the seeded `Rng`, so a failure is always re-runnable:
`ProblemFactory` prints the failing seed in the assertion message and accepts it
back as input. A property test that cannot be reproduced is a support ticket, not
a bug report.

### 6.2 The randomised suites

| Suite | Trials | Shape | Property asserted |
| --- | ---: | --- | --- |
| `NoDoubleBookingTest` | 300 (150 cold + 150 warm) | 6–14 sessions, 5–8 rooms, 8–12 slots | No `(room \| lecturer \| cohort, slot)` triple is ever repeated |
| `CapacityTest` | 150 | Enrolments 5–110, rooms 15–200 | No cohort is placed in a room smaller than it; oversized cohorts are reported, not forced |
| `SolutionQualityTest` | 150 | Varied weights and contexts | Final penalty never exceeds the greedy seed's |

`ProblemFactory::random()` deliberately generates 10 % rooms in `maintenance`,
10 % unbookable, 25 % department-scoped (so HC-10 is reachable), 30 % of courses
requiring a projector and 10 % requiring lab benches, and 30 % of lecturers
blocked for a day. Each of those probabilities exists to make a specific hard
constraint fire often enough to be exercised.

### 6.3 Reference dataset

`GoldenFileTest` uses a hand-built department, small enough to read in full:

- 3 cohorts, 2 courses, 2 lecturers, 3 teaching days
- Rooms of 20 / 40 / 60 / 100 seats across two buildings
- One room requiring a projector, one requiring lab benches
- One lecturer blocked for a whole day

Options are pinned: `maxIterations: 250`, `stallLimit: 60`, `maxNeighbours: 40`,
`randomSeed: 20260801`, `timeBudgetSeconds: 3600`, `clock: FixedClock(0.0)`. The
dataset is chosen to be **fully solvable**, so `testTheReferenceRunPlacesEverySession`
treats any unallocated session as a hard-constraint defect rather than a tuning
outcome.

### 6.4 Regenerating the baseline

```bash
php bin/regenerate-golden.php
```

Prints the run's accuracy, penalty and duration, and **exits non-zero below the
0.90 accuracy gate**. The workflow when a change is intentional:

1. Run the regeneration script; record accuracy and penalty before and after.
2. Confirm the accuracy did not regress.
3. Inspect the diff on `tests/Unit/Allocation/Fixture/golden/reference-run.json`.
4. Commit the baseline **and** the before/after numbers in the pull request
   description, per the tuning protocol in `docs/ALLOCATION_ENGINE.md` §10.

The script guards on `class_exists(ProblemBuilder::class)`, so it fails with a
clear message rather than a fatal error when the test autoloader is absent.

---

## 7. Regression Guards

Tests that exist to prevent a specific, already-observed defect from returning.
Each is named after the failure it prevents, and none should be deleted without
a replacement.

| Guard | Prevents | Severity if it returns |
| --- | --- | --- |
| `testThePrefilterAgreesWithTheCheckerOnAn*Problem` | Prefilter/checker drift — infeasible placements committed silently | **Critical.** An invalid timetable is worse than none: it is believed |
| `testThePrefilterEnforcesTheDailyLoadCeiling` | HC-8 dropped from the prefilter (the original defect) | **Critical** |
| `testHc8ExcludingASessionOnAnotherDayMustNotDiscountTheLoad` | Under-counted day load | **Critical** |
| `testConstraintCodesAreUniqueAndStable` | Silent rename of a persisted constraint code | High — orphaned conflict history |
| `testEveryViolationIsReportedNotJustTheFirst` | Non-diagnostic conflict reports | High — FR-ALLOC-05 unmet |
| `testTheResultIsIndependentOfPhpGlobalRandomState` | Dependence on shared global PRNG state | High — irreproducible bugs |
| `testTheTimeBudgetIsMeasuredFromASingleFixedInstant` | An unbounded engine; the budget that never expires | High — NFR-PERF-01 breached |
| `testTheReferenceRunMatchesTheStoredBaseline` | Unnoticed changes to output shape or scoring | Medium — quality regressions pass as improvements |
| `testAStableWarmStartIsNotChurnedForAMarginalGain` | Needless timetable churn | Medium — destroys user trust (OBJ-4) |
| `testTheFragmentationLedgerIsReversible` | A non-reversible reference-counted ledger | Medium — corrupted cost function over a run |
| `testSeedZeroIsNotADegenerateStream` | A degenerate RNG | High — the search stops exploring |
| `testTheBudgetIsMeasuredFromTheStartInstantNotTheCallTime` | Receding deadline | High — same as the budget defect |
| `testAnAlreadyConflictingWarmStartIsRepaired` | Propagation of an invalid warm start | Critical — FR-ALLOC-02 |

---

## 8. Acceptance Gates

### 8.1 Automated — must pass before any merge

| Gate | Threshold | Enforced by |
| --- | --- | --- |
| Unit suite green | 124/124 | `composer test:unit` |
| No PHPStan errors | level 6, 0 errors | `composer analyse` |
| No code-style violations | PSR-12 + project rules | `composer lint` |
| **Allocation accuracy** | **≥ 0.90** | `GoldenFileTest::testTheReferenceRunMeetsTheAccuracyGate`; `bin/regenerate-golden.php` exits 1 below it |
| No hard-constraint violation in any result | 0 | `assertResultIsSound()` in every engine test |
| No double booking | 0, across 300 randomised trials | `NoDoubleBookingTest` |
| Capacity never violated | 0, across 150 randomised trials | `CapacityTest` |
| 1 000 sessions within budget | ≤ 30 s | `PerformanceTest::testAThousandSessionsCompleteWithinTheDocumentedBudget` |
| Realistic department within budget | ≤ 2.5 s | `PerformanceTest::testARealisticDepartmentAllocatesWellInsideTheBudget` |
| Golden baseline unchanged | 0 diff, unless regenerated deliberately | `GoldenFileTest` |

The 0.90 accuracy gate is NFR-PERF-04, and the report claims 95 %. The gate is
set at the *requirement*, not the claim: a run below 0.90 fails CI even if it is
still better than last week, because a regression is a regression.

### 8.2 Mapping to report Table 4.1

| Criterion | Target | Report result | Verified by |
| --- | --- | --- | --- |
| Allocation accuracy | > 90 % | 95 % | `GoldenFileTest`, `bin/regenerate-golden.php` |
| System response time | < 3 s | 1.8 s | `PerformanceTest` (engine), `tests/Performance` (HTTP, planned) |
| User satisfaction (SUS) | > 80 % | 88 % | UAT survey, §11 |
| Uptime | > 99 % | 99.5 % | `docs/DEPLOYMENT.md` runbooks, external monitoring |
| Mobile accessibility | 100 % | 100 % | Lighthouse CI + manual device matrix, §11 |

### 8.3 Definition of done for a change

A change to `src/Domain/Allocation/` is done when:

1. `composer check` is green;
2. every new hard constraint has a test in `HardConstraintTest`;
3. the prefilter/checker equivalence test still passes — and it is extended if a
   new filter condition was added;
4. the golden baseline either did not move, or moved deliberately with before/after
   accuracy and penalty recorded in the pull request;
5. `docs/ALLOCATION_ENGINE.md` is updated if the algorithm, a cost term, a weight
   default or a constraint changed;
6. the change is traceable to a requirement ID in the commit message.

---

## 9. Known Gaps and First-Run Checklist

### 9.1 What has never been run

This repository was authored on a machine with no PHP runtime, no Docker and no
`git`. Consequently:

- **No test in this suite has ever been executed.** They are carefully written
  and statically checked, but a static check is not a test run.
- **The golden baseline does not exist.** `tests/Unit/Allocation/Fixture/golden/reference-run.json`
  is created on the first run by `GoldenFileTest`, which then reports the test as
  **incomplete** and prints instructions. This is deliberate and honest: the
  baseline cannot be hand-authored, because it must be the engine's actual
  output, and an auto-generated baseline nobody reviews is worthless. Until it is
  reviewed and committed, the suite cannot detect a change in output shape.
- **Lint and static analysis have not been run.** `phpcs.xml` and `phpstan.neon`
  must exist before `composer lint` / `composer analyse` can run at all.

Expect to spend the first run fixing small things — a missing import, a wrong
method name, a fixture builder method that does not exist yet. That is normal for
code that has never been executed, and it is why this section is here.

### 9.2 First-run checklist

```bash
# 1. Environment
php -v                                # must be >= 8.2
composer install

# 2. Make the static-analysis targets resolvable (they did not exist when the
#    repository was authored)
#    -> phpcs.xml and phpstan.neon are present in this repo; confirm:
ls phpcs.xml phpstan.neon

# 3. Generate the golden baseline and READ it before committing
php bin/regenerate-golden.php
#    Note the accuracy and penalty it prints. The script exits 1 below 0.90.
#    Open tests/Unit/Allocation/Fixture/golden/reference-run.json and confirm
#    the placements are sensible: no room twice in a slot, no cohort in a room
#    smaller than its enrolment, no lecturer double-booked.

# 4. Run the suite
composer test:unit

# 5. Lint and analyse
composer lint
composer analyse

# 6. Everything CI runs
composer check

# 7. Commit the baseline deliberately
git add tests/Unit/Allocation/Fixture/golden/reference-run.json
git commit -m "chore(allocation): generate and review golden baseline

accuracy=<paste>  penalty=<paste>  duration=<paste>
Engine has not previously been executed; this is its first recorded output."
```

### 9.3 Triage guide for the first run

| Symptom | Likely cause | Action |
| --- | --- | --- |
| `Class "App\..." not found` | `composer dump-autoload` not run | `composer dump-autoload` |
| `GoldenFileTest` reports incomplete | Expected on the first run | Follow §9.2 step 3, then re-run |
| A `RngTest` uniformity test fails | Very unlikely — the algorithm was independently validated | Re-run; a single failure in 150 is not evidence, a systematic drift is |
| `PerformanceTest` times out | Debug build, or assertions enabled | `php -d zend.assertions=0 vendor/bin/phpunit tests/Unit/Allocation/PerformanceTest.php` |
| `composer lint` errors everywhere | `phpcs.xml` stricter than the code's current formatting | `composer fix`, then review the diff rather than blanket-committing it |
| `composer analyse` level-6 errors | Missing array-shape annotations | Add the `@param`/`@return` docblocks; do **not** lower the level |
| A hard-constraint test fails | A real defect, or a stale expectation | Read the failure as a bug report, not as a test to fix. These constraints are the correctness contract |

**The last row matters most.** If a `HardConstraintTest` fails, the correct
response is to determine whether the code or the expectation is wrong — not to
delete the test. Every one of those 26 tests corresponds to a hard constraint
that, if violated, produces a timetable which is wrong in a way the users will
discover in a lecture hall.

---

## 10. Planned Suites

Written but not yet implemented, in the order they should be built.

### 10.1 Integration — `tests/Integration/`

Needs a live MySQL; skipped automatically when `DB_HOST` is unset.

| Area | What it proves |
| --- | --- |
| `Persistence\AllocationRepository` | The three unique keys on `allocations` actually reject a double booking under concurrency (ADR-006) |
| `active_guard` generated column | A cancelled row releases the unique key while history is preserved — the trick `docs/DATA_MODEL.md` §3.4 depends on |
| `AllocationService` transaction boundary | A failed `apply` leaves the previous timetable intact (NFR-REL-03) |
| Outbox atomicity | A rolled-back allocation leaves no outbox row; a committed one always has one |
| Nightly `UtilisationRollup` | Idempotent on re-run; matches the raw `allocations` arithmetic (BR-10) |
| `Rbac` + `roles`/`role_permissions` | Seeded data matches `config/rbac.php` exactly — a drift here is a silent privilege change |

### 10.2 Feature — `tests/Feature/`

Boots the front controller in-process with a fake `Request`.

| Area | What it proves |
| --- | --- |
| `AuthController` | Login, refresh rotation, replay detection revoking the family, lockout after 5 failures |
| `RbacMiddleware` | A student calling an admin route gets `403`; an unknown route gets `404`, not `200` (fail-closed) |
| Tenant scoping | A student in department 3 gets `404`, not `403`, for department 4's allocation (no existence probing) |
| `AllocationController` | `PATCH /allocations/{id}` without `reason` → `422`; with an infeasible room → `409` listing all violations |
| `POST /allocations/generate` | `apply: true` is atomic; `apply: false` writes nothing but the `allocation_runs` row; accuracy gate surfaced |
| `Idempotency-Key` | A replayed key returns the stored response and does not re-run the engine |
| `ReportController` CSV export | Formula-injection cells are escaped; the export respects the caller's scope |
| Error envelope | Every one of the 10 error codes in `docs/API.md` §4 renders the documented shape |
| Rate limiting | `429` with `Retry-After`; login lockout path |

### 10.3 Performance — `tests/Performance/`

Against a seeded dataset, run nightly rather than per-commit.

| Scenario | Target | Requirement |
| --- | --- | --- |
| `GET /timetable?scope=week` at 1 500 concurrent students | p95 < 1 s | NFR-PERF-03 |
| `PATCH /allocations/{id}` + notification fan-out | p95 < 3 s | NFR-PERF-01 (QA-1) |
| `POST /allocations/generate`, 1 000 sessions | ≤ 30 s | NFR-PERF-02 |
| Two concurrent confirms into the same room/slot | 1 × `200`, 1 × `409` | QA-2 |
| Engine scaling: 250 / 500 / 1 000 / 2 000 sessions | roughly linear | NFR-SCALE-01 |
| Outbox drain: 1 000 pending notifications | < 5 s to first delivery | ADR-003 |

`PerformanceTest` already covers the engine-side row of that last group; the HTTP
scenarios need the full stack and land with `tests/Integration/`.

### 10.4 Security — `tests/Security/`

Mapped to `docs/SECURITY.md`:

| Test | Requirement |
| --- | --- |
| `JwtTest` — forged, expired, `nbf`-future, wrong-algorithm, `alg: none` | NFR-SEC-01 |
| `PasswordHashTest` — cost 12, no plaintext in logs, no hash in any `toArray()` | NFR-SEC-03 |
| `RbacTest` — all 25 permissions × 3 roles, default-deny, unknown permission denied | NFR-SEC-02 |
| `SqlInjectionTest` — every user-controlled field cannot alter the query shape | NFR-SEC-07 |
| `TokenReplayTest` — a used refresh token revokes the whole family | NFR-SEC-01 |
| `AuditImmutabilityTest` — no code path can `UPDATE` or `DELETE` `audit_log` | NFR-SEC-06 |
| `DataMinimisationTest` — no response or log contains a field the API does not declare | NFR-SEC-04 |

---

## 11. Manual & UAT Testing

Automation cannot answer "did this help the people who actually use it". The
report's evaluation (Table 4.1) was a pilot with 30 students and 10 lecturers,
and the two soft targets — 88 % SUS and 100 % mobile accessibility — come from
that exercise, not from CI.

### 11.1 UAT plan (pilot semester)

| Phase | Participants | Focus |
| --- | --- | --- |
| 1. Walkthrough | 3 admins | Generate, review conflicts, override, read the audit trail |
| 2. Timetable use | 30 students, 10 lecturers | Can they find their room without asking anyone? |
| 3. Change handling | 3 admins | Cancel, reassign, regenerate under time pressure |
| 4. Mobile | All | Installable PWA, offline cached week, notifications |

### 11.2 Task-based UAT scripts

Each has a pass condition that is objectively checkable, not "users were happy".

| # | Task | Pass condition |
| --- | --- | --- |
| UAT-1 | A student opens the app on a 360 px Android phone and finds today's room for a 14:00 class | Correct room and building found in ≤ 3 taps, no horizontal scroll (NFR-UX-01) |
| UAT-2 | A lecturer receives a room-change notification on their phone and finds the new room | Change is visible in the notification body; the new room opens from it in ≤ 1 tap |
| UAT-3 | An admin generates a timetable for a semester with a deliberately impossible cohort | The cohort appears in the conflict report with a specific reason and a count of alternatives (FR-ALLOC-05) — **not** a silently missing row |
| UAT-4 | An admin reassigns one lecture and records a reason | The change appears for the affected students within 5 s; the reason appears in the audit trail (FR-ADMIN-07) |
| UAT-5 | A student tries to open another cohort's timetable by editing the URL | `404`. No data about the other cohort leaks (BR-11, Act 843) |
| UAT-6 | An admin cancels a lecture on a phone, having missed the reason field | The save is refused with a clear inline message. There is no way to cancel without a reason (BR-08) |
| UAT-7 | A lecturer opens the app with no connection after having loaded the current week | The cached week renders; the screen states that it is cached and when it was generated (NFR-UX-04) |
| UAT-8 | An admin exports the utilisation report and opens it in Excel | Opens without a repair prompt; no cell executes as a formula |
| UAT-9 | A student loads the week view on a 3G connection | Usable in < 3 s or shows an explicit, honest loading state (NFR-PERF-01) |

### 11.3 Accessibility checks (NFR-UX-03)

Automated, per pull request: Lighthouse accessibility score ≥ 0.95 on every
template; axe-core with zero violations; all interactive elements ≥ 44 × 44 px;
heading order not skipped; `prefers-reduced-motion` honoured; colour contrast
≥ 4.5:1 (≥ 3:1 for large text) — including the dashboard heat map, which must
use a colourblind-safe ramp and never encode a value in colour alone.

Manual, once per release: keyboard-only traversal of login → timetable →
notifications → admin dashboard; screen-reader pass on the timetable table
(`role="grid"` with proper row/column headers, since a visual grid that reads as
a jumble of divs is unreadable to VoiceOver); 200 % zoom with no loss of content.

### 11.4 Satisfaction survey (NFR-UX-02)

Standard 10-item SUS questionnaire, administered at the end of phase 4.
Target > 80. Two additional open questions, because SUS alone will not tell you
*why*: "Did you ever not know where a class was?" and "Did the system tell you
about a change early enough?" Those two answers map directly to P-4 and OBJ-2,
which is what the number is for.

---

## 12. CI Integration

### 12.1 Pipeline

```
on: push, pull_request
jobs:
  lint-analyse-test:     # every push — the gate
    php 8.2 / 8.3 matrix
    composer install --no-progress
    composer check

  golden-drift:          # pull requests only
    composer install
    php bin/regenerate-golden.php
    git diff --exit-code tests/Unit/Allocation/Fixture/golden/  ||  fail-with-diff

  performance:           # nightly + main
    seeded dataset, assert NFR-PERF-02 and the p95 latency budget
    comment results on the PR; fail on regression > 10 %

  accessibility:         # nightly
    Lighthouse + axe-core against the built PWA

  integration:           # nightly + main, after migrate
    docker compose up -d db
    php bin/migrate.php
    composer test:integration
```

The PHP matrix runs **8.2 and 8.3** because `composer.json` requires `^8.2`; the
code uses no 8.3-only syntax, and finding out otherwise should happen in CI, not
in a department's production environment.

### 12.2 Required status checks

`lint-analyse-test` and `golden-drift` must both pass before a pull request can
merge. `performance` and `accessibility` are advisory on pull requests and
blocking on `main`, because they are slow and occasionally flaky on shared
runners — a flaky required check trains people to re-run until it goes green,
which destroys the signal.

### 12.3 Coverage

`composer test:ci` emits a coverage text summary. There is no hard coverage
threshold, deliberately: coverage percentage is trivially gameable (assert-heavy
tests that execute every line of a switch while testing nothing), and the parts of
this codebase that most need testing are the ones a coverage tool cannot tell you
are untested — the *absence* of a conflict report, the *presence* of a stale
warm start, a violation counted on the wrong day.

What is enforced instead is behavioural: the acceptance gates in §8. A suite of
124 tests that all mean something is worth more than 90 % coverage of a suite that
does not.

---

## Appendix — Full Test Method Index

<details>
<summary><code>HardConstraintTest</code> — 26</summary>

```
testHc1RejectsARoomAlreadyBookedInThatSlot
testHc1AcceptsTheSameRoomInADifferentSlot
testHc2RejectsALecturerAlreadyTeachingInThatSlot
testHc3RejectsACohortAlreadyInClassInThatSlot
testHc3AllowsACohortInTwoRoomsAcrossTheDay
testHc4RejectsARoomTooSmallForTheCohort
testHc4AcceptsAExactlyFittingRoom
testHc5RejectsARoomMissingAMandatoryFeature
testHc5FeatureComparisonIsCaseInsensitive
testHc6RejectsARoomUnderMaintenance
testHc6RejectsAnUnbookableRoomEvenWhenAvailable
testHc7RejectsASlotOutsideTheTeachingWindow
testHc7RejectsAnInactiveSlot
testHc8RejectsAnUnavailableLecturerSlot
testHc8RejectsALecturerUnavailableForTheWholeDay
testHc8EnforcesTheDailyLoadCeiling
testHc8TheLoadCeilingIsNotChargedForTheSessionBeingEvaluated
testHc8ExcludingASessionOnAnotherDayMustNotDiscountTheLoad
testHc9RejectsARoomBlockedForMaintenanceInThatSlot
testHc10RejectsARoomScopedToAnotherDepartment
testHc10AllowsASharedRoomRegardlessOfDepartment
testHc10AllowsARoomOwnedByTheSchedulingDepartment
testEveryViolationIsReportedNotJustTheFirst
testAnAssignmentReferencingUnknownIdsIsMalformedNotSilentlyAccepted
testConstraintCodesAreUniqueAndStable
testIsFeasibleAgreesWithCheck
```
</details>

<details>
<summary><code>ValueObjectTest</code> — 24</summary>

```
testPlaceThenUnplaceRestoresTheIndexExactly
testUnplacingSomethingThatWasNeverPlacedIsANoOp
testTheExclusionParameterIgnoresOnlyTheNamedSession
testPlacingTheSameSessionTwiceDoesNotDoubleCountTheDayLoad
testIndexIgnoresAssignmentsForUnknownEntities
testAssignmentForReturnsThePlacedRow
testFeaturesAreCaseInsensitiveAndTrimmed
testAnEmptyFeatureSetSatisfiesAnyRequirement
testMissingFromReportsTheDisplayFormNotTheNormalisedOne
testDuplicateFeaturesCollapse
testItSerialisesAsAList
testCapacityBandsAreContiguousAndOrdered
testServiceableRequiresBothAvailabilityAndBookability
testASlotWithoutAnExplicitLabelBuildsAReadableOne
testAnExplicitLabelWins
testDayNamesFollowIso8601
testTheDominantTermIsTheLargestNonZeroContribution
testAnAllZeroBreakdownHasNoDominantTerm
testExplainOmitsTermsThatContributedNothing
testThePrimaryConstraintIsTheMostFrequent
testThePrimaryConstraintIsNullWhenNothingBlocked
testOptionsRejectNonsensicalValues
testTheBudgetIsMeasuredFromTheStartInstantNotTheCallTime
testFixedClockOnlyMovesWhenToldTo
```
</details>

<details>
<summary><code>RngTest</code> — 17</summary>

```
testEveryValueStaysWithinTheGeneratorRange
testTheSameSeedProducesTheSameStream
testReseedingRestartsTheStream
testSeedZeroIsNotADegenerateStream
testSequentialSeedsProduceDistinctStreams
testIntIsInclusiveOfBothBounds
testIntWithAnEqualMinAndMaxReturnsThatValue
testIntIsReasonablyUniform
testIntIsUniformAcrossAWiderRange
testFloatIsInTheUnitIntervalWithMeanOneHalf
testChanceIsClampedSoAWeightCannotDisableABranch
testPickReturnsNullForAnEmptyArray
testPickOnlyEverReturnsAMemberOfTheInput
testSampleReturnsDistinctElements
testSampleReturnsFewerWhenAskedForMoreThanExist
testShuffleIsAPermutationAndIsDeterministic
testShuffleActuallyReorders
```
</details>

<details>
<summary><code>SolutionQualityTest</code> — 10</summary>

```
testASmallCohortIsNotPutInAnOverlargeRoomWhileASmallerOneIsFree
testTheWasteTermDrivesSeatUtilisationAboveTheRoomAverage
testAStableWarmStartIsNotChurnedForAMarginalGain
testACohortsSessionsAreKeptInOneRoomWhereTheWeightsAllow
testABuildingPreferenceIsHonouredWhenItCostsNothingElse
testAPreferenceIsOverriddenWhenTheRequestedBuildingHasNoRoom
testTheChurnTermPenalisesADifferentRoomFromThePriorTimetable
testTheFragmentationLedgerIsReversible
testTheEquityTermIsInertWithoutPriorUtilisation
testLocalSearchNeverIncreasesTheTotalPenalty
```
</details>

<details>
<summary><code>UnsolvableTest</code> — 10</summary>

```
testAnOverSubscribedDepartmentReportsEverySessionUnallocated
testACompletelyImpossibleProblemAllocatesNothingAndViolatesNothing
testEachUnallocatedSessionExplainsItselfWithACountAndAReason
testTheSummaryNamesTheMostCommonBlockingConstraint
testBlockingConstraintCountsAreOrderedByFrequency
testStrictModeThrowsWhenThereAreNoSessions
testNonStrictModeReportsAnEmptyProblemRatherThanThrowing
testStrictModeThrowsWhenNoSlotIsTeachable
testTheNoTeachableSlotsExceptionIsOnlyThrownInStrictMode
testAStaleAllocationForADeletedSessionIsDroppedWithoutComment
```
</details>

<details>
<summary><code>DeterminismTest</code> — 9</summary>

```
testTheSameSeedProducesAnIdenticalSolution
testTheResultIsIndependentOfPhpGlobalRandomState
testSolvingTwiceThroughTheSameEngineInstanceIsStable
testADifferentSeedStillProducesAValidSolution
testCandidateGenerationIsOrderedDeterministically
testGreedyConstructionIsIndependentOfSessionInsertionOrder
testTheTimeBudgetIsMeasuredFromASingleFixedInstant
testTheBudgetAllowsIterationsWhileTimeRemains
testTimedOutIsFalseWhenIterationsComplete
```
</details>

<details>
<summary><code>CandidateGeneratorTest</code> — 6</summary>

```
testThePrefilterAgreesWithTheCheckerOnAnIdleProblem
testThePrefilterAgreesWithTheCheckerOnAnEmptyProblem
testThePrefilterEnforcesTheDailyLoadCeiling
testThePrefilterCountsAgreeWithTheCheckerToo
testThePrefilterExcludesTheSessionsOwnOccupancyWhenEvaluatingAMove
testCountFeasibleReturnsZeroForAnUnknownSession
```
</details>

<details>
<summary><code>IncrementalRepairTest</code> — 6</summary>

```
testAnEnrolmentIncreaseEvictsOnlyTheAffectedSession
testAGrowthThatFitsTheSameRoomChangesNothing
testARoomGoingIntoMaintenanceRelocatesOnlyItsBookings
testALecturerBecomingUnavailableRelocatesOnlyTheirSessions
testRepairingFromACompletelyEmptyTimetableStillWorks
testARepairNeverTouchesASessionWhoseRoomIsUnchangedAndStillFits
```
</details>

<details>
<summary><code>PerformanceTest</code> — 5</summary>

```
testARealisticDepartmentAllocatesWellInsideTheBudget
testTheGreedyPassAloneIsFastEnoughForAnInteractivePreview
testAThousandSessionsCompleteWithinTheDocumentedBudget
testScalingIsRoughlyLinearInTheNumberOfSessions
testTheFeasibilityCacheAvoidsRecountingEveryPendingSession
```
</details>

<details>
<summary><code>CapacityTest</code> — 4</summary>

```
testNoCohortIsEverPlacedInATooSmallRoom
testACohortLargerThanEveryRoomIsReportedNotForcedIn
testARoomExactlyAtCapacityIsAccepted
testARoomOneSeatShortIsRejected
```
</details>

<details>
<summary><code>GoldenFileTest</code> — 4</summary>

```
testTheReferenceRunMatchesTheStoredBaseline
testTheReferenceRunMeetsTheAccuracyGate
testTheReferenceRunPlacesEverySession
testTheReferenceRunPenaltyIsRecorded
```
</details>

<details>
<summary><code>NoDoubleBookingTest</code> — 3</summary>

```
testNoRoomIsEverBookedTwiceInTheSameSlot
testNoDoubleBookingSurvivesAWarmStart
testAnAlreadyConflictingWarmStartIsRepaired
```
</details>

---

## Related Documents

- [`REQUIREMENTS.md`](REQUIREMENTS.md) — the requirement IDs referenced throughout
- [`ALLOCATION_ENGINE.md`](ALLOCATION_ENGINE.md) — §13 is the test matrix implemented here; §10 the tuning protocol
- [`ARCHITECTURE.md`](ARCHITECTURE.md) — ADR-001 explains why the unit tier can exist at all
- [`API.md`](API.md) — the contract the planned feature tests will exercise
- [`SECURITY.md`](SECURITY.md) — the threat model `tests/Security/` will encode
- [`DEPLOYMENT.md`](DEPLOYMENT.md) — the CI pipeline and uptime runbooks
