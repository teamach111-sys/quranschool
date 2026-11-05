---
name: ui-layout-polish
description: Improve the visual relationships between UI elements whenever a screen feels awkward, unbalanced, cramped, stretched, or unfinished. Use when tables, buttons, inputs, filters, actions, cards, sections, or empty space do not feel geometrically coherent together, even if each individual element looks acceptable.
---

# UI Layout Polish

Use this skill to fix the composition of a screen, not to restyle isolated controls. Look at how inputs, buttons, tables, headings, cards, and surrounding whitespace work as one visual system.

## Non-negotiable composition rule

Force tables, inputs, and buttons that belong to the same workflow to read as one harmonious control surface. They must share a clear container, alignment logic, spacing rhythm, and visual hierarchy. Do not accept a result where each element looks polished by itself but the combined group feels stretched, detached, uneven, or geometrically accidental.

Before finishing, make the relationship obvious in both the screen and the code:

- Put search fields, filters, and related buttons in the toolbar or header of the table or section they control.
- Match the toolbar and table to the same horizontal container, width behavior, and side padding.
- Use a deliberate layout relationship such as `grid-cols-[minmax(0,1fr)_auto]`, `flex-wrap`, `items-center`, and a consistent `gap-*`; do not rely on random margins to create alignment.
- Let the input occupy available space only when that space is useful. Keep buttons compact, aligned, and visually connected to the input or table.
- Keep the gap from controls to the table smaller than the gap separating that section from unrelated content.
- Give the table enough width to scan, but prevent it from creating a large empty region beside or below a short control group.
- If the group cannot remain balanced on mobile, stack the controls and table relationship intentionally instead of allowing overflow or tiny controls.

Treat this rule as a layout acceptance test: if a user cannot immediately see that the input and button control the table, revise the structure and sizing before changing decorative styling.

## Layered layout and direction

Build table screens as two deliberate control layers above the table: the first pairs search with the primary action, and the second groups the filters with their apply/reset actions. Keep both layers close to the table in a shared container, with consistent gaps.

- Make each layer a flex row or responsive flex/grid group, then stack the two layers vertically with a consistent `gap-*`.
- Start each layer at the inline start. With the page's `dir="rtl"`, this means the group begins at the right; in LTR, it begins at the left. Prefer direction-aware alignment such as `justify-start` over hard-coded physical left/right alignment.
- Keep the search and primary action together in the first layer. Keep filter fields and their related actions together in the second layer.
- Let fields use deliberate, readable widths and buttons keep their natural width. On narrow screens, wrap or stack each layer without shrinking touch targets or causing overflow.
- Preserve the reading order from search/action to filters to results in both RTL and LTR. Do not reverse the DOM order just to change visual alignment.

## Core approach

- Inspect the whole section before changing individual elements. Identify the visual anchor, the primary action, the main content area, and the natural reading direction.
- Keep related controls in the same visual group. A search field, filters, and its action button should feel like one toolbar or form row rather than unrelated objects scattered across a section.
- Keep actions close to the content they affect. Do not leave a button floating outside a card, table, form, or section when its purpose is only clear from that nearby content.
- Align edges intentionally. Inputs, buttons, table columns, cards, headings, and section boundaries should share useful left or right edges where possible.
- Size elements according to their job. Avoid inputs that stretch far beyond the content they collect, buttons that are oversized relative to their label, and table columns that create large dead zones.
- Treat whitespace as structure. Remove accidental gaps, but preserve breathing room around groups. Use consistent spacing steps instead of one-off distances that make the layout feel lopsided.
- Prefer a clear hierarchy: title and context first, controls next, content after that, and secondary actions in a quieter position. Do not make every element compete for attention.
- Keep dense areas dense enough to scan and open areas open enough to feel intentional. Tables should feel connected to their controls, not detached beneath a large blank area.
- On smaller screens, stack or wrap related controls as a group. Do not preserve a desktop row if it creates overflowing inputs, tiny buttons, or awkward gaps.
- Preserve readable contrast, touch-friendly targets, and visible focus and hover states while adjusting the layout.

## Translate visual problems into layout code

When a screen looks deformed, diagnose the relationship in the markup and layout rules before changing colors or typography. Use this translation guide:

| Visual symptom | Inspect in the code | Prefer this correction |
| --- | --- | --- |
| An input is much longer than the action beside it | `w-full`, `flex-1`, missing `max-w-*`, or a grid column spanning too many tracks | Give the field a useful `max-width`, let the action keep its natural width, and group both inside one aligned flex or grid container |
| A button feels detached from a table or card | Button is outside the relevant parent, is absolutely positioned, or is separated by a large margin | Move it into the table/card header or the same action group, then align that group with the content container |
| A large blank area appears between controls and content | Excessive `mt-*`, `mb-*`, `py-*`, fixed heights, `min-h-*`, `justify-between`, or an empty grid track | Remove the accidental space, use a small consistent `gap-*`, and let the section height follow its content |
| Controls do not line up with the table below | Different `max-w-*`, horizontal padding, nested containers, or mismatched grid columns | Use one shared container and matching `px-*`, width, and alignment rules for the toolbar and table |
| A row feels stretched or lopsided | `flex-1` applied to short controls, unequal grid fractions, or a single child taking all available width | Give flexible space only to the content that benefits from it; keep compact controls at `w-auto` or a deliberate fixed width |
| Items collide or overflow on narrow screens | `flex-nowrap`, fixed widths, negative margins, or desktop-only gaps | Allow `flex-wrap`, switch to a stacked grid at the mobile breakpoint, and remove widths that cannot shrink |

Read the rendered structure from the outside inward: page container, section, header or toolbar, content wrapper, then individual control. Fix the highest-level rule causing the imbalance instead of adding compensating margins to child elements.

Use explicit layout intent in the code. Prefer a named grid or flex relationship such as `grid-cols-[minmax(0,1fr)_auto]`, `items-center`, `gap-3`, and `max-w-xl` over scattered offsets. Avoid using `absolute`, `top-*`, `left-*`, large arbitrary values, or repeated negative margins to force ordinary content into place.

Keep the existing wording and labels unless the text itself is the problem. This skill is about geometry, containment, sizing, and responsive relationships—not rewriting copy.

## Composition checks

Before considering the UI finished, check:

1. Can someone tell which button belongs to which input, table, or section without guessing?
2. Do the controls and the content below them share alignment and width logic?
3. Is the largest empty space intentional, or is it caused by an oversized element or misplaced action?
4. Does the primary action stand out without being detached from its context?
5. Do repeated rows, cards, and controls use consistent spacing and sizing?
6. Does the layout still feel balanced when labels are longer, data is empty, or the screen is narrow?

## Practical examples

- If a wide search input sits beside a short button with a large empty area beneath them, reduce the input to a useful maximum width and place the controls in a compact toolbar aligned with the table below.
- If an “Add” button sits outside a card while the form or list it controls is inside, move the action into the card header or the same control group so the relationship is immediately clear.
- If a table begins much farther left than its filters, align the table and toolbar to the same container edge instead of fixing the table alone.
- If a form has one full-width field beside several short fields, group fields by meaning and use a balanced responsive grid rather than forcing every field to occupy the same width.

Make the smallest set of changes that creates a noticeably more balanced composition. Do not add unrelated features, decorative elements, or new UI patterns just to fill space.
