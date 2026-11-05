---
name: table-toolbar-layout
description: Standardize the layout and order of filters, search, actions, and tables in admin panels, dashboards, management screens, and data-heavy pages. Use whenever a page contains a table or list with controls above it, especially when the user asks to improve confusing buttons, inputs, filters, or table organization.
---

# Table Toolbar Layout

Use this skill whenever a screen presents a table or data list with controls above it. The goal is to make the next action obvious, keep related controls together, and prevent the toolbar from becoming a wall of buttons and inputs.

## Default order

Build the area above the table in this order:

1. **Page heading and context**
   - Put the title first.
   - Add a short description only when it helps explain the data.
   - Show the total count near the title or table, not buried inside a control group.

2. **Primary action**
   - Place the main action for the page at the top-right of the heading row on desktop.
   - Use one clear label such as “Add customer”, “Create report”, or “Invite member”.
   - Do not place destructive actions beside the primary action unless they are clearly separated.

3. **Search and filtering toolbar**
   - Put search first because it is the most common way to narrow a table.
   - Put filters next, ordered from broadest to most specific: status, category, date, owner, then advanced filters.
   - Keep filters visually grouped in one toolbar.
   - Show active filter chips below or inside the toolbar so users can see what is affecting the results.
   - Include “Clear filters” only when at least one filter is active.

4. **Secondary table actions**
   - Place view options, column visibility, export, refresh, and density controls after filtering.
   - Keep these actions visually quieter than the primary action.
   - Put less-used actions inside a labeled “More” menu instead of displaying many equal-weight buttons.

5. **Table**
   - Start the table directly below the toolbar with consistent spacing.
   - Keep row actions in the final column or an overflow menu; do not scatter action buttons through the cells.

## Visual hierarchy

- Use one primary button per toolbar or section. Secondary actions should use an outline, text, or icon treatment.
- Separate the heading row from the filtering row. Do not make the page title compete with search and filters.
- Use 8px-based spacing and align controls to the same left and right edges as the table.
- Keep input heights, button heights, and control alignment consistent.
- Use readable labels and accessible names. Do not rely on icons alone for unfamiliar actions.
- Do not use color as the only indication of active filters, status, errors, or destructive actions.
- Avoid placing unrelated controls above the table, such as settings for another page or editing forms for a selected row.

## Responsive behavior

- On desktop, use a heading row followed by a single horizontal toolbar when space allows.
- On smaller screens, stack the heading, primary action, search, and filters vertically in that order.
- Make search full width on mobile.
- Let filters wrap or move into a “Filters” drawer or popover when there is not enough room.
- Keep the primary action easy to find; it may become full width on mobile.
- Do not force a dense toolbar to overflow horizontally or create tiny controls.
- Keep the table usable on mobile with horizontal scrolling, responsive columns, or a clearly designed card/list alternative.

## Interaction rules

- Search should have a clear affordance and preserve the user’s query while they work.
- Filters should update the result count or loading state clearly.
- Show a loading state inside the table area without shifting the toolbar.
- Show an empty state that explains whether there are no records or whether filters hid the records, and provide a relevant next action such as “Clear filters”.
- Ask for confirmation before destructive bulk actions.
- Only show bulk actions after rows are selected. Keep them in a selection toolbar so they do not compete with normal table controls.
- Put row-specific actions on the row, not in the global toolbar.
- Preserve control state when users paginate, sort, or return to the page when practical.

## Recommended structure

```text
Page title                                      [Primary action]
Short description or record count

[Search........................] [Status] [Date] [More filters]   [Columns] [Export]
Active filter chips                                      [Clear filters]

Table
```

For a simple table, omit controls that are not needed:

```text
Page title                                      [Add item]

[Search........................]                       [Refresh]

Table
```

## Avoid

- Do not put “Add”, “Export”, “Delete”, “Refresh”, “Filter”, and “Settings” as equally prominent buttons in one row.
- Do not place the primary action after filters or below the table.
- Do not hide the only way to clear active filters.
- Do not use a separate toolbar for every small control.
- Do not show disabled-looking controls without explaining why they are unavailable.
- Do not place an edit form above the table unless editing the entire collection is the page’s main purpose.
- Do not make users guess whether a button affects one row, selected rows, or the whole table.

## Quality check

Before finishing a table page, verify:

- A new user can identify the page purpose and main action immediately.
- Search appears before less-common filters.
- Filters are grouped and their active state is visible.
- Secondary actions do not overpower the primary action.
- Bulk actions appear only after selection.
- The empty, loading, error, and mobile states preserve the same hierarchy.
- Every visible control has a clear label, purpose, and scope.
