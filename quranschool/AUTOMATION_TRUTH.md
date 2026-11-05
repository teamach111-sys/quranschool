# AUTOMATION TRUTH

This file is the authoritative automation guide for the quranschool app. It
MUST be read in full before any automation work (a recurring-task reduction,
a scheduled job, a wizard, a pre-filled flow) is done.

SCOPE — read this first. This file owns WHAT the app does for the user
without being asked, and WHICH repeated tasks disappear. It does not restate
other truths: `schema.sql` owns what data exists, the role guide owns who
does what, `COMPONENT_CREATION_TRUTH.md` owns HOW things are built,
`UX_TRUTH_v2.md` owns HOW the app FEELS (a UX rule that an automation unit
touches — undo, bilingual, optimistic UI — is enforced by that file, not
this one). An automation unit that violates another truth is not done, by
that truth's rules.

CORE QUESTION for every unit in this file: does this remove a task a staff
member currently does every day / every week / every term, without removing
a human decision the schema or the role guide assigns to a person?

============================================================
1. GOLDEN RULES OF AUTOMATION IN THIS APP
============================================================

1.1. Automate the PREPARATION, never the JUDGMENT. The system may assemble
     the roster, generate the periods, pre-fill the label, and flip a
     date-derived status. It may not decide that a child was absent, that
     money was received, or that a portion was learned well — those are
     human decisions owned by a role (role guide).

1.2. Every automated write has an owner. A row created or changed by
     automation carries the acting user as recorded_by (the staff member who
     triggered it), except scheduled jobs, which stamp the school's dedicated
     system actor (see 7.2). Nothing is ever written with a NULL or guessed
     recorded_by where the schema stores who did what.

1.3. Every automated write is scoped and reviewable. Every insert/update
     carries the signed-in user's school_id and the selected year_id.
     Bulk generated rows (payment periods, rollover copies) land in a
     draft/review state or in a confirmation wizard — the user sees WHAT
     will be created and HOW MANY before it happens, and an undoable toast
     or a summary after (UX truth 7.1). No silent mass writes.

1.4. Never invent data the schema does not carry (UX truth 1.6). If an
     automation needs a field the schema lacks (a default fee, a grace
     period, a system actor), it goes through section 7 — schema extensions
     — first. It is never faked in app constants that pretend to be data,
     except true UX constants (e.g. absence-alert threshold), which are
     declared in section 3 per unit.

1.5. Never auto-delete, never auto-archive. Automation creates, pre-fills,
     updates statuses and labels, and ends date ranges. Deletion and
     archival (students, rooms, users, payments) stay manual and role-gated
     (role guide). A rollover wizard ends assignments by setting
     assigned_until; it never removes rows.

1.6. Money is the strictest domain. The system may generate 'due' periods,
     flip 'due' → 'late' on dates, and number receipts. It may NEVER mark a
     payment paid, set an amount, or waive — without a human pressing the
     action, and then the role guide's rules apply (secretary/admin,
     notes on waived, no refunds, no deletion).

1.7. Past years are untouchable. Every automated write first verifies the
     selected year is the current active year. In past-year mode (UX truth
     U6) every automation is disabled, not merely hidden.

1.8. Scheduled jobs are idempotent. A job run twice in a row produces the
     same state as run once (upsert on the schema's unique keys, recompute
     instead of append). Every job logs what it changed and how many rows,
     in plain bilingual words for the admin dashboard.

1.9. Automation is bilingual like everything else. Toasts, summaries,
     wizard screens, and job reports read correctly in French AND Arabic
     (UX truth 1.2). "3 périodes générées pour Groupe Al-Houda" /
     "تم إنشاء 3 فترات لمجموعة الحدى" — never one language, never raw codes.

1.10. One unit at a time (component truth rule). Each numbered unit below is
      delivered as a discrete reviewable piece with its acceptance check
      passed, then STOP for the user's verdict.

============================================================
2. THE REPEATED-TASK INVENTORY (WHAT IS AUTOMATABLE)
============================================================

Audited against the role guide, these are the tasks staff currently repeat
by hand. Priority = pain × frequency.

  Domain        Repeated manual task today                         Units
  ------------  -------------------------------------------------  ------
  Attendance    Look up the room's students, mark each one,
                re-marking risks duplicates                        A1–A6
  Payments      Create the same monthly period per student by
                hand; chase due rows and flip them late by hand;
                type receipt numbers and dates                     P1–P5
  Memorization  Type surah/ayah labels by hand; recompute
                progress; search for the next portion              M1–M4
  Students      Invent/assign student numbers; re-type guardian
                data at re-enrollment; hunt incomplete records     S1–S3
  Academic year Once a year: recreate rooms, schedules, teacher
                assignments by hand, re-enroll everyone            Y1–Y2
  Schedules     Manually detect slot conflicts; find today's
                sessions for attendance                            SC1–SC2
  Dashboards    Manually scan for overdue payments, repeated
                absences, teacherless rooms, incomplete records    G1–G2
  Accounts      Remember to log role changes                       R1

Quick wins (small effort, daily relief): A1–A3, M2, P3, S1, G1.
Big wins (annual relief, wizard-scale): Y1, P1, SC1.
Everything else sits in between.

============================================================
3. AUTOMATION CATALOG
============================================================
Each unit states its trigger type:
  [on-open]    computed when a screen loads — reads only, no writes
  [on-action]  runs when a user presses a control — writes as that user
  [scheduled]  nightly job — writes as the system actor (7.2), idempotent
  [wizard]     multi-step confirmation flow — writes after explicit review

------------------------------------------------------------
3.1 ATTENDANCE (Présences)
------------------------------------------------------------

A1. Roster pre-fill [on-open]
Opening attendance for a room/slot assembles the list automatically:
active students of the room for the selected year (students.room_id +
status), in name order, with each student's status defaulting to a
DRAFT state. The teacher never searches for students. The draft becomes
real rows only when the teacher presses save (A3).
PASS: opening attendance for Groupe Al-Houda lists its 8 active students
with zero clicks of lookup; paused/withdrawn students are excluded (or
shown separately, per school policy).

A2. One-tap default + exception marking [on-action]
One "Tout présent / الجميع حاضر" control marks the whole draft present in
a single tap; the teacher then flips only the exceptions (absent, late,
excused) with one tap per student. This is the UX truth U1 flow — a full
room of 12 marked in under 60 seconds on a phone.
PASS: 12 students marked, 2 exceptions flipped, saved — under 60s.

A3. Idempotent upsert on save [on-action]
Saving attendance writes one row per student per day per year
(uq_attendance_student_day): re-marking a day UPDATES the existing row
(recorded_by becomes the current marker) and never duplicates. Partial
saves keep previously marked rows.
PASS: marking the same room twice on the same date leaves exactly one row
per student, count unchanged.

A4. Missed-attendance task queue [on-open]
The teacher dashboard automatically lists today's schedule slots
(schedules.day_of_week = today, validity range covering today) that have
no attendance rows yet, each linking straight to A1's screen. The admin
dashboard lists any room/date still unmarked after the session day.
No one has to remember whose attendance is missing.
PASS: a slot skipped in the morning appears on both dashboards the same
evening, one tap from its attendance screen.

A5. Late/absence auto-suggestion [on-action]
When the teacher opens a slot's attendance after the session's scheduled
end (schedules.ends_at), the draft pre-suggests 'late' for students
defaulted present — as a suggestion the teacher confirms, never a silent
write. The suggestion names the session time in plain words.
PASS: opening attendance at 18:00 for a 16:30–17:30 slot shows the late
suggestion with the session time; nothing is saved without the teacher.

A6. Repeated-absence alert feed [on-open]
Students with 3+ 'absent'/'late' records in the trailing 30 days
(threshold is an app constant, declared here: ABSENCE_ALERT_THRESHOLD = 3)
appear automatically in the admin and secretary dashboards' alerts panel,
each alert linking to the exact student (UX truth U5). No manual scanning
of attendance history.
PASS: a student crossing the threshold appears within one dashboard load,
with their recent records one tap away.

------------------------------------------------------------
3.2 PAYMENTS (Paiements & finances)
------------------------------------------------------------

P1. Billing period generator [wizard]
A per-year billing template (see 7.1: period type + default amount) lets
an admin/secretary generate 'due' payment rows for a whole room, level,
or school in one wizard: pick students, review the count and total, then
create. The schema's uq_student_billing_period blocks exact duplicates;
the wizard skips students who already have the period (idempotent).
A student enrolled mid-period gets the remaining periods only.
PASS: generating October periods for 22 active students creates exactly
the missing rows in one review step, skips existing ones, and reports
"18 créées, 4 déjà présentes / تم إنشاء 18، 4 موجودة".

P2. Due → late rollover [scheduled]
A nightly job flips status 'due' → 'late' where period_end plus a grace
period (app constant: LATE_GRACE_DAYS = 7, declared here) has passed.
Date-derived status is bookkeeping, not judgment (rule 1.1) — this is the
one payment status automation allowed. Paid and waived rows are never
touched. The job reports its count to the admin dashboard (1.8).
PASS: a 'due' row 8 days past its period_end is 'late' after the next
night's run; rerunning the job changes nothing.

P3. Receipt + paid_at automation [on-action]
Recording a payment auto-sets paid_at to now, auto-fills payment_method
from the school's last-used method for that student (suggestion, editable),
and generates the reference number REC-YYYY-#### sequentially from the
year's existing references — never typed by hand. Currency comes from
schools.currency (MAD). The CHECK constraint (status='paid' OR paid_at
IS NULL) is satisfied by construction.
PASS: recording cash for a student produces paid_at = now and the next
free REC-2026-#### with zero manual fields beyond amount and method.

P4. Unpaid balances view [on-open]
The secretary's collections view (UX truth U4) is computed on open:
per student, sum of 'due' + 'late' for the year, oldest period first,
with the guardian's phone tappable/copyable and a one-tap "record
payment" straight from the balance row. No one maintains this list by
hand — it IS the query.
PASS: a student with two unpaid periods shows one balance row for both,
with the guardian phone and record action in the row.

P5. Waive guardrails [on-action]
Marking 'waived' requires a note before the action completes (role
guide). The UI and the server both enforce it; the server rejects a
waive without a note even if the client is bypassed.
PASS: a waived row without a note is impossible through the UI or the API.

------------------------------------------------------------
3.3 MEMORIZATION (Séances de mémorisation)
------------------------------------------------------------

M1. Next-portion suggestion [on-open]
When the teacher starts a session for a student, the system computes the
continuation from the student's latest session (surah_end/ayah_end) and
pre-fills the pickers with the next portion, using quran-metadata.json
(UX truth §5). The teacher confirms or adjusts; nothing is ever written
without them. Between-session gaps show plainly ("dernière séance il y a
12 jours / آخر جلسة منذ ١٢ يوماً").
PASS: after a session ending at Al-Baqarah 255, the next-session screen
opens pre-set to Al-Baqarah 256, and continues across the surah boundary
per the portion convention.

M2. Auto portion label [on-action]
On picking start and end, portion_label is pre-filled from
quran-metadata.json (UX truth §5.3b) — the teacher adjusts wording, never
surah/ayah spelling. This is automation of typing, not of judgment.
PASS: no session is ever saved with a hand-typed surah name.

M3. Progress computed on read [on-open]
Surah-level, juz-level, and whole-Quran progress (UX truth §5.3c) are
computed from stored sessions at read time, from the metadata file.
Never stored, never seeded, never estimated (UX truth 5.3c).
PASS: progress shown matches an independent recomputation from the raw
session rows, including the juz exclusive-end rule (Juz 30 inclusive).

M4. Session fields auto-set [on-action]
Creating a session sets teacher_id = signed-in teacher, room_id = the
student's room, session_date = today, and school_id/year_id from context.
The teacher never fills identity fields — only the portion, evaluation,
and notes.
PASS: a new session row carries all identity fields without a single
identity input in the form.

------------------------------------------------------------
3.4 STUDENTS & ENROLLMENT
------------------------------------------------------------

S1. Student number auto-generation [on-action]
On creating a student, student_number is auto-filled QS-YYYY-NNN as the
next free number for the year (max existing + 1). Editable before save;
validated unique per year (uq_student_number_year).
PASS: three students created in a row get three consecutive free numbers,
and a manually typed number that collides is rejected in plain words.

S2. Re-enrollment pre-fill [wizard]
When creating the new-year row for a returning student (the schema's
convention: one row per student per year), the full_name, birth_date,
guardian_name, guardian_phone, guardian_email are pre-filled from their
previous-year row; only room, enrollment_date and status are confirmed.
The wizard finds last year's row by guardian identity or previous
student_number when names are ambiguous — the human confirms the match.
PASS: re-enrolling a 2025-2026 student for 2026-2027 requires typing
nothing already on file, and never silently merges two different children.

S3. Incomplete-record detector [on-open]
The secretary dashboard automatically lists students missing
guardian_phone, student_number, or birth_date (the role guide's "new or
incomplete records"), each linking to the exact student row. Detection
is a query, not a routine.
PASS: a student saved without a guardian phone appears on the dashboard
within one reload and disappears once completed.

------------------------------------------------------------
3.5 ACADEMIC YEAR LIFECYCLE
------------------------------------------------------------

Y1. Year rollover wizard [wizard]
The once-a-year mega-task becomes one guided wizard that prepares the new
year in reviewable steps:
  1. Create the new academic_years row (year_label, dates) — sets
     is_current on save (the DB's one-current-year guarantee holds).
  2. Copy active rooms (rooms) into the new year, with levels and
     capacity editable per row before creation.
  3. Copy schedule slots (schedules) with valid_from = the new year's
     start_date, keeping day/time/label; conflict check (SC1) runs on
     the copies before they are written.
  4. End the old year's room_teachers assignments (assigned_until = old
     year's end_date) and stage the same assignments for the new year
     for review.
  5. List students of the old year grouped by status; the user selects
     who to re-enroll (S2 pre-fill applies per student) — enrollment
     rows are NEVER created without explicit per-student selection
     (rule 1.1: enrollment is a human decision).
Each step shows a count before writing and an undoable summary after.
PASS: from "2026-2027 closes" to "2027-2028 ready" in one wizard, with
every row count visible before it is written and no student enrolled
without a tick.

Y2. Current-year sanity check [scheduled]
A nightly job verifies exactly one is_current active year per school; if
the date has passed the current year's end_date, the admin dashboard
shows a plain alert ("L'année 2026-2027 a pris fin — ouvrir la suivante?")
linking to Y1. The job never flips is_current by itself (a human decides
the year is over — rule 1.1).
PASS: after 2027-06-30 the dashboard shows the alert daily until a human
acts; no year was auto-switched.

------------------------------------------------------------
3.6 SCHEDULES (Emploi du temps)
------------------------------------------------------------

SC1. Live conflict detection [on-open/on-action]
While a slot is drawn or edited, the system checks room AND teacher
overlap for the same weekday within overlapping validity dates (the
schema's documented application-logic rule) and flags the conflict
visibly before save is possible (UX truth U7). Also runs inside Y1 step 3.
PASS: an overlapping slot is visibly flagged and unsavable in both
directions (same room, same teacher).

SC2. Today's sessions derived [on-open]
Attendance entry points and the "today" dashboards are derived from
schedules (day_of_week, validity range, labels) — a slot exists once and
appears everywhere it matters. No one re-enters session dates anywhere.
PASS: adding a Wednesday slot makes it appear on the teacher's dashboard
the following Wednesday with its A1 attendance entry, with no second entry.

------------------------------------------------------------
3.7 DASHBOARDS & ALERTS (all roles)
------------------------------------------------------------

G1. Admin alerts panel [on-open]
Computed on every load, per the role guide's alerts list, each alert
linking to its exact record (UX truth U5):
  - 'late' payments with student and amount (feeds from P2)
  - repeated absences (A6)
  - rooms with no active teacher for the year (room_teachers check)
  - inactive users with pending items (A4 unmarked attendance)
PASS: every alert resolves to the specific record in one tap; the panel
is empty when nothing is wrong — an empty state that says so (UX truth).

G2. Freshness stamp [on-open]
Every automated view shows its freshness (UX truth 4.4): "Données de
16:45". A long-open dashboard can be refreshed with one tap.
PASS: dashboards never silently go stale.

------------------------------------------------------------
3.8 ACCOUNTS & ROLES
------------------------------------------------------------

R1. Role-change history auto-write [on-action]
Every change to users.role_id writes its role_change_history row
(user_id, changed_by, old/new role, reason) inside the same transaction
— the admin cannot bypass it (role guide). Deactivation with a reason
writes old_role_id = new_role_id per the role guide's convention.
PASS: changing a role and then reading the history shows the row without
any second action by the admin.

============================================================
4. WHAT MUST NEVER BE AUTOMATED
============================================================

  - Marking a student absent/late/excused without a human save (A1–A2
    are drafts; nothing writes without the teacher).
  - Marking any payment paid, setting an amount, or waiving (P3/P5 are
    the human's actions; P2 only flips due→late on dates).
  - Creating memorization sessions or evaluations — only M1's suggestion
    is automated.
  - Enrolling a student into a new year without per-student selection
    (Y1 step 5).
  - Deleting or archiving anything (students, rooms, users, payments) —
    rule 1.5; automation never removes rows.
  - Switching is_current on its own (Y2 alerts; a human confirms).
  - Assigning or moving teachers/students between rooms — role-guide
    decisions, only staged for review (Y1 step 4).
  - Any write to a past/closed year (rule 1.7) or to another school's
    rows (rule 1.3).

============================================================
5. SCHEMA-EXTENSION REQUIREMENTS (read before building P1, P2, Y2)
============================================================

These automations need data the current schema does not carry. Per rule
1.4 they are NOT faked in code; the schema gains them first (a migration
following schema.sql's conventions: school-scoped, soft-active, FK targets
on (id, school_id[, year_id])):

  E1. Billing template — for P1. Either schools.monthly_fee DECIMAL(10,2)
      (single-fee simplicity) or a billing_templates table
      (school_id, year_id, level, period_type ENUM('monthly','term'),
      amount). Decide with the user; the wizard consumes it.
  E2. System actor — for P2. One dedicated active user row per school
      (role: admin, full_name 'Système / النظام', non-login) that
      scheduled jobs stamp as recorded_by. Rule 1.2 holds: every row
      still names its author.
  E3. LATE_GRACE_DAYS and ABSENCE_ALERT_THRESHOLD are app constants
      declared in this file (7 and 3); promote them to schema fields
      only if the user asks for per-school tuning.
  E4. Attendance edit audit — only if the user asks for a log of
      secretary/admin corrections to teacher-marked attendance (the role
      guide flags attendance has no edit log). An attendance_audit table
      (before/after status, editor, reason) is the shape; nothing else
      in this file depends on it.

============================================================
6. IMPLEMENTATION MODEL
============================================================

  [on-open] units are queries computed at read time — no writes, no
  caching that can go stale without the G2 stamp.
  [on-action] units run inside the server handler, in a transaction
  wherever the schema's rules demand it (overlaps, duplicates, role
  history). Optimistic UI + undo toast per UX truth 4.3/7.1 for
  reversible writes; server wait for anything money-related.
  [wizard] units are step-confirmed flows with per-step counts (rule
  1.3); each step is a single transaction and independently reviewable.
  [scheduled] units run nightly, idempotent (rule 1.8), scoped per
  school (every school processed independently), stamping E2's system
  actor, and reporting their result count to the admin dashboard.
  Every unit's server-side check mirrors its UI check (role guide rule 1:
  never rely on hiding a control).

============================================================
7. CHECKLIST BEFORE DECLARING ANY AUTOMATION UNIT DONE
============================================================

  1. The unit removes the repeated task named in section 2 — verified by
     doing the task once through the UI without the old manual steps.
  2. Every automated write carries school_id, year_id, and a real
     recorded_by (acting user or system actor).
  3. Bulk or scheduled writes are idempotent: run twice, state unchanged.
  4. Nothing in section 4 was automated.
  5. Any schema need came from section 5 and exists before the unit ships.
  6. Past-year and other-school writes are impossible, not just hidden.
  7. Toasts, summaries, and alerts are correct in French AND Arabic.
  8. Reversible writes are undoable (UX truth 7.1); the UX unit's own
     acceptance criterion (UX truth §9) passes where the units overlap.
  9. The unit's PASS line in section 3 is demonstrated, on a phone.
 10. One unit delivered, then STOP for the user's verdict.
