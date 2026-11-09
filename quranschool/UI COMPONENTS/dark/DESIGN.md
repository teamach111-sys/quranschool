---
name: Fajr Mist
colors:
  surface: '#0f131b'
  surface-dim: '#0f131b'
  surface-bright: '#353942'
  surface-container-lowest: '#0a0e16'
  surface-container-low: '#181c24'
  surface-container: '#1c2028'
  surface-container-high: '#262a33'
  surface-container-highest: '#31353e'
  on-surface: '#dfe2ee'
  on-surface-variant: '#c2c7d0'
  inverse-surface: '#dfe2ee'
  inverse-on-surface: '#2c3039'
  outline: '#8c919a'
  outline-variant: '#42474f'
  surface-tint: '#9ccaff'
  primary: '#c6dfff'
  on-primary: '#003257'
  primary-container: '#93c5fd'
  on-primary-container: '#145283'
  inverse-primary: '#2b6193'
  secondary: '#ffb95f'
  on-secondary: '#472a00'
  secondary-container: '#ee9800'
  on-secondary-container: '#5b3800'
  tertiary: '#ffd2d0'
  on-tertiary: '#670211'
  tertiary-container: '#ffaba8'
  on-tertiary-container: '#93262b'
  error: '#ffb4ab'
  on-error: '#690005'
  error-container: '#93000a'
  on-error-container: '#ffdad6'
  primary-fixed: '#d0e4ff'
  primary-fixed-dim: '#9ccaff'
  on-primary-fixed: '#001d35'
  on-primary-fixed-variant: '#03497a'
  secondary-fixed: '#ffddb8'
  secondary-fixed-dim: '#ffb95f'
  on-secondary-fixed: '#2a1700'
  on-secondary-fixed-variant: '#653e00'
  tertiary-fixed: '#ffdad8'
  tertiary-fixed-dim: '#ffb3b0'
  on-tertiary-fixed: '#410006'
  on-tertiary-fixed-variant: '#881d24'
  background: '#0f131b'
  on-background: '#dfe2ee'
  surface-variant: '#31353e'
typography:
  display-lg:
    fontFamily: Plus Jakarta Sans
    fontSize: 3rem
    fontWeight: '600'
    lineHeight: 3.75rem
    letterSpacing: -0.02em
  display-lg-mobile:
    fontFamily: Plus Jakarta Sans
    fontSize: 2rem
    fontWeight: '600'
    lineHeight: 2.5rem
    letterSpacing: -0.015em
  headline-lg:
    fontFamily: Plus Jakarta Sans
    fontSize: 2rem
    fontWeight: '600'
    lineHeight: 2.5rem
    letterSpacing: -0.01em
  headline-lg-mobile:
    fontFamily: Plus Jakarta Sans
    fontSize: 1.5rem
    fontWeight: '600'
    lineHeight: 2rem
    letterSpacing: -0.01em
  headline-md:
    fontFamily: Plus Jakarta Sans
    fontSize: 1.5rem
    fontWeight: '600'
    lineHeight: 2rem
    letterSpacing: -0.005em
  headline-sm:
    fontFamily: Plus Jakarta Sans
    fontSize: 1.25rem
    fontWeight: '500'
    lineHeight: 1.75rem
  body-lg:
    fontFamily: Plus Jakarta Sans
    fontSize: 1.125rem
    fontWeight: '400'
    lineHeight: 1.875rem
  body-md:
    fontFamily: Plus Jakarta Sans
    fontSize: 1rem
    fontWeight: '400'
    lineHeight: 1.625rem
  body-sm:
    fontFamily: Plus Jakarta Sans
    fontSize: 0.875rem
    fontWeight: '400'
    lineHeight: 1.375rem
  label-lg:
    fontFamily: Plus Jakarta Sans
    fontSize: 0.875rem
    fontWeight: '600'
    lineHeight: 1.25rem
    letterSpacing: 0.01em
  label-md:
    fontFamily: Plus Jakarta Sans
    fontSize: 0.75rem
    fontWeight: '500'
    lineHeight: 1rem
    letterSpacing: 0.02em
  label-sm:
    fontFamily: Plus Jakarta Sans
    fontSize: 0.6875rem
    fontWeight: '500'
    lineHeight: 0.875rem
    letterSpacing: 0.04em
rounded:
  sm: 0.25rem
  DEFAULT: 0.5rem
  md: 0.75rem
  lg: 1rem
  xl: 1.5rem
  full: 9999px
spacing:
  gutter: 1rem
  gutter-md: 1.5rem
  gutter-lg: 2rem
  margin: 1rem
  margin-md: 2rem
  margin-lg: 3rem
  space-xs: 0.25rem
  space-sm: 0.5rem
  space-md: 1rem
  space-lg: 1.5rem
  space-xl: 2.5rem
---

## Brand & Style

This design system establishes a tranquil, meditative environment tailored specifically for early-morning memorization, deep contemplation, and low-light Quranic recitation. Inspired by the calm stillness of Fajr—the transitional dawn twilight—the visual tone deliberately breaks away from ubiquitous pitch-black dark modes and traditional Islamic green palettes.

Instead, the UI cultivates an airy, contemplative atmospheric slate mood. The aesthetic embraces modern soft minimalism enriched with subtle tonal elevations and luminous sky accents. It feels whisper-quiet, respectful, and optically calming on tired eyes during pre-dawn hours, balancing structural clarity with warm sacred achievement cues.

## Colors

The palette relies on nuanced tonal containment rather than raw contrast, eliminating visual glare while retaining pristine legibility.

- **Canvas / Root Background (`#333740`):** A serene, balanced dawn slate gray avoiding heavy black.
- **Surface Lower / Recessed (`#2B2E36`):** Dedicated to recessed viewports, verse audio scrubbers, and verse playback states.
- **Surface Default (`#3F444E`):** Standard card containers, reading panels, and modular blocks.
- **Surface Elevated (`#4A505C`):** Floating navigation bars, active sheets, context drawers, and toolbars.
- **Primary Dawn Azure (`#93C5FD`):** The guiding light of dawn. Reserved for active verse progress, focal markers, primary controls, and active pagination.
- **Secondary Dawn Sun (`#F59E0B` / `#FDE047`):** Gentle golden morning sunlight deployed for Tajweed mastery stars, Hifz streaks, and milestone badges.
- **Tertiary Warning (`#F87171`):** A soft dawn coral for pronunciation corrections and tajweed reminders, eliminating harsh warning reds.
- **Text & Borders:** Text hierarchy transitions from crisp white (`#F8FAFC`) down to mist silver (`#CBD5E1` and `#94A3B8`). Structural lines leverage soft slate borders (`#525866` and `#474D5A`) for low-contrast definition.

## Typography

The typography leverages Plus Jakarta Sans across all application levels to project a clean, modern, architectural aesthetic. The vertical rhythm is deliberately generous—line heights of 1.6x to 1.7x on body text provide ample visual breathing space, reducing eye strain during extended memorization and self-testing sessions. 

When paired with script fonts for Quranic text, Plus Jakarta Sans serves as an unobtrusive, highly legible scaffold for translations, verse numbers, Juz indicators, and memorization prompts.

## Layout & Spacing

Layouts adhere to an adaptive fluid-to-centered grid structured around rhythmic multiples of 4px and 8px.

- **Mobile (< 768px):** Single-column layout with 1rem canvas margins and 1rem gutter. Content prioritizes full-width card containers with clean horizontal breathing room.
- **Tablet (768px - 1024px):** 6-column grid with 2rem margins, enabling side-by-side memorization splits (e.g., Ayah recitation canvas alongside memorization logs).
- **Desktop (> 1024px):** Centered max-width viewport (capped at 1200px) on a 12-column grid with 3rem margins, ensuring recitation lines never extend beyond comfortable scanning widths.

## Elevation & Depth

Visual hierarchy rejects intense, heavy drop shadows in favor of subtle tonal surface steps and low-contrast perimeter strokes.

- **Level 0 (Base Canvas):** Background `#333740` with zero elevation.
- **Level 1 (Surface Containers):** Background `#3F444E` framed with a 1px border of `#525866`. No shadow is applied; depth is purely chromatic.
- **Level 2 (Elevated Sheets & Floating Nav):** Background `#4A505C` framed by a 1px border of `#626978` and a diffused ambient twilight shadow: `0 8px 24px -4px rgba(27, 30, 36, 0.45)`.
- **Level Recessed (Audio dock, Input Wells):** Background `#2B2E36` with a soft inset perimeter stroke `#3A3F4B`.

## Shapes

Corner radii stay strictly within the modern 8px to 12px range (`roundedness: 2`), balancing structural precision with tactile softness. 

- **Small Components (Chips, Tags, Badges):** 0.5rem (8px).
- **Standard Containers (Cards, Modals, Action Buttons):** 0.75rem (12px).
- **Recitation Viewport Surfaces:** 1rem (16px) for spacious outer boundaries.
- **Pills:** Full rounded pill radius is reserved strictly for audio recording toggles, active recitation status pills, and Juz progression meters.

## Components

### Buttons
- **Primary:** Dawn Sky Blue (`#93C5FD`) background with `#1E293B` text in semi-bold weight. Provides an immediate, soft-luminous affordance without harsh glare. Hover: subtle brightness elevation; active: scale 0.98.
- **Secondary / Tonal:** `#4A505C` background, 1px border `#525866`, text `#F8FAFC`.
- **Ghost:** Transparent background, text `#CBD5E1`, hovering into `#3F444E`.

### Cards & Reading Blocks
- Constructed using `#3F444E` with 1px border `#525866` and 12px corners.
- Padding uses `space-md` or `space-lg`.
- Verse cards feature an active state displaying an inner accent line of `#93C5FD` on the right or left edge to identify the currently recited Ayah.

### Input Fields & Search Bars
- Background `#2B2E36` (recessed), border `#525866`, text `#F8FAFC`, placeholder `#94A3B8`.
- Focus state switches the border to `#93C5FD` accompanied by a soft glow ring `0 0 0 2px rgba(147, 197, 253, 0.2)`.

### Chips & Verse Badges
- Surface `#2B2E36` with `#525866` borders for neutral markers.
- Achievement & Surah completion tags use a gentle dawn sunlight treatment: `#F59E0B` text on `rgba(245, 158, 11, 0.12)` fill with `rgba(245, 158, 11, 0.3)` border.

### Tajweed & Pronunciation Prompts
- Inline flags use `#F87171` over a tonal tint `rgba(248, 113, 113, 0.1)` with 8px radius for calming, supportive error correction.

### Recitation Audio Bar & Hifz Progress Tracker
- A persistent anchored container at the screen bottom using `#4A505C` with a top border `#525866`.
- Audio scrub track uses `#2B2E36`, filled by a `#93C5FD` progress line with a rounded 4px height.