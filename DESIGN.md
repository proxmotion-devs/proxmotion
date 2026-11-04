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

Proxmotion's admin panel is a quiet operations room. Everything at rest is neutral: white or near-black surfaces, grey text, hairline borders. Only two things are allowed to speak. One is **state**: a green, amber or red badge, an amber-bordered panel, a red error. The other is **the provider's single brand colour**, carried by primary buttons and the active navigation item. Focus rings stay neutral, so the brand can never hide where the keyboard is. Staff look across a room of servers, tasks and nodes. Whatever is coloured is what needs their attention, or what they can act on.

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
- **Provider Accent** (default near-black `hsl(0 0% 9%)` light, near-white `hsl(0 0% 98%)` dark): primary buttons, the active sidebar item, links styled as primary. At runtime the provider's branding colour replaces `--primary` and `--sidebar-primary` in both modes, with black or white text chosen by WCAG contrast. The form warns when it looks like the amber or red of status.
- **Focus Ring** (`--ring`: `hsl(0 0% 3.9%)` light, `hsl(0 0% 83.1%)` dark): neutral, never the brand colour. The components draw it as a 3px ring at 50%, which stays at least 3.7:1 against the page in both modes (3.74 and 3.94 measured). Treat the default as a placeholder: design every screen as if this colour could be any hue.

### Secondary
- **Status Green** (`status-success-*`, Tailwind emerald 100/800; dark: emerald 900 at 40% / emerald 300): healthy, active, succeeded.
- **Status Amber** (`status-warning-*`, Tailwind amber 100/800, border amber 300; dark: amber 900 at 40% / amber 300, border amber 800): suspended, degraded, over a limit, needs a look. Also used for warning detail text (`--status-warning-text`: amber 700 / amber 400) and the border of a panel that needs attention (`--status-warning-border`).
- **Status Red** (`status-danger-*`, Tailwind red 100/800; dark: red 900 at 40% / red 300; inline text `--status-danger-text`: red 700 / red 400) and **Destructive Red** (`destructive`): failed, error, and the buttons that delete or terminate.

Solid marks (meter bars, live dots) use `--status-success-fill` (emerald 500) and `--status-warning-fill` (amber 500); a full meter uses `destructive`.

All status and guide colours live as CSS variables in `panel/resources/css/app.css` (`--status-*`, `--guide*`, light and `.dark`) and as Tailwind colours (`status-success`, `status-warning-text`, `guide`, …). No admin page uses palette classes (`text-amber-700`, `bg-red-50`, …) any more; new code must not bring them back. The one exception is the customer console page (`pages/console/Show.vue`), a fixed black surface around noVNC that keeps its own light-on-dark colours in both modes.

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

**The Any-Colour Rule.** Never rely on the accent's hue for meaning or contrast. The provider may pick yellow, pale blue or black. Meaning comes from labels, icons and the status tones; the accent only marks "primary". Focus is drawn in the neutral ring colour, never in the accent.

**The Explanation Is Not State Rule.** Sky belongs to guides only. Don't use it for info badges, links in general, or "in progress".

## Typography

**Body Font:** Instrument Sans (weights 400, 500, 600 via Bunny Fonts), with `ui-sans-serif, system-ui, sans-serif`
**Label/Mono Font:** the system monospace stack (`ui-monospace, …`) for IP addresses, VMIDs, UUIDs, tokens, paths and `<code>`

**Character:** One humanist-grotesque family, used at small sizes and medium weights. It reads friendly rather than technical, so the monospace is kept for machine values only.

### Hierarchy
- **Page title** (600, 1.25rem, tight tracking -0.025em): the `Heading` at the top of each page, followed by a muted 0.875rem description. It has no margin of its own: the page's `gap-6` column spaces it (24px), and a page without one passes a class.
- **Metric** (600, 1.875rem, tabular numbers): dashboard figures and stat cards.
- **Section title** (500, 1rem): `Heading variant="small"` for sections and dialogs.
- **Body** (400, 0.875rem, ~1.43): almost all UI text, table cells, form labels (500), table headers (500, muted). Inputs use 1rem on phones and 0.875rem from `md` up, so mobile browsers do not zoom.
- **Label** (500, 0.75rem): badges, helper and detail text, timestamps.
- **Code** (monospace, 0.75rem on a muted pill): inline technical values inside prose and guides.

### Named Rules
**The Tabular Figures Rule.** Every number that is compared or updates live (capacity, traffic, counts, percentages, sizes) uses tabular numerals.

**The Page Language Rule.** Numbers are written the way the page language writes them, through `lib/format.ts` (`formatNumber`, `formatPercent`, `formatBytes`, …), never with `toFixed` or a literal `%`: "1.5 GiB" and "45%" in English, "1,5 GiB" and "%45" in Turkish. Units stay as they are (KiB, GB, Mbps).

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
- **Typed confirmation:** anything that destroys a server's VM or disks (delete, rebuild; reinstall, rollback and restore follow the same pattern in their own dialogs) uses `TypedConfirmDialog`: a red-text outline trigger, a dialog showing the hostname as selectable monospace text above an empty monospace field (`HostnameConfirmField`, shared with the reinstall and move dialogs; never the hostname as a placeholder, which looks already typed), and a destructive submit that stays disabled until the text matches (trimmed, case-insensitive); the server checks `confirm_hostname` again. On the server page, Delete sits on its own row below a hairline, apart from Suspend and Reinstall, with one line saying what is lost. `ConfirmDelete` stays for records the panel can recreate (locations, pools, templates). Any other yes/no question uses `ConfirmDialog` (destructive when it wipes something); never the browser's `window.confirm()`, which can be neither styled nor translated.
- **Focus:** border turns to the neutral ring colour plus a 3px ring at 50% opacity (at least 3.7:1 against the page, whatever the brand colour). Disabled is 50% opacity with no pointer events.

### Status badges
The panel's main signal. `StatusBadge` takes a tone (`StatusTone`: success, warning, danger, neutral, progress) and an English label key. The colours are the `--status-*` tokens of `app.css` (`bg-status-success`, `text-status-success-foreground`, …), never Tailwind palette classes.
- **Style:** 6px corners, 2px × 8px padding, 0.75rem medium text, a tinted fill with dark text of the same hue (light mode), or a 40% deep fill with light text (dark mode). No border.
- **Progress:** work still running (provisioning, reinstalling, deleting, installing, a running task, run or move) uses the neutral colours with a 12px spinning loader before the label; with reduced motion the loader stays but does not spin. Queued work stays plain neutral.
- **Rule:** always a word, never colour alone; "Recorded only" style neutral badges are as important as the coloured ones.

**The Busy Is Not Wrong Rule.** Amber means "needs a look". Something that is merely in progress is `progress`, never `warning`; a state an administrator chose on purpose (Draft, Modified, Custom, Paid, Test server, Reserved) is `neutral`.

### Panels / Cards
- **Corner Style:** 12px.
- **Background:** Paper, the same as the page; separated by a hairline border only.
- **Shadow Strategy:** none (see Elevation & Depth).
- **Attention state:** amber 300 border (amber 800 dark) and amber detail text.
- **Internal Padding:** 16px (20px for dashboard link cards). Clickable cards hover to `muted` at 40%.

### Inputs / Fields
- **Style:** 36px high, 6px corners, `input` hairline stroke, transparent fill (input grey at 30% in dark mode), stroke shadow, 12px horizontal padding.
- **Focus:** ring-coloured border plus 3px ring at 50%.
- **Error:** destructive border and a destructive ring at 20% (40% dark); the message sits below via `InputError` in the danger text tone. `FormField` links its control to the hint and the error (`aria-describedby`) and sets `aria-invalid` while there is an error, so every field inside a `FormField` gets the red border without its own code.
- **Disabled:** 50% opacity, not-allowed cursor.
- **Selects:** `NativeSelect` uses the platform select, styled into the theme; where `appearance: base-select` is supported it gets the panel's own menu look with muted uppercase group labels.
- **Wizard steps** (new server): 32px outline buttons with 6px corners and the focus ring, the step number in tabular figures (a check once passed); the current step takes a foreground border and medium weight, never the provider colour; steps not reached yet are disabled at 50%. A disabled **Next** says what is missing in one muted line beside it ("Choose a package."). A new step takes focus on its visually hidden name ("Step 2 of 6: Resources & placement"), so screen readers hear where they are and Tab goes on to its first field.

### Navigation
- **Sidebar:** links with 16px icons on the Sidebar Ground, in four labelled groups of at most five pages, daily work first: **Operate** (Dashboard, Servers, Drift report, Reports), **Infrastructure** (Locations, Clusters, IP pools, Placement groups, DNS providers), **Catalog** (OS templates, Packages, Firewall rule sets, Apps), **Administration** (Billing systems, Administrators, Panel settings, Help); `useMainNavigationGroups()`. A new page joins the group it belongs to rather than a new one. The active item takes the light grey sidebar accent fill and medium weight. The provider colour reaches the sidebar through `--sidebar-primary`, which fills the 32px logo tile in the sidebar header. It collapses to icons on desktop and slides in as a sheet on phones.
- **Skip link:** the first Tab on every admin page shows **Skip to content** (`SkipLink`) in the top left corner, above the viewport until focused, which jumps past the menu to `#main-content`.
- **Header:** breadcrumbs at body size beside the sidebar trigger; on the right, **Find a server** (`ServerQuickFind`): a search box (16rem, 20rem from `lg`) taking a hostname, IP address, billing ID or VMID, opening the server list searched for it, focused with Ctrl+K / ⌘K (shown as a muted `kbd` hint). On phones it is a 32px search icon linking to the server list.

### Tables
- **Header:** `muted` at 50% fill, muted 0.875rem medium text, left-aligned. A column of row actions has no visible heading but is named "Actions" for screen readers (`sr-only`); no `<th>` is ever empty.
- **Rows:** hairline top border, hover to `muted` at 30%; primary cell (hostname, name) is a medium-weight link.
- **Wrapper:** 12px rounded hairline border with horizontal scroll on narrow screens.
- **Above the table:** a search form (input plus a 36px outline icon button, full width on phones, 24rem from `sm`) and filters in one wrapping row.
- **Pagination:** the shared `Pagination` component: 32px targets, outline *Previous* / *Next* with chevrons (dimmed when unavailable), plain page numbers, the current page filled with the Provider Accent and `aria-current`. Laravel's HTML labels are never rendered.
- **Empty:** one centred muted line with 40px vertical padding that says why it is empty (no records yet, nothing matches the search, nothing with this status).

### Guide Box (signature)
`GuideBox` opens explanatory pages with a calm how-to: 12px corners, sky border at 30%, sky fill at 5% (`border-guide/30`, `bg-guide/5`), a sky lightbulb icon (`text-guide-icon`), a medium title, muted body text with styled lists and inline code, and a "More in Help" link (`text-guide-link`). A 32px ghost × hides it, which is remembered per browser and read before the first render, so a hidden guide never flashes open and shifts the page; hidden guides leave a 32px-high "Show guide" text button. Its title is an `h2` under the page title. Every control in it shows the focus ring. It is the panel's reassurance made visible.

### Notices
`Notice` (components/admin) is the one way to say something about the state of what a page shows ("restart to apply", "suspended over its quota", "the VM was deleted outside the panel", a task's error): 8px corners, 12px padding, a 16px icon before the text. `warning` (default) uses the amber tint and border with a triangle icon; `danger` uses the red tint, a red border at 40% and a circled exclamation; `success` (a check that passed, a token or secret shown once) uses the green tint and a circled check. Body text stays in the foreground colour; bold lines inside carry the detail (the reason, the figures). A notice about something the administrator can do from here carries that control (a small outline button on the page background, after the text): "restart to apply" offers **Reboot now** (confirmed) or **Start now**, never "use the button over there". Never hand-build an amber or red box.

### Error pages
A request that fails in the browser gets the panel's own page (`pages/errors/Show.vue`, `AppHttpErrorPages`) on the sign-in layout, never Laravel's plain HTML or Inertia's overlay: a title that names what happened ("Page not found", "The panel is being updated"), one line on what to do, the code in small muted monospace ("Error 404"), **Try again** for a server error or maintenance, and **Back to the dashboard** only for someone signed in (a customer on the console page never sees it). A server error keeps Laravel's stack trace page while debugging. An expired page (419) goes back with a toast, and a visit or poll that cannot reach the panel at all shows one error toast, at most every 30 seconds.

### Tabs
Page tabs (the server page) sit on a hairline with a 2px underline in the foreground colour, not the accent, so a pale provider colour cannot hide the selection. Counts are small muted pills; running work shows a 14px spinner. Arrow keys, Home and End move between tabs, only the selected tab is in the tab order, focus shows the inset ring, and the row scrolls sideways on phones instead of widening the page: the edge with more tabs behind it fades out (a 2.5rem mask, only while there is more to scroll to), and the chosen tab scrolls into view. The tabs name their panel (`aria-controls`) and the panel names its tab (`role="tabpanel"`, `aria-labelledby`).

### Server page
The Overview tab follows the support job, not the subsystems: running tasks, then **Everyday tasks** (Reset root password, Reinstall, Change hostname) with one muted line saying why any of them is unavailable (linked with `aria-describedby`), then **IP addresses**, then the Resources / Access / Placement cards, then live figures, usage and diagnostics, and last **Suspend or delete**. A reason for a disabled control sits next to the control, never only in a notice at the top of the page. IP rows put what the address is on the left (monospace address, Primary badge, pool name in sans, the PTR under it) and its actions on the right as 32px targets with text ("Reverse DNS"); rows that cannot be removed keep the remove slot empty so the actions line up, and on phones the actions wrap under the address instead of squeezing it.

In the header, the power state is worded apart from the panel's status ("Powered on" / "Powered off" next to "Active"), only the usual power action is a button (Start when off, Reboot when on), and Shut down and Stop (red) sit in a "More" menu beside it. Diagnostics stay quiet when they have nothing to say: when no check failed, Checks shows one line with a "Show checks" toggle (`aria-expanded`), and any failed check shows the full list. Each check's icon is backed by its status in words for screen readers ("Failed:"), and the "Checked inside the server" line is a polite live region that also says how many checks failed, so Check again is heard. Sections under the page title are `h2`, whatever their size. Polling stays audible too: the power badge is a polite status region ("Powered on" is read out when it changes), `TaskAnnouncer` reads out a task that starts, succeeds or fails while the page is open, whatever the tab ("Reinstall: Failed."), and every step of a task has its state in words before it ("Running:", "Still to come:").

### Attention list
The dashboard's first panel lists only the problems that exist right now (failed tasks of the last 24 hours, servers in error, clusters that failed to sync, nodes not online, unhealthy storages and disks, drift, usage limits), one 12px × 16px row each: a 16px icon in the danger or warning text tone, the label, the count in tabular figures and a chevron when the row links somewhere. Details (the failed tasks, the servers over their limits) sit indented under their row; when there are more than it lists, the last line links to the report that has the rest ("And 2 more in the operations report."), never a dead end. The panel's border turns red at 40% when a danger row is present and amber when only warnings are; with nothing to show it says so in one calm line with a green check. Counts that are not problems (servers by status, the inventory) live in a gap-px stat strip and a divided list, never in rows of same-size cards.

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
