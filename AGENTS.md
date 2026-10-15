# AGENTS.md — Proxmotion

This file is the single source of truth for **every AI coding agent** working in this repository (Claude Code, Gemini CLI, Codex, Cursor, etc.). `CLAUDE.md` and `GEMINI.md` import it; shared rules live only here.

---

## 1. Project Overview

**Proxmotion** is an open-source (**AGPL-3.0**), multi-tenant **VPS management panel for Proxmox VE**, in the spirit of Virtualizor and VirtFusion. Hosting providers connect their Proxmox nodes and clusters; when a customer buys a VPS through a billing system such as WHMCS, the server is provisioned automatically, and the customer manages it from their own panel.

The first deployment is the maintainers' own hosting company, but the project is built as a **general-purpose open-source product**: nothing company-specific (names, IP ranges, branding, provider APIs) may be hard-coded.

### Three faces
1. **Admin panel:** clusters and nodes, locations, IP pools, packages, OS templates, app recipes, users, servers, tasks, audit logs.
2. **Client panel:** power actions, browser console (noVNC / xterm.js), reinstall, root password reset, rDNS, SSH keys, snapshots/backups, traffic and resource graphs, **App Marketplace**.
3. **Billing API + modules:** a REST API and ready-made modules; **WHMCS first**, later Blesta, HostBill, Paymenter, FOSSBilling.

### Core features
- Manage **mixed infrastructure**: standalone Proxmox nodes and Proxmox clusters side by side (see 3.6)
- KVM (QEMU) VPS first; LXC later
- Automatic install and reinstall from cloud-init OS templates
- **Four network models:** bridged public IP, routed/failover IP, NAT VPS, IPv6 (see 3.7)
- IP pool management, automatic allocation, rDNS, MAC/IP anti-spoofing
- Packages (plans): CPU, RAM, disk, traffic quota, port speed, IP count, backup quota, network model
- Automatic placement: pick a node with enough free capacity
- Asynchronous job queue for create, suspend, unsuspend, terminate, change package
- In-browser console via a panel-side WebSocket proxy
- Traffic accounting; throttle or suspend on quota overrun
- Snapshots and backups (Proxmox Backup Server later)
- One-click SSO from the billing system into the client panel
- **App Marketplace (OVH-style):** one-click install of OpenVPN/WireGuard, control panels, Docker, web/CMS stacks — both at order time and later from the client panel (see 3.5)
- **Migration:** adopt existing Proxmox VMs; import from Virtualizor (see 3.8)
- Audit log, 2FA, scoped API tokens
- UI in **Turkish and English** from day one (i18n-ready for more)

### Non-goals (for now)
- Installing Proxmox itself or building clusters (Ceph, HA setup)
- A billing system of our own (billing is WHMCS's job)
- Non-Proxmox hypervisors
- Reseller system — deferred to Phase 9

---

## 2. Tech Stack

| Area | Choice | Rationale |
|---|---|---|
| Backend | **PHP 8.3 + Laravel 13** (official Vue starter kit) | Hosting ecosystem (WHMCS, Blesta) is PHP; queues, auth and API tooling built in |
| Auth | **Laravel Fortify** — login, password reset, email verification, TOTP 2FA, passkeys | Comes with the starter kit; public registration is **disabled** (accounts come from billing or admins) |
| Database | **MariaDB 10.11+ / MySQL 8** | Hosting-industry standard |
| Queue / cache / locks | **Redis** + **Laravel Horizon** | Long-running Proxmox tasks run asynchronously |
| Frontend | **Vue 3 + Inertia.js 3 + TypeScript + Tailwind CSS 4**, shadcn-vue (reka-ui) components, Wayfinder for typed routes | Single repo, SPA feel, no separate internal API |
| Frontend toolchain | **Vite+** (`vp`): Vite, Oxlint, Oxfmt and Vitest in one CLI | Comes with the starter kit; `vite` is aliased to `@voidzero-dev/vite-plus-core` in `package.json` |
| i18n | English source strings as keys; `lang/tr.json` + Laravel PHP lang files (`laravel-lang/common`), shared with the frontend via `laravel-vue-i18n` | One translation source for backend and frontend |
| Console | **noVNC** (KVM), **xterm.js** (LXC) behind a panel WebSocket proxy | Proxmox credentials never reach the browser |
| Charts | Chart.js or ECharts | Traffic / CPU / RAM graphs |
| Proxmox client | Our own thin HTTP client (Laravel `Http`, Guzzle) | Full control, easy faking in tests |
| Realtime | Laravel Reverb | Live task progress |
| Tests | Pest (PHPUnit), Vitest via `vp test` + happy-dom + Vue Test Utils | |
| Code quality | Laravel Pint, PHPStan/Larastan (level 8), `vp check` (Oxfmt + Oxlint), vue-tsc, shellcheck | |
| WHMCS module | Standalone PHP, compatible with WHMCS's minimum PHP | Runs on the WHMCS server, no Laravel/Composer |
| Deployment | **Docker Compose** (primary) + **native installer** for Debian/Ubuntu | See 3.9 |

Before adding a dependency, check whether existing ones already cover it. Every new dependency must be **AGPL-3.0-compatible**; state the reason in the commit message.

---

## 3. Architecture

### 3.1 High-level flow

```
WHMCS / Blesta  ──REST API (token)──▶  Proxmotion Panel (Laravel)
                                          │   ├── Admin UI (Inertia/Vue)
Customer browser ──HTTPS / WSS───────▶    │   ├── Client UI (Inertia/Vue)
                                          │   ├── Public API v1
                                          │   └── Console WS proxy
                                          ▼
                                   Redis queue (Horizon workers)
                                          │
                                          ▼
              Proxmox VE API — one connection per cluster / standalone node
                                          │
                                          ▼
                       VPS (QEMU Guest Agent, cloud-init) / NAT gateways
```

### 3.2 Repository layout

```
proxmotion/
├── AGENTS.md / CLAUDE.md / GEMINI.md
├── LICENSE                               # AGPL-3.0
├── README.md / CONTRIBUTING.md / SECURITY.md / CODE_OF_CONDUCT.md
├── panel/                                # Laravel application
│   ├── app/
│   │   ├── Models/                       # Eloquent models (Laravel convention), incl. Concerns/HasPublicUuid
│   │   ├── Enums/                        # UserRole, ClusterType, NodeStatus, NetworkModel, IpVersion, …
│   │   ├── Domain/                       # Business logic (services/actions), grouped by domain
│   │   │   ├── Infrastructure/           # ClusterSynchronizer, …
│   │   │   ├── Networking/               # GenerateIpAddresses, rDNS, firewall, NAT port forwards
│   │   │   ├── Servers/                  # power, reinstall, resize
│   │   │   ├── Usage/                    # traffic/resource accounting, quotas
│   │   │   ├── Backups/                  # snapshots, backups
│   │   │   ├── Apps/                     # RecipeLoader, app installs
│   │   │   └── Migration/                # adopt existing VMs, Virtualizor importer
│   │   ├── Proxmox/                      # Proxmox API client (the ONLY touchpoint)
│   │   │   ├── ProxmoxClient.php         # token auth, host failover, error mapping
│   │   │   ├── ProxmoxConnection.php     # hosts/port/token (secret hidden from dumps)
│   │   │   ├── ProxmoxClientFactory.php  # forCluster(), make()
│   │   │   ├── Resources/                # ClusterResource, NodesResource, TasksResource (later: Qemu, GuestAgent, Firewall…)
│   │   │   ├── Data/                     # typed DTOs of API responses (NodeInfo, StorageInfo, Upid, …)
│   │   │   ├── TaskWaiter.php            # UPID tracking
│   │   │   └── Exceptions/
│   │   ├── Support/                      # Locales, Network/Cidr
│   │   ├── Provisioning/                 # Orchestration
│   │   │   ├── ServerCreator.php         # reserve node/storage/VMID/IPs under a per-location lock, queue the task
│   │   │   ├── IpAllocator.php           # pool choice, IPv4 assignment, next free IPv6 address
│   │   │   ├── TaskRunner.php            # one Task per operation, refuses parallel tasks per server
│   │   │   ├── Placement/                # NodePlacer (least memory used after placement), Placement
│   │   │   ├── Network/                  # NetworkConfig: net0, ipconfig0, nameserver, anti-spoofing addresses
│   │   │   └── Pipelines/                # CreateServer, Power (suspend/unsuspend), TerminateServer — idempotent
│   │   ├── Jobs/                         # Queue jobs (run pipelines)
│   │   ├── Http/
│   │   │   ├── Controllers/Admin/
│   │   │   ├── Controllers/Client/
│   │   │   ├── Controllers/Api/V1/       # Billing API
│   │   │   ├── Requests/                 # FormRequest validation
│   │   │   └── Resources/                # API JSON transformers
│   │   ├── Policies/                     # Authorization (clients see only their own servers)
│   │   └── Console/                      # Scheduled commands (traffic collection, sync…)
│   ├── recipes/                          # Bundled App Marketplace recipes
│   │   ├── _schema/recipe.schema.json
│   │   ├── _lib/                         # Shared shell helpers (status writing, OS detection)
│   │   └── openvpn/ wireguard/ docker/ portainer/ coolify/ cpanel/ plesk/ aapanel/
│   │       hestiacp/ cyberpanel/ lamp/ lemp/ wordpress/ nodejs/
│   │       └── (each) recipe.yaml, install.sh, actions/*.sh, README.md
│   ├── lang/                             # tr.json (UI strings), en.json (empty: keys are English), {en,tr}/*.php
│   ├── database/migrations, factories, seeders
│   ├── resources/js/                     # Vue 3 + TS: pages/{admin,client,auth,settings}, components/, layouts/, composables/
│   ├── routes/web.php, api.php, channels.php
│   └── tests/Unit, Feature, Fixtures/proxmox/*.json
├── integrations/
│   ├── whmcs/modules/servers/proxmotion/ # WHMCS server module
│   └── blesta/                           # later
├── deploy/
│   ├── docker/                           # Dockerfiles + compose.yml (dev and production)
│   └── installer/                        # Native Debian/Ubuntu installer (install.sh, nginx, systemd units)
└── docs/                                 # Install guide, admin guide, API reference (OpenAPI), ADRs
```

### 3.3 Layering rules
1. **The Proxmox API is reached only through `app/Proxmox/`.** Controllers, jobs and models never make HTTP calls themselves.
2. **Controllers are thin:** validate (FormRequest) → authorize (Policy) → call a domain service or dispatch a job → return a Resource / Inertia response.
3. **Every operation that changes Proxmox runs in the queue.** HTTP requests never wait on a Proxmox task; they create a `Task` record and return immediately. Pages poll (`usePoll`) while a task runs; Reverb is planned for the client panel.
4. **Pipeline steps are idempotent:** re-running a half-finished pipeline recognizes what already exists and continues. Each step records what it did on the `Task`.
5. **Resource allocation is atomic:** VMID, IP addresses, NAT ports and node capacity are reserved inside a DB transaction plus a Redis lock, so two concurrent provisions can never get the same IP/VMID/port.
6. **The panel database is the source of truth.** Proxmox state is reconciled periodically; drift is reported to admins and never auto-deleted.
7. Long Proxmox tasks return a **UPID**; `TaskWaiter` polls until `exitstatus == "OK"` and handles timeouts and failures explicitly.
8. **No SSH to hypervisors.** The panel uses only the Proxmox API (and the QEMU Guest Agent through it). Anything that seems to need host shell access must be solved differently or escalated to the maintainers.

### 3.4 Core data models
`Location`, `Cluster` (one API connection: host(s), port, token id, encrypted token secret, `type: standalone | cluster`), `Node` (belongs to a cluster; capacity limits, active/maintenance), `Storage` (`shared` flag), `NetworkBridge`, `IpPool` (v4/v6, network model, gateway, netmask, VLAN, location, optional per-IP MAC), `IpAddress`, `NatPortForward`, `Package`, `OsTemplate` (template VMID mapped per node/cluster), `Iso`, `Server` (uuid, vmid, node, package, user, network model, status, suspension reason, `external_id` = billing service id, `origin: provisioned | adopted | imported`), `Task`, `UsageRecord`, `Snapshot`, `Backup`, `User` (admin/client role, locale), `SshKey`, `ApiToken` (scoped), `AuditLog`, `AppRecipe`, `AppInstallation`, `AppEntitlement`.

`Server.status`: `pending → provisioning → active ⇄ suspended`, `active → reinstalling → active`, `* → terminating → terminated`, `* → error`.

`AppInstallation.status`: `queued → installing → installed`, `installing → failed` (retryable), `installed → running_action → installed`.

### 3.5 App Marketplace

The equivalent of OVH's "install with an application". A customer can install an app (e.g. OpenVPN) **at order time** or **later from the client panel**. It is a module of the panel, not a separate product.

#### Install paths
| Path | When | Mechanism |
|---|---|---|
| **At order time** | `POST /servers` contains an `app` field (WHMCS order form) | After cloning, the pipeline runs `install.sh` on first boot via cloud-init |
| **Later** | Client panel "Install app" or API | **QEMU Guest Agent**: `file-write` puts the script and inputs into the VPS, `exec` runs it |
| **Reinstall** | An app is selected while reinstalling the OS | Same as at order time |

- **Status tracking is identical for both paths:** the script writes progress to `/var/lib/proxmotion/apps/<installation_uuid>/status.json` inside the VPS; the panel reads it periodically via Guest Agent `file-read`. **No phone-home** HTTP from the VPS to the panel — no panel token is ever placed on the VPS and the VPS needs no network path to the panel.
- **Outputs** (panel URL, username/password, `.ovpn`/`.conf` files) are written by the script to `outputs.json` and `outputs/`; the panel reads them, stores them **encrypted**, and shows/offers them for download in the client panel.
- If the VPS does not answer the Guest Agent (agent removed, VPS stopped), the "install later" button is disabled with the reason shown.
- Every panel template must ship `qemu-guest-agent`, with `agent: 1` set on the VM.

#### Recipe format
Each app is a directory under `panel/recipes/<slug>/`:

```yaml
# recipe.yaml
slug: openvpn
name: OpenVPN
version: 1.0.0
category: vpn                    # vpn | control-panel | container | web
description: { en: "...", tr: "..." }
os:
  - { family: ubuntu, versions: ["22.04", "24.04"] }
  - { family: debian, versions: ["12"] }
requirements: { min_memory_mib: 512, min_disk_mib: 5120, min_cores: 1 }
network_models: [bridged, routed, nat]   # NAT: required ports are forwarded automatically
install_modes: [order, post]
billing: free                    # free | paid (paid requires an AppEntitlement)
license: none                    # none | external (cPanel/Plesk: licence sold separately in WHMCS)
inputs:
  - { key: port, type: port, default: 1194, label: { en: "Port", tr: "Port" } }
  - { key: protocol, type: enum, options: [udp, tcp], default: udp }
outputs:
  - { key: client_config, type: file, filename: client.ovpn, secret: true }
firewall:
  - { port: "{{ inputs.port }}", proto: "{{ inputs.protocol }}" }
actions:
  - { key: add_client, script: actions/add-client.sh, inputs: [{ key: name, type: slug }] }
  - { key: revoke_client, script: actions/revoke-client.sh, inputs: [{ key: name, type: slug }] }
timeout_minutes: 15
```

- `install.sh` and `actions/*.sh` must be **idempotent**, start with `set -euo pipefail`, and report step-by-step progress to `status.json` using the `_lib/` helpers.
- **Inputs are never interpolated into scripts.** The panel validates them against their declared types (port, enum, slug, email, domain, password…) and writes them to the VPS as `inputs.json`; scripts read them with `jq`.
- Eligibility is checked in the panel: if OS family/version, package resources or network model don't fit, the app is not listed to the customer or the billing API, and requests are rejected with `422`.

#### Recipe sources
- **`bundled`:** versioned in the repo; CI validates the schema, runs shellcheck and an install test on a test VM; shipped with panel releases. `php artisan apps:sync` syncs them into the database.
- **`custom`:** admins can add their own recipe in the panel (YAML + script upload/editor). Validated against the same JSON Schema; only admins with `apps.manage` may create/edit; every change is written to `AuditLog` with a version diff. Custom recipes are created **inactive**; the admin activates them after a "try on test server" run.
- A custom recipe can never override a bundled recipe with the same slug.

#### Licensing and pricing
- `billing: free` → always installable.
- `billing: paid` → chosen at order time through a WHMCS configurable option; for later installs, the billing system grants the right via `POST /servers/{id}/app-entitlements`. Without an entitlement the client panel shows a "Buy" button linking to WHMCS.
- `license: external` (cPanel, Plesk) → the panel only installs; the licence is sold as a separate WHMCS product and its activation is not the panel's responsibility. The client panel shows a "Licence required" notice.

#### Initial app list
| Category | Apps |
|---|---|
| VPN | OpenVPN, WireGuard (with add/revoke client actions) |
| Control panel | cPanel\*, Plesk\* (`license: external`), aaPanel, HestiaCP, CyberPanel |
| Containers | Docker, Docker + Portainer, Coolify |
| Web / CMS | LAMP, LEMP, WordPress, Node.js |

### 3.6 Infrastructure topology (mixed)

Providers run **standalone nodes and clusters side by side**, and both must be first-class.

- The connection unit is a **`Cluster`**. A standalone node is simply a `Cluster` with `type: standalone` and exactly one `Node`. All code paths treat both the same way.
- For real clusters, store multiple API endpoints and fail over between them if one node is down.
- `Storage.shared` decides behaviour: on **local storage** (LVM-thin, ZFS, dir) the template must exist on the target node (clone per node, or full clone + migrate); on **shared storage** (Ceph RBD, NFS, iSCSI) any node in the cluster can use it.
- **Placement** chooses a node within the requested location, filtering by package fit, node state (`active`, not `maintenance`), storage availability and network-model support, then scores by free RAM/disk and configurable over-commit ratios. Strategies are pluggable (`least-used`, `fill-first`).
- **Live migration** is only offered between nodes of the same cluster (later phase); moving across clusters is backup/restore based.
- Nodes are synced periodically (`php artisan infra:sync`): storages, bridges, templates, capacity and running VMs (used for drift detection and adoption).

### 3.7 Network models

Each `IpPool` declares one network model; each `Package` lists the models it allows. Network setup is implemented as one strategy class per model in `Provisioning/Network/`.

| Model | How it works | Notes |
|---|---|---|
| **Bridged public IP** | VPS gets a public IPv4 directly on `vmbrX` (optional VLAN tag); shared gateway of the subnet | Most common; `ipfilter` + `macfilter` mandatory |
| **Routed / failover IP** | Additional IPs routed to the host (OVH/Hetzner style). VPS uses a `/32` with the provider gateway as an on-link route, **or** a provider-issued virtual MAC stored on the `IpAddress` | Gateway/route config is pushed via cloud-init network config; provider-specific APIs (e.g. creating an OVH vMAC) are **out of core** — the admin enters the MAC, optional provider plugins later |
| **NAT VPS** | VPS gets a private IP behind a per-node NAT; customers get an allocated **port range** plus SSH forward; outbound via SNAT | DNAT cannot be configured through the Proxmox API. Design: a panel-managed **NAT gateway VM per node** (small Debian VM, nftables) configured through the Guest Agent — keeps the "no SSH to hosts" rule. Final design to be confirmed in an ADR before Phase 9 |
| **IPv6** | Per-VPS address(es) or a routed **/64 per VPS** from a /48 or /56 pool | Can be combined with any IPv4 model; routed /64 preferred over a shared /64 to avoid NDP exhaustion issues |

- App recipes declare their ports; on NAT VPS, those ports are forwarded automatically within the customer's port range, or the install is rejected if they cannot be.
- rDNS is only available for public IPs (bridged, routed, IPv6).

### 3.8 Migration and import

1. **Adopt existing Proxmox VMs** (Phase 2): an admin wizard lists VMs found on synced nodes that the panel does not manage, and lets the admin assign a user, package, IP addresses (matched to pools), and optionally a WHMCS `external_id`. Adoption only **records** the VM (`origin: adopted`); it must not reconfigure, restart or resize it unless the admin explicitly chooses to apply the package.
2. **Virtualizor import** (Phase 8): reads data from the Virtualizor Admin API (users, VPS, IPs, plans) and maps it onto Proxmotion entities, adopting the underlying Proxmox VMs. Includes a dry-run report, idempotent re-runs, and a documented procedure to switch WHMCS services from the Virtualizor module to the Proxmotion module (keeping service ids).
3. Imports never delete anything on Proxmox or in the source system.

### 3.9 Deployment

- **Docker Compose (primary):** `deploy/docker/compose.yml` runs `app` (php-fpm + nginx), `horizon`, `scheduler`, `reverb`, `mariadb`, `redis`. Production images are built in CI and published to GHCR.
- **Native installer:** `deploy/installer/install.sh` for supported Debian/Ubuntu LTS releases — nginx, php-fpm, MariaDB, Redis, systemd units for Horizon/scheduler/Reverb, Let's Encrypt. Must be idempotent and safe to re-run for upgrades.
- Both paths share the same `.env` contract and the same `php artisan proxmotion:install` / `proxmotion:upgrade` commands.
- **Branding** (name, logo, colours, support links) is configurable from the admin panel — no hard-coded company branding.

---

## 4. Billing API and WHMCS Module

### 4.1 Public API v1 (`/api/v1`, Bearer token)
| Endpoint | Description |
|---|---|
| `POST /servers` | Create (package, location, os_template, optional app + inputs, hostname, user email/external id, SSH key) → `202` + task id |
| `GET /servers/{id}` | Status, IPs, resources |
| `POST /servers/{id}/suspend`, `/unsuspend` | Suspend / unsuspend |
| `DELETE /servers/{id}` | Terminate |
| `POST /servers/{id}/package` | Change package (upgrade/downgrade) |
| `POST /servers/{id}/power` | `start|shutdown|stop|reboot` |
| `POST /servers/{id}/reinstall` | Reinstall OS (optionally with an app) |
| `POST /servers/{id}/password` | Reset root password |
| `GET /servers/{id}/usage` | Traffic and resource usage |
| `POST /users/{id}/sso` | One-time, short-lived (≤60 s) client panel login URL |
| `GET /packages`, `/locations`, `/os-templates` | Options for the billing side |
| `GET /apps?os_template=&package=` | Apps eligible for the given OS and package |
| `POST /servers/{id}/apps` | Install an app on a running server → `202` + installation id |
| `GET /servers/{id}/apps/{installation}` | Installation status (outputs only with the `apps:secrets` scope) |
| `POST /servers/{id}/app-entitlements` | Grant install right for a paid app / `DELETE` to revoke |
| `GET /tasks/{id}` | Task status |

- All write endpoints support an **`Idempotency-Key`** header; replaying the same key never creates a second server.
- Servers can be looked up by `external_id` (WHMCS service id).
- The API is versioned; never make breaking changes to v1. Keep `docs/api/openapi.yaml` current.
- Optional **webhooks** (HMAC-SHA256 signed): `server.provisioned`, `server.failed`, `server.suspended`, `app.installed`, `app.failed`, etc.

### 4.2 WHMCS server module (`integrations/whmcs/modules/servers/proxmotion/`)
Implement: `MetaData`, `ConfigOptions`, `TestConnection`, `CreateAccount`, `SuspendAccount`, `UnsuspendAccount`, `TerminateAccount`, `ChangePackage`, `ChangePassword`, `ClientArea`, `ServiceSingleSignOn`, `AdminServicesTabFields`, `AdminCustomButtonArray`, `UsageUpdate`.

- The module is a thin client of the panel API; business logic stays in the panel.
- Configurable Options mapping (extra IPs, extra RAM, OS, location, **app**) is supported. Admins can generate/refresh the app configurable option from the panel's app list in module settings.
- For paid apps, when a WHMCS product addon is activated the module calls `app-entitlements`; on addon cancellation it revokes the right (the installed app is not removed).
- The WHMCS client area shows installed apps and their outputs (e.g. `.ovpn` download) through the panel via SSO; secrets are never stored in the WHMCS database.
- Errors are returned to WHMCS as readable text and logged with `logModuleCall()` (tokens masked).
- Target the lowest PHP version supported by current WHMCS; no Laravel/Composer dependencies.

---

## 5. Development Commands

```bash
# inside panel/
composer setup                    # install deps, .env, key, migrate, build assets
php artisan migrate --seed        # local accounts: admin@example.com / client@example.com, password "password"
composer dev                      # serve + queue + vite (concurrently)

# all quality gates at once (same as CI)
composer ci:check

# or individually
npm run check                     # Oxfmt + Oxlint (`npm run check:fix` to fix)
npm run types:check               # vue-tsc
npm run test                      # Vitest (frontend)
composer lint:check               # Pint (`composer lint` to fix)
composer types:check              # PHPStan level 8
php artisan test                  # Pest
php artisan apps:validate         # recipe schema validation (from Phase 5)
shellcheck recipes/**/*.sh ../deploy/installer/*.sh
```

- **PHP must be on `PATH` for frontend commands too:** the Wayfinder Vite plugin runs `php artisan wayfinder:generate` during `vp build`, `vp dev` and `vp test`. On Windows, [Laravel Herd](https://herd.laravel.com/windows) provides PHP and Composer (`%USERPROFILE%\.config\herd\bin`).
- After adding or renaming routes, run `npm run build` (or `php artisan wayfinder:generate --with-form`) before Feature tests: Inertia pages are resolved from the Vite manifest.
- With Docker: `docker compose -f deploy/docker/compose.yml up -d`.

A task is not "done" until `composer ci:check` passes (plus recipe validation and shellcheck once those exist).

---

## 6. Coding Conventions

- Code, identifiers, comments, commit messages and **all documentation are in English**.
- **UI text is always translatable; the key is the English text itself.** In templates use `{{ $t('Save changes') }}` / `:placeholder="$t('Name')"`, in scripts `trans()` from `laravel-vue-i18n`, in PHP `__('Profile updated.')`. Use placeholders instead of concatenation: `$t('Added :time', { time })`. Add every new key with its Turkish translation to `lang/tr.json` (`lang/en.json` stays empty).
- Page titles (`<Head title>`), layout `title`/`description`, breadcrumb titles, nav item titles and the `title`/`description`/`label`/`hint`/`submit-label` props of `Heading`, `FormField`, `FormDialog`, `StatusBadge`, `ConfirmDelete` are passed as **English keys** and translated by the component that renders them — don't wrap them in `$t()` again.
- Flash messages: `$this->toast('success', 'Location created.')` in controllers (English key, translated server-side).
- Admin pages reuse `components/admin/*` (FormDialog, FormField, NativeSelect, ConfirmDelete, StatusBadge) and `useForm` + Wayfinder (`form.submit(store())`). Optional number inputs use `OptionalNumber` from `lib/forms.ts`.
- Files under `resources/js/components/ui/` are generated shadcn-vue components; avoid editing them.
- `declare(strict_types=1);` and full parameter/return types everywhere.
- Units are consistent: disk and RAM in **MiB**, traffic in **bytes**, port speed in **Mbps**. Conversions happen in a single helper class.
- Use native PHP enums (`ServerStatus`, `PowerAction`, `TaskStatus`, `NetworkModel`, `ClusterType`).
- Domain errors are explicit exception classes (`NodeUnavailable`, `InsufficientCapacity`, `IpPoolExhausted`, `NatPortRangeExhausted`, `ProxmoxTaskFailed`, `RecipeNotEligible`).
- Frontend: Composition API + `<script setup lang="ts">`; pages under `resources/js/pages/admin/*` and `pages/client/*`, shared components in `components/`, routes imported from Wayfinder (`@/routes/...`), never hard-coded URLs.
- Roles: `App\Enums\UserRole` (`admin`, `client`). Admin routes live in the `admin.` route group behind the `role:admin` middleware. `role` is deliberately **not** mass-assignable.
- Avoid N+1 queries (`with()`); paginate all large lists.
- No company-specific values (IP ranges, hostnames, provider names, branding) in code, seeds or defaults — use config or admin settings.
- New source files carry no per-file licence header; the root `LICENSE` (AGPL-3.0) applies.

---

## 7. Security Rules (IMPORTANT)

This panel manages customer infrastructure and is internet-facing. Agents **must** follow these rules:

1. **Tenant isolation:** customers can access only their own servers. Every client controller/route is protected by a **Policy**; public identifiers are non-guessable **UUIDs**. Every new client endpoint gets a "cannot access another user's server" test.
2. **Proxmox credentials** (API token secrets) are stored with Laravel's `encrypted` cast and never appear in API responses, Inertia props, logs or error messages. Root passwords are stored encrypted and shown only once.
3. **Console access:** the customer's browser never connects to Proxmox directly. The panel issues a short-lived single-use console ticket and proxies the WebSocket (`vncproxy` / `termproxy` + `vncwebsocket`).
4. **API tokens** are scoped (e.g. `servers:write`, `servers:read`, `apps:secrets`), stored hashed, support IP restrictions; all APIs are rate-limited.
5. **SSO links** are single-use, valid ≤60 s and signed.
6. **Network isolation:** Proxmox firewall `ipfilter`/`macfilter` must be enabled for every VPS (anti-spoofing); customers may use only the IPs assigned to them. NAT VPS port forwards are restricted to the customer's allocated range.
7. **User input** (hostname, rDNS, SSH keys, cloud-init data, app inputs) is strictly validated and never passed raw into Proxmox parameters or a shell.
8. **Destructive operations** (terminate, reinstall, snapshot rollback, node/cluster removal) require extra confirmation in admin and client UIs and are written to `AuditLog`.
9. **App Marketplace:** only scripts from recipe files are ever executed inside a VPS via the Guest Agent; free-form commands/scripts from customers or the API are never executed. Inputs are type-validated and passed as `inputs.json`. Secret outputs are stored encrypted and never logged. Custom recipes require `apps.manage` and are audited.
10. Admins must confirm TOTP 2FA before any admin page opens (`2fa` middleware; `PROXMOTION_REQUIRE_ADMIN_2FA=false` only for local development). Users cannot delete their own accounts — deletion is an admin/billing action. Session cookies `Secure`, `HttpOnly`, `SameSite=Lax`.
11. `.env` and real credentials are never committed; only `.env.example`.
12. **Open source hygiene:** no secrets, internal hostnames, real IPs or customer data in code, fixtures, docs, screenshots or commit history. Fixtures use documentation ranges (`192.0.2.0/24`, `198.51.100.0/24`, `203.0.113.0/24`, `2001:db8::/32`) and `example.com`. Vulnerabilities are reported per `SECURITY.md`, not in public issues.
13. Agents **never perform write operations against a real Proxmox server, a live WHMCS or a live Virtualizor**, unless the user explicitly asks; use faked HTTP tests and the local dev environment.

---

## 8. Test Strategy

- **Proxmox client:** `Http::fake()` with responses from `tests/Fixtures/proxmox/*.json`. No unit test may hit a real API.
- **Provisioning pipelines:** success, failure and "interrupted then re-run" (idempotency) cases for each step; each network model strategy tested separately.
- **Topology:** placement tests covering standalone nodes, clusters with shared storage, clusters with local storage, nodes in maintenance.
- **Allocation:** concurrent provisions never receive the same IP, VMID or NAT port range.
- **Authorization:** unauthorized-access tests for every client and API endpoint (other user, wrong token scope, suspended server).
- **API contract:** v1 response shapes are asserted in Feature tests.
- **App recipes:** all `recipe.yaml` files validated against the JSON Schema (`apps:validate`); input validation, eligibility checks and the Guest Agent flow (`file-write → exec → file-read`) tested with `Http::fake()`. Scripts pass shellcheck. Real install tests for each bundled recipe on each supported OS live in the `recipes` integration group.
- **Migration:** adoption and Virtualizor import tested with fixtures, including dry-run output and idempotent re-runs.
- **i18n:** `TranslationsTest` fails if a key has no entry in `lang/tr.json`, or if a PHP lang file exists for `en` but not `tr`. It detects `$t('…')`, `trans('…')`, `__('…')`, `->toast('…', '…')`, static and bound `title`/`description`/`label`/`hint`/`submit-label` attributes, and `title:`/`label:`/`description:`/`detail:`/`buttonText:` properties. Keys hidden elsewhere (e.g. a `Record` of status labels, `placeholder` props) must be added by hand.
- **Proxmox:** use `Tests\Concerns\FakesProxmox` (fixtures in `tests/Fixtures/proxmox/`, stray requests prevented). Summaries of test runs must count **errors as well as failures**.
- **Mass assignment:** columns owned by the sync (node status/resources, storage details, bridge data, templates) are intentionally not fillable; the sync writes them with `forceFill`. `Server`, `Task` and IP assignment (`server_id`) have no fillable columns at all — only provisioning code writes them. Don't add them to `#[Fillable]`.
- **Provisioning:** use `Tests\Support\FakeProxmoxCluster` (an in-memory Proxmox: clone, config, resize, power, delete, firewall, permissions) with `Tests\Concerns\BuildsInfrastructure`. The test queue is synchronous, so `ServerCreator::create()` runs the whole pipeline.
- **Frontend:** Vitest tests live next to the code as `*.test.ts` (`import { … } from 'vite-plus/test'`); mock `@inertiajs/vue3` and provide `$t` through `global.mocks` when mounting components.
- **WHMCS module:** functions tested with the panel API mocked.
- **Integration tests** (`@group integration`, `@group recipes`): require a real test Proxmox, skipped if `PROXMOX_TEST_*` env vars are missing; touch only resources in the `proxmotion-test` pool.

---

## 9. Proxmox API Notes

- API root: `https://<host>:8006/api2/json`, header `Authorization: PVEAPIToken=USER@REALM!TOKENID=SECRET`.
- New VMID: `GET /cluster/nextid` (still reserve it with a panel-side lock against races; VMIDs are per cluster — standalone nodes each have their own namespace).
- Clone: `POST /nodes/{node}/qemu/{templateVmid}/clone` (`newid`, `name`, `full`, `storage`, `target` — `target` only within a cluster and requires shared storage or a full clone).
- Config: `POST /nodes/{node}/qemu/{vmid}/config` (`cores`, `memory`, `net0`, `ipconfig0`, `ciuser`, `cipassword`, `sshkeys` — **URL-encoded**, `nameserver`, `agent`).
- Disk grow: `PUT /nodes/{node}/qemu/{vmid}/resize` — shrinking is not supported; a downgrade that shrinks disk is rejected or requires reinstall.
- Port speed: `rate=` inside `net0` (MB/s — convert from Mbps carefully).
- Power: `POST .../status/start|shutdown|stop|reboot|suspend|resume`.
- Console: `POST .../vncproxy` (`websocket=1`) → `GET .../vncwebsocket?port=&vncticket=`; LXC uses `termproxy`.
- Usage: `GET /nodes/{node}/qemu/{vmid}/status/current` (`netin`/`netout` are cumulative counters that reset when the VM restarts — handle this when computing deltas), `GET .../rrddata`.
- Task status: `GET /nodes/{node}/tasks/{upid}/status` → `status == "stopped"` and `exitstatus == "OK"`.
- Firewall: `PUT .../firewall/options` (`enable`, `ipfilter`, `macfilter`), `POST .../firewall/ipset`, `POST .../firewall/rules` (app ports). The Proxmox firewall does **not** do DNAT — hence the NAT gateway design in 3.7.
- Guest Agent: `POST .../agent/ping`, `POST .../agent/file-write` (`file`, `content`; size-limited — chunk large scripts or base64 + concatenate), `POST .../agent/exec` (`command` as array, `input-data`) → `pid`, `GET .../agent/exec-status?pid=` (`exited`, `exitcode`, `out-data`, `err-data`), `GET .../agent/file-read?file=` (may be truncated — check `truncated`). Start long installs detached (`systemd-run`/`nohup`) and track them through `status.json`.
- Cloud-init custom data: `cicustom: vendor=<storage>:snippets/<file>.yaml` requires a storage with the `snippets` content type. Snippet files are believed not to be uploadable via the API — **verify against the API viewer** and decide the delivery method in an ADR at the start of Phase 5 (options: shared snippet storage, or a minimal first-boot hook plus Guest Agent delivery).
- Cluster resources: `GET /cluster/resources?type=vm|node|storage` for sync and adoption discovery.

Never guess ambiguous API behaviour; consult the official API viewer (https://pve.proxmox.com/pve-docs/api-viewer/) and write fixtures to match it.

---

## 10. Roadmap

- [x] **Phase 0 — Skeleton:** Laravel 13 Vue starter kit (Inertia 3, TS, Tailwind 4, Vite+), Fortify auth (2FA, passkeys; registration disabled), admin/client roles, i18n (`en`/`tr`) with language switcher, Pint/PHPStan 8/Pest/Oxlint/Oxfmt/Vitest, CI (GitHub Actions), Docker Compose dev environment (not yet run), `LICENSE` (AGPL-3.0), `README`, `CONTRIBUTING`, `SECURITY`, `CODE_OF_CONDUCT`, Proxmotion logo/favicons, admin 2FA enforcement, self account deletion removed.
- [x] **Phase 1 — Infrastructure management (admin):** `app/Proxmox` client (token auth, host failover, typed errors) + `TaskWaiter`; locations; clusters (connection tested on save, standalone/cluster auto-detected, encrypted token); `ClusterSynchronizer` + `SyncCluster` job + `infra:sync` (every 5 min) for nodes, storages, bridges, QEMU templates; node placement settings (maintenance, over-commit, max servers); "use for servers" per storage; IP pools (v4/v6, bridged/routed/NAT, IPv4 addresses generated, reserve/MAC/notes); OS templates mapped to Proxmox templates; packages; admin dashboard. **Not yet verified against a real Proxmox server.**
- [x] **Phase 2 — Provisioning engine:** servers and tasks; per-cluster resource pool (token only needs VM rights on the pool); permission check listing missing **and excess** privileges; placement (online, not in maintenance, bridge, storage, CPU/memory/disk over-commit, max servers); VMID + IP allocation under a per-location lock; idempotent create (full clone → config + cloud-init → grow disk → anti-spoofing ipset → start), suspend/unsuspend, terminate (frees IPs, keeps history); retry after failure; admin server pages with polling progress; customers created by admins; adopt existing VMs from the pool without touching Proxmox; deletion guards; client dashboard lists own servers. Limitations: one IPv4 + one IPv6 configured by cloud-init (extra IPs are assigned/allowed but configured in the OS); routed /32 with on-link gateway relies on cloud-init and needs real-world verification; NAT not yet supported; Horizon not set up yet (`queue:work`).
- [ ] **Phase 3 — Client panel:** server list/detail, power actions, noVNC console proxy, reinstall, root password reset, rDNS, SSH keys.
- [ ] **Phase 4 — Billing integration:** public API v1 + OpenAPI, token scopes, idempotency, SSO, webhooks, **WHMCS module**.
- [ ] **Phase 5 — App Marketplace:**
  - 5a: recipe schema, `RecipeLoader`, `apps:sync` / `apps:validate`, Guest Agent client, install-later flow + client panel; first recipes **OpenVPN, WireGuard, Docker**.
  - 5b: install at order time (cloud-init, ADR on snippet delivery), reinstall + app, WHMCS configurable option and paid-app entitlements.
  - 5c: admin custom recipe editor, remaining recipes (Portainer, Coolify, aaPanel, HestiaCP, CyberPanel, cPanel, Plesk, LAMP, LEMP, WordPress, Node.js), app actions (VPN add/revoke client).
- [ ] **Phase 6 — Usage and quotas:** traffic collection, graphs, throttle/suspend on overrun, `ChangePackage` (upgrade/downgrade), WHMCS `UsageUpdate`.
- [ ] **Phase 7 — Snapshots and backups:** snapshot CRUD, scheduled backups, Proxmox Backup Server, restore.
- [ ] **Phase 8 — Migration and production install:** Virtualizor importer (dry-run, idempotent, WHMCS module switch guide), native installer, production Docker images, upgrade command, admin branding settings.
- [ ] **Phase 9 — Expansion:** **NAT VPS** (NAT gateway per node, port ranges — ADR first), LXC, ISO upload/mount, live migration within clusters, reseller system, Blesta / HostBill / Paymenter / FOSSBilling modules, optional provider plugins (e.g. OVH vMAC).

Each phase must be usable on its own and complete with passing tests. Do not skip phases without the maintainers' agreement.

---

## 11. How Agents Should Work

- Before a large change, write a short plan; if a solution needs to bend the architecture (Section 3) or security rules (Section 7), ask the user first.
- Significant design decisions (NAT gateway, snippet delivery, placement scoring, etc.) are recorded as ADRs in `docs/adr/NNNN-title.md`.
- Schema changes go in a **new migration**; never edit a migration that has been released.
- When changing the public API v1 contract, update `docs/api/openapi.yaml` and the WHMCS module in the same change.
- Small, focused commits using Conventional Commits with a scope when possible (`feat(whmcs): …`, `fix(provisioning): …`, `feat(recipes): …`).
- If you notice this file is out of date (new command, changed layout), update `AGENTS.md` in the same change.
- Do not report unverified Proxmox/WHMCS/Virtualizor behaviour as "working"; state clearly what was not tested.
- Communicate with the user in the language they use (the maintainers usually write in Turkish); keep everything committed to the repo in English.
