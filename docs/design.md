# Design System Inspired by Linear

## 1. Visual Theme & Atmosphere

This system keeps Linear's precision, restraint, and typographic confidence, but translates it into a light-mode-first experience for CIC. The default canvas is bright and airy rather than atmospheric: a cool mineral background (`#f6f8fb`) with clean white panels (`#ffffff`) and soft tinted surfaces (`#f3f6fa`). Instead of content emerging from darkness, hierarchy is created through density, border clarity, and carefully controlled cool-gray contrast.

The overall impression should feel academic, modern, and dependable. Large surfaces are calm and spacious. Cards, navigation, and controls sit on top of the canvas with crisp edges and light shadows, creating separation without visual heaviness. The interface should feel structured and institutional, but never bureaucratic or stale.

The typography system remains entirely Inter Variable with OpenType features `"cv01"` and `"ss03"` enabled globally. Inter is used at a remarkable range of weights — from 300 (light body) through 510 (medium, the signature emphasis weight) to 590 (semibold emphasis). At display sizes (72px, 64px, 48px), Inter uses aggressive negative letter-spacing (-1.584px to -1.056px), creating compressed, authoritative headlines that feel engineered rather than ornamental. Berkeley Mono remains the monospace companion for code and technical labels, with fallbacks to ui-monospace, SF Mono, and Menlo.

The color system is led by CIC's own identity. Royal blue (`#0352A1`) becomes the primary brand color for CTAs, navigation accents, and key interactions, while academic gold (`#FFC300`) is used more selectively for emphasis, highlights, and ceremonial moments. Most of the UI remains cool-neutral so the brand colors feel intentional rather than decorative.

**Key Characteristics:**
- Light-mode-native: `#f6f8fb` canvas, `#ffffff` panel background, `#f3f6fa` secondary surfaces
- Inter Variable with `"cv01", "ss03"` globally for a cleaner geometric feel
- Signature weight 510 for most UI emphasis and navigation
- Aggressive negative letter-spacing at display sizes (-1.584px at 72px, -1.056px at 48px)
- CIC brand palette: `#0352A1` (primary blue) / `#2167AC` (blue hover) / `#FFC300` (accent gold) / `#FFCA1F` (gold hover)
- Crisp cool borders: `#d6dee8` to `#eef2f7`
- White cards with soft blue-gray shadows for separation
- Solid surfaces first, subtle tinting second, brand color last
- Radix-style primitive thinking for controls and interactions
- Success green (`#16A34A`, `#10B981`) used only for status indicators

## 2. Color Palette & Roles

### Background Surfaces
- **App Canvas** (`#F6F8FB`): Default page background. A cool, mineral off-white that reduces glare and gives white panels room to stand out.
- **Panel Base** (`#FFFFFF`): Primary surface for cards, modals, nav bars, and content containers.
- **Secondary Surface** (`#F3F6FA`): Soft section background for grouped areas, toolbars, and low-emphasis containers.
- **Tinted Surface** (`#EDF4FB`): Light blue-tinted surface for feature highlights, selected states, and identity-aware sections.

### Text & Content
- **Primary Text** (`#101828`): Dense slate for headlines, primary content, and high-importance UI text.
- **Secondary Text** (`#475467`): Default body text, descriptions, and navigation labels.
- **Tertiary Text** (`#667085`): Muted support text, helper copy, and placeholder-adjacent content.
- **Quaternary Text** (`#98A2B3`): Lowest-emphasis metadata, disabled states, and subtle labels.

### Brand & Accent
- **Brand Royal Blue** (`#0352A1`): Primary brand color from the logo. Use for CTA backgrounds, active controls, selected states, and key navigational emphasis.
- **Brand Blue Hover** (`#2167AC`): Hover and active variant for primary actions.
- **Deep Heritage Blue** (`#024384`): Pressed state, dense brand blocks, and more serious institutional surfaces.
- **Accent Gold** (`#FFC300`): Signature highlight from the logo mark. Use for identity-led emphasis, small badges, and meaningful visual punctuation.
- **Accent Gold Hover** (`#FFCA1F`): Slight lift for interactive gold accents and hover highlights.
- **Accent Gold Tint** (`#FFF2BF`): Soft gold wash for celebratory callouts and low-intensity highlight backgrounds.

### Status Colors
- **Green** (`#16A34A`): Primary success and active state color.
- **Emerald** (`#10B981`): Secondary success for softer confirmations and status pills.

### Border & Divider
- **Border Primary** (`#D6DEE8`): Default border for inputs, cards, and visible divisions.
- **Border Secondary** (`#E6EBF2`): Softer border for grouped surfaces and low-emphasis cards.
- **Border Tertiary** (`#EEF2F7`): Hairline division for dense layouts and subtle separation.
- **Line Strong** (`#C6D1DF`): Stronger structural line for tables, sticky headers, and segmented controls.
- **Line Subtle** (`#F0F4F8`): Whisper-light divider for large sections and spacious layouts.

### Support Neutrals
- **Pure White** (`#FFFFFF`): Cards, popovers, and high-contrast surfaces.
- **Soft White** (`#FCFDFD`): Alternative high-brightness surface when pure white feels too sharp.
- **Slate Tint** (`#EAF0F6`): Decorative background wash and subtle panel tinting.
- **Ink Tint** (`#344054`): Strong medium-dark supporting text and icon color.

### Overlay
- **Overlay Primary** (`rgba(15,23,42,0.28)`): Modal and drawer backdrop. Dark enough for focus, light enough to stay aligned with a bright UI.

## 3. Typography Rules

### Font Family
- **Primary**: `Inter Variable`, with fallbacks: `SF Pro Display, -apple-system, system-ui, Segoe UI, Roboto, Oxygen, Ubuntu, Cantarell, Open Sans, Helvetica Neue`
- **Monospace**: `Berkeley Mono`, with fallbacks: `ui-monospace, SF Mono, Menlo`
- **OpenType Features**: `"cv01", "ss03"` enabled globally — cv01 provides an alternate lowercase 'a' (single-story), ss03 adjusts specific letterforms for a cleaner geometric appearance.

### Hierarchy

| Role | Font | Size | Weight | Line Height | Letter Spacing | Notes |
|------|------|------|--------|-------------|----------------|-------|
| Display XL | Inter Variable | 72px (4.50rem) | 510 | 1.00 (tight) | -1.584px | Hero headlines, maximum impact |
| Display Large | Inter Variable | 64px (4.00rem) | 510 | 1.00 (tight) | -1.408px | Secondary hero text |
| Display | Inter Variable | 48px (3.00rem) | 510 | 1.00 (tight) | -1.056px | Section headlines |
| Heading 1 | Inter Variable | 32px (2.00rem) | 400 | 1.13 (tight) | -0.704px | Major section titles |
| Heading 2 | Inter Variable | 24px (1.50rem) | 400 | 1.33 | -0.288px | Sub-section headings |
| Heading 3 | Inter Variable | 20px (1.25rem) | 590 | 1.33 | -0.24px | Feature titles, card headers |
| Body Large | Inter Variable | 18px (1.13rem) | 400 | 1.60 (relaxed) | -0.165px | Introduction text, feature descriptions |
| Body Emphasis | Inter Variable | 17px (1.06rem) | 590 | 1.60 (relaxed) | normal | Emphasized body, sub-headings in content |
| Body | Inter Variable | 16px (1.00rem) | 400 | 1.50 | normal | Standard reading text |
| Body Medium | Inter Variable | 16px (1.00rem) | 510 | 1.50 | normal | Navigation, labels |
| Body Semibold | Inter Variable | 16px (1.00rem) | 590 | 1.50 | normal | Strong emphasis |
| Small | Inter Variable | 15px (0.94rem) | 400 | 1.60 (relaxed) | -0.165px | Secondary body text |
| Small Medium | Inter Variable | 15px (0.94rem) | 510 | 1.60 (relaxed) | -0.165px | Emphasized small text |
| Small Semibold | Inter Variable | 15px (0.94rem) | 590 | 1.60 (relaxed) | -0.165px | Strong small text |
| Small Light | Inter Variable | 15px (0.94rem) | 300 | 1.47 | -0.165px | De-emphasized body |
| Caption Large | Inter Variable | 14px (0.88rem) | 510–590 | 1.50 | -0.182px | Sub-labels, category headers |
| Caption | Inter Variable | 13px (0.81rem) | 400–510 | 1.50 | -0.13px | Metadata, timestamps |
| Label | Inter Variable | 12px (0.75rem) | 400–590 | 1.40 | normal | Button text, small labels |
| Micro | Inter Variable | 11px (0.69rem) | 510 | 1.40 | normal | Tiny labels |
| Tiny | Inter Variable | 10px (0.63rem) | 400–510 | 1.50 | -0.15px | Overline text, sometimes uppercase |
| Link Large | Inter Variable | 16px (1.00rem) | 400 | 1.50 | normal | Standard links |
| Link Medium | Inter Variable | 15px (0.94rem) | 510 | 2.67 | normal | Spaced navigation links |
| Link Small | Inter Variable | 14px (0.88rem) | 510 | 1.50 | normal | Compact links |
| Link Caption | Inter Variable | 13px (0.81rem) | 400–510 | 1.50 | -0.13px | Footer, metadata links |
| Mono Body | Berkeley Mono | 14px (0.88rem) | 400 | 1.50 | normal | Code blocks |
| Mono Caption | Berkeley Mono | 13px (0.81rem) | 400 | 1.50 | normal | Code labels |
| Mono Label | Berkeley Mono | 12px (0.75rem) | 400 | 1.40 | normal | Code metadata, sometimes uppercase |

### Principles
- **510 is the signature weight**: Inter Variable's 510 weight creates a quiet emphasis without the heaviness of medium or semibold.
- **Compression at scale**: Display sizes use progressively tighter letter-spacing — -1.584px at 72px, -1.408px at 64px, -1.056px at 48px, -0.704px at 32px. Below 24px, spacing relaxes toward normal.
- **OpenType as identity**: `"cv01", "ss03"` make Inter feel more geometric and purposeful.
- **Three-tier weight system**: 400 (reading), 510 (emphasis/UI), 590 (strong emphasis). The 300 weight appears only in intentionally low-emphasis contexts.

## 4. Component Stylings

### Buttons

**Ghost Button (Default)**
- Background: `#FFFFFF`
- Text: `#344054`
- Padding: comfortable
- Radius: 6px
- Border: `1px solid #D6DEE8`
- Outline: none
- Focus shadow: `0 0 0 4px rgba(3,82,161,0.12), 0 8px 24px rgba(15,23,42,0.08)`
- Use: Standard actions, secondary CTAs

**Subtle Button**
- Background: `#F3F6FA`
- Text: `#475467`
- Padding: 0px 6px
- Radius: 6px
- Hover: `#EDF4FB`
- Use: Toolbar actions, contextual buttons

**Primary Brand Button**
- Background: `#0352A1`
- Text: `#FFFFFF`
- Padding: 8px 16px
- Radius: 6px
- Hover: `#2167AC`
- Shadow: `0 10px 24px rgba(3,82,161,0.18)`
- Use: Primary CTAs, key conversions, major navigation actions

**Icon Button (Circle)**
- Background: `#FFFFFF`
- Text: `#344054`
- Radius: 50%
- Border: `1px solid #E6EBF2`
- Shadow: `0 1px 2px rgba(16,24,40,0.04)`
- Use: Close, menu toggle, icon-only actions

**Pill Button**
- Background: `#FFFFFF`
- Text: `#475467`
- Padding: 0px 10px 0px 5px
- Radius: 9999px
- Border: `1px solid #D6DEE8`
- Use: Filter chips, tags, status indicators

**Small Toolbar Button**
- Background: `#F3F6FA`
- Text: `#667085`
- Radius: 2px
- Border: `1px solid #E6EBF2`
- Shadow: `0 1px 2px rgba(16,24,40,0.04)`
- Font: 12px weight 510
- Use: Toolbar actions, quick-access controls

### Cards & Containers
- Background: `#FFFFFF`
- Border: `1px solid #E6EBF2` (standard) or `1px solid #EEF2F7` (subtle)
- Radius: 8px (standard), 12px (featured), 22px (large panels)
- Shadow: `0 1px 2px rgba(16,24,40,0.04), 0 12px 32px rgba(15,23,42,0.06)`
- Hover: subtle border tightening or shadow lift, not dramatic color shifts

### Inputs & Forms

**Text Area**
- Background: `#FFFFFF`
- Text: `#101828`
- Border: `1px solid #D6DEE8`
- Padding: 12px 14px
- Radius: 6px

**Search Input**
- Background: `#F6F8FB`
- Text: `#101828`
- Border: `1px solid #E6EBF2`
- Padding: 1px 32px (icon-aware)

**Button-style Input**
- Background: `#FFFFFF`
- Text: `#667085`
- Border: `1px solid #D6DEE8`
- Padding: 1px 6px
- Radius: 5px
- Focus shadow: `0 0 0 4px rgba(3,82,161,0.10)`

### Badges & Pills

**Success Pill**
- Background: `#DCFCE7`
- Text: `#166534`
- Radius: 50% (circular)
- Font: 10px weight 510
- Use: Status dots, completion indicators

**Neutral Pill**
- Background: `#F3F6FA`
- Text: `#475467`
- Padding: 0px 10px 0px 5px
- Radius: 9999px
- Border: `1px solid #E6EBF2`
- Font: 12px weight 510
- Use: Tags, filter chips, category labels

**Subtle Badge**
- Background: `#EDF4FB`
- Text: `#0352A1`
- Padding: 0px 8px 0px 2px
- Radius: 2px
- Border: `1px solid #D7E7F8`
- Font: 10px weight 510
- Use: Inline labels, version tags, contextual highlights

### Navigation
- Light sticky header on a translucent white or canvas-tinted background
- CIC mark left-aligned with disciplined spacing
- Links: Inter Variable 13–14px weight 510, `#475467` text
- Active/hover: text deepens to `#101828`, with blue used for active emphasis only
- CTA: Brand royal blue button or bordered white ghost button
- Mobile: hamburger collapse
- Search: command palette trigger (`/` or `Cmd+K`)

### Image Treatment
- Product screenshots on white or soft-tinted surfaces with `#E6EBF2` border
- Top-rounded images: `12px 12px 0px 0px` radius
- Dashboard previews dominate feature sections, but always sit inside a framed surface
- Subtle shadow beneath screenshots: `0 16px 40px rgba(15,23,42,0.08)`

## 5. Layout Principles

### Spacing System
- Base unit: 8px
- Scale: 1px, 4px, 7px, 8px, 11px, 12px, 16px, 19px, 20px, 22px, 24px, 28px, 32px, 35px
- The 7px and 11px values suggest micro-adjustments for optical alignment
- Primary rhythm: 8px, 16px, 24px, 32px (standard 8px grid)

### Grid & Container
- Max content width: approximately 1200px
- Hero: centered single-column with generous vertical padding
- Feature sections: 2–3 column grids for feature cards
- Alternate between `#F6F8FB`, `#FFFFFF`, and occasional tinted sections instead of full-bleed dark blocks
- Changelog: single-column timeline layout

### Whitespace Philosophy
- **Light as breathing room**: Negative space should feel clean, open, and intentional. The canvas is not blank emptiness but a calm structural field.
- **Compressed headlines, expanded surroundings**: Dense display typography still needs generous spacing around it. The tension comes from precise type on roomy light surfaces.
- **Section isolation**: Separate major sections with vertical rhythm, soft background shifts, or hairline dividers rather than heavy boxes everywhere.

### Border Radius Scale
- Micro (2px): Inline badges, toolbar buttons, subtle tags
- Standard (4px): Small containers, list items
- Comfortable (6px): Buttons, inputs, functional elements
- Card (8px): Cards, dropdowns, popovers
- Panel (12px): Panels, featured cards, section containers
- Large (22px): Large panel elements
- Full Pill (9999px): Chips, filter pills, status tags
- Circle (50%): Icon buttons, avatars, status dots

## 6. Depth & Elevation

| Level | Treatment | Use |
|-------|-----------|-----|
| Flat (Level 0) | No shadow, `#F6F8FB` bg | Page background, base canvas |
| Subtle (Level 1) | `0 1px 2px rgba(16,24,40,0.04)` | Toolbar buttons, micro-elevation |
| Surface (Level 2) | `#FFFFFF` bg + `1px solid #E6EBF2` border | Cards, input fields, containers |
| Inset (Level 2b) | `inset 0 1px 0 rgba(255,255,255,0.9), inset 0 -1px 0 rgba(214,222,232,0.7)` | Recessed panels, segmented controls |
| Ring (Level 3) | `0 0 0 1px rgba(214,222,232,0.9)` | Border-as-structure technique |
| Elevated (Level 4) | `0 12px 32px rgba(15,23,42,0.08)` | Floating elements, dropdowns |
| Dialog (Level 5) | `0 24px 64px rgba(15,23,42,0.16), 0 8px 24px rgba(15,23,42,0.08)` | Popovers, command palette, modals |
| Focus | `0 0 0 4px rgba(3,82,161,0.12)` + light shadow support | Keyboard focus on interactive elements |

**Shadow Philosophy**: In a light interface, elevation should come from clean surface changes and restrained shadows. Start with the canvas (`#F6F8FB`), move to white surfaces for functional containers, then add soft cool shadows only when an element truly needs to float. Borders do most of the structural work; shadows are secondary and should never look muddy or smoky.

## 7. Do's and Don'ts

### Do
- Use Inter Variable with `"cv01", "ss03"` on ALL text
- Use weight 510 as your default emphasis weight
- Apply aggressive negative letter-spacing at display sizes (-1.584px at 72px, -1.056px at 48px)
- Build on bright surfaces: `#F6F8FB` for canvas, `#FFFFFF` for panels, `#F3F6FA` for secondary sections
- Use cool, crisp borders (`#D6DEE8` to `#EEF2F7`) to organize the layout
- Keep most cards and controls on solid white or softly tinted surfaces
- Reserve brand royal blue (`#0352A1`) for primary CTAs and active surfaces, and use accent gold (`#FFC300`) as a restrained highlight
- Use `#101828` for primary text and `#475467` for most body copy
- Use blue focus treatment and soft cool shadows for interaction clarity

### Don't
- Don't default the experience to dark surfaces or near-black sections
- Don't flood the UI with brand blue or gold; the neutral system should do most of the work
- Don't use muddy gray borders or heavy drop shadows
- Don't use positive letter-spacing on display text
- Don't skip the OpenType features (`"cv01", "ss03"`)
- Don't use weight 700 (bold); 590 should remain the ceiling
- Don't let gold dominate the UI chrome; it is a secondary accent, not a base color
- Don't use pure decorative gradients where a clean solid surface would communicate better

## 8. Responsive Behavior

### Breakpoints
| Name | Width | Key Changes |
|------|-------|-------------|
| Mobile Small | <600px | Single column, compact padding |
| Mobile | 600–640px | Standard mobile layout |
| Tablet | 640–768px | Two-column grids begin |
| Desktop Small | 768–1024px | Full card grids, expanded padding |
| Desktop | 1024–1280px | Standard desktop, full navigation |
| Large Desktop | >1280px | Full layout, generous margins |

### Touch Targets
- Buttons use comfortable padding with 6px radius minimum
- Navigation links at 13–14px with adequate spacing
- Pill tags have 10px horizontal padding for touch accessibility
- Icon buttons at 50% radius ensure circular, easy-to-tap targets
- Search trigger is prominently placed with generous hit area

### Collapsing Strategy
- Hero: 72px → 48px → 32px display text, tracking adjusts proportionally
- Navigation: horizontal links + CTAs → hamburger menu at 768px
- Feature cards: 3-column → 2-column → single column stacked
- Product screenshots: maintain aspect ratio, may reduce padding
- Changelog: timeline maintains single-column through all sizes
- Footer: multi-column → stacked single column
- Section spacing: 80px+ → 48px on mobile

### Image Behavior
- Dashboard screenshots maintain border and white-surface framing at all sizes
- Hero visuals simplify on mobile (fewer floating UI elements)
- Product screenshots use responsive sizing with consistent radius
- Light backgrounds require consistent card framing so screenshots never visually dissolve into the page

## 9. Agent Prompt Guide

### Quick Color Reference
- Primary CTA: Brand Royal Blue (`#0352A1`)
- Page Background: App Canvas (`#F6F8FB`)
- Panel Background: Panel Base (`#FFFFFF`)
- Secondary Surface: `#F3F6FA`
- Tinted Surface: `#EDF4FB`
- Heading text: Primary Text (`#101828`)
- Body text: Secondary Text (`#475467`)
- Muted text: Tertiary Text (`#667085`)
- Subtle text: Quaternary Text (`#98A2B3`)
- Accent: Gold (`#FFC300`)
- Accent Hover: Warm Gold (`#FFCA1F`)
- Border (default): `#D6DEE8`
- Border (subtle): `#E6EBF2`
- Focus ring: `0 0 0 4px rgba(3,82,161,0.12)`

### Example Component Prompts
- "Create a hero section on `#F6F8FB` background. Headline at 48px Inter Variable weight 510, line-height 1.00, letter-spacing -1.056px, color `#101828`, font-feature-settings `'cv01', 'ss03'`. Subtitle at 18px weight 400, line-height 1.60, color `#667085`. Brand CTA button (`#0352A1`, 6px radius, 8px 16px padding) and ghost button (`#FFFFFF` bg, `1px solid #D6DEE8` border, 6px radius)."
- "Design a card on a light background: `#FFFFFF` background, `1px solid #E6EBF2` border, 8px radius. Title at 20px Inter Variable weight 590, letter-spacing -0.24px, color `#101828`. Body at 15px weight 400, color `#667085`, letter-spacing -0.165px."
- "Build a pill badge: `#F3F6FA` background, `#475467` text, 9999px radius, 0px 10px padding, `1px solid #E6EBF2` border, 12px Inter Variable weight 510."
- "Create navigation: light sticky header on `#FFFFFF` with subtle bottom border `1px solid #E6EBF2`. Inter Variable 13px weight 510 for links, `#475467` text. Brand royal blue CTA `#0352A1` right-aligned with 6px radius."
- "Design a command palette: `#FFFFFF` background, `1px solid #E6EBF2` border, 12px radius, soft elevated shadow. Input at 16px Inter Variable weight 400, `#101828` text. Results list with 13px weight 510 labels in `#475467` and 12px metadata in `#98A2B3`."

### Iteration Guide
1. Always set font-feature-settings `"cv01", "ss03"` on all Inter text.
2. Letter-spacing scales with font size: -1.584px at 72px, -1.056px at 48px, -0.704px at 32px, normal below 16px.
3. Three weights: 400 (read), 510 (emphasize/navigate), 590 (announce).
4. Surface elevation should move from `#F6F8FB` canvas to `#FFFFFF` panels with restrained shadows, not from dark to darker surfaces.
5. Royal blue (`#0352A1`) is the primary chromatic anchor, while gold (`#FFC300`) is the controlled secondary accent.
6. Borders should stay cool, light, and clear; avoid muddy dividers.
7. Berkeley Mono for any code or technical content, Inter Variable for everything else.
