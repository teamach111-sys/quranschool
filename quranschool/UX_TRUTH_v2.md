# UX TRUTH

This file is the authoritative UX guide for the quranschool app. It MUST be
read in full before any UX work (a page redesign, a flow improvement, a new
interaction pattern) is done.

SCOPE — read this first. `COMPONENT_CREATION_TRUTH.md` owns HOW things are
built: one component at a time, shared primitives (Modal, ConfirmDeleteModal,
Table, Palette), translation.js discipline, direct schema-backed queries.
This file owns HOW the app FEELS and BEHAVES. It deliberately does NOT
restate any rule the component truth already owns; where that file speaks,
this file only points to it. A UX unit that violates a component-truth rule
is not done, by that file's rules, not this one.

Other sources of truth: the role guide (who sees/does what per page),
`schema.sql` (what data exists), `quran-metadata.json` (section 5 — use of
this file is NOT optional).

============================================================
1. THE GOLDEN RULES OF UX IN THIS APP
============================================================

1.1. Every screen answers, within one glance, three questions: WHERE am I,
     WHAT is the state of my data, WHAT can I do next. Decoration never
     outweighs those three answers.

1.2. Bilingual is not a feature, it is the product. A screen is not done
     until it is correct in BOTH French and Arabic, including RTL layout,
     numerals, dates, and badges. A screen that breaks in Arabic mode is a
     broken screen.

1.3. The phone is the primary device. Staff walk between rooms with a phone
     in one hand. Every screen must be fully usable at 360px width before
     it is checked on desktop.

1.4. The data is Muslim-school data: Quran, children, money. Tone is
     respectful, calm, precise. No gamification gimmicks, no loud colors,
     no emoji clutter in professional views.

1.5. Nothing destructive is ever one click away, and no destructive action
     is ever ambiguous. Reversible actions get UNDO, not confirmation
     (section 7).

1.6. Never invent data the schema does not carry. If a UX state has no
     backing field in schema.sql, it does not get built. (What to build
     with the data is this file's job; whether the data exists is the
     schema's.)

1.7. Error prevention beats error messages. A UX that makes the wrong
     action impossible (picker bounded to valid ayahs, date range that
     cannot invert) is better than a friendly error after the fact.

============================================================
2. SCREENS: HIERARCHY AND SCANNING
============================================================

2.1. One primary action per screen. It is the visually strongest element
     ("Nouvel élève", "Marquer les présences"). Everything else is
     secondary: smaller, quieter, secondary color. Two equal-weight primary
     buttons on one screen is a bug.

2.2. Value first, label second. Numbers are the largest text on stat cards;
     labels are small, muted, bilingual. A dashboard is scannable in three
     seconds without reading a word.

2.3. Maximum 5–7 items in top-level navigation per role, and only pages
     the role guide grants that role. A user never sees a menu item that
     leads to "you do not have access."

2.4. Progressive disclosure. Lists show what is needed to CHOOSE a row
     (name, number, status, one key metric). Everything else lives behind
     the row click in a detail panel — never as more columns crammed into
     a table. Person names stay consistent everywhere: full_name prominent,
     student_number secondary and muted.

2.5. Related actions are grouped where the eye already is: row actions in
     the row, record actions in the record's header, screen actions in the
     page header. The user never hunts for an action in a different region
     than the object it acts on.

2.6. Status is always a badge with a bilingual text label — never bare
     text, never color alone. present/absent/late/excused,
     paid/due/late/waived, active/paused/withdrawn must stay distinguishable
     in grayscale.

============================================================
3. MOBILE-FIRST EXECUTION
============================================================

3.1. Tables collapse to cards on narrow screens: the row's identity and
     status on top, key metrics beneath, actions as a clearly tappable row.
     Column headers never appear on mobile.

3.2. All tap targets are at least 44x44px with real spacing between them.
     Icon-only buttons always carry an accessible name.

3.3. Primary thumb-zone placement: on phones, the screen's primary action
     sits in the lower third (sticky action bar where the flow is linear,
     e.g. marking attendance).

3.4. Inputs match their data: `tel` keyboards for phone numbers, `numeric`
     for amounts and ayah numbers, date pickers pre-set to today. Autofill
     and autocomplete enabled wherever the browser can help.

============================================================
4. MOTION, PERCEIVED SPEED, AND FRESHNESS
============================================================

4.1. Transitions are 150–250ms with ONE easing curve across the whole app.
     Motion communicates: what opened, what changed, where an item moved.
     Anything longer than 250ms feels slow; anything instant feels broken.

4.2. No layout shift on load. Skeletons reserve the real space of tables
     and cards. Content popping in unannounced is a bug.

4.3. Reversible mutations apply optimistically: the row updates instantly,
     then an undoable toast confirms ("Présence enregistrée — Annuler").
     Irreversible or network-dependent writes wait for the server, with a
     pending state on the exact button pressed.

4.4. Dashboards show their freshness ("Données de 16:45") and have a
     visible refresh. Nothing silently goes stale across a long session.

4.5. Never block the whole screen on a slow region. A slow table loads
     inside its own skeleton while the page header and navigation stay
     interactive.

============================================================
5. THE QURAN METADATA FILE — MANDATORY (quran-metadata.json)
============================================================

This section is an instruction to the AI: the site MUST use
quran-metadata.json as part of the UX. It is the single source of Quran
truth for every surah, ayah, and juz shown anywhere in the app.

5.1. Where it lives
-------------------
- Copy `quran-metadata.json` into the project as a static data file and
  load it at build time (~1470 lines; never fetched from a remote API,
  never re-typed by hand).
- All Quran data in the UI derives from this file. NEVER hardcode a surah
  name, an ayah count, or a juz boundary in any component, placeholder,
  or validation message.

5.2. What the file contains (use it exactly as structured)
----------------------------------------------------------
- `meta` — riwaya "Hafs 'an 'Asim", totals (114 surahs, 6236 ayahs, 30
  juz), supported label languages fr + ar.
- `surahs[]` — per surah: `id` (1–114), `name` (transliteration),
  `name_ar`, `name_fr`, `ayah_count`, `revelation`, `juz_start`.
- `juz[]` — per juz 1–30: `label`, `label_ar`, and `start`/`end`
  positions { surah, ayah }. READ THE CONVENTION: a juz `end` is EXCLUSIVE
  (the juz ends just before that ayah), EXCEPT Juz 30, explicitly
  `"inclusive": "inclusive"` at 114:6. All juz math respects this or
  progress will be wrong.

5.3. Where the metadata shows up in UX
--------------------------------------
a) Surah selectors (memorization session entry, progress views):
   - Searchable dropdown listing "id — name — name_ar" (e.g.
     "36 — Ya-Sin — يس"). Arabic names are ALWAYS visible, even in French
     mode — they are how teachers recognize surahs. Search matches
     transliteration, French name, Arabic name, and surah number.
   - The ayah picker for the chosen surah is bounded by that surah's
     `ayah_count` (1..ayah_count). Never a blind 1–286. This is golden
     rule 1.7 in action: the wrong ayah cannot be picked.
   - Pairs are validated with the schema's surah rules (start <= end; the
     cross-surah convention: ending at a surah's last ayah and continuing
     into the next surah is allowed).

b) Portion display and auto-labeling:
   - Recorded portions render as surah names in the UI language with the
     Arabic alongside: "Al-Baqarah 255–286 → Ali 'Imran 1–5". `portion_label`
     remains the stored human-readable string; the metadata guarantees
     correct spelling every time.
   - When start and end are picked, the app pre-fills the portion label
     from the metadata; the teacher can adjust wording but never the
     surah/ayah spelling.

c) Progress tracking (the heart of memorization UX):
   - Per student, cumulative memorized ayahs from their sessions are
     expressed three ways, always computed from the metadata:
       1. Surah-level: complete surahs (covered ayahs = the file's
          ayah_count) vs in progress ("An-Naba 1–20").
       2. Juz-level: complete/in-progress juz, using the file's boundaries
          and the exclusive/inclusive rule above.
       3. Whole-Quran: percent of 6236 ayahs.
   - Progress numbers are never estimated, faked, or seeded.
   - The student view includes a compact surah map (1–114) colored by
     state (complete / in progress / untouched), Arabic names on tap or
     hover, in BOTH languages and both directions (RTL-safe).

d) Validation and friendly errors:
   - Errors quote the real numbers: "L'ayah 287 n'existe pas dans An-Nisa
     (176 ayahs)" — ayah_count comes from the file. Bilingual, plain
     words, never raw codes.

5.4. Metadata changes
---------------------
- If the file is ever updated, every derived UI updates automatically
  because nothing is hardcoded. Never fork the data into other JSON files
  or constants.

============================================================
6. TIME, NUMBERS, AND DATA ORIENTATION
============================================================

6.1. Recency is relative, detail is absolute. Lists say "il y a 2 jours" /
     "منذ يومين"; detail panels show the exact date. "Today" is always
     labeled as today, never as a bare date.

6.2. Numerals stay Western (0–9) in BOTH languages for consistency of
     student numbers, amounts, and ayah references. Dates follow the UI
     language. Currency uses schools.currency (MAD), formatted identically
     in French and Arabic.

6.3. The persistent year selector (component truth C1) must ALSO be
     always-visible in the header of every year-scoped screen — the user
     never has to wonder which year is on screen. Past-year mode shows a
     persistent banner ("Consultation — année 2025-2026 clôturée") and a
     one-tap return to the current year.

============================================================
7. TRUST, REVERSIBILITY, AND RECOVERY
============================================================

7.1. Reversible actions (status flips, edits, marking attendance) use
     optimistic UI + undo toast (4.3). Confirmation is reserved for what
     cannot be undone, and goes through the shared ConfirmDeleteModal with
     wording that names the actual record and states the consequence
     ("Archiver Hiba Sqalli ? — l'élève restera dans l'historique").

7.2. A form never loses the user's input: validation errors keep every
     typed value inline next to the field, in plain bilingual words
     ("Le téléphone du tuteur est requis"); network errors offer Retry, not
     dismissal.

7.3. Errors say what happened, what it means, and what to do. A generic
     "Une erreur est survenue" with no path forward is a bug.

7.4. Every list remembers itself: search text, filters, sort, page, and
     scroll restore when the user goes into a record and comes back. Screens
     are URL-addressable so a view can be shared or bookmarked.

============================================================
8. ACCESSIBILITY (BOTH LANGUAGES, BOTH THEMES)
============================================================

8.1. Text contrast at least 4.5:1 against its background in light and dark
     modes, French and Arabic alike.

8.2. Full keyboard path for the core flows: table rows, modal open/close,
     field order, save. Focus is always visible; modals trap focus and
     return it on close.

8.3. Arabic renders in a proper Arabic typeface with generous line height;
     never squeezed into a Latin-only font. In RTL mode the layout mirrors,
     including direction-implying icons (back, forward, indent).

8.4. Every icon-only control has an accessible bilingual name; every
     status badge is readable by screen reader as text, not color.

============================================================
9. HIGH-VALUE UX UPGRADES (PRIORITIZED, WITH ACCEPTANCE CRITERIA)
============================================================

Deliver as discrete reviewable units, one at a time (component truth rule).
Each has a measurable check — the unit is not done until its check passes.

U1. Teacher's "today" flow. Dashboard → one tap to attendance for the
    current slot; each student markable in one tap; save offers the next
    natural task (memorization entry).
    PASS: full room of 12 marked in under 60 seconds on a phone.

U2. Metadata-driven memorization entry: surah/ayah pickers bounded by
    quran-metadata.json, auto portion label, instant preview ("This brings
    Sara Haddad to Juz 3 — 62% of the Quran").
    PASS: no surah name, ayah count, or juz boundary anywhere outside the
    metadata file; preview matches recomputation from stored sessions.

U3. Student profile hub: identity, guardians, room, last-30-days attendance
    dots, metadata-driven memorization map, payment balance — one screen a
    parent phone call can be handled from.
    PASS: every answer a guardian typically asks is reachable in one tap
    from the student's row.

U4. Secretary collections view: unpaid/late balances with tappable/copyable
    guardian phones and one-tap "record payment" from the row.
    PASS: a payment is recorded from the balance row without navigating
    away.

U5. Admin alerts panel: each alert links to the exact record it concerns
    (the late payment's student, the room without a teacher), never to a
    generic page.
    PASS: every alert resolves to a specific record in one tap.

U6. Past-year read-only mode: banner, hidden/disabled actions, one-tap
    return to current year.
    PASS: a past-year screen never allows a write, and never surprises
    after the click.

U7. Weekly schedule as a day/time grid with live conflict feedback while a
    slot is drawn (schema overlap rules surfaced pre-save).
    PASS: an overlapping slot is visibly flagged before save is possible.

============================================================
10. UX ANTI-PATTERNS (NEVER DO THESE)
============================================================

- Hardcoding surah names, ayah counts, or juz boundaries (section 5).
- Shipping a screen without verifying it in Arabic/RTL mode.
- Two equal primary actions on one screen.
- Color as the only carrier of meaning.
- Confirm dialogs for reversible actions; undo for irreversible ones.
- Empty states that say nothing ("No data") instead of offering the next
  action.
- A form that wipes input on error.
- Bare 1–286 ayah pickers, unbounded date pickers, any input that permits
  a known-invalid value.
- The user wondering which year is on screen, or whether data is stale.
- Icons with no accessible name; tap targets under 44px.
- Numerals or dates formatted inconsistently between French and Arabic.
- Adding a field or view with no backing column in schema.sql.

============================================================
11. CHECKLIST BEFORE DECLARING ANY UX UNIT DONE
============================================================

1. Screen answers: where am I / what is the state / what next — in one
   glance.
2. Correct and fully readable in French AND Arabic (RTL verified), with
   consistent numerals and dates.
3. Role rules from the role guide respected; navigation shows only this
   role's pages.
4. Year selector visible; past years in read-only mode.
5. All Quran references computed from quran-metadata.json — nothing
   hardcoded.
6. Loading (skeletons, no shift), empty, error, and success states handled
   in the UI language; reversible actions undoable.
7. Usable at 360px width: tables collapsed, 44px targets, thumb-zone
   primary action.
8. Keyboard path and focus behavior verified; contrast passes 4.5:1.
9. The unit's acceptance criterion (section 9) passes on a phone.
10. One unit delivered, then STOP for the user's verdict (component truth
    rule — referenced, not restated).
