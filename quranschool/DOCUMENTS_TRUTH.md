# DOCUMENTS TRUTH

This is the authoritative guide for documents, exports, and the unified
action-history page in the quranschool app. Read it before building or
changing a document template, export flow, or action-history component.

## Scope and sources of truth

This file owns:

- What users can export, in which formats, and how exported documents are
  generated.
- How consequential actions are recorded and reviewed in the action-history
  page.
- The safety, access, and acceptance rules for these features.

It does not replace other truths:

- `schema.sql` owns the data that currently exists. Add a schema extension
  before relying on fields or tables that are not there.
- `SCHOOL_MANAGEMENT_APP_ROLE_RESPONSIBILITIES_AND_NAVIGATION_GUIDE.md`
  owns which roles may see and do what.
- `COMPONENT_CREATION_TRUTH.md` owns component structure, shared components,
  direct database access, translation discipline, and the one-component-at-a-
  time workflow.
- `UX_TRUTH_v2.md` owns bilingual behavior, RTL, mobile layout, accessibility,
  and interaction patterns.
- `table_toolbar.md`, when present, owns table-toolbar layout.
- `AUTOMATION_TRUTH.md` owns scheduled and other automated actions.

When rules overlap, satisfy all applicable truths. Do not use this guide to
expand a role's permissions or to claim an unimplemented schema feature exists.

## 1. The problem this guide solves

The app must let an authorized user leave with a usable document or dataset,
not just view it on screen. Export is a normal action on every data page where
the role guide permits the user to view the underlying records.

The app must also answer, for each consequential change: **what happened, to
which record, when, and who did it?** A single read-only action-history page
must make those events reviewable by an authorized administrator.

## 2. Document and export rules

### 2.1 Every eligible page has an export path

Every data page that presents records or a report provides a clearly named,
role-authorized export action. Do not limit exporting to one hidden admin
screen or assume that a browser's Print command is the export feature.

Provide both kinds of output where applicable:

1. **Single-record document:** a downloadable PDF for an individual record
   when a useful, printable document exists (for example a payment receipt,
   student summary, attendance record, or schedule).
2. **List/report export:** a downloadable CSV for tabular data and a
   print-ready PDF for a human-readable report or summary.

The exact documents offered on each page must match its data and the role
guide. Do not invent certificates, official forms, signatures, school
approvals, or other claims the database cannot support. A payment receipt
must represent a recorded payment, not a due, late, or waived period as money
received.

### 2.2 Export the user's actual view

- By default, exports respect the selected academic year, active search,
  filters, sort order, role scope, and teacher room assignments.
- State clearly whether the action exports the current page, all matching
  filtered records, or one selected record. Never imply that a partial page
  is the full result.
- Show the matching record count before a bulk export when practical. Require
  an explicit user action; never download school data automatically.
- An export must use the same server-side authorization and school/year
  scoping as the on-screen query. A modified URL, request, or client-side
  filter must not widen the export.
- Past/closed academic years remain read-only. Read-only does not by itself
  forbid exporting records the role is allowed to view.
- Empty results produce a clear bilingual message and no misleading,
  blank-looking official document.

### 2.3 Formats and document contents

- **PDF** is the default for an individual printable document and a
  human-readable report. Use selectable text where possible; preserve correct
  French and Arabic shaping, readable Arabic fonts, RTL layout, and Western
  numerals as required by the UX truth.
- **CSV** is the default for tabular data intended for spreadsheet use. Use
  UTF-8, stable column headers, correctly escaped values, and explicit date
  and amount formatting that does not silently change the underlying value.
- Use `schools.currency` for money. Never infer a currency from a locale.
- A document/report identifies its subject, school, selected academic year
  when relevant, generation time, and applied date/filter scope. It does not
  expose internal IDs unless needed for a legitimate operational purpose.
- Omit fields the user's role may not view. Do not include passwords,
  password hashes, tokens, secrets, or hidden/private fields in an export.
- Spreadsheet-safe CSV output must prevent formula injection: untrusted cell
  values beginning with spreadsheet formula characters must be safely
  encoded or escaped.
- File names are understandable, safe, and bilingual only where useful; do
  not place private student details in a shared or public URL.

### 2.4 Export UI

- Put a contextual **Export** action on eligible data pages, close to the
  table or report it affects. For tables, follow the table-toolbar truth:
  export is a secondary action after search and filters, not a competing
  primary button.
- For a single record, place **Export PDF** with that record's actions.
- When PDF and CSV are both available, offer a clearly labeled format
  choice; do not make an unlabeled icon the only route.
- Show a pending state on the pressed control. On success, confirm the
  format and scope in a bilingual message. On failure, retain filters and
  offer a retry without generating or claiming a file that does not exist.
- The page and export must remain usable at 360px, in French and Arabic/RTL,
  and with keyboard and screen-reader navigation.

## 3. Role access for exports and action history

Enforce permissions on the server for both page reads and export requests.
Navigation visibility is not authorization.

- **Administrator:** export data and reports visible to the administrator
  for their school; view the school's full action history.
- **Secretary:** export only operational data the role guide permits them to
  view, such as student, attendance-follow-up, and payment information; no
  users, roles, role history, or administrator-only data.
- **Teacher:** export only their permitted teaching data for currently
  assigned rooms and the selected year, such as their students, attendance,
  memorization sessions, and own timetable; no school-wide, payment, user,
  or role data.
- **Action history:** the unified school-wide history page is
  administrator-only, consistent with the existing users/roles/history
  permissions. A teacher or secretary must not gain access by guessing the
  page URL or calling its data endpoint.

Do not expose a broader export merely because a shared table component can
render it. Resolve every export against the role guide's page-level
permissions.

## 4. Action history: what must be recorded

### 4.1 Record consequential actions, not interface noise

Record every successful, persisted business or administrative action,
including at minimum:

- Creating a record.
- Editing a record, with the changed fields and before/after values where
  safe to retain.
- Archiving, deactivating, reactivating, status changes, and other soft
  deletes.
- Deletion, if a role-authorized delete is ever offered.
- Student room placement or move; teacher/room assignments; schedule
  changes; attendance marks and corrections; memorization-session changes;
  payment creation or status/amount changes; academic-year changes; user
  activation and role changes; and school-setting changes.
- Bulk or wizard actions, with the action, affected record count, and enough
  detail to identify the affected set without losing the individual record
  trail.
- Successful exports, including format, page/report, academic year where
  relevant, applied scope or filters, and record count. Do not copy exported
  document contents into the audit record.
- Scheduled/system actions, attributed to the explicitly configured system
  actor, never to a guessed human.

Do not fill the history with page views, focus changes, hover, or every
button click. A failed or cancelled action is not a completed mutation; do
not report it as successful. Security-event logging, such as authentication
failures, is separate and may be added only with a defined, privacy-safe
source and policy.

### 4.2 Minimum event information

Each event must make it possible to answer **who / what / when / which
record**. The schema extension must support, as applicable:

- A unique event ID.
- `school_id` on every event and nullable `year_id` for actions that are not
  year-scoped.
- The authenticated `actor_user_id`, or a documented system actor for
  scheduled work. Resolve the actor from the server-side session; never trust
  an actor ID supplied by the browser.
- Action type (for example `created`, `updated`, `archived`, `exported`),
  entity/table type, and target record ID.
- A server-generated timestamp with a consistent timezone policy.
- Changed-field names and safe before/after values for updates; a clear
  summary for create, archive, export, and bulk events.
- A reason when the existing business rules require one, such as waiving a
  payment or making a sensitive correction.
- A request/correlation identifier where available, to connect a bulk action
  to its events without storing authentication secrets.

Use stable machine-readable action/entity keys in storage and translate
labels at display time. Keep a recognizable actor name in history even if
that account is later deactivated.

### 4.3 Privacy, integrity, and coverage

- Never put passwords, password hashes, tokens, credentials, session secrets,
  or full exported documents in event details.
- Minimize sensitive before/after values. Where full snapshots would expose
  unnecessary child, guardian, or financial information, store changed-field
  names and a safe summary or redacted values instead.
- Audit events are append-only for application users. No normal role can edit
  or delete them; corrections are new events, not rewrites of history.
- Write the business change and its audit event in the same database
  transaction. If the event cannot be recorded, fail the audited mutation
  rather than commit a change that has no actor/history trail.
- Apply `school_id` isolation in both event creation and every history query.
  Never accept a caller-selected school ID as authority.
- Apply `year_id` filters consistently when the selected year is relevant.
  School-wide, year-independent events remain visible with an explicit
  “all years”/not-applicable scope, rather than being silently assigned to
  the selected year.
- Do not let an event from one school identify records or actors from another
  school.

## 5. Schema and implementation requirements

The attached schema is not a complete action audit system. It currently has
`role_change_history`, `recorded_by` on attendance/payments, and timestamps on
some tables; these do not identify every later edit across the application.
Do not claim that `created_at`, `updated_at`, or `recorded_by` alone provide a
complete history.

Before implementing the unified page, extend the database with a
school-scoped, append-only event store (or an equivalently complete,
documented design). The extension must preserve the existing foreign-key and
school-isolation conventions in `schema.sql`. Do not put fabricated audit
events in frontend constants or infer a past actor from the current record.

Keep `role_change_history` working as required by the role guide and
automation truth. Every role change must still write that canonical record
and appear in the unified history, atomically. Do not remove or weaken the
role-history behavior to avoid showing the same event in the new page.

All mutation paths must be covered: UI actions, server/API handlers, imports,
bulk wizards, and scheduled jobs. A log written only from a UI callback is
not sufficient. Centralize audit creation in trusted server-side
data-access/mutation code where possible, while preserving the project's
direct-query architecture. Do not introduce an ORM or repository layer if
the component truth forbids it.

Export generation must query through the same server-side, scoped data
access as the page. Do not generate a PDF/CSV from only whatever rows happen
to be loaded in the browser when the user selected “all matching records.”
Use a maintained, project-compatible generation library if one is needed;
do not add a remote export service that receives school/student data without
explicit approval.

## 6. Action-history page

Add an administrator-only page named **Historique des actions** /
**سجل الإجراءات**. It is a read-only review surface, not an editing tool.

The page must provide:

- A searchable, paginated event list, newest first, with readable actor,
  action, affected record, and exact timestamp.
- Filters for date range, actor, action type, entity, and academic year;
  active filters and result counts are visible.
- A row detail view with safe before/after changes, reason, related record
  link (when still available), and the event scope.
- Useful empty, loading, and error states. Failures must not look like “no
  history.”
- A CSV/PDF export of the currently authorized, filtered event list, itself
  recorded as an export action without recursively creating an event for
  every row in that file.
- An explicit note that older actions are unavailable if the audit system
  was introduced after those records were created. Never backfill or imply
  historical coverage that the data cannot verify.

Use the shared `Table`, `Modal`, and `Palette`; follow the table-toolbar
layout and responsive card behavior. Translate every new string in
`translation.js` in French and Arabic. At narrow widths, show the same key
information without requiring a wide desktop table.

## 7. Component delivery

Implement this feature in discrete reviewable components according to
`COMPONENT_CREATION_TRUTH.md`. Suggested units are:

1. Schema-backed audit event writing for one mutation path, including a
   transaction and its acceptance check.
2. One PDF export path for an eligible individual record.
3. One filtered CSV export path for an eligible table.
4. The read-only action-history table and its filters.
5. Action-history detail and export behavior.

These are suggested units, not permission to build multiple units at once.
Build exactly one component/unit, verify it, then stop for the user's
verdict before proceeding.

## 8. Acceptance checks

Do not declare document/export work complete until the relevant checks pass:

1. An authorized user can download a real single-record PDF where the page
   offers one; it identifies the right school/record and renders correctly
   in French and Arabic/RTL.
2. An authorized table export matches the current year, search, filters,
   role scope, and stated result count; it cannot leak another school's or
   another role's records.
3. CSV opens with correct UTF-8 text, safe cell handling, stable columns,
   and unaltered dates/amounts.
4. A successful export is logged once with its actor, timestamp, format,
   scope, and record count; its file contents are not copied to the audit
   log.
5. A create, edit, archive/status change, and sensitive correction each
   produce the expected event showing the correct human actor and record.
6. Repeating an update produces a new event for each successful action;
   it never overwrites the earlier event.
7. A database failure that prevents an audit write leaves the related
   audited business mutation uncommitted.
8. Role changes appear in the unified page and still create the required
   `role_change_history` row in the same transaction.
9. A teacher cannot read school-wide history or export another room's data
   by changing a URL or request. A secretary cannot export users or roles.
10. The history page is read-only, school-scoped, paginated, searchable,
    bilingual, RTL-safe, keyboard accessible, and usable at 360px.
11. Empty, loading, permission-denied, and failure states are distinct and
    correctly translated.
12. Events from before rollout are identified as unavailable rather than
    fabricated.

## 9. Non-negotiable summary

- Every eligible record/report page has an authorized export path.
- Individual printable documents use PDF; tabular data can be exported as
  safely encoded CSV; reports can also be print-ready PDFs.
- The server enforces the same role, school, room, and year scope for exports
  as for page data.
- Every successful consequential mutation and export records who did it and
  when in a durable, append-only history.
- The action-history page is administrator-only and read-only.
- Extend the schema before relying on fields that do not exist. Never
  fabricate actors, prior state, or historical coverage.
- Follow the other truth files and deliver one component at a time.
