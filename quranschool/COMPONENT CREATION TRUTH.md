# COMPONENT CREATION TRUTH

This file is the authoritative workflow for building the quranschool app one
component at a time. It MUST be read in full before any component is created.

The companion file `SCHOOL_MANAGEMENT_APP_ROLE_RESPONSIBILITIES_AND_NAVIGATION_GUIDE.md`
defines what each role (admin, teacher, secretary) can see and do per page, and
the database schema lives in `schema.sql`. Both are the source of truth for
behavior and data. This file governs HOW components are built and delivered.

============================================================
1. THE GOLDEN RULE: ONE COMPONENT AT A TIME
============================================================

You create EXACTLY ONE component, then STOP and wait for the user's verdict.

- Do NOT build the next component, the next page, or "the rest of the page"
  after finishing the current one.
- Do NOT scaffold multiple components in a single pass, even if they look
  related.
- After finishing a component, report what it does in plain language and say
  you are stopping for review.
- The user will either approve it ("go to the next component" / "next") or
  tell you to redo it because something is wrong.
- If the user says redo: fix the problem on THAT component only. Do not
  advance.
- Only when the user explicitly says to continue to the next component do
  you start the following one from the list below.

This rule overrides any instinct to finish a page or a feature end-to-end.
Supervised, correct components beat a pile of half-working ones.

============================================================
2. ARCHITECTURE & CONVENTIONS
============================================================

Folder layout (by role)
-----------------------
Components live under role-named folders inside the app folder. Each role
folder has its own `components` folder:

```
app/
  admin/
    components/
  teacher/
    components/
  secretary/
    components/
  shared/
    components/
```

- A component used by ONLY admin goes in `app/admin/components/`.
- A component used by ONLY teacher goes in `app/teacher/components/`.
- A component used by ONLY secretary goes in `app/secretary/components/`.
- A component used by MORE THAN ONE role goes in `app/shared/components/`
  and adapts its content/actions to the current role.

How to decide shared vs role-specific:
- The role guide (section 4 "SHARED PAGE RULE") says which pages are shared
  and how each role's view differs. Follow it.
- If the same table backs a page for multiple roles but the columns, filters,
  or allowed actions differ, the component is SHARED and must branch on the
  signed-in user's role.
- If a page exists for only one role, its components are role-specific.

Direct database querying (NO migrations, NO models)
----------------------------------------------------
This project queries the database DIRECTLY. Do NOT create migrations, ORM
models, repository classes, or an abstraction layer over the queries.

- Read and write by running SQL / direct queries against the database from
  the component's data-access code (server-side / edge-function context).
- Every query MUST scope by the signed-in user's `school_id`. Teachers are
  additionally scoped by their active `room_teachers` assignments for the
  selected year.
- Mirror the schema's CHECK constraints and unique keys in your validation
  before insert/update (overlapping slots, one attendance row per student
  per day, billing-period overlaps, surah/ayah ranges, paid_at rules, etc.).
  The schema enforces them too, but the app must show friendly errors.
- Never hardcode a school_id or year_id. Always pull them from the session
  and the persistent year selector.

Persistent year selector
------------------------
`academic_years` has exactly one `is_current = TRUE` row per school
(enforced by the unique key `uq_one_current_year_per_school`). A persistent
year selector filters rooms, students, schedules, attendance, memorization
sessions, and payments by `year_id`.

- The selected year is the default for every year-scoped page.
- The current year (`is_current`) is the default selected year.
- Admins may browse earlier years; earlier years are READ-ONLY (no create /
  edit on records of an inactive/closed year).
- The selector state is persistent (it survives navigation). Persist it so
  that switching pages keeps the same selected year. Check `schema.sql`:
  `academic_years.is_current`, `is_active`, and the `uq_one_current_year_per_school`
  unique key are what make "one current year per school" enforceable.

============================================================
3. PRE-CODED COMPONENTS YOU MUST REUSE
============================================================

The following are ALREADY built and live in the shared layer. You MUST use
them instead of writing your own versions. Do not reinvent them.

- **Modal** — the shared modal for forms, detail panels, and create/edit
  dialogs. Use it for every create/edit/detail overlay. Do not build ad-hoc
  dialogs.
- **ConfirmDeleteModal** — the shared confirmation modal for destructive
  actions. Use it for EVERY delete and for archive/deactivate confirmations
  that warrant a second click. Deletes are rare and restricted (see role
  guide section 6); when a delete is offered, it goes through this modal.
- **Table** — the shared data table with sorting, filtering, and pagination.
  Use it for every list view. Do not hand-roll `<table>` markup.
- **Palette** — the shared color/spacing/theme system. Use its tokens for
  all colors, spacing, and styling. Do not introduce raw hex colors or a
  parallel theme. Match the existing look exactly.

Before writing a component, confirm where these live (in `app/shared/`)
and import them. If you are about to write something these cover, use the
shared one instead.

============================================================
4. TRANSLATION (REQUIRED AFTER EVERY COMPONENT)
============================================================

The app is bilingual (French / Arabic). UI strings are managed in
`translation.js`.

- After finishing EACH component, update `translation.js` with every new
  user-facing string that component introduced (labels, buttons, column
  headers, modal titles, status badges, empty states, error messages,
  placeholders).
- Add both the `fr` and `ar` entries for each new key. Never leave a
  missing translation.
- Use stable, descriptive keys (e.g. `students.column.fullName`, not `k17`).
- Do not inline French/Arabic strings directly in the component markup —
  route them through `translation.js` so the language toggle works.
- This update is part of "finishing the component." A component is not done
  until `translation.js` is updated for it.

============================================================
5. WHAT GOES IN A COMPONENT (AND WHAT DOESN'T)
============================================================

A component is a PIECE of a page, not the whole page.

- A page is composed of MULTIPLE components (a summary stat card, a filter
  bar, a data table, a create/edit modal, a detail panel, etc.).
- A single component must NOT contain the entire page contents. If a page
  has a header, filters, a table, and a form modal, those are separate
  components.
- Build the smallest coherent unit that does one thing. Examples of good
  components: "StudentsTable", "StudentFormModal", "AttendanceSummaryCard",
  "YearSelector", "RoomTeacherAssignmentTable", "PaymentStatusBadge".
- Shared components adapt to role: e.g. "StudentsTable" for admin shows all
  students with full edit; for teacher shows only assigned-room students
  read-only; for secretary shows all students with registration actions.

Each component below is listed with:
- **Name** — the component to build.
- **For** — which role(s) use it and on which page.
- **Purpose** — what it does and what data it touches.
- **Shared behavior** — if shared, how it differs per role.

============================================================
6. COMPONENT LIST (BUILD IN THIS ORDER, ONE AT A TIME)
============================================================

The order is bottom-up: shared infrastructure first, then role pages.
After each component, STOP and wait for the user's approval.

--- SHARED INFRASTRUCTURE ---

C1. YearSelector
    For: all roles, persistent across every page.
    Purpose: dropdown of academic_years for the signed-in user's school.
      Defaults to is_current year. Switching it updates the persistent
      selection so every year-scoped page re-filters. Read-only display when
      viewing an inactive/closed year (admin only). Other roles are locked
      to the current/active year.
    Shared: yes — same control everywhere; admin may select past years
      read-only, teacher/secretary stay on the current active year.

C2. StatCard
    For: all roles, on each dashboard.
    Purpose: a single summary metric (count, sum, share) with a label,
      value, and optional trend/icon. Reused across the three dashboards
      (total students, active staff, unpaid balances, today's lessons,
      etc.).
    Shared: yes — purely presentational; the parent dashboard passes the
      label, value, and role context.

C3. PageHeader
    For: all roles, top of every page.
    Purpose: page title, optional subtitle, and the slot for the
      YearSelector plus any primary action button (e.g. "Nouvel élève").
    Shared: yes — renders title + actions; role-agnostic layout.

--- ADMIN PAGES ---

C4. AdminDashboard
    For: admin, Dashboard page.
    Purpose: composes multiple StatCards (total active students, active
      staff, active rooms, attendance summary, payment summary) plus an
      alerts list (late payments, repeated absences, rooms without an
      active teacher, inactive users with pending items). Read-only
      overview for the selected year.
    Shared: no — admin only.

C5. SchoolSettingsForm
    For: admin, École page.
    Purpose: view and edit the single schools row (name_fr, name_ar,
      address, phone, email, currency). Direct update query scoped by
      school_id.
    Shared: no — admin only (other roles: no access).

C6. AcademicYearsTable
    For: admin, Années scolaires page (other roles: read-only view).
    Purpose: list academic_years for the school. Admin can create, open/
      close (is_active), and set current (is_current — DB enforces one
      current per school). Past/closed years are read-only.
    Shared: partial — admin gets full CRUD; teacher and secretary get a
      read-only list (no create/edit). Branch on role.

C7. AcademicYearFormModal
    For: admin, Années scolaires page.
    Purpose: create/edit an academic year (year_label, start_date,
      end_date, is_active, is_current). Validate end_date >= start_date
      and the one-current-year rule before writing.
    Shared: no — admin only.

C8. StudentsTable (shared)
    For: admin (Élèves), secretary (Élèves), teacher (Mes groupes d'élèves).
    Purpose: list students for the selected year with search/filter by
      full_name, student_number, room, status. Columns adapt per role.
    Shared: yes —
      admin: all students, all statuses, full edit + status change +
        move between rooms.
      secretary: all students, registration + edit + room placement.
      teacher: only students in their assigned rooms; statuses active and
        paused shown (withdrawn hidden by default); no add/move; read-only
        teaching profile view.

C9. StudentFormModal (shared)
    For: admin and secretary (create/edit); teacher (read-only detail).
    Purpose: create/edit a students row (full_name, birth_date,
      guardian_name/phone/email, room_id, enrollment_date, status, notes).
      Validates required fields; room_id must belong to same school/year.
    Shared: yes —
      admin/secretary: full create/edit.
      teacher: read-only detail of teaching-relevant fields only.

C10. ConfirmDeleteModal usage — Student archive
     For: admin and secretary, Élèves page.
     Purpose: confirm before setting a student status to 'paused' or
       'withdrawn' (archive, never delete — rows are ON DELETE RESTRICT).
       Uses the shared ConfirmDeleteModal with role-appropriate wording.
     Shared: yes — admin and secretary confirm archive; teacher has no
       such action.

C11. RoomsTable (shared)
     For: admin (Groupes & attribution), secretary (placement),
       teacher (Mes groupes — own rooms only).
     Purpose: list rooms for the selected year with level, age range,
       capacity, is_active, and assigned teachers.
     Shared: yes —
       admin: full CRUD + archive (is_active=false) + teacher assignment.
       secretary: view + place students (room_id update) only.
       teacher: only rooms in their active room_teachers; read-only.

C12. RoomFormModal
     For: admin, Groupes & attribution.
     Purpose: create/edit a room (name, level, age_min, age_max, capacity,
       is_active). Validate age_min <= age_max and capacity > 0.
     Shared: no — admin only.

C13. RoomTeacherAssignmentTable
     For: admin, Groupes & attribution.
     Purpose: list room_teachers for the selected year with assigned_from
       / assigned_until. Admin can assign (validate teacher role + is_active
       + no overlapping ranges in a transaction) and end an assignment
       (set assigned_until). History preserved.
     Shared: no — admin only (teacher sees only their own assignment,
       read-only — render via the shared RoomsTable context).

C14. RoomTeacherAssignmentModal
     For: admin, Groupes & attribution.
     Purpose: assign a teacher to a room (select active teacher, room,
       assigned_from, optional assigned_until). Validate teacher role,
       is_active, and overlapping ranges before insert.
     Shared: no — admin only.

C15. SchedulesTable (shared)
     For: admin (Emploi du temps — full), teacher (Mon emploi du temps —
       own slots), secretary (read-only if needed).
     Purpose: list schedule slots by school/teacher/room/day for the
       selected year.
     Shared: yes —
       admin: create/edit all slots; reject overlapping room/teacher slots
         on same weekday within overlapping validity dates (transaction).
       teacher: only their own slots; read-only.
       secretary: read-only.

C16. ScheduleFormModal
     For: admin, Emploi du temps.
     Purpose: create/edit a schedule slot (room, teacher, day_of_week,
       starts_at/ends_at, valid_from/valid_until, label). Validate
       ends_at > starts_at, day 1-7, and overlap rules before write.
     Shared: no — admin only.

C17. AttendanceTable (shared)
     For: admin (Présences — all), teacher (Présences — own rooms),
       secretary (Présences — school follow-up).
     Purpose: list attendance for the selected year, filterable by room,
       teacher, student, date, status.
     Shared: yes —
       admin: view + correct any record (update existing row; never
         duplicate thanks to uq_attendance_student_day).
       teacher: mark/edit only for students in their active rooms; only
         their own records (recorded_by) within correction period.
       secretary: view + add follow-up notes; corrections per policy.

C18. AttendanceEntryModal (shared)
     For: teacher (mark), admin (correct), secretary (note follow-up).
     Purpose: set status present/absent/late/excused + note for one student
       per day. On save, upsert against the unique key
       (student_id, year_id, attendance_date) so re-marking updates, never
       duplicates.
     Shared: yes —
       teacher: full mark for own rooms.
       admin: correct any.
       secretary: note-level edit only.

C19. MemorizationSessionsTable (shared)
     For: admin (Séances de mémorisation — all), teacher (own rooms),
       secretary (view if needed).
     Purpose: list memorization_sessions by teacher/room/student/date with
       portion_label and evaluation.
     Shared: yes —
       admin: full access.
       teacher: own rooms only; create/update own.
       secretary: view only.

C20. MemorizationSessionFormModal (shared)
     For: teacher (create for own rooms), admin (full).
     Purpose: record a session (surah_start/ayah_start, surah_end/ayah_end,
       portion_label, evaluation, notes). Validate surah 1-114, ayah 1-286,
       start <= end, and the surah-boundary convention before insert.
     Shared: yes — teacher: own rooms, teacher_id = self; admin: any.

C21. UsersTable
     For: admin, Utilisateurs & rôles.
     Purpose: list users with role, is_active, language, contact. Admin
       creates, deactivates/reactivates, assigns role, resets access.
     Shared: no — admin only (teacher/secretary: own profile only).

C22. UserFormModal
     For: admin, Utilisateurs & rôles.
     Purpose: create/edit a user (full_name, email unique, password_hash,
       phone, language, role_id, is_active). On role change, write a
       role_change_history row (old_role_id, new_role_id, changed_by,
       reason).
     Shared: no — admin only.

C23. RoleChangeHistoryTable
     For: admin, Historique des rôles.
     Purpose: list role_change_history (user, changed_by, old/new role,
       reason, created_at) read-only. The system writes rows automatically
       on role changes; this is the review view.
     Shared: no — admin only.

C24. PaymentsTable (shared)
     For: admin (Paiements — full), secretary (Paiements — operational).
     Purpose: list payments by student/period/status for the selected year.
     Shared: yes —
       admin: full access, create billing periods, mark paid/late/waived,
         no delete, no refund (schema has no refund status).
       secretary: record payments (status='paid' + paid_at + method +
         reference), update due->paid/late, waived needs a note, no delete,
         no refund.

C25. PaymentFormModal (shared)
     For: admin (full) and secretary (record).
     Purpose: create/edit a payment (period_start/end, amount, currency,
       status, paid_at, payment_method, reference, notes). Validate
       period_end >= period_start, amount >= 0, paid_at only when
       status='paid', and no overlapping billing periods per student.
     Shared: yes — admin: all fields; secretary: operational subset (record
       payment, mark paid/late, waived with note).

C26. AdminDashboardAlerts
     For: admin, Dashboard.
     Purpose: the alerts panel within the admin dashboard — overdue 'late'
       payments, students with repeated absences, rooms without an active
       teacher, inactive users with pending items.
     Shared: no — admin only.

--- TEACHER PAGES ---

C27. TeacherDashboard
     For: teacher, Dashboard.
     Purpose: teacher's personal overview — their groups (active
       room_teachers), today's lessons (schedules for today's weekday),
       upcoming lessons, attendance tasks pending (active students with no
       attendance row for today), recent memorization sessions they
       recorded.
     Shared: no — teacher only.

C28. MyRoomsList
     For: teacher, Mes groupes d'élèves.
     Purpose: teacher's assigned rooms with their students (active + paused;
       withdrawn hidden). Entry point to attendance and session entry.
     Shared: no — teacher only (uses the shared RoomsTable/StudentsTable
       scoped to their assignments).

--- SECRETARY PAGES ---

C29. SecretaryDashboard
     For: secretary, Dashboard.
     Purpose: operational overview — total active students, incomplete
       student records (missing guardian phone/email or student_number),
       attendance items needing follow-up (recent absent/late), recent
       payments, unpaid/overdue balances, admin alerts.
     Shared: no — secretary only.

--- SHARED PROFILE ---

C30. ProfileForm
     For: all roles, Mon profil.
     Purpose: view/edit own users row (full_name, phone, language) and
       change password (new password_hash). Scoped to the signed-in user.
     Shared: yes — identical for all roles; self-edit only.

============================================================
7. REMINDER BEFORE EVERY COMPONENT
============================================================

1. Re-read the relevant section of the role guide for the page this
   component belongs to.
2. Confirm shared vs role-specific and put the file in the right role
   folder (or shared/).
3. Reuse Modal, ConfirmDeleteModal, Table, and Palette. Do not rebuild them.
4. Query the database directly; scope by school_id (and teacher assignments
   for teachers); use the persistent selected year_id.
5. Validate against the schema's CHECK/unique constraints before writing.
6. Update translation.js with every new string (fr + ar).
7. Build ONLY this component, then STOP and wait for the user's verdict.
