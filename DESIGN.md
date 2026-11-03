---
name: Proxmotion
description: A calm, white-label control room for running Proxmox VPS infrastructure.
colors:
  primary: "hsl(0 0% 9%)"
  primary-foreground: "hsl(0 0% 98%)"
  background: "hsl(0 0% 100%)"
  foreground: "hsl(0 0% 3.9%)"
  sidebar: "hsl(0 0% 98%)"
  secondary: "hsl(0 0% 92.1%)"
  secondary-foreground: "hsl(0 0% 9%)"
  muted: "hsl(0 0% 96.1%)"
  muted-foreground: "hsl(0 0% 45.1%)"
  border: "hsl(0 0% 92.8%)"
  input: "hsl(0 0% 89.8%)"
  destructive: "hsl(0 84.2% 60.2%)"
  dark-background: "hsl(0 0% 3.9%)"
  dark-foreground: "hsl(0 0% 98%)"
  dark-sidebar: "hsl(0 0% 7%)"
  dark-muted: "hsl(0 0% 16.08%)"
  dark-muted-foreground: "hsl(0 0% 63.9%)"
  dark-border: "hsl(0 0% 14.9%)"
  status-success-bg: "oklch(95% 0.052 163.051)"
  status-success-text: "oklch(43.2% 0.095 166.913)"
  status-warning-bg: "oklch(96.2% 0.059 95.617)"
  status-warning-text: "oklch(47.3% 0.137 46.201)"
  status-warning-border: "oklch(87.9% 0.169 91.605)"
  status-danger-bg: "oklch(93.6% 0.032 17.717)"
  status-danger-text: "oklch(44.4% 0.177 26.899)"
  guide-accent: "oklch(68.5% 0.169 237.323)"
  series-1: "#2a78d6"
  series-2: "#eb6834"
typography:
  page-title:
    fontFamily: "Instrument Sans, ui-sans-serif, system-ui, sans-serif"
    fontSize: "1.25rem"
    fontWeight: 600
    lineHeight: 1.4
    letterSpacing: "-0.025em"
  metric:
    fontFamily: "Instrument Sans, ui-sans-serif, system-ui, sans-serif"
    fontSize: "1.875rem"
    fontWeight: 600
    lineHeight: 1.2
    fontFeature: "\"tnum\""
  section-title:
    fontFamily: "Instrument Sans, ui-sans-serif, system-ui, sans-serif"
    fontSize: "1rem"
    fontWeight: 500
    lineHeight: 1.5
  body:
    fontFamily: "Instrument Sans, ui-sans-serif, system-ui, sans-serif"
    fontSize: "0.875rem"
    fontWeight: 400
    lineHeight: 1.43
  label:
    fontFamily: "Instrument Sans, ui-sans-serif, system-ui, sans-serif"
    fontSize: "0.75rem"
    fontWeight: 500
    lineHeight: 1.33
  code:
    fontFamily: "ui-monospace, SFMono-Regular, Menlo, Consolas, monospace"
    fontSize: "0.75rem"
    fontWeight: 400
    lineHeight: 1.33
rounded:
  sm: "4px"
  md: "6px"
  lg: "8px"
  xl: "12px"
  full: "9999px"
spacing:
  "1": "4px"
  "2": "8px"
  "3": "12px"
  "4": "16px"
  "5": "20px"
  "6": "24px"
  "10": "40px"
components:
  button-primary:
    backgroundColor: "{colors.primary}"
    textColor: "{colors.primary-foreground}"
    typography: "{typography.body}"
    rounded: "{rounded.md}"
    padding: "8px 16px"
    height: "36px"
  button-outline:
    backgroundColor: "{colors.background}"
    textColor: "{colors.foreground}"
    rounded: "{rounded.md}"
    padding: "8px 16px"
    height: "36px"
  button-secondary:
    backgroundColor: "{colors.secondary}"
    textColor: "{colors.secondary-foreground}"
    rounded: "{rounded.md}"
    padding: "8px 16px"
    height: "36px"
  button-destructive:
    backgroundColor: "{colors.destructive}"
    textColor: "#ffffff"
    rounded: "{rounded.md}"
    padding: "8px 16px"
    height: "36px"
  input:
    backgroundColor: "{colors.background}"
    textColor: "{colors.foreground}"
    rounded: "{rounded.md}"
    padding: "4px 12px"
    height: "36px"
  panel:
    backgroundColor: "{colors.background}"
    rounded: "{rounded.xl}"
    padding: "16px"
  table-header:
    backgroundColor: "{colors.muted}"
    textColor: "{colors.muted-foreground}"
    typography: "{typography.body}"
    padding: "12px 16px"
  status-badge-success:
    backgroundColor: "{colors.status-success-bg}"
    textColor: "{colors.status-success-text}"
    typography: "{typography.label}"
    rounded: "{rounded.md}"
    padding: "2px 8px"
  status-badge-warning:
    backgroundColor: "{colors.status-warning-bg}"
    textColor: "{colors.status-warning-text}"
    typography: "{typography.label}"
    rounded: "{rounded.md}"
    padding: "2px 8px"
  status-badge-danger:
    backgroundColor: "{colors.status-danger-bg}"
    textColor: "{colors.status-danger-text}"
    typography: "{typography.label}"
    rounded: "{rounded.md}"
    padding: "2px 8px"
  status-badge-neutral:
    backgroundColor: "{colors.muted}"
    textColor: "{colors.muted-foreground}"
    typography: "{typography.label}"
    rounded: "{rounded.md}"
    padding: "2px 8px"
---

# Design System: Proxmotion

## Overview

**Creative North Star: "The Control Room"**

Proxmotion's admin panel is a quiet operations room. Everything at rest is neutral: white or near-black surfaces, grey text, hairline borders. Only two things are allowed to speak. One is **state**: a green, amber or red badge, an amber-bordered panel, a red error. The other is **the provider's single brand colour**, carried by primary buttons, the active navigation item and focus rings. Staff look across a room of servers, tasks and nodes. Whatever is coloured is what needs their attention, or what they can act on.

The panel belongs to the hosting provider, not to Proxmotion. Its neutral base is deliberate: the provider sets one colour and a logo under *Panel settings → Branding*, and the panel takes them on without clashing. Proxmotion's own craft shows in precision rather than identity: tabular numbers that line up, status that is never ambiguous, guides that explain before anyone gets lost.

The intended feel is **comfortable and reassuring**. Support staff who are less technical than the sysadmins do routine work here, sometimes on a phone during an incident. Spacing stays generous, controls are full-size targets, destructive actions are clearly separated and confirmed, and every page can open with a calm, hideable how-to. Density comes from clear structure, never from shrinking things.

**Key Characteristics:**
- Achromatic neutral base in light and dark mode; colour only for state and the brand accent.
- One brand colour, injected at runtime; no second accent.
- Flat surfaces divided by 1px borders; shadows only for things floating above the page.
- Instrument Sans throughout; tabular numbers for every figure; monospace for addresses, IDs and code.
- Inset sidebar shell: navigation on a faint grey ground, content on an inset rounded sheet.
- Bilingual (English, Turkish) layouts that tolerate longer strings.

## Colors

A greyscale room with a single, swappable brand light and a fixed traffic-light vocabulary for state.

### Primary
- **Provider Accent** (default near-black `hsl(0 0% 9%)` light, near-white `hsl(0 0% 98%)` dark): primary buttons, the active sidebar item, the focus ring, links styled as primary. At runtime the provider's branding colour replaces `--primary`, `--sidebar-primary` and `--ring` in both modes, with black or white text chosen by WCAG contrast. Treat the default as a placeholder: design every screen as if this colour could be any hue.

### Secondary
- **Status Green** (`status-success-*`, Tailwind emerald 100/800; dark: emerald 900 at 40% / emerald 300): healthy, active, succeeded.
- **Status Amber** (`status-warning-*`, Tailwind amber 100/800, border amber 300; dark: amber 900 at 40% / amber 300, border amber 800): suspended, degraded, over a limit, needs a look. Also used for warning detail text (`--status-warning-text`: amber 700 / amber 400) and the border of a panel that needs attention (`--status-warning-border`).
- **Status Red** (`status-danger-*`, Tailwind red 100/800; dark: red 900 at 40% / red 300; inline text `--status-danger-text`: red 700 / red 400) and **Destructive Red** (`destructive`): failed, error, and the buttons that delete or terminate.

All status and guide colours live as CSS variables in `panel/resources/css/app.css` (`--status-*`, `--guide*`, light and `.dark`) and as Tailwind colours (`status-success`, `status-warning-text`, `guide`, …). Pages still using palette classes (`text-amber-700`, `bg-red-50`, …) move to them as each part of the panel is reworked.

### Tertiary
- **Guide Sky** (`guide-accent`, Tailwind sky 500, used at 30% for borders and 5% for fills; icon sky 600 / 400, link sky 700 / 400): only for `GuideBox` how-tos and their "More in Help" link. It marks *explanation*, never state or action.
- **Chart Series** (`series-1` blue `#2a78d6` / `#3987e5` dark, `series-2` orange `#eb6834` / `#d95926` dark): the two series of live and historical usage charts (e.g. in / out, read / write). The same hues in both modes.

### Neutral
- **Paper** (`background`, `hsl(0 0% 100%)`; dark `hsl(0 0% 3.9%)`): page and inset content sheet, cards, popovers.
- **Ink** (`foreground`, `hsl(0 0% 3.9%)`; dark `hsl(0 0% 98%)`): primary text.
- **Sidebar Ground** (`sidebar`, `hsl(0 0% 98%)`; dark `hsl(0 0% 7%)`): the shell around the inset sheet.
- **Quiet Grey** (`muted` `hsl(0 0% 96.1%)`, `muted-foreground` `hsl(0 0% 45.1%)`; dark `hsl(0 0% 16.08%)` / `hsl(0 0% 63.9%)`): table headers (at 50%), row hover (at 30%), descriptions, secondary text, neutral badges, inline code.
- **Hairline** (`border` `hsl(0 0% 92.8%)`, `input` `hsl(0 0% 89.8%)`; dark both `hsl(0 0% 14.9%)`): every divider, panel edge, table row rule and input stroke.

### Named Rules
**The Two Voices Rule.** Only state and the provider accent may carry colour. A new feature that wants its own hue must express it as one of the three status tones, the guide sky, or the accent, or stay neutral.

**The Any-Colour Rule.** Never rely on the accent's hue for meaning or contrast. The provider may pick yellow, pale blue or black. Meaning comes from labels, icons and the status tones; the accent only marks "primary" and "focused".

**The Explanation Is Not State Rule.** Sky belongs to guides only. Don't use it for info badges, links in general, or "in progress".

## Typography

**Body Font:** Instrument Sans (weights 400, 500, 600 via Bunny Fonts), with `ui-sans-serif, system-ui, sans-serif`
**Label/Mono Font:** the system monospace stack (`ui-monospace, …`) for IP addresses, VMIDs, UUIDs, tokens, paths and `<code>`

**Character:** One humanist-grotesque family, used at small sizes and medium weights. It reads friendly rather than technical, so the monospace is kept for machine values only.

### Hierarchy
- **Page title** (600, 1.25rem, tight tracking -0.025em): the `Heading` at the top of each page, followed by a muted 0.875rem description.
- **Metric** (600, 1.875rem, tabular numbers): dashboard figures and stat cards.
- **Section title** (500, 1rem): `Heading variant="small"` for sections and dialogs.
- **Body** (400, 0.875rem, ~1.43): almost all UI text, table cells, form labels (500), table headers (500, muted). Inputs use 1rem on phones and 0.875rem from `md` up, so mobile browsers do not zoom.
- **Label** (500, 0.75rem): badges, helper and detail text, timestamps.
- **Code** (monospace, 0.75rem on a muted pill): inline technical values inside prose and guides.

### Named Rules
**The Tabular Figures Rule.** Every number that is compared or updates live (capacity, traffic, counts, percentages, sizes) uses tabular numerals.

**The Machine Values Rule.** Addresses, identifiers and code are monospace; human words never are.

## Layout

- **Shell:** an inset sidebar layout. The sidebar is 16rem (collapses to a 3rem icon rail on desktop; becomes an 18rem sheet below `md`, 768px) on the Sidebar Ground. Content sits on an inset Paper sheet beside it. A 64px header (48px when collapsed) holds the sidebar trigger and breadcrumbs above a hairline.
- **Page:** content is a vertical flex column with 16px padding (`p-4`) and 24px gaps (`gap-6`) between blocks: heading, guide, then panels and tables.
- **Grids:** stat cards and summary panels flow 1 → 2 (`sm`, 640px) → 3 (`lg`, 1024px) columns with 16px gaps.
- **Spacing rhythm:** a 4px base; 8px inside controls and lists, 12px between related items, 16px inside panels and around groups, 24px between page sections. Card-style panels may use 20px.
- **Tables:** full-width inside a rounded, bordered, horizontally scrollable wrapper; cells 16px × 12px; empty states centred with 40px vertical padding.
- **Comfort over density:** when space is tight, wrap (`flex-wrap`) or scroll a table horizontally rather than shrinking type or targets. Turkish strings are often a third longer than English; leave room.

## Elevation & Depth

The room is flat. Surfaces sit on the same plane and are separated by hairline borders and the tonal step between Sidebar Ground and Paper. Depth is used only for things that float above the page: popovers, select pickers, dropdown menus, dialogs and toasts (`shadow-md` and above). Inputs and outline buttons have a barely-there `shadow-xs` that reads as a stroke, not a lift. The generated `Card` component still ships `shadow-sm`; hand-built panels do not use it, and new panels should not.

### Shadow Vocabulary
- **Stroke** (`box-shadow: 0 1px 2px 0 rgb(0 0 0 / 0.05)`): inputs and outline buttons.
- **Float** (`box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1)`): menus, select pickers, popovers.

### Named Rules
**The Flat-Room Rule.** At rest nothing casts a shadow. If an element is not floating over other content, separate it with a border or a tonal step, not a shadow.

## Shapes

Soft, consistent corners at three sizes. Controls (buttons, inputs, selects, status badges, menu items) use 6px. Panels, cards, table wrappers and guides use 12px. Pill shapes (9999px) are reserved for the generic `Badge`, avatars and switches. Borders are always 1px hairlines. A panel that needs attention changes the colour of its border (amber), never its weight.

## Components

### Buttons
Steady and unhurried: full-height targets with one clear primary per area.
- **Shape:** gently rounded (6px), 36px high (32px small, 40px large), 16px horizontal padding, 0.875rem medium text, 16px icons with 8px gap.
- **Primary:** Provider Accent fill with its contrasting text; hover at 90% opacity.
- **Outline:** Paper fill, hairline border, stroke shadow; hover to the accent-grey (`accent`) fill. The default for secondary actions in toolbars and dialogs.
- **Secondary / Ghost:** light grey fill, or no fill until hover. Ghost for icon buttons and row actions.
- **Destructive:** Destructive Red fill with white text (red at 60% in dark mode). Used only for the final confirming action of terminate, delete, reinstall, rollback or restore, normally inside `ConfirmDelete` or a dialog that asks for the hostname.
- **Focus:** border turns to the ring colour plus a 3px ring at 50% opacity. Disabled is 50% opacity with no pointer events.

### Status badges
The panel's main signal. `StatusBadge` takes a tone (`StatusTone`: success, warning, danger, neutral, progress) and an English label key. The colours are the `--status-*` tokens of `app.css` (`bg-status-success`, `text-status-success-foreground`, …), never Tailwind palette classes.
- **Style:** 6px corners, 2px × 8px padding, 0.75rem medium text, a tinted fill with dark text of the same hue (light mode), or a 40% deep fill with light text (dark mode). No border.
- **Progress:** work still running (provisioning, reinstalling, deleting, installing, a running task, run or move) uses the neutral colours with a 12px spinning loader before the label; with reduced motion the loader stays but does not spin. Queued work stays plain neutral.
- **Rule:** always a word, never colour alone; "Recorded only" style neutral badges are as important as the coloured ones.

**The Busy Is Not Wrong Rule.** Amber means "needs a look". Something that is merely in progress is `progress`, never `warning`.

### Panels / Cards
- **Corner Style:** 12px.
- **Background:** Paper, the same as the page; separated by a hairline border only.
- **Shadow Strategy:** none (see Elevation & Depth).
- **Attention state:** amber 300 border (amber 800 dark) and amber detail text.
- **Internal Padding:** 16px (20px for dashboard link cards). Clickable cards hover to `muted` at 40%.

### Inputs / Fields
- **Style:** 36px high, 6px corners, `input` hairline stroke, transparent fill (input grey at 30% in dark mode), stroke shadow, 12px horizontal padding.
- **Focus:** ring-coloured border plus 3px ring at 50%.
- **Error:** destructive border and a destructive ring at 20% (40% dark); the message sits below via `InputError`.
- **Disabled:** 50% opacity, not-allowed cursor.
- **Selects:** `NativeSelect` uses the platform select, styled into the theme; where `appearance: base-select` is supported it gets the panel's own menu look with muted uppercase group labels.

### Navigation
- **Sidebar:** grouped links with 16px icons on the Sidebar Ground; the active item takes the light grey sidebar accent fill and medium weight. The provider colour reaches the sidebar through `--sidebar-primary`, which fills the 32px logo tile in the sidebar header. It collapses to icons on desktop and slides in as a sheet on phones.
- **Header:** breadcrumbs at body size beside the sidebar trigger.

### Tables
- **Header:** `muted` at 50% fill, muted 0.875rem medium text, left-aligned.
- **Rows:** hairline top border, hover to `muted` at 30%; primary cell (hostname, name) is a medium-weight link.
- **Wrapper:** 12px rounded hairline border with horizontal scroll on narrow screens.

### Guide Box (signature)
`GuideBox` opens explanatory pages with a calm how-to: 12px corners, sky border at 30%, sky fill at 5% (`border-guide/30`, `bg-guide/5`), a sky lightbulb icon (`text-guide-icon`), a medium title, muted body text with styled lists and inline code, and a "More in Help" link (`text-guide-link`). A 32px ghost × hides it, which is remembered per browser; hidden guides leave a 32px-high "Show guide" text button. Every control in it shows the focus ring. It is the panel's reassurance made visible.

### Browser surfaces
Text selection is the provider colour at 22%, the page scrollbar uses muted grey at 40% on a transparent track, and every table cell uses tabular numerals (`app.css` base layer).

## Do's and Don'ts

### Do:
- **Do** keep every surface achromatic and let `StatusBadge` tones and the Provider Accent carry all colour.
- **Do** test every new screen with a light, a mid and a dark provider colour, in light and dark mode, and keep focus rings visible on each.
- **Do** pair every status colour with a label and, where useful, an icon.
- **Do** use tabular numerals for figures and monospace for addresses, VMIDs and IDs.
- **Do** separate panels with 1px hairlines and 12px corners; use the amber border for "needs attention".
- **Do** keep controls at their full 36px height and wrap or scroll before shrinking; leave room for Turkish strings.
- **Do** start explanatory pages with a `GuideBox` and keep destructive actions in red, at the end of a confirmation.

### Don't:
- **Don't** introduce a second brand accent or decorative colour; Proxmotion's own identity does not compete with the provider's inside the panel.
- **Don't** encode meaning in the accent's hue, since the provider can set any colour.
- **Don't** use sky outside guides, or red for anything that is not failure or destruction.
- **Don't** add resting shadows to panels and cards; shadows are for floating layers only.
- **Don't** shrink type below 0.75rem or targets below 32px to fit more onto a screen.
