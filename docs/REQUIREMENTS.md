# Requirements Specification

**Project:** Classroom Allocation & Timetable Management System (CATMS)
**Client:** University of Technology and Applied Science (UTAS) — Computer Science Department
**Source:** *Design and Implementation of a Mobile-Friendly Classroom Allocation and
Timetable Management System for the University of Technology and Applied Science*,
Agyapong Junior (2026)
**Status:** Baseline for implementation · v1.0

> Every requirement below carries a stable ID and a citation to its origin in the report.
> IDs are referenced from the code (`@req FR-ALLOC-01`), the tests and the traceability
> matrix in §10. **Do not renumber existing IDs** — retire them instead.

---

## Table of Contents

1. [Stakeholders and Roles](#1-stakeholders-and-roles)
2. [Problem Context](#2-problem-context)
3. [Objectives](#3-objectives)
4. [User Requirements](#4-user-requirements)
5. [Functional Requirements](#5-functional-requirements)
6. [Non-Functional Requirements](#6-non-functional-requirements)
7. [Domain Model & Business Rules](#7-domain-model--business-rules)
8. [Data Requirements](#8-data-requirements)
9. [Out of Scope](#9-out-of-scope)
10. [Traceability Matrix](#10-traceability-matrix)

---

## 1. Stakeholders and Roles

| Role | Code | Description | Primary goals |
| --- | --- | --- | --- |
| Student | `student` | Enrolled learner | See allocated classroom and live timetable; receive change alerts |
| Lecturer | `lecturer` | Academic staff | Manage own teaching schedule; receive schedule notifications |
| Administrator | `admin` | Departmental administrator | Allocate rooms, resolve clashes, manage users/rooms/courses, view analytics |
| *(System)* | `system` | Automated actor | Runs the allocation engine, emits notifications, enforces constraints |

Access is strictly **role-based**. The system is multi-department aware, so every
record that belongs to a department is scoped by `department_id`.

---

## 2. Problem Context

The current (as-is) process is entirely manual:

```
[ Lecturer/Admin ] → draws timetable by hand on paper/spreadsheet
        ↓
[ Notice board + printed notices ] → students/lecturers must physically check
        ↓
[ Last-minute changes ] → re-copy, re-print, re-pin  →  late/incorrect information
```

Documented failure modes (report §1.2, §2.5):

| ID | Failure mode | Consequence |
| --- | --- | --- |
| P-1 | Timetable clashes and overlaps | Students miss lectures; classes overcrowded |
| P-2 | Classroom double-booking | Physical conflict over a teaching space |
| P-3 | Inefficient use of classroom space | Under-utilised rooms, wasted capacity |
| P-4 | Slow communication of venue changes | Late arrivals, uncertainty, erratic information |
| P-5 | No data for planning and analysis | No usage trends, no basis for infrastructure decisions |
| P-6 | Manual process does not scale | As enrolment grows, administration becomes unmanageable |

**Primary case study:** the Computer Science Department at UTAS, piloting
face-to-face lecture allocation.

---

## 3. Objectives

From report §3.3. Each objective has a measurable target.

| ID | Objective | Function | Target |
| --- | --- | --- | --- |
| OBJ-1 | Automate classroom allocation | Dynamically assign rooms from class size, room capacity, lecturer availability | Eliminate double bookings and overlaps |
| OBJ-2 | Real-time update and notifications | Mobile alerts for cancellations and room reallocations | Timely communication; less last-minute confusion |
| OBJ-3 | Improve resource utilisation | Optimise allocation of available classrooms | **≥ 90 %** utilisation efficiency |
| OBJ-4 | Transparency and accessibility | One shared digital timetable for students, lecturers, admins | Improve trust; minimise disputes from allocation errors |
| OBJ-5 | Support administrative decision-making | Reports and analytics on usage, lecturer schedules, peak demand | Data-driven academic planning |
| OBJ-6 | Mobile-first in higher education | Deploy a mobile-friendly solution usable anywhere | Scalable model for other departments |

---

## 4. User Requirements

High-level statements (report §3.6), decomposed into testable functional requirements in §5.

| ID | Requirement |
| --- | --- |
| UR-1 | **Students** access their allocated classrooms and lecture timetable in real time |
| UR-2 | **Lecturers** receive notifications of class schedules / their lecturer timetable |
| UR-3 | **Administrators** allocate classrooms, rectify timetable clashes, notify and update timetables and class allocations |
| UR-4 | The system notifies **all users** of timetable / allocation changes to avoid confusion and missed classes |
| UR-5 | All users can access the system from a **mobile device** |

---

## 5. Functional Requirements

### 5.1 Core allocation and timetabling (report §3.7)

| ID | Requirement | MoSCoW |
| --- | --- | --- |
| **FR-ALLOC-01** | The system shall automate classroom allocation based on available rooms, class sizes and timetable restrictions | Must |
| **FR-ALLOC-02** | The system shall prevent double allocation of a classroom | Must |
| **FR-ALLOC-03** | The system shall automatically assign a classroom using class size, room capacity and lecturer availability, **recalculating dynamically when changes occur** | Must |
| **FR-ALLOC-04** | The system shall allow administrators to select or override allocations when necessary | Must |
| **FR-ALLOC-05** | The system shall detect and report unsatisfiable constraints (conflicts) rather than silently producing an invalid timetable | Must |
| **FR-ALLOC-06** | The system shall track allocation status: `confirmed`, `updated`, `cancelled` | Must |
| **FR-TIME-01** | The system shall generate **weekly and daily** class timetables visible to both students and lecturers | Must |
| **FR-TIME-02** | The system shall allow administrators to reassign class schedules and allocate classrooms dynamically as situations require | Must |
| **FR-TIME-03** | Students can view their allocated classroom and updated timetable | Must |
| **FR-TIME-04** | Lecturers can manage their teaching schedules and class allocations | Must |
| **FR-TIME-05** | Administrators can create and edit schedules | Must |
| **FR-TIME-06** | Users can view **semester** timelines aligned to the academic calendar | Should |
| **FR-ROOM-01** | Users (lecturers/admin) can view available classrooms in real time | Must |
| **FR-ROOM-02** | The system allows comparison of classrooms based on capacity, availability and suitability | Should |
| **FR-ROOM-03** | Administrators can manage classroom data: capacity, availability, special requirements | Must |
| **FR-ROOM-04** | The system ensures rooms are allocated fairly and efficiently, matching class size and course requirement | Must |

### 5.2 Accounts and identity (report §3.7.1, §3.7.2, §4.2.2)

| ID | Requirement | MoSCoW |
| --- | --- | --- |
| **FR-AUTH-01** | Students, lecturers and administrators shall be able to register | Must |
| **FR-AUTH-02** | All three roles shall log in securely with registered e-mail + password | Must |
| **FR-AUTH-03** | The system shall provide a forgot-password / account-recovery flow | Must |
| **FR-AUTH-04** | Sessions shall expire and be refreshable; logout shall invalidate the session | Must |
| **FR-AUTH-05** | Access shall be restricted to authenticated users | Must |
| **FR-PROF-01** | Users can update personal information: name, contact details | Must |
| **FR-PROF-02** | Users can view and (for admins) change assigned roles: Student, Lecturer, Administrator | Must |
| **FR-PROF-03** | Lecturer profiles shall expose assigned courses and teaching schedules | Should |
| **FR-PROF-04** | The system shall prevent overlapping schedules for lecturers handling multiple courses | Must |

### 5.3 Booking, tracking and notification (report §3.7.6, §3.7.7, §3.9.3)

| ID | Requirement | MoSCoW |
| --- | --- | --- |
| **FR-BOOK-01** | Once a class is allocated, a **confirmation notification** is sent | Must |
| **FR-BOOK-02** | New allocations shall be propagated to affected users | Must |
| **FR-NOTIF-01** | Users shall receive in-application notifications about timetable changes | Must |
| **FR-NOTIF-02** | The system shall emit notifications on cancellation and room reallocation | Must |
| **FR-NOTIF-03** | Users can view and mark notifications as read | Must |
| **FR-NOTIF-04** | Notifications shall be scoped by role and by the recipient's own schedule | Must |
| **FR-NOTIF-05** | The notification system shall expose a pluggable channel interface (in-app, e-mail, SMS) | Should |

### 5.4 Academic calendar (report §3.7.8)

| ID | Requirement | MoSCoW |
| --- | --- | --- |
| **FR-CAL-01** | Classroom allocations shall be aligned with the official academic calendar | Must |
| **FR-CAL-02** | Users can view semester timelines (start, end, weeks, teaching/exam periods) | Must |
| **FR-CAL-03** | Administrators can create and edit semesters and academic calendar entries | Must |
| **FR-CAL-04** | Allocations outside a semester's valid teaching window shall be rejected | Must |

### 5.5 Search, reporting and administration (report §3.7.9, §3.7.10, §4.2.2)

| ID | Requirement | MoSCoW |
| --- | --- | --- |
| **FR-SEARCH-01** | Users can search for classrooms (by name, building, capacity, features) | Must |
| **FR-SEARCH-02** | Users can search for class schedules (by course, lecturer, cohort, day) | Must |
| **FR-REPORT-01** | Administrators can monitor classroom utilisation statistics | Must |
| **FR-REPORT-02** | Reports can be generated to analyse peak usage times, utilised rooms and scheduling conflicts | Must |
| **FR-REPORT-03** | Reports shall be exportable (CSV) | Should |
| **FR-ADMIN-01** | The admin can manage users (students, lecturers, staff) | Must |
| **FR-ADMIN-02** | The admin can manage classroom data | Must |
| **FR-ADMIN-03** | The admin can manage courses: create, update, delete (course code, title, credit/scheduled hours) | Must |
| **FR-ADMIN-04** | The admin can manage lecturers: add, update, remove | Must |
| **FR-ADMIN-05** | The admin dashboard shows real-time metrics: active users, pending allocations, room-utilisation status, notifications and alerts | Must |
| **FR-ADMIN-06** | The admin dashboard provides a room-utilisation heat map | Should |
| **FR-ADMIN-07** | Every administrative override and regeneration is written to an audit trail | Must |

### 5.6 MoSCoW summary (report §3.4.1)

| Priority | Contents |
| --- | --- |
| **Must have** | Automated allocation · conflict-free scheduling · notifications · authentication · timetable views · admin CRUD |
| **Should have** | Timetable exports · basic reporting · academic calendar timelines · pluggable notification channels |
| **Could have** | Personalisation options · advanced analytics (utilisation heat maps, predictive demand) |
| **Won't have (phase 1)** | Full SIS integration · standalone native mobile app · drag-and-drop timetable editor · load/stress testing programme |

---

## 6. Non-Functional Requirements

From report §3.8 and Table 4.1.

### 6.1 Security

| ID | Requirement | Verification |
| --- | --- | --- |
| **NFR-SEC-01** | End-to-end encryption in transit (TLS 1.2+) and at rest for credentials | TLS scan; bcrypt hashes only |
| **NFR-SEC-02** | Role-based access control on every endpoint | `tests/Unit/Core/RbacTest.php` |
| **NFR-SEC-03** | Passwords stored as adaptive salted hashes (`password_hash`, bcrypt) | Code review + `AuthTest` |
| **NFR-SEC-04** | Compliance with Ghana's **Data Protection Act, 2012 (Act 843)**: lawful processing, minimisation, retention limits, data-subject rights | `docs/SECURITY.md` §6 |
| **NFR-SEC-05** | Account lockout and rate limiting on authentication endpoints | `tests/Integration/Auth/*` |
| **NFR-SEC-06** | Audit log of all state-changing administrative actions, immutable and attributable | `audit_log` table |
| **NFR-SEC-07** | Input validation and parameterised queries throughout; no client-supplied trust | `ValidatorTest`, static analysis |

### 6.2 Performance

| ID | Requirement | Target | Report result |
| --- | --- | --- | --- |
| **NFR-PERF-01** | Timetable updates and allocation changes returned in **< 3 s** at peak academic hours | < 3 s | **1.8 s** |
| **NFR-PERF-02** | Allocation engine completes a full-semester generate within a bounded time budget | ≤ 30 s for 1 000 sessions | — |
| **NFR-PERF-03** | List/search endpoints respond in < 1 s at p95 | < 1 s | — |
| **NFR-PERF-04** | Allocation accuracy (conflict-free sessions / total sessions) **> 90 %** | > 90 % | **95 %** |

### 6.3 Scalability

| ID | Requirement |
| --- | --- |
| **NFR-SCALE-01** | Support increasing numbers of students, lecturers and administrators **across multiple departments** without performance degradation |
| **NFR-SCALE-02** | All tenant-scoped tables carry `department_id` with a composite index |
| **NFR-SCALE-03** | Stateless API tier — horizontal scaling behind a load balancer |
| **NFR-SCALE-04** | Utilisation computation must not degrade to full-table scans as history grows (materialised daily rollups) |

### 6.4 Maintainability

| ID | Requirement |
| --- | --- |
| **NFR-MAINT-01** | Modular design adopted — feature updates and bug fixes possible **without disrupting ongoing academic activity** |
| **NFR-MAINT-02** | Strict separation of domain / application / infrastructure layers |
| **NFR-MAINT-03** | Domain layer is framework-free and unit-testable without a database |
| **NFR-MAINT-04** | Public API is versioned (`/api/v1`) and backward compatible within a major version |
| **NFR-MAINT-05** | Database changes are versioned, forward-only migrations |
| **NFR-MAINT-06** | Every module is documented and traceable to a requirement ID |

### 6.5 Usability and accessibility

| ID | Requirement | Target | Report result |
| --- | --- | --- | --- |
| **NFR-UX-01** | Fully usable on a mobile device (no horizontal scroll, 44 px touch targets) | 100 % | **100 %** |
| **NFR-UX-02** | User satisfaction (SUS) | > 80 % | **88 %** |
| **NFR-UX-03** | Keyboard navigable, semantic HTML, WCAG 2.1 AA colour contrast, `prefers-reduced-motion` respected | AA | — |
| **NFR-UX-04** | Timetable legible offline once loaded (PWA shell + cached week) | — | — |

### 6.6 Reliability

| ID | Requirement | Target | Report result |
| --- | --- | --- | --- |
| **NFR-REL-01** | Uptime | > 99 % | **99.5 %** |
| **NFR-REL-02** | Database durability — transactional writes, daily backups, tested restore | RPO 24 h / RTO 4 h | — |
| **NFR-REL-03** | Allocation writes are atomic: a failed generation leaves the previous timetable intact | — | — |

---

## 7. Domain Model & Business Rules

### 7.1 Entities

`Department`, `User`, `Semester`, `Course`, `Cohort`(enrolment group), `Enrollment`,
`Room`, `RoomFeature`, `TimeSlot`, `Session`, `Allocation`, `Notification`, `AuditLog`.

Definitions: [`docs/DATA_MODEL.md`](DATA_MODEL.md).

### 7.2 Business rules

| ID | Rule |
| --- | --- |
| **BR-01** | A room may host **at most one** session per time slot. A violation is a double booking. |
| **BR-02** | A lecturer may teach **at most one** session per time slot. |
| **BR-03** | A cohort (class) may attend **at most one** session per time slot. |
| **BR-04** | `room.capacity >= cohort.enrolled_students` for every allocation. |
| **BR-05** | The room must provide every feature the course requires (projector, lab benches, …). |
| **BR-06** | A room with status `maintenance` or `out_of_service` is never allocated. |
| **BR-07** | Session start/end times shall not overlap a `break` or `exam` period in the calendar. |
| **BR-08** | An administrator override is permitted but **must** be recorded with actor, reason and timestamp. |
| **BR-09** | Cancelling an allocation notifies the cohort's students and the lecturer. |
| **BR-10** | Room utilisation % = (sum of allocated seat-hours) / (total available seat-hours in the window) |
| **BR-11** | An allocation is only visible to students enrolled in the cohort, or to the assigned lecturer, or to admins. |
| **BR-12** | Editing a confirmed allocation transitions it to `updated` and re-notifies affected users. |

---

## 8. Data Requirements

Minimum fields the system must hold (derived from §3.7, §4.2.2 figures 4.8–4.12).

| Entity | Required attributes |
| --- | --- |
| User | id, role, e-mail, password hash, first/last name, phone, student index / staff ID, department, status, timestamps |
| Course | id, code, title, description, credit hours, scheduled hours per week, required room features, department, lecturer (default) |
| Room | id, code, name, building, capacity, floor, status, features (many-to-many), department |
| Cohort | id, course, semester, name, enrolled student count, students (many-to-many) |
| TimeSlot | day of week, start time, end time, slot label |
| Allocation | id, cohort, course, lecturer, room, time slot, semester, status, source (`auto`/`manual`), score, override reason, timestamps |
| Semester | id, name, academic year, start/end date, registration/teaching/exam windows |
| Notification | id, recipient, type, title, body, related allocation, read flag, channel, created at |
| AuditLog | id, actor, action, entity, entity id, before/after JSON, ip, user agent, timestamp |

---

## 9. Out of Scope

Explicitly excluded from phase 1 (report §5.2, MoSCoW "Won't-have"):

- Integration with the university's central **Student Information System (SIS)** — recommended as future work; would automate import of student lists, course codes and lecturer assignments.
- **Native** stand-alone mobile application (the PWA covers mobile delivery in v1).
- **Drag-and-drop** timetable editing.
- Full **load/stress testing** programme (recommended, not delivered in v1).
- SMS gateway procurement and e-mail provider contracts (adapters are shipped, providers are configuration).
- Examination seating, grade management, attendance, fee management.

---

## 10. Traceability Matrix

| Report § | Requirement group | IDs | Implementation | Tests |
| --- | --- | --- | --- | --- |
| §3.6 (i) | Student access | UR-1, FR-TIME-03 | `TimetableController`, `TimetableService` | `TimetableTest` |
| §3.6 (ii) | Lecturer notifications | UR-2, FR-NOTIF-01 | `NotificationController`, `NotificationService` | `NotificationTest` |
| §3.6 (iii) | Admin control | UR-3, FR-ALLOC-04, FR-ADMIN-0x | `AdminController`, `AllocationService` | `AdminOverrideTest` |
| §3.6 (iv) | Notify all on change | UR-4, FR-NOTIF-02 | `NotificationService` (event listeners) | `NotificationTest` |
| §3.6 (v) | Mobile access | UR-5, NFR-UX-01 | `public/assets/css/app.css`, PWA manifest | `AccessibilityTest` |
| §3.7 (i) | Automated allocation | FR-ALLOC-01 | `Domain\Allocation\AllocationEngine` | `AllocationEngineTest` |
| §3.7 (ii) | No double allocation | FR-ALLOC-02 | `CandidateGenerator`, DB unique index | `NoDoubleBookingTest` |
| §3.7 (iii) | Weekly & daily timetables | FR-TIME-01 | `TimetableService` | `TimetableTest` |
| §3.7 (iv) | Admin reassign | FR-TIME-02 | `AllocationService::reassign` | `AdminOverrideTest` |
| §3.7 (v) | Search | FR-SEARCH-01/02 | `SearchController` | `SearchTest` |
| §3.7.1 | Registration & auth | FR-AUTH-01…05 | `AuthController`, `Core\Auth` | `AuthTest` |
| §3.7.2 | Profile management | FR-PROF-01…04 | `ProfileController` | `ProfileTest` |
| §3.7.3 | Class & timetable mgmt | FR-TIME-03…05 | `TimetableController`, `AdminController` | `TimetableTest` |
| §3.7.4 | Allocation optimisation | FR-ALLOC-03 | `Domain\Allocation\*` | `AllocationEngineTest` |
| §3.7.5 | Course & classroom selection | FR-ROOM-01…04 | `RoomController`, `AllocationService` | `RoomAvailabilityTest` |
| §3.7.6 | Booking & confirmation | FR-BOOK-01/02 | `AllocationService::confirm` | `BookingTest` |
| §3.7.7 | Tracking & notifications | FR-NOTIF-01…04, FR-ALLOC-06 | `NotificationService`, `Allocation.status` | `NotificationTest` |
| §3.7.8 | Academic calendar | FR-CAL-01…04 | `SemesterController`, `CalendarGuard` | `AcademicCalendarTest` |
| §3.7.9 | Resource management | FR-REPORT-01…03 | `ReportController`, `ReportingService` | `ReportTest` |
| §3.7.10 | Admin dashboard | FR-ADMIN-01…07 | `DashboardController` | `DashboardTest` |
| §3.8 | Non-functional | NFR-* | `docs/ARCHITECTURE.md`, `tests/` | `tests/Unit/Core/*`, `tests/Performance/*` |
| §3.9.1 | Allocation engine | FR-ALLOC-01…06 | `Domain\Allocation\AllocationEngine` | `AllocationEngineTest` |
| §3.9.2 | Web & mobile app | All FR-*, NFR-UX-* | `public/` PWA | `AccessibilityTest` |
| §3.9.3 | Notification system | FR-NOTIF-* | `NotificationService` + adapters | `NotificationTest` |
| §4.2.2 | Screens (fig 4.4–4.12) | FR-ADMIN-*, FR-TIME-* | `public/assets/js/views/*` | Manual/UAT |
| §4.3.1 (v) | Cloud deployment | NFR-SCALE-03, NFR-REL-01 | `docker-compose.yml`, CI | `docs/DEPLOYMENT.md` |
| Table 4.1 | Evaluation criteria | NFR-PERF-*, NFR-REL-*, NFR-UX-* | — | `tests/`, UAT report |
