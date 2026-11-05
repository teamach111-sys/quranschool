SCHOOL MANAGEMENT APP (quranschool)
ROLE RESPONSIBILITIES AND NAVIGATION GUIDE
Tailored to the database schema (schools, roles, users, academic_years, rooms,
students, room_teachers, schedules, attendance, memorization_sessions,
payments, role_change_history)

Purpose
-------
The application uses one shared system with role-based access. Each user sees
the pages and records relevant to their role. The most important rule is that
permissions must be enforced by the system (server-side checks against the
user's role), not only hidden from the navigation menu.

The three roles are stored in the `roles` table (role_key: 'admin',
'teacher', 'secretary') and every user holds exactly ONE role via
`users.role_id`:
- Administrateur (admin): manages the whole school and sees the complete
  picture.
- Enseignant (teacher): manages teaching activities for the rooms they are
  assigned to through `room_teachers`.
- Secrétariat (secretary): manages students, attendance follow-up, and
  payments.

Two structural facts shape everything below:
1. Multi-school ready: every table carries `school_id`. The signed-in user's
   `users.school_id` scopes every query; no user ever reads or writes rows of
   another school.
2. Academic-year scoping: `academic_years` has one `is_current` year per
   school. A persistent year selector filters rooms, students, schedules,
   attendance, memorization sessions and payments by `year_id`. The current
   year is the default; admins may browse earlier years read-only.

============================================================
1. ADMINISTRATEUR
============================================================

Dashboard: Administrateur
-------------------------
The administrator sees a school-wide overview for the selected year:
- Total students (count of `students` with status 'active' for the year)
- Number of active staff (count of `users` with `is_active = TRUE`)
- Active groups (count of `rooms` with `is_active = TRUE`)
- Attendance summaries (share of present/absent/late/excused in `attendance`)
- Upcoming schedule items (next `schedules` slots by `day_of_week`)
- Payment and finance summaries (sum of `payments` by status
  'paid'/'due'/'late'/'waived')
- Important alerts: overdue 'late' payments, students with repeated absences,
  rooms without an active teacher in `room_teachers`, inactive users with
  pending items

École
-----
The administrator can:
- View and edit the single `schools` row: name_fr, name_ar, address, phone,
  email, currency (MAD default)

Années scolaires
----------------
The administrator can:
- Create academic years (`academic_years`: year_label, start_date, end_date)
- Open or close a year (`is_active`)
- Set the current year (`is_current`) — the database enforces one current
  year per school; switching the selector re-points every year-scoped page
- Review information from previous years (read-only)
- The system prevents editing records belonging to an inactive/closed year

Élèves
------
Students are enrolled per academic year: a returning student gets a NEW row
the year they re-enroll (the schema's documented convention). There is no
'archived' status; students are marked 'paused' or 'withdrawn'.

The administrator can:
- View all students for the selected year (all `students.status` values)
- Create, edit student records, and set status to 'active', 'paused', or
  'withdrawn' (paused/withdrawn play the role of archiving; rows are never
  deleted so historical reports stay accurate)
- "Restore" a student by setting status back to 'active' (same-year row) or
  by creating the new-year enrollment row
- View each student's groups (`room_id`), attendance, schedule, and payment
  information
- Move students between groups (update `students.room_id` within the same
  year; the composite FK guarantees the room belongs to the same school/year)
- Correct student information when necessary (notes, guardian details)

Groupes & attribution
---------------------
"Groups" are the `rooms` table (recreated per academic year, with level
beginner/intermediate/advanced, age_min/age_max, capacity).

The administrator can:
- Create, edit rooms, and archive them (`is_active = FALSE`) rather than
  delete — `students.room_id` and FKs reference rooms with ON DELETE RESTRICT
- Delete a room only when nothing references it (no students, schedules,
  assignments); otherwise archive
- Assign teachers to rooms: insert `room_teachers` rows with assigned_from /
  assigned_until (NULL = open-ended). The application must, inside a
  transaction:
  * validate that the assigned user has role_key 'teacher' and is_active
  * reject overlapping assignment ranges for the same teacher
- See every room and every teacher assignment for the year
- End an assignment by setting `assigned_until` (history is preserved; Bilal
  Sabri's past assignment in the seed data is the model)
- Change assignments when staff or schedules change

Emploi du temps
---------------
Timetable = `schedules`: weekly slots per year (day_of_week 1=Monday..7=Sunday,
starts_at/ends_at, valid_from/valid_until, label such as 'Hifz', 'Revision',
'Muraja'a').

The administrator can:
- View the complete school timetable
- Create and edit schedule slots (room, teacher, day, times, validity range,
  label)
- The system must reject overlapping slots for the same room or the same
  teacher on the same weekday within overlapping validity dates (CHECK
  constraints cannot detect interval overlaps — this is enforced in
  application logic inside a transaction)
- Publish or unpublish timetable changes by adjusting valid_from/valid_until
- View the timetable by school, teacher, room, or day (all four views come
  from the same table)

Présences
---------
Attendance = one record per student per day per year
(`uq_attendance_student_day`: student_id, year_id, attendance_date), with
status 'present'/'absent'/'late'/'excused', an optional note, and `recorded_by`
pointing at the user who marked it.

The administrator can:
- View attendance for the entire school
- Filter by academic year, room, teacher (via room_teachers), student, and
  date
- Correct any attendance record (the unique key means a correction updates
  the existing row; a re-marked day must not create a duplicate)
- Review repeated absences and lateness
- See attendance summaries and reports

Note: `attendance` keeps only `created_at`, with no updated_at or edit log.
If a full audit trail of corrections is required, add an audit table or
column; otherwise the admin correction simply overwrites the teacher's entry.

Séances de mémorisation
-----------------------
Memorization sessions = `memorization_sessions`: surah_start/ayah_start →
surah_end/ayah_end plus a required `portion_label`, and evaluation
'excellent'/'good'/'needs_review'.

Portion convention (from the schema): a session that ends exactly at the end
of a surah is recorded with surah_end = that surah and ayah_end = its last
ayah number; cross-surah ranges (e.g. "fin Sourate 2 -> début Sourate 3")
live in portion_label.

The administrator can:
- View all memorization sessions
- Review sessions by teacher, room, student, or date
- Monitor progress across the school (surah/ayah ranges, evaluation mix)
- Correct or manage records when necessary
- See school-wide progress summaries

Utilisateurs & rôles
--------------------
Users are soft-deactivated (`users.is_active = FALSE`), never deleted — every
reference to users is ON DELETE RESTRICT because of recorded_by / teacher_id.

The administrator can:
- Create user accounts (full_name, email unique, password_hash, phone,
  language 'fr'/'ar')
- Deactivate and reactivate accounts (`is_active`)
- Assign the role via `users.role_id` — Administrateur, Enseignant, or
  Secrétariat (exactly one role per user; the three roles are fixed rows in
  `roles`)
- Connect a user to teaching work by creating `room_teachers` assignments
  (there is no separate staff-profile link column)
- "Reset access" = set a new password_hash (no reset-token table exists) or
  deactivate/reactivate the account
- Control which areas each role can use (this permission matrix is enforced
  in application code on top of role_id; the schema does not store per-page
  permissions)

Historique des rôles
--------------------
`role_change_history` records every role change: user_id, changed_by,
old_role_id (NULL when the role was assigned at account creation),
new_role_id, reason (VARCHAR 255), created_at.

The administrator can:
- View role changes with who made each change and when
- Review previous role assignments
- Use the history for accountability and troubleshooting
- The system writes a row automatically whenever `users.role_id` changes —
  admins cannot bypass it
- Note: deactivating a user is not a role change; record it here with
  old_role_id = new_role_id and a reason (as the seed data does) or keep a
  separate log if stricter separation is wanted

Paiements & finances
--------------------
Payments = `payments`: billing periods (period_start/period_end) per student
and year, amount, currency (school's `schools.currency`, MAD), status
'paid'/'due'/'late'/'waived', paid_at, payment_method (seed uses
'espece'/'virement'/'carte'), reference (receipt numbers REC-2026-xxxx),
notes.

The administrator can:
- View all payments and financial records
- Create billing periods (the unique key
  uq_student_billing_period blocks exact duplicate periods; overlapping
  periods for the same student must be rejected in application logic)
- Edit records and mark payments as received (setting status='paid' must
  set paid_at — the CHECK constraint `status='paid' OR paid_at IS NULL`
  enforces that paid_at is only present on paid rows)
- Mark payments 'waived' with a reason in notes (the seed shows this for a
  paused student)
- Mark 'late' when overdue
- There is no 'refunded' status in the schema: refunds are handled by policy
  (e.g. a new negative-amount row is blocked by `amount >= 0`, so refunds
  should be recorded as waived with a note, or the schema extended) — until
  the schema gains refund support, the app must not offer a "refund" action
- Review unpaid balances ('due' and 'late' by period)
- Filter by student, period, date, or payment status
- View financial summaries and reports
- Delete financial history: not offered (no soft delete exists on payments;
  deleting would break historical reports — prefer status changes)

Mon profil
----------
The administrator can:
- View and edit their own profile (full_name, phone, language preference)
- Change their password (new password_hash)
- Manage personal account preferences (users.language)

ADMINISTRATOR ACCESS SUMMARY
----------------------------
The administrator has full school-wide visibility and management access. They
can see all rooms, all groups, all students, all teachers, all attendance
records, and all finances for their school. Every role change is recorded in
role_change_history automatically.

============================================================
2. ENSEIGNANT
============================================================

Dashboard: Enseignant
---------------------
The teacher sees a personal overview built from their own data:
- Their groups: rooms where they have an active `room_teachers` assignment
  for the selected year (assigned_until IS NULL or >= today)
- Today's lessons: `schedules` slots for their teacher_id matching today's
  day_of_week within the validity range
- Upcoming lessons
- Attendance tasks still to complete (active students in their rooms with no
  `attendance` row for today's session date)
- Recent memorization sessions they recorded (teacher_id = their id)
- Relevant notices

Présences
---------
The teacher can:
- See attendance for students in their assigned rooms
- Mark students present, absent, late, or excused (the four status values)
- Add a short note (attendance.note, up to 500 chars)
- Edit their own attendance records (rows they created as recorded_by)
  during the allowed correction period
- View attendance history for their assigned students

The system must enforce (not just hide):
- Attendance reads/writes are limited to students whose room is in the
  teacher's active room_teachers assignments for the selected year
- The teacher cannot change another teacher's records unless an admin
  grants it (recorded_by check)
- One record per student per day per year: re-marking a day updates the
  existing row, never duplicates it

Séances de mémorisation
-----------------------
The teacher can:
- Create memorization sessions for students in their assigned rooms
  (room_id and teacher_id = their own id)
- Record the portion (surah/ayah ranges or portion_label only) and the
  evaluation ('excellent', 'good', 'needs_review')
- Add notes and follow-up information
- View previous sessions for students they teach
- Update their own session records when permitted

The system must enforce:
- Session writes are limited to their assigned rooms' students; teacher_id
  is set to the signed-in teacher
- Sessions of other teachers are read-only or invisible per school policy
- The schema's range checks apply (surah 1-114, ayah 1-286, start <= end);
  the app should validate before insert and show friendly errors
- The teacher cannot change school-wide settings or other teachers'
  assignments

Mes groupes d'élèves
--------------------
The teacher can:
- View the rooms assigned to them (room_teachers for the selected year)
- View the students in those rooms (students.room_id, status 'active' and
  'paused'; withdrawn students hidden by default)
- Open a student profile with information relevant to teaching (full_name,
  birth_date, room, notes)
- Review attendance, schedule, and memorization progress for their students
- Use the group list to begin attendance or a teaching session

The system must enforce:
- The teacher cannot add a student to a room or move students between rooms
  (students.room_id is admin/secretary-only)
- The teacher cannot view students outside their assigned rooms unless
  explicitly authorized

Mon emploi du temps
-------------------
The teacher can:
- View their own timetable (schedules where teacher_id = their id)
- See the rooms, times, and labels assigned to them
- Open a slot to take attendance or record a session
- See schedule changes that affect their lessons (validity ranges)

The system must enforce:
- The teacher cannot edit `schedules` at all
- The teacher cannot assign teachers, rooms, or groups

Mon profil
----------
The teacher can:
- View and edit their own profile (full_name, phone, language)
- Change their password
- Manage personal account preferences

TEACHER ACCESS SUMMARY
----------------------
The teacher works within the rooms assigned to them via room_teachers. Their
access follows those assignments automatically — no manual per-teacher
configuration is needed. They record attendance and memorization sessions but
cannot manage school-wide configuration, users, room assignments, schedules,
or finances.

============================================================
3. SECRÉTARIAT
============================================================

Dashboard: Secrétariat
----------------------
The secretary sees an operational overview:
- Total active students (status 'active' for the selected year)
- New or incomplete student records (recently created rows, missing guardian
  phone/email or student_number)
- Attendance items needing follow-up (recent 'absent' and 'late' records)
- Recent payments (latest payments rows, recorded_by = any)
- Unpaid or overdue balances ('due' and 'late' payments)
- Important administrative alerts

Élèves
------
The secretary can:
- View all student records for the selected year
- Create and edit student records; register new students (new
  students row for the current year, with enrollment_date)
- Set status 'active', 'paused', or 'withdrawn' per school policy
- View contact and administrative details (guardian_name, guardian_phone,
  guardian_email)
- Assign students to rooms (update students.room_id) when this is part of
  the secretary's responsibility
- Search and filter students quickly (by full_name, student_number, room,
  status)

The system must enforce:
- No access to memorization progress writes
- No access to users, roles, or teacher assignments (room_teachers)

Présences
---------
The secretary can:
- View school-wide attendance information
- Search attendance by student, room, date, and status
- Follow up on absences and lateness
- Add administrative notes or contact follow-up details
- Prepare attendance reports

The system must enforce:
- Corrections to a teacher's original record create an audit entry or are
  restricted per policy (attendance has no edit log; require explicit
  policy here)
- No access to attendance configuration reserved for the administrator

Paiements & finances
--------------------
The secretary can:
- View student payment information
- Record payments received (insert payments rows with recorded_by = their
  id, set status 'paid', paid_at, payment_method, reference)
- Update payment status ('due' -> 'paid'/'late'; 'waived' requires a note)
- Search payments by student, period, or status
- View unpaid balances
- Provide payment confirmations or receipts (the reference field carries
  receipt numbers such as REC-2026-0015)
- Prepare finance reports for the administrator

The system must enforce:
- No deletion of payment rows
- No refunds (schema has no refund status or negative amounts)
- No balance changes without a recorded reason (notes)

Mon profil
----------
The secretary can:
- View and edit their own profile
- Change their password
- Manage personal account preferences

SECRETARY ACCESS SUMMARY
------------------------
The secretary focuses on daily administration: students, room placement,
attendance follow-up, and payment collection. They have broad operational
access to operational tables, but no access to users, roles, role history,
school settings, academic years, teacher assignments, schedules, or
destructive financial actions.

============================================================
4. SHARED PAGE RULE
============================================================

Use shared page names where the underlying table is the same, but show
different information and actions based on the user's role:

- Groupes (rooms): teacher sees assigned rooms via room_teachers;
  administrator sees and edits all rooms; secretary sees rooms for
  enrollment and placement only.
- Élèves (students): teacher sees students of their assigned rooms;
  administrator and secretary see all students for the selected year.
- Présences (attendance): teacher records attendance for their rooms;
  secretary follows up across the school; administrator reviews, corrects,
  and manages everything.
- Emploi du temps (schedules): teacher sees their own slots;
  administrator creates and edits all slots; secretary read-only if needed.
- Mémorisation (memorization_sessions): teacher writes for their rooms;
  administrator full access; secretary view only if needed.
- Mon profil (users row of the signed-in user): every role sees their own
  profile with the same basic account actions.

Use separate pages when the purpose is genuinely different:

- Administrator dashboard: school-wide performance and management.
- Teacher dashboard: today's teaching work and assigned rooms.
- Secretary dashboard: registrations, attendance follow-up, and payments.
- Utilisateurs & rôles (users + roles): administrator-only page.
- Historique des rôles (role_change_history): administrator-only page.
- Paiements (payments): administrator and secretary, with different actions.
- Années scolaires (academic_years) and École (schools):
  administrator-only editing, read-only for others.

============================================================
5. PERMISSION LEVELS
============================================================

Each page defines what a role can do:
- View: can see the information.
- Create: can add a new record.
- Edit: can change an existing record.
- Archive: can set is_active = FALSE (rooms, users) or status = 'paused'/
  'withdrawn' (students) instead of deleting.
- Delete: should be rare and restricted (rooms only when unreferenced).
- Approve: can confirm a sensitive action (e.g. marking payments 'waived').
- Export: can download a report, when allowed.

Recommended default permissions (per selected academic year):

Area                         Administrateur   Enseignant       Secrétariat
---------------------------  ---------------  ---------------  ---------------
School settings (schools)    View/Edit        No               No
Academic years               View/Edit        View             View
Students (per-year rows)     View/Edit        Assigned rooms   View/Edit
Rooms (groups)               View/Edit        Assigned view    View + place students
Teacher assignments
  (room_teachers)            View/Edit        Own view         No
Timetable (schedules)        View/Edit        Own view         View
Attendance                   Full access      Own rooms, own   School follow-up,
                                              records          note-level edits
Memorization sessions        Full access      Own rooms        View if needed
Users (users)                Full access      Own profile      Own profile
Roles & history
  (roles, role_change_history) Full access    No               No
Payments (payments)          Full access      No               Operational use
Own profile                  Edit             Edit             Edit

============================================================
6. IMPORTANT BUSINESS RULES
============================================================

1. Never rely only on hiding a menu item. The system must also block
   unauthorized access if someone opens a direct page — every server-side
   query filters by the signed-in user's school_id, role_id, and (for
   teachers) active room_teachers assignments.
2. Teachers are connected to rooms through room_teachers rows; their access
   follows those assignments automatically, including the assigned_from/
   assigned_until date ranges.
3. Administrators can see and correct all records of their school; role
   changes are always written to role_change_history, and sensitive
   corrections should be logged the same way.
4. Secretaries have broad operational access, but never access to roles,
   permissions, users, teacher assignments, or destructive financial
   actions.
5. Prefer archiving (rooms.is_active, users.is_active, students.status =
   'paused'/'withdrawn') over deleting, so historical reports remain
   accurate; deletes are blocked by ON DELETE RESTRICT foreign keys anyway.
6. Every sensitive change should record who made it and when: recorded_by
   on attendance and payments, changed_by on role_change_history.
7. The current academic year (academic_years.is_current) is the default
   filter and controls which records are open for editing; inactive years
   are read-only.
8. Use the same page layout when the table is shared, but change the
   filters, records, and available actions for each role.
9. Use separate dashboards for the three roles; they aggregate different
   tables and priorities.
10. Data-entry validations the app must mirror from the schema:
    - attendance: one row per student per day per year (update, never
      duplicate)
    - memorization_sessions: surah 1-114, ayah 1-286, start <= end, and the
      surah-boundary portion_label convention
    - payments: no overlapping billing periods per student; paid_at only
      when status = 'paid'; amount >= 0
    - schedules: no overlapping room or teacher slots on the same weekday
    - room_teachers: assigned user must be an active teacher; no
      overlapping assignment ranges
    - users: unique email; passwords stored as password_hash output only

Recommended overall structure:
- Shared pages: students, attendance, timetable views, memorization
  records, and profile.
- Administrator-only pages: school settings, academic years, users and
  roles, role history, room/teacher assignments, full timetable editing,
  and full finance control.
- Teacher-focused pages: my rooms, my timetable, attendance entry, and
  memorization sessions.
- Secretary-focused pages: student registration, room placement, attendance
  follow-up, and payment collection/search.
