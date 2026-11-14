---
name: CuanCeria
colors:
  surface: '#fbf9f3'
  surface-dim: '#dbdad4'
  surface-bright: '#fbf9f3'
  surface-container-lowest: '#ffffff'
  surface-container-low: '#f5f3ee'
  surface-container: '#f0eee8'
  surface-container-high: '#eae8e2'
  surface-container-highest: '#e4e2dd'
  on-surface: '#1b1c19'
  on-surface-variant: '#4d4634'
  inverse-surface: '#30312d'
  inverse-on-surface: '#f2f1eb'
  outline: '#7f7661'
  outline-variant: '#d1c5ad'
  surface-tint: '#745c00'
  primary: '#745c00'
  on-primary: '#ffffff'
  primary-container: '#ffd23f'
  on-primary-container: '#725a00'
  inverse-primary: '#edc22e'
  secondary: '#006c4f'
  on-secondary: '#ffffff'
  secondary-container: '#51fac1'
  on-secondary-container: '#007152'
  tertiary: '#ad2c4f'
  on-tertiary: '#ffffff'
  tertiary-container: '#ffc9d0'
  on-tertiary-container: '#ab2b4d'
  error: '#ba1a1a'
  on-error: '#ffffff'
  error-container: '#ffdad6'
  on-error-container: '#93000a'
  primary-fixed: '#ffe089'
  primary-fixed-dim: '#edc22e'
  on-primary-fixed: '#241a00'
  on-primary-fixed-variant: '#574500'
  secondary-fixed: '#54fdc4'
  secondary-fixed-dim: '#27e0a9'
  on-secondary-fixed: '#002116'
  on-secondary-fixed-variant: '#00513b'
  tertiary-fixed: '#ffd9dd'
  tertiary-fixed-dim: '#ffb2bd'
  on-tertiary-fixed: '#400014'
  on-tertiary-fixed-variant: '#8c1038'
  background: '#fbf9f3'
  on-background: '#1b1c19'
  surface-variant: '#e4e2dd'
typography:
  display-hero:
    fontFamily: Plus Jakarta Sans
    fontSize: 48px
    fontWeight: '800'
    lineHeight: 56px
    letterSpacing: -0.03em
  display-hero-mobile:
    fontFamily: Plus Jakarta Sans
    fontSize: 36px
    fontWeight: '800'
    lineHeight: 44px
    letterSpacing: -0.02em
  headline-lg:
    fontFamily: Plus Jakarta Sans
    fontSize: 32px
    fontWeight: '800'
    lineHeight: 40px
    letterSpacing: -0.02em
  headline-lg-mobile:
    fontFamily: Plus Jakarta Sans
    fontSize: 26px
    fontWeight: '700'
    lineHeight: 34px
    letterSpacing: -0.01em
  headline-md:
    fontFamily: Plus Jakarta Sans
    fontSize: 22px
    fontWeight: '700'
    lineHeight: 30px
    letterSpacing: -0.01em
  headline-sm:
    fontFamily: Plus Jakarta Sans
    fontSize: 18px
    fontWeight: '700'
    lineHeight: 24px
  body-lg:
    fontFamily: Plus Jakarta Sans
    fontSize: 16px
    fontWeight: '500'
    lineHeight: 26px
  body-md:
    fontFamily: Plus Jakarta Sans
    fontSize: 14px
    fontWeight: '500'
    lineHeight: 22px
  body-sm:
    fontFamily: Plus Jakarta Sans
    fontSize: 12px
    fontWeight: '500'
    lineHeight: 18px
  label-lg:
    fontFamily: Plus Jakarta Sans
    fontSize: 14px
    fontWeight: '700'
    lineHeight: 18px
    letterSpacing: 0.02em
  label-md:
    fontFamily: Plus Jakarta Sans
    fontSize: 12px
    fontWeight: '700'
    lineHeight: 16px
    letterSpacing: 0.04em
  label-sm:
    fontFamily: Plus Jakarta Sans
    fontSize: 10px
    fontWeight: '800'
    lineHeight: 14px
    letterSpacing: 0.06em
rounded:
  sm: 0.25rem
  DEFAULT: 0.5rem
  md: 0.75rem
  lg: 1rem
  xl: 1.5rem
  full: 9999px
spacing:
  gutter: 1rem
  margin: 1.25rem
  space-xs: 0.25rem
  space-sm: 0.5rem
  space-md: 1rem
  space-lg: 1.5rem
  space-xl: 2.25rem
---

## Brand & Style

This design system expresses a "soft-pop neobrutalism" aesthetic tailored for personal wealth tracking. Rather than intimidating users with stark institutional austerity or harsh monochrome grids, it reframes financial mindfulness into an encouraging, tactile ritual. 

- **Personality:** Optimistic, unpretentious, energetic, and celebration-oriented.
- **Target Audience:** Digital natives, young professionals, and gig-economy workers seeking stress-free budgeting, saving streaks, and clear goal progression.
- **Emotional Response:** Confidence, playful relief, agency, and delight upon logging expenses or reaching milestones.
- **Design Philosophy:** Softened neobrutalism combines crisp structural outlines (`#1E1B2E`), punchy offset box shadows, and generous border radii with a candy-coated pastel palette. The balance relies on high legibility, bouncy tactile affordances, and zero sterile enterprise clutter.

## Colors

The color architecture relies on a warm, organic cream canvas anchored by bold outline inks, illuminated by buoyant candy tones:

- **Canvas & Surfaces:**
  - Base canvas: `#FFFDF7` (Warm Cream).
  - Elevated card surface: `#FFFFFF` (Crisp Milk).
  - Muted secondary container: `#F4EFE6` (Sand).
- **Primary Accent (`#FFD23F` - Sunny Gold):** Used for primary conversion triggers, hero totals, gamified balance streaks, and major focus nodes.
- **Secondary Accent (`#06D6A0` - Fresh Mint):** Signals positive cashflow, income events, successful target milestones, and secure states.
- **Tertiary Accent (`#FF6B8B` - Punchy Coral):** Highlights expense deductions, alerts, budget caps, and high-priority action badges.
- **Quaternary Accent (`#7B61FF` - Electric Periwinkle):** Serves analytical categorization, investment vaults, and secondary functional interactive pills.
- **Structural Ink (`#1E1B2E` - Deep Licorice):** High-contrast, near-black ink applied to typography, solid borders, and directional drop shadows to maintain razor-sharp legibility across pastel surfaces.

## Typography

Plus Jakarta Sans powers the entire hierarchy, combining geometric precision with welcoming, humanized terminals. 

- **Weight Distribution:** Heavy weights (700 and 800) give titles punchy, toy-like authority without sacrificing scan speed. Numerical figures, currency inputs, and metrics are always rendered in 700 or 800 weight.
- **Body & Microcopy:** Medium (500) weight prevents lightweight spindliness against high-contrast outlines and saturated pastel fills.
- **Tracking:** Headings feature snug negative tracking to form compact visual stamps; labels and tags use wide uppercase tracking for effortless legibility at compact dimensions.

## Layout & Spacing

The layout is anchored in an 8pt rhythmic grid with deliberate breathing room to counterbalance high-contrast borders and solid drop-shadows.

- **Mobile (< 768px):** Single-column stack with `margin: 1.25rem` and `gutter: 1rem`. Cards stretch fully within bounds, ensuring large, thumb-friendly touch targets.
- **Tablet (768px – 1024px):** 8-column layout with `margin: 2rem` and `gutter: 1.25rem`. Financial summaries stack side-by-side with expense logs.
- **Desktop (> 1024px):** 12-column layout maxing out at `1200px` content width. Fixed side navigation with modular dashboards, analytics charts, and goal widgets spanning 4, 6, or 8 columns.
- **Whitespace Cadence:** Keep dense internal component padding compact (`space-sm` to `space-md`), while maintaining expansive macro margins (`space-xl`) between dashboard sections to avoid visual fatigue.

## Elevation & Depth

This system avoids blurred Gaussian drop-shadows, glow filters, or skeuomorphic bevels. Instead, elevation is expressed entirely through **hard, offset shadows and structural dark strokes**:

- **Strokes:** A standard `2px solid #1E1B2E` outline encapsulates all actionable units, floating cards, tags, and inputs.
- **Base Interactive Layer (Cards, Modules):**
  - Border: `2px solid #1E1B2E`
  - Shadow: `4px 4px 0px #1E1B2E`
- **Elevated Interactive Layer (Buttons, Modals, Active Badges):**
  - Shadow: `6px 6px 0px #1E1B2E`
- **Hover/Active Mechanics:**
  - Hover: Shifts element `-2px, -2px` with shadow expanding to `6px 6px 0px #1E1B2E` (or `8px 8px`).
  - Pressed/Active: Translates element `+4px, +4px` down-right, collapsing the shadow to `0px 0px 0px #1E1B2E` for an unmistakable physical click down.
- **Z-Index Layering:** Overlays and bottom sheets receive an opaque backdrop scrim with a 3px top/side border and an exaggerated `8px 8px 0px #1E1B2E` perimeter.

## Shapes

The shape vocabulary injects the "soft" into neobrutalism. Sharp 90-degree corners are rejected in favor of bubbly, approachable rounds:

- **Cards & Modules:** Utilize `1rem` (16px) corner radius, softened against the hard black offset shadow.
- **Buttons & Search Bars:** Fixed at `1rem` to match cards or `2rem` for full pills depending on hierarchy.
- **Chips, Badges, & Indicators:** Always full pill shapes (`9999px` / `rounded-full`) to contrast against rectangular card outlines.
- **Progress Trackers:** Capsule tracks with rounded inner fill bars to emphasize smooth volume accumulation.

## Components

- **Buttons:**
  - *Primary:* `#FFD23F` background, `2px solid #1E1B2E`, `4px 4px 0px #1E1B2E` shadow, bold label. On click, translates down-right into shadow.
  - *Secondary:* `#FFFFFF` background, same border and shadow style, deep ink text.
  - *Destructive / Alert:* `#FF6B8B` background with deep ink outline and shadow.
- **Cards & Modules:**
  - White background, `2px solid #1E1B2E` outline, `4px 4px 0px #1E1B2E` hard shadow, `1rem` radius. Optional header zone tinted in mint, cream, or periwinkle with a horizontal divider stroke.
- **Chips & Pills:**
  - Fully rounded (`9999px`), `1.5px solid #1E1B2E`, paired with soft pastel fills (e.g., `#E8FDF5` for income filters, `#FFEAEF` for spending). Selected chips gain a `2px 2px 0px #1E1B2E` shadow.
- **Inputs & Form Controls:**
  - Heavy cream background (`#FFFDF7`), `2px solid #1E1B2E` border, `0.75rem` radius. Focused inputs shift background to pure white and project an immediate `3px 3px 0px #FFD23F` or `#7B61FF` offset ring.
- **Checkboxes & Radios:**
  - Checkboxes: Squircle with `2px solid #1E1B2E`. Checked state fills with `#06D6A0` and a thick licorice checkmark icon.
  - Radio: Circular outer ring with an offset solid licorice inner disc when selected.
- **Gamified Elements (Unique to CuanCeria):**
  - *Streak Badges:* Pill containers with flame/coin iconography, glowing in `#FFD23F`, featuring an energetic `3px 3px 0px #1E1B2E` offset.
  - *Progress Trackers (Savings Goals):* `12px` thick track with a `2px` black border; filled segment uses vibrant mint or coral with diagonal soft candy striping.
  - *Celebration Snackbars:* Floating pill banners emerging from screen bottom with `4px 4px 0px #1E1B2E` depth and celebratory emoji counters.