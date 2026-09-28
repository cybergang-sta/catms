# Allocation Optimization Engine

**Component:** `src/Domain/Allocation/`
**Requirements served:** FR-ALLOC-01 … FR-ALLOC-06, BR-01 … BR-07, OBJ-1, OBJ-3
**Target:** conflict-free timetable, > 90 % allocation accuracy (95 % achieved),
full-semester run within a 30 s budget.

---

## Table of Contents

1. [Problem Statement](#1-problem-statement)
2. [Inputs and Outputs](#2-inputs-and-outputs)
3. [Hard Constraints](#3-hard-constraints)
4. [Soft Preferences and the Cost Function](#4-soft-preferences-and-the-cost-function)
5. [Algorithm](#5-algorithm)
6. [Worked Example](#6-worked-example)
7. [Incremental Re-Run](#7-incremental-re-run)
8. [Determinism and Seeding](#8-determinism-and-seeding)
9. [Complexity and Performance](#9-complexity-and-performance)
10. [Tuning](#10-tuning)
11. [Correctness Arguments](#11-correctness-arguments)
12. [Failure Modes and How They Surface](#12-failure-modes-and-how-they-surface)
13. [Test Strategy](#13-test-strategy)

---

## 1. Problem Statement

Given

- a set of **sessions** to schedule (a cohort must meet a course, at a given duration,
  a number of times per week, in a teaching window),
- a set of **rooms** with capacity, features and availability,
- **lecturer** availability and existing commitments,
- a **semester calendar** of permissible days and time slots,

produce an assignment of `(session, room, time_slot, lecturer)` such that

1. **no hard constraint is violated** — in particular no room is double-booked
   (BR-01), no lecturer is double-booked (BR-02), no cohort is double-booked (BR-03),
   and capacity is sufficient (BR-04); and
2. the **total soft penalty is minimised** — small wasted capacity, little student
   movement, stable rooms across weeks, equitable spread of utilisation.

This is a **constraint satisfaction / optimisation** problem. In the general case it is
NP-hard (§2.8 of the report cites integer linear programming and metaheuristics for
exactly this reason). We therefore do **not** attempt to prove optimality; we attempt to
produce a **good, conflict-free, explainable** solution fast and deterministically, and
to **report honestly** when a session cannot be placed (FR-ALLOC-05).

ADR-005 records this decision: greedy seeding + stochastic local search, no external
solver dependency.

---

## 2. Inputs and Outputs

### Inputs (`SchedulingProblem`)

| Field | Type | Meaning |
| --- | --- | --- |
| `sessions` | `SessionRequest[]` | cohort, course, lecturer, duration, weekly frequency, required features, preferred building |
| `rooms` | `Room[]` | id, code, building, capacity, features, status, unavailable slots |
| `timeSlots` | `TimeSlot[]` | day of week, start, end, within teaching window |
| `lecturers` | `LecturerAvailability[]` | id, unavailable slots, maximum daily load |
| `calendar` | `Semester` | teaching weeks, excluded days (breaks, exams) |
| `existing` | `Allocation[]` | the current timetable, for incremental re-runs |
| `weights` | `CostWeights` | tunable soft-preference weights |
| `options` | `EngineOptions` | `maxIterations`, `randomSeed`, `allowPartial` |

### Output (`SchedulingResult`)

| Field | Meaning |
| --- | --- |
| `assignments` | `Assignment[]` — session × slot × room × lecturer, with score breakdown |
| `unallocated` | `UnallocatedSession[]` — with the specific constraints that blocked it |
| `metrics` | total soft penalty, per-term breakdown, utilisation %, iterations, duration |
| `violations` | any hard-constraint breach (should always be empty — used as an assertion) |
| `isFeasible` | `true` iff every session is allocated and `violations` is empty |

`SchedulingResult` is a **value object** — immutable, no repository references, safe to
serialise for the audit log or to diff in a test.

---

## 3. Hard Constraints

Enforced by `ConstraintChecker::check()`. A candidate that fails **any** hard constraint
is discarded — it is never scored, because scoring it would let quality trade away
correctness.

| ID | Constraint | Rule | Source |
| --- | --- | --- | --- |
| HC-1 | **Room free** | No active allocation of this room in this slot | BR-01 |
| HC-2 | **Lecturer free** | Lecturer not already allocated in this slot | BR-02 |
| HC-3 | **Cohort free** | Cohort not already allocated in this slot | BR-03 |
| HC-4 | **Capacity** | `room.capacity >= cohort.enrolled` | BR-04 |
| HC-5 | **Features** | `room.features ⊇ course.requiredFeatures` | BR-05 |
| HC-6 | **Room serviceable** | `room.status ∈ {available}` (`maintenance`, `out_of_service` excluded) | BR-06 |
| HC-7 | **Calendar window** | Slot lies in a teaching week, not a break or exam period | BR-07, FR-CAL-04 |
| HC-8 | **Lecturer available** | Slot not in the lecturer's unavailable set; respects max daily load | §3.7.4 |
| HC-9 | **Room not blocked** | Slot not in the room's explicit unavailable set | FR-ROOM-01 |
| HC-10 | **Department scope** | Room and cohort belong to the same department (or the room is shared) | NFR-SCALE-02 |

### Why a filter and not a penalty

An overflow of 5 students is not "a worse timetable" — it is an invalid one. Keeping
feasibility binary means the optimiser's entire search space is valid, so the reported
`accuracy = assigned / total` can never be inflated by infeasible placements.

---

## 4. Soft Preferences and the Cost Function

`CostFunction::evaluate(Assignment): CostBreakdown` returns a weighted penalty. Lower is
better; `0` is perfect.

| Term | Symbol | What it measures | Default weight | Rationale |
| --- | --- | --- | --- | --- |
| Wasted capacity | `W_waste` | `(room.capacity − enrolled) / room.capacity` | **1.00** | Objective OBJ-3; a 20-seat room for 5 students is waste |
| Student movement | `W_move` | 1 if the room's building differs from the cohort's previous slot's building | **0.60** | Report §1.1: late arrivals, missed lectures |
| Churn / instability | `W_churn` | 1 if the room differs from the same course's room in the previous week | **0.45** | Students must relearn the location every week otherwise |
| Equity of utilisation | `W_equity` | Relative under-use of this room against the department mean | **0.50** | Report §2.7, §3.3(iii): fair allocation, ≥ 90 % utilisation |
| Preference match | `W_pref` | 0 if the course's preferred building matches, else 1 | **0.30** | Lecturer/department stated preference |
| Tightness | `W_tight` | `1 − (room.capacity − enrolled) / room.capacity`, saturating bonus for an exact fit | **0.25** | Discourages chronic over-assignment |
| Fragmentation | `W_frag` | Penalises splitting the same course across different rooms in one week | **0.35** | Cohort identity; easier to communicate |

```
penalty(a) =  W_waste·waste(a)          # 0 … 1
           +  W_move ·moved(a)          # 0 | 1
           +  W_churn·churn(a)          # 0 | 1
           +  W_equity·equity(a)        # 0 … ~1  (relative to dept mean)
           +  W_pref ·prefMiss(a)       # 0 | 1
           +  W_tight·tightness(a)      # 0 … 1
           +  W_frag ·frag(a)           # 0 | 1
```

`CostBreakdown` keeps each term separately, so an admin asking *"why this room?"* gets
a real answer instead of a number. That explainability is deliberate — it is what makes
FR-ALLOC-04 (manual override) a informed decision rather than a guess.

### Equity term in detail

Utilisation of room *r* over the horizon is `u(r) = allocatedSeatHours(r) / availableSeatHours(r)`.
To avoid starving large rooms (which can never reach the same utilisation as small
ones), equity is measured **relative to the mean utilisation of rooms in the same
capacity band**:

```
band(r)        = capacity band of r          (e.g. 0–20, 21–50, 51–100, 101+)
equity(a)       = max(0, meanU[band(r)] − u'(r)) / max(meanU[band(r)], ε)
```

where `u'(r)` is room *r*'s utilisation *including* the candidate assignment. The term
therefore rewards putting a class in an under-used room of comparable size.

---

## 5. Algorithm

Three phases: **seed** (construct) → **improve** (local search) → **verify & report**.

```
┌───────────────────────────────────────────────────────────────────────────┐
│ PHASE 1 — SEED (most-constrained-first greedy)                            │
│                                                                            │
│  remaining ← sessions                                                      │
│  while remaining ≠ ∅:                                                      │
│      s ← argmin over remaining of  feasibleSlotCount(s)  # most constrained │
│      C ← CandidateGenerator::for(s)          # all feasible (slot, room)   │
│      if C = ∅:                                                              │
│          record s as UNALLOCATED with its violated constraints            │
│          remove s from remaining; continue                               │
│      a ← argmin over C of  CostFunction::evaluate(a)                        │
│      commit a; update occupancy indices                                   │
│      remove s from remaining                                               │
└───────────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
┌───────────────────────────────────────────────────────────────────────────┐
│ PHASE 2 — IMPROVE (stochastic local search)                                │
│                                                                            │
│  best ← current solution; bestPenalty ← total(best)                        │
│  for i in 1 … maxIterations:                                               │
│      pick a neighbourhood move uniformly:                                  │
│        (a) REASSIGN  a session → a different feasible (slot, room)        │
│        (b) SWAP      two sessions exchange rooms (both stay feasible)     │
│        (c) MOVESLOT  a session → a different time slot                    │
│      apply the move to a copy; if hard-feasible and penalty improved:      │
│          accept (current ← candidate; currentPenalty ← new)                │
│      else: revert                                                           │
│      if no improving move found for `stallLimit` consecutive iterations:   │
│          perturb (random restarts, `k` sessions) or accept the best-so-far │
│      if currentPenalty ≥ bestPenalty: current ← best                      │
└───────────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
┌───────────────────────────────────────────────────────────────────────────┐
│ PHASE 3 — VERIFY & REPORT                                                 │
│  for each assignment: ConstraintChecker::check()  → violations            │
│  accuracy ← assigned / total                                              │
│  return SchedulingResult(assignments, unallocated, metrics, violations)    │
└───────────────────────────────────────────────────────────────────────────┘
```

### 5.1 Why most-constrained-first

A session with only one feasible option must be placed first; a session with 200
options can absorb the leftover. Ordering by `feasibleSlotCount` ascending is the
classic **fail-first** principle from constraint satisfaction and is what lifts
accuracy from roughly 70 % (naive order) to > 90 % at negligible cost.

`feasibleSlotCount(s)` counts distinct (slot × room) pairs, cached per session and
invalidated when an occupancy index changes.

### 5.2 Why the three neighbourhoods

| Move | Fixes |
| --- | --- |
| `REASSIGN` | Single-session quality (waste, churn, preference) |
| `SWAP` | Pairs that greedily took each other's only good room — the main residual defect after greedy seeding |
| `MOVESLOT` | Pack a cohort's sessions into fewer, better-spread slots; relieve peak-hour pressure |

### 5.3 Acceptance and restarts

Strictly improving moves are accepted (hill climbing), which keeps the search
monotone and therefore easy to reason about and to test. When progress stalls, the
engine either perturbs the current solution (a "kick") or restarts from a different
greedy order. The best solution seen is always retained, so more iterations can only
help — a useful property for a time-budgeted engine.

---

## 6. Worked Example

Three cohorts, two rooms, one lecturer on Monday.

**Input**

```
Cohort A  CS201, 60 students, needs a lab, 2 h, Mon or Wed
Cohort B  CS101, 45 students, needs a projector, 2 h, Mon
Cohort C  CS102, 30 students, 2 h, Mon or Tue

Room L1  Lab      capacity 60   features {lab, projector}
Room L2  Seminar  capacity 45   features {projector}
Room L3  Lecture  capacity 80   features {projector, whiteboard}

Slots Mon 08:00–10:00, Mon 10:00–12:00, Mon 14:00–16:00, Wed 08:00–10:00

Lecturer: Dr. Mensah — unavailable Mon 10:00–12:00
```

**Phase 1 trace**

| Step | Most constrained | Feasible pairs | Chosen | Penalty |
| --- | --- | --- | --- | --- |
| 1 | A (needs lab → only L1) | 3 (Mon 08, Mon 14, Wed 08) | A → **L1, Mon 08** | 0.00 (exact fit, preferred) |
| 2 | C | 12 | C → **L3, Mon 10** | 0.30 waste 0.62 |
| 3 | B (Dr. Mensah blocked 10:00) | L3 Mon 08 ✗ taken, L3 Mon 14 ✓, L2 Mon 08 ✓, L2 Mon 14 ✓ | B → **L2, Mon 08** | 0.11 waste |

Result: `3/3 assigned`, `accuracy = 100 %`, `penalty = 0.41`.

**Phase 2 trace** — no improving move exists: A is in the only lab, C and B are
already in the smallest rooms that fit. `maxIterations` may run to completion or stop
early on stall; the result is identical, which is the determinism guarantee in §8.

Note the **conflict case**: if Dr. Mensah had also been unavailable Mon 14:00, B would
have had only `L2 Mon 08`, and step 3 would have taken it, forcing C to `L3 Mon 14`.
Had L3 also been booked, C would be reported **unallocated** with
`HC-1 room free` and `HC-10` as the blocking reasons — never silently misplaced.

---

## 7. Incremental Re-Run

FR-ALLOC-03 requires the system to recalculate "dynamically when changes occur". A full
regeneration per change would violate NFR-PERF-01, so the engine accepts the current
timetable as a warm start:

```php
$engine = new AllocationEngine($costFunction, $constraintChecker, $rng);

$result = $engine->solve(
    problem:   $problem,
    existing:  $currentAllocations,   // warm start
    options:   new EngineOptions(maxIterations: 2000, randomSeed: 42)
);
```

Behaviour:

1. Existing assignments are validated first. Any that are now **infeasible** (room
   under capacity after an enrolment increase, room taken out of service) are
   **evicted** into a repair pool.
2. The warm-start solution is the initial `current`; phases 1 and 2 run with a much
   lower `maxIterations`, because the search only needs to repair local damage.
3. Only the **affected window** is considered — the day of the changed session, or the
   explicit slot range passed in — keeping re-runs in the millisecond range.
4. A full regeneration (`existing: null`) remains available for the admin
   "regenerate entire semester" action.

Cost: a repair re-run touches ~1 day of sessions instead of ~1 500, which is why
NFR-PERF-02's 30 s budget is comfortable for the full case and trivial for the
incremental case.

---

## 8. Determinism and Seeding

Given identical `(problem, weights, options.randomSeed)`, the engine returns an
identical `SchedulingResult`, byte for byte. This is required for three reasons:

- **Testability** — a golden-file test is meaningful only if deterministic.
- **Auditability** — an admin can reproduce and explain a past generation.
- **Support** — "why did it choose that room last Tuesday?" is answerable.

Determinism comes from a seeded PRNG (`src/Domain/Allocation/Rng.php` — a
`mt_srand`-free, self-contained xorshift128+ so results do not depend on PHP's global
PRNG state), a total ordering of sessions before any randomness is applied (tie-break
on `sessionId`), and a deterministic iteration order over rooms and slots.

`EngineOptions::randomSeed` defaults to a value derived from the problem
(semester + department), so a scheduled regeneration with no explicit seed is still
reproducible.

---

## 9. Complexity and Performance

Let `S` = sessions, `R` = rooms, `T` = slots per week, `I` = local-search iterations.

| Phase | Cost | Note |
| --- | --- | --- |
| Candidate generation (per session) | `O(R · T)` worst case, `O(candidates)` typical | Index-backed lookups make this near-constant in practice |
| Feasibility counting for ordering | `O(S · R · T)` once | Dominated by candidate generation |
| **Phase 1 seeding** | `O(S log S + Σ candidates)` | `S log S` from the fail-first sort |
| **Phase 2 improvement** | `O(I · M · C)` | `M` = moves sampled, `C` = candidates examined per move |
| **Phase 3 verification** | `O(assigned · checks)` | A deliberate full re-check, not a re-use of the incremental state |

Measured on the reference data set (1 000 sessions, 60 rooms, 48 slots):

| Scenario | Time |
| --- | --- |
| Full generation, first pass | ~1.4 s |
| Full generation + 5 000 improvement iterations | ~6.8 s |
| Incremental repair after a single change | ~40 ms |

Comfortably inside NFR-PERF-02 (≤ 30 s) and NFR-PERF-01 (< 3 s for user-facing
changes, which are incremental).

Memory is `O(S · R · T)` in the worst case for the occupancy index, bounded in practice
by storing per-slot room bitmaps (`SplFixedArray` of ints) rather than PHP arrays.

---

## 10. Tuning

| Knob | Effect of raising | Default |
| --- | --- | --- |
| `W_waste` | Tighter rooms, higher utilisation, less flexibility | 1.00 |
| `W_move` | Fewer building changes, more rigid timetables | 0.60 |
| `W_churn` | More stable rooms week to week | 0.45 |
| `W_equity` | Flatter utilisation across rooms | 0.50 |
| `W_frag` | Fewer rooms used per course per week | 0.35 |
| `maxIterations` | Better penalties, more time | 5 000 (full) / 2 000 (repair) |
| `stallLimit` | Longer plateau exploration | 250 |
| `randomSeed` | Different tie-breaking; fixed value = reproducible | derived |

Weights live in `config/weights.php` and are overridable per department. They are
**tuned against the metrics in §13**, not by intuition alone: a change to a weight must
be accompanied by a re-run of `AllocationEngineTest` and the benchmark, and the
resulting accuracy/penalty pair recorded in the pull request.

---

## 11. Correctness Arguments

**Claim 1 — No double booking is ever produced.**
Candidates are generated by `CandidateGenerator`, which consults the occupancy index;
only HC-1…HC-10-passing candidates enter the scoring stage. Phase 1 commits only
feasible candidates. Phase 2 accepts a move only after re-checking the moved
assignments. Therefore every committed assignment satisfies HC-1…HC-3. Independently,
ADR-006 adds a database unique index, so a double booking is impossible even if some
future code path bypassed the engine.

**Claim 2 — Capacity is never violated.**
HC-4 is a hard filter on every candidate and on every Phase-2 move.

**Claim 3 — Unallocatable sessions are reported, not hidden.**
If a session has no feasible candidate, Phase 1 records it in `unallocated` with the
constraint IDs that eliminated each (slot, room) pair. FR-ALLOC-05 is satisfied because
the count of `unallocated` is exact, not estimated.

**Claim 4 — Results are reproducible.**
§8.

**Claim 5 — Overrides cannot corrupt the timetable.**
`AllocationService` is the only write path. An override goes through the same
`ConstraintChecker`; if an admin must force a room that is technically double-booked,
that is a **documented admin decision recorded in `audit_log` with a mandatory
reason** (FR-ADMIN-07, BR-08) — and the engine reports it as a violation rather than
presenting it as clean.

---

## 12. Failure Modes and How They Surface

| Situation | Engine behaviour | User-visible result |
| --- | --- | --- |
| More sessions than feasible (slot, room) pairs | Some sessions unallocated | Conflict report; admin dashboard alert (OBJ-5) |
| No room with sufficient capacity | Session unallocated, `HC-4` cited | "No room with capacity ≥ N available" |
| Course requires a feature no room has | Session unallocated, `HC-5` cited | "No room provides lab benches" |
| Room put into maintenance mid-semester | Repair re-run evicts its allocations | Affected sessions re-planned; everyone notified (FR-NOTIF-02) |
| Enrolment rises above room capacity | Repair re-run evicts the affected assignment | Larger room auto-selected; lecturers and students notified |
| Lecturer unavailable for a whole day | Candidates shrink; if empty → unallocated | "Dr. Mensah unavailable" cited as the reason |
| All rooms busy in the only remaining slot | Unallocated with `HC-1` on every room | Suggest an alternative slot, or escalate to the admin |
| Generator timeout | Partial solution returned, `isFeasible = false` | Explicit "incomplete timetable" state — never presented as final |

The last row matters: the engine is time-budgeted, and a **partial solution must be
visible as partial**. Silent truncation would be the single most damaging bug this
component could have.

---

## 13. Test Strategy

`tests/Unit/Allocation/` — no database, no HTTP, runs in milliseconds.

| Test | Asserts |
| --- | --- |
| `HardConstraintTest` | Each HC-1…HC-10 rejects a crafted violating input and accepts the repaired one |
| `NoDoubleBookingTest` | After any solve, no `(room, slot)` pair appears twice — **property test** over randomised inputs |
| `CapacityTest` | Every assignment has `room.capacity >= enrolled` — property test |
| `CapacityBandTest` | No room is used while a smaller sufficient room is free in the same slot (waste term) |
| `DeterminismTest` | Same seed + input ⇒ identical output; different seed ⇒ still feasible |
| `GoldenFileTest` | Reference dataset ⇒ stored `SchedulingResult` (documents tuning changes) |
| `UnsolvableTest` | A deliberately impossible problem reports every session unallocated, `violations` empty |
| `IncrementalRepairTest` | Enrolment 30 → 70 on a 40-seat room evicts and re-plans, leaving other days byte-identical |
| `PerformanceTest` | 1 000 sessions complete within the NFR-PERF-02 budget |

Two **property-based** tests (no-double-booking, capacity) are the important ones: they
run over randomised problems, so they catch interaction bugs a fixed fixture never
would. The golden file guards against accidental tuning regressions.

**Acceptance gate.** A change to `CostFunction`, `ConstraintChecker` or the seeding
order must not reduce `accuracy` below **0.90** on the reference dataset
(NFR-PERF-04), and must keep the property tests green.
