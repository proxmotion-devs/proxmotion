# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

- **Primary: a hosting provider's staff, in two layers.**
  - *Sysadmins* set up and run the infrastructure: connect Proxmox clusters and standalone nodes, build OS templates, define IP pools, packages, placement groups, firewall rule sets, app recipes, billing systems and panel settings. They also step in when something breaks (failed tasks, drift, unhealthy storage, stuck provisioning).
  - *Support staff*, less technical, handle day-to-day server work for customers who opened a ticket: reinstall, root password, power, console, IP addresses, hostname, rDNS, snapshots and backups, apps. They need to act safely without understanding Proxmox internals.
- Every panel user is an administrator (no roles, ADR 0007) who must pass TOTP 2FA before any admin page opens.
- **Secondary: the provider's customers**, who never sign in to Proxmotion. They manage their VPS in the billing system's client area (WHMCS, WISECP) through Proxmotion's modules. The only Proxmotion page they ever see is the browser console, opened through a signed, single-use link.
- **Future: evaluators** — hosting providers comparing Proxmotion with Virtualizor or VirtFusion, on a planned Proxmotion website and documentation.

## Product Purpose

Proxmotion is an open-source (AGPL-3.0) VPS management panel for Proxmox VE. Providers connect their nodes and clusters; when a customer buys a VPS in the billing system, the server is provisioned automatically and every later customer action reaches the panel through its billing API. Success for staff: infrastructure set up once, servers provisioned and operated without touching Proxmox by hand, problems seen before customers report them, and support work done quickly without risky mistakes.

## Positioning

All four are confirmed as the claims future work must carry:

1. **Open source, no lock-in.** AGPL-3.0, no licence fee, auditable code, a general-purpose product with nothing company-specific hard-coded.
2. **Billing-first, no client panel.** Customers live in their billing system; Proxmotion is a staff tool plus an API. Every customer-facing feature exists as an API endpoint and in each billing module.
3. **Honest and secure.** It states plainly what has not been verified against real systems; it never uses SSH to hypervisors; it enforces anti-spoofing, guest isolation, scoped hashed API tokens, mandatory 2FA, audited destructive actions.
4. **Proxmox-native depth.** Clusters and standalone nodes side by side, shared vs. local storage, ZFS / thin pool / Ceph / SMART health, drift reports, live and cross-cluster moves, linked clones, guest-agent-driven configuration.

## Operating Context

- Staff work in the admin panel (Inertia + Vue SPA), mostly on desktop, but **phone use during incidents is a real scenario** (checking a failed task, power-cycling or suspending a server away from a desk).
- Long Proxmox operations run in a queue; pages poll or receive live updates while tasks run, showing stages, progress and estimates. Much of the work is watching state change: task progress, app install logs, live CPU/memory/network figures, usage history.
- Destructive operations (terminate, reinstall, rebuild, rollback, restore, node/cluster removal) require extra confirmation, often typing the hostname, and are written to the audit log.
- Setup follows an order shown on *Admin → Help*; pages that need explaining open with a hideable how-to (`GuideBox`).
- UI in **English and Turkish** from day one; English strings are the translation keys. Turkish text runs longer and must fit.
- Customers reach the console page from WHMCS / WISECP; it shows the provider's branding and support contacts.

## Capabilities and Constraints

- Full feature set and verification status: `AGENTS.md` §1, §3, §10 and `docs/wiki/Features.md`. Many features are tested only against fakes; the UI must not present unverified behaviour as proven.
- Stack is fixed: Laravel 13, Vue 3 + Inertia 3 + TypeScript, Tailwind CSS 4, shadcn-vue (reka-ui) components (`resources/js/components/ui/` is generated, avoid editing), Wayfinder routes, `laravel-vue-i18n`.
- Units: disk and RAM in MiB, traffic in bytes, port speed in Mbps; converted in one place.
- Terminology to keep: cluster, node, storage, location, placement group, package, OS template, IP pool, network model (bridged, routed, NAT, IPv6), task, drift, app / recipe, billing system, panel settings.
- No company-specific values (names, IP ranges, hostnames, branding) in code, seeds or defaults. Fixtures and screenshots use documentation IP ranges and `example.com`.
- Secrets (Proxmox tokens, root passwords, app outputs) are never shown in UI props or logs; root passwords and secret outputs are revealed once or behind an audited *Show details*.
- Not yet built: NAT VPS, LXC, rescue mode, reseller system, the Virtualizor importer (on hold), more billing modules. Open decision: the planned Proxmotion website and documentation site have no scope, content or stack yet.

## Brand Commitments

- **White-label first.** Each provider sets the panel's name, one colour, logo, dark-mode logo, favicon and support contacts under *Panel settings → Branding* (ADR 0053). That one colour drives `--primary`, `--sidebar-primary` and `--ring` in both light and dark mode, with black or white text chosen by contrast; colours below 3:1 against the page are warned about, not refused. Proxmotion's own identity stays in the background of the panel and must not compete with the provider's brand. Any design must hold up with an arbitrary provider colour and logo, in light and dark mode.
- **Proxmotion's own brand** (name, logo, favicons in `panel/public/`, `BrandMark.vue` / `AppLogo*.vue`) leads only on Proxmotion's own surfaces: the planned website and documentation, the README, and the panel's defaults before a provider brands it.
- Voice: plain, precise, factual English (and Turkish); says what happened and what was not verified; no marketing claims inside the panel.

## Evidence on Hand

- Feature overview and verification status: `docs/wiki/Features.md`; architecture decisions: `docs/adr/` (0001–0056); API contract: `docs/api/openapi.yaml`; guides under `docs/guides/` (with Turkish translations in `docs/guides/tr/`).
- Proxmotion logo and favicons: `panel/public/favicon.svg`, `favicon.ico`, `apple-touch-icon.png`.
- Version 0.1.0, explicitly **not ready for production**. Only part of it has been checked against a real Proxmox VE server; WHMCS and WISECP modules have not run in a real billing system.
- No customers, testimonials, case studies, benchmarks, press or adoption figures exist. Future work, especially the website, must not invent them.

## Product Principles

1. **Safe by default for the less technical operator.** Support staff should be able to do routine work without being able to cause damage by accident: clear state, clear consequences, confirmation for anything destructive, reasons shown when something is unavailable.
2. **Truth over reassurance.** Show real state from Proxmox and the guest, say what is pending, failed or unverified, and never paper over drift or partial failure.
3. **The provider's brand, Proxmotion's craft.** The panel carries the provider's identity; Proxmotion shows itself through precision and reliability, not its logo.
4. **Billing system is the customer's home.** Customer-facing capability goes into the API and modules, never into a Proxmotion client area.
5. **Proxmox depth without Proxmox burden.** Expose the infrastructure detail sysadmins need, while keeping everyday tasks free of hypervisor jargon.

## Accessibility & Inclusion

- Target: **WCAG 2.2 AA** for the admin panel, auth pages and the customer console page.
- Branding colours are arbitrary: contrast and focus visibility must hold for any provider colour in both modes (already warned below 3:1 for interface parts).
- Layouts must work on phone widths for incident use, and fit Turkish text lengths.
- Light and dark mode are both first-class.
