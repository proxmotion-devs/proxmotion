# AGENTS.md — Proxmotion

This file is the single source of truth for **every AI coding agent** working in this repository (Claude Code, Gemini CLI, Codex, Cursor, etc.). `CLAUDE.md` and `GEMINI.md` import it; shared rules live only here.

---

## 1. Project Overview

**Proxmotion** is an open-source (**AGPL-3.0**), multi-tenant **VPS management panel for Proxmox VE**, in the spirit of Virtualizor and VirtFusion. Hosting providers connect their Proxmox nodes and clusters; when a customer buys a VPS through a billing system such as WHMCS or WISECP, the server is provisioned automatically, and the customer manages it from the billing system's client area through a Proxmotion module. **Proxmotion has no client panel and no customer accounts** (ADR 0007): customers only ever see their billing system.

The first deployment is the maintainers' own hosting company, but the project is built as a **general-purpose open-source product**: nothing company-specific (names, IP ranges, branding, provider APIs) may be hard-coded.

### Two faces
1. **Admin panel** (staff only): clusters and nodes, locations, IP pools, packages, OS templates, app recipes, administrators, servers, tasks, audit logs.
2. **Billing API + modules:** a REST API and ready-made modules for billing systems — **WHMCS first, then WISECP**, later Blesta, HostBill, Paymenter, FOSSBilling. Everything a customer does (power actions, browser console, reinstall, root password reset, rDNS, snapshots/backups, traffic and resource graphs, **App Marketplace**) goes through the API and is shown by the module in the billing system's client area.

### Core features
- Manage **mixed infrastructure**: standalone Proxmox nodes and Proxmox clusters side by side (see 3.6)
- KVM (QEMU) VPS first; LXC later
- Automatic install and reinstall from cloud-init OS templates
- **Four network models:** bridged public IP, routed/failover IP, NAT VPS, IPv6 (see 3.7)
- IP pool management, automatic allocation, rDNS, MAC/IP anti-spoofing
- Packages (plans): CPU, RAM, disk, traffic quota, port speed, IP count, backup quota, network model
- Placement: pick a node with enough free capacity inside a placement group (named set of nodes and storages, ADR 0020), or a single node chosen by an admin; the billing API places automatically within the package's groups
- Asynchronous job queue for create, suspend, unsuspend, terminate, change package
- In-browser console via a panel-side WebSocket proxy
- Traffic accounting; throttle or suspend on quota overrun
- Snapshots and backups (Proxmox Backup Server later)
- Browser console for customers through a signed, single-use console link issued by the API (the panel's only customer-facing page)
- **App Marketplace (OVH-style):** one-click install of OpenVPN/WireGuard, control panels, Docker, web/CMS stacks — both at order time and later from the billing system's client area (see 3.5)
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
WHMCS / WISECP  ──REST API (token)──▶  Proxmotion Panel (Laravel)
(client area via module)                  │   ├── Admin UI (Inertia/Vue, staff only)
Customer browser ──signed console link─▶  │   ├── Public API v1
                                          │   └── Console page + WS proxy
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
│   │   ├── Enums/                        # ClusterType, NodeStatus, NetworkModel, IpVersion, …
│   │   ├── Domain/                       # Business logic (services/actions), grouped by domain
│   │   │   ├── Infrastructure/           # ClusterSynchronizer, PermissionChecker, DriftDetector (ADR 0005), Distributions (well-known os-release IDs and families, for recipe suggestions), TemplateNameGuess, OsReleaseCheck (what a server of a template reports, shown only) (ADR 0024, 0026)
│   │   │   ├── Networking/               # GenerateIpAddresses, ReverseDns drivers (ADR 0003), firewall, NAT port forwards
│   │   │   ├── Servers/                  # ConsoleSessions, ConsoleLinks, ServerPower, GuestAgentProbe, ServerPasswords, ServerReinstaller, GuestScripts + GuestAddresses (ADR 0009, 0010), HostnameChanger (ADR 0012), ResourceOptions; GuestScripts also logs every run (GuestScriptLog) and runs drafts in trials (ADR 0023)
│   │   │   ├── Usage/                    # traffic/resource accounting, quotas
│   │   │   ├── Backups/                  # snapshots, backups
│   │   │   ├── Apps/                     # RecipeLoader + RecipeSynchronizer (apps:validate/sync), InputValidator, AppEligibility, AppInstaller, InstallationPoller (ADR 0015); recipe format v2 and stored versions, AppRunner + RunPoller for uninstalls, upgrades and actions, AppUpgrades + VersionRange (upgrade targets), GuestPackage, GuestOutputs (ADR 0016); RecipeEditor, RecipeSkeleton, AppTrials (trial copies of drafts on test servers), RecipeArchive (zip import/export) (ADR 0018); AppStarter (shared by install_app and the post-boot app step), OrderedAppOptions (ADR 0016 §5); AppEntitlements (ADR 0019); AppSystems (the operating system versions apps are offered on, suggestions from recipes, ADR 0026); AppLogs (run.log copied in pieces, masked), LiveAppPolls (ADR 0021); AppGuest (what differs on Windows: directory, library files, start/stop/read/remove commands, ADR 0029); AppStopper (stops scripts inside the server by unit/task name), AppCanceller (cancel by administrators and billing systems) (ADR 0030)
│   │   │   ├── Migration/                # adopt existing VMs, Virtualizor importer
│   │   │   └── Webhooks/                 # Webhooks (events of finished tasks), WebhookSignature (ADR 0013)
│   │   ├── Proxmox/                      # Proxmox API client (the ONLY touchpoint)
│   │   │   ├── ProxmoxClient.php         # token auth, host failover, error mapping
│   │   │   ├── ProxmoxConnection.php     # hosts/port/token (secret hidden from dumps)
│   │   │   ├── ProxmoxClientFactory.php  # forCluster(), make()
│   │   │   ├── Resources/                # ClusterResource, NodesResource, TasksResource, QemuResource, GuestAgentResource (later: Firewall…)
│   │   │   ├── Data/                     # typed DTOs of API responses (NodeInfo, StorageInfo, Upid, …)
│   │   │   ├── TaskWaiter.php            # UPID tracking
│   │   │   └── Exceptions/
│   │   ├── Support/                      # Locales, Network/Cidr
│   │   ├── Provisioning/                 # Orchestration
│   │   │   ├── ServerCreator.php         # reserve node/storage/VMID/IPs under a per-location lock, queue the task
│   │   │   ├── ResourceChanger.php       # upgrade/downgrade: capacity + addresses under the same lock, queue ChangeResources
│   │   │   ├── PrimaryAddressChanger.php # swap or replace the primary address through the billing API (ADR 0011), queue ChangePrimaryAddress
│   │   │   ├── AddressChanger.php        # admin edit of a server's addresses (add, remove, primary) or apply them again (ADR 0028), queue ChangeAddresses
│   │   │   ├── IpAllocator.php           # pool choice, IPv4 assignment, next free IPv6 address
│   │   │   ├── HostnameGenerator.php     # hostnames of servers ordered without one, from the location's template (ADR 0031)
│   │   │   ├── LinkedClones.php          # whether a server can be a linked clone of its template, else why not (ADR 0027)
│   │   │   ├── TaskRunner.php            # one Task per operation, refuses parallel tasks per server
│   │   │   ├── TaskFailer.php            # ends a task as failed (pipeline error, RunServerTask::failed(), admin "Mark as failed" for stuck tasks)
│   │   │   ├── Stages/                   # StagedPipeline, StageRunner (stages until one waits, Proxmox tasks checked once per run, deadline, run budget), TaskDeadlineExceeded (ADR 0032)
│   │   │   ├── TaskPlan.php              # the steps a task is expected to record, from the server's state (shown as still to come)
│   │   │   ├── TaskEstimates.php         # remaining time of a create/reinstall: per-step medians of the last 20 successful runs with its OS template (ADR 0032 §5)
│   │   │   ├── Placement/                # NodePlacer (least memory used after placement), Placement, PlacementRules (placement groups, chosen node/storage)
│   │   │   ├── Network/                  # NetworkConfig: net0, ipconfig0, nameserver, anti-spoofing addresses
│   │   │   └── Pipelines/                # CreateServer, ReinstallServer (staged, ADR 0032), Power (suspend/unsuspend, power actions), ResetPassword, ChangeResources, Addresses (primary address changes, address edits, apply again), TerminateServer, InstallApp, AppRun (uninstalls, upgrades and actions) — idempotent
│   │   ├── Jobs/                         # Queue jobs: RunServerTask (pipelines; queues itself again with a delay while a staged task waits, ADR 0032), SyncCluster, PublishPtr/RemovePtr (rDNS), DeliverWebhook, PollAppInstallation and PollAppRun (queued by apps:poll), StopAppScript (after a timeout or cancel, ADR 0030)
│   │   ├── Http/
│   │   │   ├── Controllers/Admin/
│   │   │   ├── Controllers/Api/V1/       # Billing API (ADR 0008); CustomerConsoleController for console links
│   │   │   ├── Middleware/               # AuthenticateApiToken, RequireApiScope, HandleIdempotency, 2FA, console proxy auth
│   │   │   ├── Requests/                 # FormRequest validation (ReinstallRequest, RdnsRequest authorize first; Api/V1/*)
│   │   │   └── Resources/Api/V1/         # API JSON transformers
│   │   ├── Policies/                     # Authorization (server state rules for admins; API tokens use scopes)
│   │   └── Console/                      # Scheduled commands (traffic collection, sync…)
│   ├── recipes/                          # Bundled App Marketplace recipes
│   │   ├── _schema/recipe.schema.json, recipe-v2.schema.json   # format 1 (ADR 0015), format 2 (ADR 0016)
│   │   ├── _lib/                         # proxmotion.sh (helpers: inputs, status, outputs), run.sh (runner started by systemd-run); Windows (ADR 0029): proxmotion.ps1, run.ps1, start.ps1 (registers the scheduled task), read-log.ps1; stop.sh / stop.ps1 (stop a runner, ADR 0030; written only when something is stopped)
│   │   └── openvpn/ wireguard/ docker/ (5a), fastpanel/, wordpress/, cpanel/, plesk/, whmcs/, hestiacp/,
│   │       cyberpanel/, portainer/, coolify/, aapanel/, lamp/, lemp/, nodejs/; Windows: iis/, sqlserver-express/, chocolatey/
│   │       └── (each) recipe.yaml, install.sh, actions/*.sh, README.md
│   ├── lang/                             # tr.json (UI strings), en.json (empty: keys are English), {en,tr}/*.php
│   ├── database/migrations, factories, seeders
│   ├── resources/js/                     # Vue 3 + TS: pages/{admin,auth,console,settings}, components/, layouts/, composables/
│   ├── routes/web.php, api.php, channels.php
│   └── tests/Unit, Feature, Fixtures/proxmox/*.json
├── integrations/
│   ├── whmcs/modules/servers/proxmotion/ # WHMCS server module
│   ├── wisecp/                           # WISECP 5 server module (coremio/modules/Servers/Proxmotion, ADR 0014)
│   └── blesta/                           # later
├── deploy/
│   ├── docker/                           # Dockerfiles + compose.yml (dev and production)
│   ├── console-proxy/                    # Node.js WebSocket relay for the noVNC console (ADR 0002)
│   ├── proxmox/                          # Scripts admins run on a Proxmox node (prepare-images.sh: build images off-node, natively or via images.Dockerfile; build-templates.sh: create templates on the node with qm/pvesh only)
│   └── installer/                        # Native Debian/Ubuntu installer (install.sh, nginx, systemd units)
└── docs/                                 # wiki/Features.md (feature overview), install guide, admin guide, guides/ (os-templates.md, iso-templates.md, windows-templates.md, real-proxmox-verification.md; tr/ Turkish translations), API reference (OpenAPI), ADRs
```

### 3.3 Layering rules
1. **The Proxmox API is reached only through `app/Proxmox/`.** Controllers, jobs and models never make HTTP calls themselves.
2. **Controllers are thin:** validate (FormRequest) → authorize (Policy) → call a domain service or dispatch a job → return a Resource / Inertia response.
3. **Every operation that changes Proxmox runs in the queue.** HTTP requests never wait on a Proxmox task; they create a `Task` record and return immediately. Pages poll (`usePoll`) while a task runs; billing modules poll `GET /tasks/{id}`. Creating and reinstalling run in **stages** (ADR 0032): a stage that has to wait (a Proxmox task, the guest agent, Windows setup, cloud-init) ends the job, which queues its next run with that delay, so no worker sleeps through a provisioning; the task's `stage`, `stage_state`, `dispatch`, `heartbeat_at` and `deadline_at` say where it is. Keep each run short: long waits belong in a stage that returns a delay, never in a `Sleep` inside a staged pipeline.
4. **Pipeline steps are idempotent:** re-running a half-finished pipeline recognizes what already exists and continues. Each step records what it did on the `Task`.
5. **Resource allocation is atomic:** VMID, IP addresses, NAT ports and node capacity are reserved inside a DB transaction plus a Redis lock, so two concurrent provisions can never get the same IP/VMID/port.
6. **The panel database is the source of truth.** Proxmox state is reconciled periodically; drift is reported to admins and never auto-deleted.
7. Long Proxmox tasks return a **UPID**; `TaskWaiter` polls until `exitstatus == "OK"` and handles timeouts and failures explicitly (staged tasks check it once per run with `TaskWaiter::check()` through `StageRunner::proxmoxTask()`).
8. **No SSH to hypervisors.** The panel uses only the Proxmox API (and the QEMU Guest Agent through it). Anything that seems to need host shell access must be solved differently or escalated to the maintainers.

### 3.4 Core data models
`Location` (optional `hostname_template` naming servers ordered without a hostname from `{ip}`, `{vmid}`, `{location}`; such a server's Proxmox VM is named after its primary address (`Server.vm_name`) and the `hostname_changed` script sets the hostname inside the guest after the first boot (Windows: one restart); ADR 0031), `Cluster` (one API connection: host(s), port, token id, encrypted token secret, `type: standalone | cluster`), `Node` (belongs to a cluster; capacity limits, active/maintenance), `Storage` (`shared` flag), `NetworkBridge`, `IpPool` (v4/v6, network model, gateway, netmask, VLAN, location, optional per-IP MAC), `IpAddress`, `NatPortForward`, `Package` (optional list of placement groups its servers may be placed on; none = any node of the location), `PlacementGroup` (panel-only named set of nodes of one location and optionally their storages; ADR 0020), `OperatingSystem` + `OperatingSystemVersion` (the administrators' own list of operating systems (name, Linux or Windows, slug made from the name) and their versions, managed in the *Operating systems* section of the OS templates page; starts empty, ADR 0026), `OsTemplate` (template VMID mapped per node/cluster; of one `OperatingSystemVersion`, from which `distribution` (the system's slug), `version` and `platform` are copied on save; `family` (enum `OsFamily`) only for API v1's `os_family`, known for well-known slugs, else `other`; *Check against a server* shows what `/etc/os-release` of one of its active servers reports next to it and saves nothing; ADR 0024, 0026; `clone_mode` `full` (default) or `linked`: a linked clone when the template's storage allows it, else a full clone with the reason on the clone step, audited as `os_template.clone_mode_changed`, ADR 0027), `Iso`, `Server` (uuid, vmid, node, package, network model, status, suspension reason, `billing_system_id`, `external_id` = billing service id (unique per billing system), `external_client_id` = billing customer id, `origin: provisioned | adopted | imported`, `is_test` (drafts may be tried on it; never a server of a billing system, ADR 0018 §7); no panel owner — ADR 0007), `Task`, `UsageRecord`, `Snapshot`, `Backup`, `User` (staff/administrator, locale), `BillingSystem` (owns tokens and servers; ADR 0008), `ApiToken` (scoped, hashed, belongs to a billing system), `IdempotencyKey`, `AuditLog` (actor: user or API token), `DnsProvider` (rDNS driver + encrypted credentials, ADR 0003), `ServerDrift` (ADR 0005), `WebhookEndpoint` (URL, encrypted signing secret, events; belongs to a billing system) and `WebhookDelivery` (signed body, attempts; pruned after 30 days) (ADR 0013), `AppRecipe` (identity, source `bundled` or `custom`, active; its content lives in `AppRecipeVersion`, one row per version with status `draft`, `published`, `archived` or `trial` (a frozen copy of a draft, `<draft version>-trial.N`, `trial_of_id`), ADR 0016, 0018), `AppInstallation` (points to the version it was installed with; `mode` `post` or `order`, ADR 0016 §5; `is_trial` for trials of drafts, hidden from the API and webhooks, ADR 0018 §7), `AppEntitlement` (right to install a paid app on one server: source `order`, `api` or `admin`, optional billing `reference`, `revoked_at`; ADR 0019), `AppLog` (what one install attempt or run printed: encrypted, secret values masked, at most 1 MiB, pruned after 90 days; ADR 0021), `GuestScriptLog` (one run of a guest script during an operation or a trial: source default, published or draft, exit code, inputs, encrypted stdout/stderr (none for password scripts), pruned after 90 days; ADR 0023). Apps are offered on the `OperatingSystemVersion`s administrators tick for each app (`app_recipe_operating_system_version`, *Apps → Choose systems* or the editor's *Requirements* tab, the same ticks saved at once without publishing (`AppSystemsPicker`), audited as `app.systems_changed`); a recipe's `requires.os` only suggests versions (`Recipe::suggests()`, `AppSystems`, ADR 0026).

`Server.status`: `pending → provisioning → active ⇄ suspended` (a new, reinstalled or unsuspended server becomes `active` once its guest agent answers — a new or reinstalled Windows server only once Windows setup has finished (`ImageState` complete, Cloudbase-Init stopped), a new or reinstalled Linux server only once `cloud-init status` no longer says `running` or `not started` (`GuestScripts::CLOUD_INIT_STATUS_COMMAND`; a refused `guest-exec`, `error` or `degraded` leave a note) — or after the wait times out: 10 minutes for the agent, 15 for Windows setup, 10 more for cloud-init, 3 for unsuspend), `active → reinstalling → active`, `* → terminating → terminated`, `* → error`.

`AppInstallation.status`: `queued → installing → installed`, `queued|installing → failed` (retryable), `installed|failed → removed` (server reinstalled or terminated); `installed|failed|uninstall_failed|upgrade_failed → uninstalling → removed | uninstall_failed`; `installed|upgrade_failed → upgrading → installed` (new version) `| upgrade_failed` (old version kept) (ADR 0016). Uninstalls, upgrades and actions run in `AppRun` records (`queued → running → succeeded | failed`, kind `uninstall`, `upgrade` or `action`); actions leave the installation `installed`. A cancel (ADR 0030) is an `installing → failed` or `running → failed` with the error *Cancelled by an administrator.* / *Cancelled by the billing system.* (a cancelled uninstall leaves `uninstall_failed`, an upgrade `upgrade_failed`).

### 3.5 App Marketplace

The equivalent of OVH's "install with an application". A customer can install an app (e.g. OpenVPN) **at order time** or **later from the billing system's client area** (module → API). It is a module of the panel, not a separate product.

Phase 5a (install later through the API, the admin panel and the WHMCS client area) is built as decided in ADR 0015: a short `install_app` task uploads the recipe and starts `recipes/_lib/run.sh` with `systemd-run`; `apps:poll` (every minute) reads `status.json` and stores the outputs encrypted.

Windows apps (ADR 0029, 2026-10-20): recipes with `requires.platform: windows` and `.ps1` scripts, offered on Windows templates only (Linux recipes on Linux templates only, also in trials). The same contract (`status.json`, `outputs.json`, `run.log`) in `C:/ProgramData/Proxmotion/apps/<installation>` (`icacls`: SYSTEM and Administrators only); `start.ps1` registers a scheduled task `\Proxmotion\<name>` that runs `run.ps1` as SYSTEM, which runs the script in a child `powershell.exe` and appends its output as UTF-8. A script exiting with 3010 (`Pm-RequestRestart`) is run again from the beginning after the runner restarted Windows (at most 3 times per run). `.ps1` files are written with a UTF-8 BOM (Windows PowerShell 5.1). The platform comes from the installation's recipe version (`AppInstallation::platform()`). Bundled: IIS, SQL Server Express, Chocolatey.

Stopping and cancelling (ADR 0030, 2026-10-20): an installing app or a running uninstall, upgrade or action can be cancelled by an administrator (server page) or the billing system (API, WHMCS, WISECP): it fails at once with the usual webhook, audited as `app.install_cancelled` / `app.run_cancelled`, and `StopAppScript` stops it inside the server by the name it was started as (`proxmotion-app-<installation>-<attempt>`, `proxmotion-app-run-<run>`): `stop.sh` sends `SIGTERM`, then `SIGKILL`, to the unit's processes; `stop.ps1` disables and stops the scheduled task and its start-up tasks and ends the runner's process tree. Timeouts queue the same stop, and `AppStarter` / `AppRunPipeline` stop the installation's latest attempt and run before they upload anything (a start fails while one keeps running). Nothing the script did is undone. Queued installations and runs cannot be cancelled.

#### Install paths
| Path | When | Mechanism |
|---|---|---|
| **At order time** | `POST /servers` contains `app` + `app_inputs` (WHMCS configurable option, WISECP order answer, admin create form) | The last step of the create task starts the same Guest Agent flow as "later" once the agent answers (ADR 0004, 0016 §5) — no cloud-init snippets; a failed start fails only the installation |
| **Later** | "Install app" in the billing module (API) | **QEMU Guest Agent**: `file-write` puts the script and inputs into the VPS, `exec` runs it |
| **Reinstall** | An app is selected while reinstalling the OS | Same as at order time |

- **Status tracking is identical for both paths:** the script writes progress to `/var/lib/proxmotion/apps/<installation_uuid>/status.json` inside the VPS; the panel reads it periodically via Guest Agent `file-read`. **No phone-home** HTTP from the VPS to the panel — no panel token is ever placed on the VPS and the VPS needs no network path to the panel.
- **Outputs** (panel URL, username/password, `.ovpn`/`.conf` files) are written by the script to `outputs.json` and `outputs/`; the panel reads them, stores them **encrypted**, and hands them to the billing module through the API (`apps:secrets` scope) for display/download. Administrators see them on the server page with *Show details* (audited as `app.outputs_revealed`).
- **Logs** (ADR 0021): `run.sh` sends the script's output to `run.log`; the panel copies new lines through the guest agent (`AppLogs`, `dd` + `base64` from an offset) every minute and once more when the script ends, masks secret inputs and outputs, and keeps one encrypted log per install attempt and run. Administrators watch it live from the server page (*Watch* / *Log*, `GET /admin/app-logs/{log}` polled every 2 s; that request and the page's own refreshes poll running scripts through `LiveAppPolls`, at most every 2 s per script); billing systems never get logs.
- If the VPS does not answer the Guest Agent (agent removed, VPS stopped), the "install later" button is disabled with the reason shown.
- Every panel template must ship `qemu-guest-agent`, with `agent: 1` set on the VM.

#### Recipe format
Each app is a directory under `panel/recipes/<slug>/`. The example below is format 1 (ADR 0015); new recipes use **format 2** (ADR 0016: `format: 2`, `requires` with platform, `os: { families: [...], distributions: [...] }` (ADR 0024: families like `/etc/os-release` ID_LIKE — `debian` = Debian, Ubuntu and the distributions built on them, `rhel` = RHEL, AlmaLinux, Rocky, CentOS, Fedora, Oracle Linux — and single distributions by os-release ID; the list form of older recipes is still read; since ADR 0026 only a suggestion: administrators tick the versions an app is offered on, and `run.sh` checks nothing in the guest), `min_versions` and `max_versions` (lowest and highest version per distribution; `12` covers `12.7`), `resources`, `lifecycle` with install/uninstall/upgrade scripts, labelled actions; start with `php artisan apps:make <slug>` (`--platform=windows` for PowerShell scripts, ADR 0029)). Every version is stored and installations keep theirs.

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
- **Inputs are never interpolated into scripts.** The panel validates them against their declared types (port, enum, slug, email, domain, password, url (HTTPS only)…) and writes them to the VPS as `inputs.json`; scripts read them with `jq`.
- Eligibility is checked in the panel: if the template's operating system version is not ticked for the app (ADR 0026), package resources or network model don't fit, the app is not listed to the customer or the billing API, and requests are rejected with `422`. The admin server page lists such apps greyed out with the first reason (`unavailableApps`), but never offers them.

#### Recipe sources
- **`bundled`:** versioned in the repo; CI validates the schema, runs shellcheck and an install test on a test VM; shipped with panel releases. `php artisan apps:sync` syncs them into the database. Administrators can edit a bundled app in place (ADR 0018 §2): their versions are numbered `<bundled>-local.N`, `apps:sync` then stores new bundled versions without publishing them (the editor offers to compare or go back), and *Back to bundled version* undoes the edit.
- **`custom`:** admins can add their own recipe in the panel (YAML + script upload/editor). Validated against the same JSON Schema; only admins with `apps.manage` may create/edit; every change is written to `AuditLog` with a version diff. Custom recipes are created **inactive**; the admin activates them after a "try on test server" run.
- A custom recipe can never override a bundled recipe with the same slug (edit the bundled app in place instead).

#### Licensing and pricing
- `billing: free` → always installable.
- `billing: paid` (ADR 0019) → ordering a server with the app grants an entitlement automatically; for later installs, retries and reinstalls with the app the server needs an active entitlement, granted by the billing system via `POST /servers/{id}/app-entitlements` (WHMCS: product addon mapped in module setting 6, `hooks.php`; WISECP: add-on value `paid_app`, synced on create and upgrade) or by an administrator on the server page. Revoking never removes an installed app. The catalog lists paid apps; with `server` it says `entitled`, and the modules show "Buy" when it is `false`.
- `license: external` (cPanel, Plesk) → the panel only installs; the licence is sold as a separate WHMCS product and its activation is not the panel's responsibility. The module shows a "Licence required" notice.

#### Initial app list
| Category | Apps |
|---|---|
| VPN | OpenVPN, WireGuard (with add/revoke client actions) |
| Control panel | cPanel\*, Plesk\* (`license: external`), aaPanel, HestiaCP, CyberPanel |
| Containers | Docker, Docker + Portainer, Coolify |
| Web / CMS | LAMP, LEMP, WordPress, Node.js |
| Billing | WHMCS* (`license: external`; the customer gives the download address and licence key) |

### 3.6 Infrastructure topology (mixed)

Providers run **standalone nodes and clusters side by side**, and both must be first-class.

- The connection unit is a **`Cluster`**. A standalone node is simply a `Cluster` with `type: standalone` and exactly one `Node`. All code paths treat both the same way.
- For real clusters, store multiple API endpoints and fail over between them if one node is down.
- `Storage.shared` decides behaviour: on **local storage** (LVM-thin, ZFS, dir) the template must exist on the target node (clone per node, or full clone + migrate); on **shared storage** (Ceph RBD, NFS, iSCSI) any node in the cluster can use it.
- **Placement** chooses a node within the requested location (and within the package's placement groups, when the package names any — e.g. to keep plans on one CPU generation), filtering by package fit, node state (`active`, not `maintenance`), storage availability and network-model support, then scores by free RAM/disk and configurable over-commit ratios. Strategies are pluggable (`least-used`, `fill-first`). The billing API always places this way.
- **Placement groups** (ADR 0020, *Admin → Placement groups*): a panel-only named set of nodes of one location, optionally with the storages disks may go to (otherwise the nodes' *Use for servers* storages). Packages may name groups (only those of the ordered location count); packages no longer name clusters (converted into groups "Cluster <name>" by a migration). A group a package names cannot be deleted. Nothing is written to Proxmox: `clusters.resource_pool` stays the one Proxmox pool the token works in.
- **Admin create form** (`pages/admin/servers/Create.vue`, a wizard: type & location → resources & placement → operating system & app → network & IPs → access & billing → review; steps and the fields each owns in `lib/serverWizard.ts`, a server-side error opens the first step with an error). It also offers inactive OS templates (listed last, "(inactive)"), so a template can be tried before customers see it: such a server has no billing system and is a test server (also after a reinstall onto one; refused for a server of a billing system), and preselects one from `?os_template=` (the OS template check links there, ADR 0024 §8). The first step asks **Package or Custom** (`resource_mode`):
  - **Package** (`PackageFields`): the server gets exactly the package's resources (shown read-only, values sent with it are ignored) and is placed automatically like a billing order — the best node of the package's placement groups in the location, or of the whole location when it names none; no placement is accepted. The step says where it goes and on how many nodes it fits (`automaticFit`).
  - **Custom** (`ResourceFields`, `PlacementFields`): resources entered by hand, no package; the administrator must choose a **placement group** of the location (best node inside it, nodes in maintenance skipped) or a **single node** (also one in maintenance) with optionally its storage (any available storage that takes VM disks, also one not marked *Use for servers*); the other checks still apply and refusals say which one failed. `CapacityNote` warns when the server does not fit the chosen node (free vCPU, memory, server slots, disk after over-commit, `NodePlacer::freeCapacity()`) or fits on no node of the chosen group.

  The server checks capacity again on create. IPv4 addresses come from the first pool with room, or from an **IPv4 pool** chosen on the form, optionally with the addresses ticked by hand (the first is the primary, the rest of the server's addresses are the next free ones of that pool; at most the first 1024 free addresses are listed). The billing API keeps `primary_ipv4` only.
- **Live migration** is only offered between nodes of the same cluster (later phase); moving across clusters is backup/restore based.
- Nodes are synced periodically (`php artisan infra:sync`): storages, bridges, templates, capacity and running VMs (used for adoption and for drift reports, see ADR 0005; drift is shown to admins, never repaired automatically).

### 3.7 Network models

Each `IpPool` declares one network model; each `Package` lists the models it allows. Network setup is implemented as one strategy class per model in `Provisioning/Network/`.

| Model | How it works | Notes |
|---|---|---|
| **Bridged public IP** | VPS gets a public IPv4 directly on `vmbrX` (optional VLAN tag); shared gateway of the subnet | Most common; `ipfilter` + `macfilter` mandatory |
| **Routed / failover IP** | Additional IPs routed to the host (OVH/Hetzner style). VPS uses a `/32` with the provider gateway as an on-link route, **or** a provider-issued virtual MAC stored on the `IpAddress` | Gateway/route config is pushed via cloud-init network config; provider-specific APIs (e.g. creating an OVH vMAC) are **out of core** — the admin enters the MAC, optional provider plugins later |
| **NAT VPS** | VPS gets a private IP behind a per-node NAT; customers get an allocated **port range** plus SSH forward; outbound via SNAT | DNAT cannot be configured through the Proxmox API. Design: a panel-managed **NAT gateway VM per node** (small Debian VM, nftables) configured through the Guest Agent — keeps the "no SSH to hosts" rule. Final design to be confirmed in an ADR before Phase 9 |
| **IPv6** | Per-VPS address(es) or a routed **/64 per VPS** from a /48 or /56 pool | Can be combined with any IPv4 model; routed /64 preferred over a shared /64 to avoid NDP exhaustion issues |

- IPv6 is optional per location: when the location has no active IPv6 pool for the server's network model, its IPv6 addresses are skipped on creation and upgrades (the server gets IPv4 only); a server without IPv4 still needs a pool, and an IPv6 pool that has run out fails the operation.
- App recipes declare their ports; on NAT VPS, those ports are forwarded automatically within the customer's port range, or the install is rejected if they cannot be.
- rDNS is only available for public IPs (bridged, routed, IPv6). PTRs are published through a pluggable driver per IP pool (`none` = record only/export, `powerdns`; others optional), see ADR 0003.

### 3.8 Migration and import

1. **Adopt existing Proxmox VMs** (Phase 2): an admin wizard lists VMs found on synced nodes that the panel does not manage, and lets the admin assign a user, package, IP addresses (matched to pools), and optionally a WHMCS `external_id`. Adoption only **records** the VM (`origin: adopted`); it must not reconfigure, restart or resize it unless the admin explicitly chooses to apply the package.
2. **Virtualizor import** (Phase 8): reads data from the Virtualizor Admin API (users, VPS, IPs, plans) and maps it onto Proxmotion entities, adopting the underlying Proxmox VMs. Includes a dry-run report, idempotent re-runs, and a documented procedure to switch WHMCS services from the Virtualizor module to the Proxmotion module (keeping service ids).
3. Imports never delete anything on Proxmox or in the source system.

### 3.9 Deployment

- **Docker Compose (primary):** `deploy/docker/compose.yml` runs `app` (php-fpm + nginx), `console-proxy`, `horizon`, `scheduler`, `reverb`, `mariadb`, `redis`. Production images are built in CI and published to GHCR.
- **Native installer:** `deploy/installer/install.sh` for supported Debian/Ubuntu LTS releases — nginx, php-fpm, MariaDB, Redis, systemd units for Horizon/scheduler/Reverb, Let's Encrypt. Must be idempotent and safe to re-run for upgrades.
- Both paths share the same `.env` contract and the same `php artisan proxmotion:install` / `proxmotion:upgrade` commands.
- **Branding** (name, logo, colours, support links) is configurable from the admin panel — no hard-coded company branding.

---

## 4. Billing API and WHMCS Module

### 4.1 Public API v1 (`/api/v1`, Bearer token)

The API is the only way customers' actions reach the panel (ADR 0007). Every customer-facing feature needs an endpoint here and support in each billing module. Tokens, scopes, idempotency and console links: ADR 0008. Scopes: `catalog:read`, `servers:read`, `servers:write`, `servers:secrets`, `apps:secrets` (outputs of installed apps; installing and reading installations use the server scopes, ADR 0015).

| Endpoint | Description |
|---|---|
| `POST /servers` | Create (package with optional `resources` overrides, or no package and full `resources` + `network_model`, location, os_template, optional `app` + `app_inputs` (installed after the first boot, ADR 0016 §5), hostname (optional when the location has a hostname template: generated by `HostnameGenerator` and set as the PTR of the primary addresses, ADR 0031), `external_id` (service, required) + optional `external_client_id`, SSH keys) → `202` + task |
| `GET /servers?external_id=&external_client_id=&status=` | Find servers (paginated) |
| `GET /servers/{id}` | Status, live power state, IPs, resources |
| `POST /servers/{id}/suspend`, `/unsuspend` | Suspend (optional reason) / unsuspend |
| `DELETE /servers/{id}` | Terminate |
| `POST /servers/{id}/package` | Change package or resources (upgrade/downgrade): disk only grows, CPU/memory at the next restart unless `reboot`, newest extra addresses released |
| `POST /servers/{id}/hostname` | Rename: panel and PTRs equal to the old name at once, inside the guest by the `hostname_changed` script; Windows at the next restart unless `reboot`; the Proxmox VM name stays (ADR 0012) |
| `POST /servers/{id}/primary-address` | Make an additional address (swap) or a free pool address (old primary released) the primary; applied in the guest, `ipconfig0` untouched (ADR 0011) |
| `POST /servers/{id}/power` | `start|shutdown|stop|reboot` |
| `POST /servers/{id}/reinstall` | Reinstall OS (SSH keys, optionally `app` + `app_inputs`; a paid app needs an entitlement) |
| `POST /servers/{id}/password` | Reset root password (task): to `root_password` chosen in the billing system, or random |
| `GET /servers/{id}/password` | The initial or new root password, once (`root_password_unrevealed`), then `410` — `servers:secrets` |
| `POST /servers/{id}/console` | Signed, single-use console link (≤60 s to open) for the module to open in the customer's browser — `servers:secrets` |
| `POST /servers/{id}/rdns` | Set or remove the PTR of one of the server's addresses |
| `GET /servers/{id}/usage` | Traffic and resource usage — Phase 6 |
| `GET /packages`, `/locations`, `/os-templates` | Options for the billing side (`generates_hostnames` per location, ADR 0031) |
| `GET /apps?os_template=&package=&server=&mode=` | Apps eligible for the given OS, package or server (`catalog:read`); `mode=order` for apps that can come with a new system; paid apps are listed, with `server` also `entitled` |
| `POST /servers/{id}/apps` | Install an app on a running server → `202` + the installation (one at a time per server) |
| `GET /servers/{id}/apps`, `GET /servers/{id}/apps/{installation}` | Installations with status and progress (outputs only with the `apps:secrets` scope) |
| `POST /servers/{id}/apps/{installation}/retry` | Run a failed installation again |
| `POST /servers/{id}/apps/{installation}/cancel` | Cancel an installing app: `202` + the installation, `failed` at once; the installer is stopped inside the server (ADR 0030) |
| `DELETE /servers/{id}/apps/{installation}` | Uninstall (the recipe's uninstall script) → `202` + the run (ADR 0016) |
| `POST /servers/{id}/apps/{installation}/upgrade` | Upgrade to the version the installation's `upgrade` names, with `inputs` for the inputs it adds → `202` + the run (ADR 0016) |
| `POST /servers/{id}/apps/{installation}/actions/{key}` | Run an action (e.g. add or revoke a VPN client) with its inputs → `202` + the run |
| `GET /servers/{id}/apps/{installation}/runs/{run}` | An uninstall, upgrade or action with its status (outputs only with `apps:secrets`) |
| `POST /servers/{id}/apps/{installation}/runs/{run}/cancel` | Cancel a running uninstall, upgrade or action: `202` + the run (ADR 0030) |
| `GET /servers/{id}/apps/{installation}/runs` | The latest uninstalls, upgrades and actions (at most 10) |
| `POST /servers/{id}/app-entitlements` | Grant the right to install a paid app (`201`, or `200` with the active one) / `DELETE .../app-entitlements/{app}` revokes (`404` when none is active) / `GET` lists them (ADR 0019) |
| `GET /tasks/{id}` | Task status |

- All write endpoints support an **`Idempotency-Key`** header; replaying the same key never creates a second server.
- Servers can be looked up by `external_id` (WHMCS service id). A token only sees the servers of its **billing system** (others are `404`), and `external_id` is unique per billing system, so several billing systems can share one panel.
- The API is versioned; never make breaking changes to v1. Keep `docs/api/openapi.yaml` current (`OpenApiContractTest` fails when a route is missing from it).
- Operations that change Proxmox answer `202` with the task; state conflicts and running tasks are `409`, unreachable Proxmox `503`. Tasks and audit log entries record the token (`api_token_id`).
- Code: routes in `routes/api.php` (middleware `AuthenticateApiToken` → `throttle:api` → `api.scope:…` → bindings → `ScopeToBillingSystem` → `api.idempotent`), controllers in `Http/Controllers/Api/V1`, resources in `Http/Resources/Api/V1`, requests in `Http/Requests/Api/V1`; billing systems and their tokens are managed under *Admin → Billing systems*. The customer console is `CustomerConsoleController` (`/console/{server}`).
- Optional **webhooks** (ADR 0013), configured per billing system under *Admin → Billing systems*: `server.provisioned`, `server.suspended`, `server.unsuspended`, `server.reinstalled`, `server.terminated`, `server.failed`, `task.succeeded`, `task.failed`, sent by `RunServerTask` when a task finishes, also for operations admins start; `app.installed`, `app.failed` when an app installation finishes (ADR 0015, without outputs); `app.uninstalled`, `app.uninstall_failed`, `app.upgraded`, `app.upgrade_failed`, `app.action_succeeded`, `app.action_failed` when an uninstall, upgrade or action finishes (ADR 0016, with the run, without outputs). Signed `Proxmotion-Signature: t=<unix>,v1=<HMAC-SHA256 of "t.body">`, only `2xx` counts, 8 attempts over about 22 hours, at least once and unordered (receivers drop repeats by event id). Documented under `webhooks:` in `docs/api/openapi.yaml`; payloads never carry secrets.

### 4.2 WHMCS server module (`integrations/whmcs/modules/servers/proxmotion/`)
Implement: `MetaData`, `ConfigOptions`, `TestConnection`, `CreateAccount`, `SuspendAccount`, `UnsuspendAccount`, `TerminateAccount`, `ChangePackage`, `ChangePassword`, `ClientArea` (power, console link, reinstall, password, rDNS, apps, graphs), `AdminServicesTabFields`, `AdminCustomButtonArray`, `UsageUpdate`.

- The module is a thin client of the panel API; business logic stays in the panel.
- Configurable Options mapping (extra IPs, extra RAM, OS, location, **app**) is supported. Admins can generate/refresh the app configurable option from the panel's app list in module settings.
- For paid apps, when a WHMCS product addon is activated the module calls `app-entitlements`; on addon cancellation it revokes the right (the installed app is not removed).
- The WHMCS client area shows installed apps and their outputs (e.g. `.ovpn` download) by fetching them from the API on demand; secrets are never stored in the WHMCS database.
- Errors are returned to WHMCS as readable text and logged with `logModuleCall()` (tokens and `root_password` fields masked).
- Target WHMCS 8 and 9, i.e. **PHP 7.2** syntax and functions (no typed properties, arrow functions, `match`, `?->`, `??=`, `str_contains`, …); `Php72CompatibilityTest` checks the code with php-parser, because PHPStan's `phpVersion` does not catch most of it. No Laravel/Composer dependencies; classes in `lib/` (namespace `Proxmotion\Whmcs`) are loaded by `lib/autoload.php`.
- Done (2026-10-16): everything above except `UsageUpdate`, graphs and apps (apps added 2026-10-17, see below). `ChangePackage` sends the new product's package and configurable options to `POST /servers/{id}/package` (setting 5 *Restart after upgrades* sends `reboot`). The service is found with `GET /servers?external_id=<service id>` (no WHMCS custom field), writes carry an `Idempotency-Key` and are retried once after a lost connection, the WHMCS password becomes the root password when it has 12+ characters (otherwise Proxmotion's is stored on the service through `localAPI('UpdateClientProduct')`), and `ChangePassword` sends the chosen password. Setup and configurable option names: the module's `README.md`. **Not run in a real WHMCS yet.**
- Apps (2026-10-17, ADR 0015): the client area lists installed apps (status, progress, error, retry) and the apps that fit the server with a form for their inputs (`InstallApp`, `RetryApp`); outputs are fetched on every view, file outputs downloaded through `DownloadAppFile`, never stored in WHMCS, and masked in the module log (as are app inputs). **Not run in a real WHMCS yet.**
- Order-time apps and paid apps (2026-10-18, ADR 0016 §5, 0019): the configurable option `app` and custom fields `app_<key>` order an app with the server (only the chosen app's declared inputs are sent, from `GET /apps?mode=order`); the reinstall form offers the apps that fit the server and need no input without a default; module setting 6 *Paid app addons* (`<addon id>=<app id>` lines) and `hooks.php` (`AddonActivation` grants, `AddonCancelled`/`AddonTerminated` revoke, through `AddonHooks` with `ServiceStore`/`WhmcsServiceStore`, every outcome in the activity log); paid apps without a right show "Buy" (the service's addons in the cart). **Not run in a real WHMCS yet.**
- Upgrades (2026-10-18, ADR 0016 phase 3): the client area shows *Upgrade to <version>* with fields for the inputs the new version adds (`UpgradeApp`, inputs masked in the module log), `upgrade_failed` installations and upgrade runs. **Not run in a real WHMCS yet.**
- Cancel (2026-10-20, ADR 0030): *Cancel* next to an installing app and a running run, with a confirmation (`CancelApp`, also while the server is not active). **Not run in a real WHMCS yet.**
- Generated hostnames (2026-10-20, ADR 0031): without a valid domain, `CreateAccount` asks `GET /locations`; for a location with `generates_hostnames` it sends no hostname and sets the service's domain to the generated one from the create answer, otherwise `vps<service id>`. **Not run in a real WHMCS yet.**
- Webhook receiver (2026-10-17, ADR 0013): `webhook.php` in the module directory bootstraps `init.php` and hands the request to `WebhookReceiver` (`lib/`, data through `WebhookStore` / `WhmcsWebhookStore`, faked in tests). The signing secret (`whsec_…`) is the **Access Hash** of the WHMCS server (the token stays in the password field; an access hash starting with `whsec_` is never used as the token). The signature is compared with `hash_equals`, timestamps more than 300 s off are refused, event ids are kept a week in `mod_proxmotion_webhook_events` to drop repeats (released again when processing failed, so the retry is processed), and the service is the one with id `data.server.external_id` on a WHMCS server whose secret matched. Every event is logged with `logModuleCall` (secret masked). Because events are unordered, status changes first fetch the current server: `server.provisioned` sets a *Pending* service *Active* and the domain to the hostname, `server.suspended`/`unsuspended` set *Active* ⇄ *Suspended* only while the server is still in that state; `server.terminated` never changes the service (billing is the admins' call) but sends an admin email and activity log entry when the service is still *Active*/*Suspended*; `server.failed` sends an admin email (`SendAdminEmail`, type `account`) and an activity log entry; other events are only logged. An unreachable API answers `503`, so Proxmotion retries. The module still polls tasks it started.

### 4.3 WISECP server module (`integrations/wisecp/coremio/modules/Servers/Proxmotion/`)
Done 2026-10-17 (ADR 0014; setup in `integrations/wisecp/README.md`), **not run in a real WISECP yet**.

- Targets **WISECP 5, PHP 8.2** (typed code, `declare(strict_types=1)`). The class `WISECP\Modules\Servers\Proxmotion` extends WISECP's `ServerModule`; its own classes are in `src/` (namespace `Proxmotion\Wisecp`, loaded by `src/autoload.php`). It shares no code with the WHMCS module: the API client, `WebhookSignature`, the receiver and `ServerOrder` are ported and kept in step by hand.
- WISECP is closed source and partly ionCube-encoded. Never copy WISECP code into the repository; a local copy of WISECP for reference stays out of git.
- Server form: the panel's address in WISECP's IP/hostname field, the API token in WISECP's password field (always required by WISECP's form; the unused username field is relabelled through `lang/*.php`), the module field `webhook_secret` (encrypted by WISECP). Product Module tab: package (or none), location, OS template, network model, restart after upgrades, resource values; order answers (`requirement-params`) replace OS template and location and give hostname, SSH key, root password; add-on values `extra_*` add to the package.
- `create` (service active at once, server id in `config.server_id`, password in the service login), `suspend`, `unsuspend`, `cancel` (terminate), `upgrade`, `change_password` (waits up to 20 s, fails only when the task failed). The service is found with `GET /servers?external_id=<service id>`; writes carry an `Idempotency-Key`.
- Client area through WISECP's server tools (`config.php` → `supported`): power (start/shutdown/reboot/stop), root password reset, reinstall (Proxmotion's templates, password generated by Proxmotion and stored when `dashboard_data` fetches it), VNC console (returns `redirect` to the signed console link), hostname change, reverse DNS; tools are hidden while the server is busy or not active.
- Apps (2026-10-17, ADR 0015): WISECP has no app tool, so `configure_features()` adds one with `add_tool("apps")` and its page is the module's `pages/tools/apps.php` (installed apps with status, progress, error, retry; a form per installable app; text outputs shown and files downloaded as base64 through `tool_action`, fetched on demand and never stored in WISECP). Inputs are sent as JSON in the field `secret_inputs` (no tag stripping, masked in the service history); inputs and outputs are masked in the module log. Whether WISECP shows an added tool is unverified (ADR 0014).
- Webhooks: `POST /api/v1/proxmotion/webhook`, a public route of WISECP's API from `hooks.php`, handled by `Proxmotion::api_webhook()` → `WebhookReceiver` (data through `WebhookStore` / `WisecpWebhookStore`). Same rules as the WHMCS receiver; status changes through `Services::change_status` with `module_completed`, so the module is not called back; terminations and failures notify administrators and add a service history line; refused requests are logged without their body. Whether WISECP dispatches a public route to a server module is unverified (ADR 0014).
- Order-time apps and paid apps (2026-10-18, ADR 0016 §5, 0019): order answers `app` and `app_inputs` (one `key=value` per line, since WISECP's order answers are fixed by the module); the add-on value `paid_app` grants paid apps on create and upgrade and revokes the rights the module granted (reference `wisecp-addon`) for add-ons that are gone; the Apps tool notes paid apps to buy and offers *Reinstall with an app* (WISECP's own reinstall tool has no place for one). Unverified: whether WISECP accepts a text value for an add-on module value and calls `upgrade()` when add-ons change.
- Upgrades (2026-10-18, ADR 0016 phase 3): the Apps tool shows *Upgrade to <version>* with fields for the inputs the new version adds (tool action `upgrade`, inputs in `secret_inputs`), `upgrade_failed` installations and upgrade runs. Not run in a real WISECP yet.
- Cancel (2026-10-20, ADR 0030): the Apps tool shows *Cancel* next to an installing app and a running run (tool action `cancel`, with `run` for a run). Not run in a real WISECP yet.
- Generated hostnames (2026-10-20, ADR 0031): without a valid hostname, an order for a location with `generates_hostnames` sends none and the service takes the generated name through `created()`; otherwise `vps<service id>`. Not run in a real WISECP yet.
- Not done: `UsageUpdate`-like metrics, account import (`list()`), applying add-on changes without an upgrade.

---

## 5. Development Commands

```bash
# inside panel/
composer setup                    # install deps, .env, key, migrate, build assets
php artisan migrate --seed        # local admin account: admin@example.com, password "password"
composer dev                      # serve + queue + scheduler + vite (concurrently; apps:poll and infra:sync need the scheduler)

# all quality gates at once (same as CI)
composer ci:check

# or individually
npm run check                     # Oxfmt + Oxlint (`npm run check:fix` to fix)
npm run types:check               # vue-tsc
npm run test                      # Vitest (frontend)
composer lint:check               # Pint (`composer lint` to fix)
composer types:check              # PHPStan level 8
php artisan test                  # Pest (the Integration suite is skipped here)
composer test:integration         # real TEST Proxmox from .env.integration (see .env.integration.example)
php artisan apps:validate         # recipe schema validation (ADR 0015)
php artisan apps:check-versions   # a changed bundled recipe needs a higher version than on --base (default origin/main; CI: the PR's base)
php artisan apps:sync             # copy the bundled recipes into the database as new versions (after an update)
php artisan apps:make <slug>      # format 2 recipe skeleton for authors (ADR 0016); --platform=windows for a PowerShell one (ADR 0029)
php artisan apps:poll             # follow running app installers, uninstalls and actions (scheduled every minute)
shellcheck $(git ls-files '*.sh')    # from the repository root, like CI (settings in .shellcheckrc)

# inside deploy/console-proxy/
npm ci && npm test              # console proxy (node:test)
```

- **SQLite (local default):** `config/database.php` uses WAL, a 5 s busy timeout and `IMMEDIATE` transactions, because the web server, the queue worker and the scheduler write at the same time; with the defaults the worker's job reservation failed with "database is locked" and the task stayed queued. A queued or running task blocks its server; `RunServerTask::failed()` ends it when its job fails, and *Mark as failed* on the server page ends one that is stuck (queued, or running without a heartbeat for longer than `RunServerTask::TIMEOUT`, or past its deadline). The queues' `retry_after` defaults to 1860 s, longer than that timeout, or a running job would be handed to a second worker and failed (ADR 0032).
- **PHP must be on `PATH` for frontend commands too:** the Wayfinder Vite plugin runs `php artisan wayfinder:generate` during `vp build`, `vp dev` and `vp test`. On Windows, [Laravel Herd](https://herd.laravel.com/windows) provides PHP and Composer (`%USERPROFILE%\.config\herd\bin`).
- After adding or renaming routes, run `npm run build` (or `php artisan wayfinder:generate --with-form`) before Feature tests: Inertia pages are resolved from the Vite manifest.
- With Docker: `docker compose -f deploy/docker/compose.yml up -d`.

A task is not "done" until `composer ci:check` passes and `php artisan apps:validate` and shellcheck pass (CI runs all three). A change to a bundled recipe (its `recipe.yaml` or any script it uses, not its README) also needs a higher `version`: installed versions never change, so `apps:sync` refuses to replace one in use. Pull requests check this with `php artisan apps:check-versions`.

---

## 6. Coding Conventions

- Code, identifiers, comments, commit messages and **all documentation are in English**. English is canonical; a guide may also get a Turkish translation under `docs/guides/tr/` with the same file name, linked from the English one, updated in the same change, and the English text wins when they differ.
- **UI text is always translatable; the key is the English text itself.** In templates use `{{ $t('Save changes') }}` / `:placeholder="$t('Name')"`, in scripts `trans()` from `laravel-vue-i18n`, in PHP `__('Profile updated.')`. Use placeholders instead of concatenation: `$t('Added :time', { time })`. Add every new key with its Turkish translation to `lang/tr.json` (`lang/en.json` stays empty).
- Page titles (`<Head title>`), layout `title`/`description`, breadcrumb titles, nav item titles and the `title`/`description`/`label`/`hint`/`submit-label` props of `Heading`, `FormField`, `FormDialog`, `StatusBadge`, `ConfirmDelete` are passed as **English keys** and translated by the component that renders them — don't wrap them in `$t()` again.
- Flash messages: `$this->toast('success', 'Location created.')` in controllers (English key, translated server-side).
- Admin pages reuse `components/admin/*` (FormDialog, FormField, NativeSelect, ConfirmDelete, StatusBadge) and `useForm` + Wayfinder (`form.submit(store())`). Optional number inputs use `OptionalNumber` from `lib/forms.ts`.
- Pages that need explaining (setup steps, OS templates, apps, billing) start with a `GuideBox` (`components/admin/GuideBox.vue`: a short how-to administrators can hide, remembered per browser, linking to a section of *Admin → Help*). *Help* (`pages/admin/Help.vue`, `HelpController`) shows the setup order with what is done, then the template guide (`TemplateGuide`: building an OS template by hand from an ISO, one tab per OS — Linux, Windows, and macOS explaining why macOS cannot be offered — with every command, following `docs/guides/iso-templates.md` and `windows-templates.md`; `#template-guide-<os>` opens a tab) and questions and answers (`HelpQuestion`, opened by `#<id>` links); it links to the full guides when `PROXMOTION_DOCS_URL` is set. Keep both in step with the pages and `docs/guides/` when a flow changes.
- Files under `resources/js/components/ui/` are generated shadcn-vue components; avoid editing them.
- `declare(strict_types=1);` and full parameter/return types everywhere.
- Units are consistent: disk and RAM in **MiB**, traffic in **bytes**, port speed in **Mbps**. Conversions happen in a single helper class.
- Use native PHP enums (`ServerStatus`, `PowerAction`, `TaskStatus`, `NetworkModel`, `ClusterType`).
- Domain errors are explicit exception classes (`NodeUnavailable`, `InsufficientCapacity`, `IpPoolExhausted`, `NatPortRangeExhausted`, `ProxmoxTaskFailed`, `RecipeNotEligible`).
- Frontend: Composition API + `<script setup lang="ts">`; pages under `resources/js/pages/admin/*`, shared components in `components/`, routes imported from Wayfinder (`@/routes/...`), never hard-coded URLs.
- There are no roles: every panel user is an administrator (ADR 0007). Admin routes live in the `admin.` route group (`auth`, `verified`, `2fa`). Customers exist only in the billing system; servers carry `external_id` / `external_client_id`.
- Avoid N+1 queries (`with()`); paginate all large lists.
- No company-specific values (IP ranges, hostnames, provider names, branding) in code, seeds or defaults — use config or admin settings.
- New source files carry no per-file licence header; the root `LICENSE` (AGPL-3.0) applies.

---

## 7. Security Rules (IMPORTANT)

This panel manages customer infrastructure and is internet-facing. Agents **must** follow these rules:

1. **Tenant isolation:** there are no customer logins (ADR 0007). The billing system decides which customer sees which service; the panel trusts only **scoped API tokens**, each limited to the servers of its **billing system**, and public identifiers are non-guessable **UUIDs** (or the billing `external_id`). Every API endpoint gets wrong-scope, wrong-token and other-billing-system tests (`BillingSystemIsolationTest`); console links are signed, single-use and bound to one server.
2. **Proxmox credentials** (API token secrets) are stored with Laravel's `encrypted` cast and never appear in API responses, Inertia props, logs or error messages. Root passwords are stored encrypted and shown only once.
3. **Console access:** the customer's browser never connects to Proxmox directly. The panel issues a short-lived single-use console ticket (reached by customers only through a signed single-use link from the API) and proxies the WebSocket (`vncproxy` / `termproxy` + `vncwebsocket`).
4. **API tokens** are scoped (`catalog:read`, `servers:read`, `servers:write`, `servers:secrets`; later `apps:secrets`), stored hashed (SHA-256 of a 48-character random secret, shown once), support IP/CIDR restrictions and expiry, can be revoked; the API is rate-limited per token and failed authentications per address (ADR 0008).
5. **Console links** from the API are single-use, valid ≤60 s and signed; there is no SSO into the panel.
6. **Network isolation:** Proxmox firewall `ipfilter`/`macfilter` must be enabled for every VPS (anti-spoofing); customers may use only the IPs assigned to them. NAT VPS port forwards are restricted to the customer's allocated range.
7. **User input** (hostname, rDNS, SSH keys, cloud-init data, app inputs) is strictly validated and never passed raw into Proxmox parameters or a shell.
8. **Destructive operations** (terminate, reinstall, snapshot rollback, node/cluster removal) require extra confirmation in the admin UI (and in the billing modules) and are written to `AuditLog`.
9. **App Marketplace and guest scripts:** only scripts from recipe files, the panel's default guest scripts (`resources/guest`) and guest script overrides that administrators saved on an OS template (audited, ADR 0010; drafts only in trial runs, ADR 0023) are ever executed inside a VPS via the Guest Agent; free-form commands/scripts from customers or the API are never executed. Inputs are type-validated and passed as `inputs.json`. Secret outputs are stored encrypted and never logged. Custom recipes require `apps.manage` and are audited.
10. Every panel user is an administrator and must confirm TOTP 2FA before any admin page opens (`2fa` middleware; `PROXMOTION_REQUIRE_ADMIN_2FA=false` only for local development). Users cannot delete their own accounts. Creating an administrator is audited. Session cookies `Secure`, `HttpOnly`, `SameSite=Lax`.
11. `.env` and real credentials are never committed; only `.env.example`.
12. **Open source hygiene:** no secrets, internal hostnames, real IPs or customer data in code, fixtures, docs, screenshots or commit history. Fixtures use documentation ranges (`192.0.2.0/24`, `198.51.100.0/24`, `203.0.113.0/24`, `2001:db8::/32`) and `example.com`. Vulnerabilities are reported per `SECURITY.md`, not in public issues.
13. Agents **never perform write operations against a real Proxmox server, a live WHMCS or a live Virtualizor**, unless the user explicitly asks; use faked HTTP tests and the local dev environment.

---

## 8. Test Strategy

- **Proxmox client:** `Http::fake()` with responses from `tests/Fixtures/proxmox/*.json`. No unit test may hit a real API.
- **Provisioning pipelines:** success, failure and "interrupted then re-run" (idempotency) cases for each step; each network model strategy tested separately.
- **Topology:** placement tests covering standalone nodes, clusters with shared storage, clusters with local storage, nodes in maintenance.
- **Allocation:** concurrent provisions never receive the same IP, VMID or NAT port range.
- **Authorization:** unauthorized-access tests for every API endpoint (missing/wrong token, wrong token scope, suspended server) and for console links (expired, reused, another server).
- **API contract:** v1 response shapes are asserted in Feature tests.
- **App recipes:** all `recipe.yaml` files validated against the JSON Schema (`apps:validate`, `tests/Feature/Apps/RecipeLoaderTest`); input validation (`InputValidatorTest`), eligibility checks and the Guest Agent flow (`file-write → exec → file-read`) tested on `FakeProxmoxCluster` (app tests tick every app for its suggested versions with `TestsConcernsOffersApps` after syncing recipes; `AppInstallTest`: write `status.json`/`outputs` into `guestFiles` and call `InstallationPoller::poll()`; `AppRunTest` the same for uninstalls and actions in `runs/<run>/` with `RunPoller::poll()`, `AppUpgradeTest` upgrades (newer versions published with `TestsConcernsPublishesRecipeVersions`); `OrderedAppTest` apps chosen with a new server or a reinstall, `AppEntitlementTest` paid apps, `AppLogTest` logs: write `run.log` into `guestFiles`; the fake answers the `AppLogs::READ_SCRIPT` reads and counts them in `guestLogReads`; `AppTrialTest` trial runs of drafts on test servers; `WindowsAppTest` Windows apps (paths, BOM, start/stop/read/remove commands, eligibility by platform); `AppCancelTest` cancels, stops after timeouts and earlier scripts stopped before a start (the fake answers every stop command with exit 0 unless `execHandler` says otherwise); `PowerShellScriptsTest` parses every bundled `.ps1` with `pwsh` (Windows PowerShell locally; skipped without either); `tests/Feature/Admin/RecipeArchiveTest` zip import/export and the archives it refuses; `RecipeVersionGuardTest` changed recipes without a new version, with two recipe directories), API in `AppApiTest`, `OrderedAppApiTest`, `AppEntitlementApiTest`. Scripts pass shellcheck. Real install tests for each bundled recipe on each supported OS live in the `recipes` integration group.
- **Migration:** adoption and Virtualizor import tested with fixtures, including dry-run output and idempotent re-runs.
- **i18n:** `TranslationsTest` fails if a key has no entry in `lang/tr.json`, or if a PHP lang file exists for `en` but not `tr`. It detects `$t('…')`, `trans('…')`, `__('…')`, `->toast('…', '…')`, static and bound `title`/`description`/`label`/`hint`/`submit-label` attributes, and `title:`/`label:`/`description:`/`detail:`/`buttonText:` properties. Keys hidden elsewhere (e.g. a `Record` of status labels, `placeholder` props) must be added by hand.
- **Proxmox:** use `Tests\Concerns\FakesProxmox` (fixtures in `tests/Fixtures/proxmox/`, stray requests prevented). Summaries of test runs must count **errors as well as failures**.
- **Mass assignment:** columns owned by the sync (node status/resources, storage details, bridge data, templates) are intentionally not fillable; the sync writes them with `forceFill`. `Server`, `Task` and IP assignment (`server_id`) have no fillable columns at all — only provisioning code writes them. Don't add them to `#[Fillable]`.
- **Provisioning:** use `Tests\Support\FakeProxmoxCluster` (an in-memory Proxmox: clone, config, resize, power incl. reboot, delete, firewall, permissions, active tasks, vncproxy, Proxmox tasks that stay running for a number of status checks (`runningPolls`), and a guest agent with files, commands, passwords and `agentDown`; `/cluster/resources` derives `maxcpu`/`maxmem`/`maxdisk` from the config). It answers every URL, so fake other HTTP services (e.g. PowerDNS) in tests that do not install it with `Tests\Concerns\BuildsInfrastructure`. The test queue is synchronous, so `ServerCreator::create()` runs the whole pipeline (a staged task sleeps between its runs there, so `Sleep::fake()` keeps waits fast); `StagedTaskTest` runs the queued runs of a staged task one by one as a worker would (`Queue::fake([RunServerTask::class])`, `withFakeQueueInteractions()`).
- **Frontend:** Vitest tests live next to the code as `*.test.ts` (`import { … } from 'vite-plus/test'`); mock `@inertiajs/vue3` and provide `$t` through `global.mocks` when mounting components.
- **WHMCS module:** `integrations/whmcs/tests` (PHPUnit suite `Whmcs` in `panel/phpunit.xml`, part of `php artisan test`): the module's functions with the panel API faked (`FakeTransport`) and WHMCS functions stubbed (`tests/bootstrap.php` records `logModuleCall`/`localAPI` calls); `Php72CompatibilityTest`; PHPStan (`integrations/whmcs/phpstan.neon`, run by `composer types:check`).
- **WISECP module:** `integrations/wisecp/tests` (PHPUnit suite `Wisecp`): the module class (as `TestProxmotion`, API faked with `FakeTransport`) and the webhook receiver (`FakeWebhookStore`); the WISECP classes it uses are imitated in `tests/stubs.php` (written for the tests, never copied from WISECP), which PHPStan level 8 also reads (`integrations/wisecp/phpstan.neon`, run by `composer types:check`); `LanguageTest` checks `lang/en.php` and `lang/tr.php` (also the keys of `pages/tools/apps.php`); `AppsTest` renders that page.
- **Integration tests** (`tests/Integration`, suite `Integration`, extend `IntegrationTestCase`; later `@group recipes`): run only with `composer test:integration` (sets `PROXMOX_INTEGRATION=1`) and settings in the uncommitted `panel/.env.integration`, otherwise skipped — never run them unless the user asks. They touch only VMIDs from `PROXMOX_TEST_VMID_FROM` up in the test pool (register created VMs with `trackVm()`; `tearDown` deletes them), and refuse root-equivalent tokens. Written so far: `ConnectionTest`, `PermissionTest` (read only), `GuestAgentTest`, `TaskWaiterTest`, `PowerTest`, `ConsoleTicketTest`, `DriftTest`, `ResourceChangeTest`, `GuestAddressesTest`, `PrimaryAddressTest`, `CloudInitIdentityTest`, `HostnameTest`, `HostnameIdentityTest` (clone the test template; `cloneTestVm()` enables the agent like provisioning does), `ProvisioningTest`, `ReinstallTest` (need a spare IPv4 address). `ManagesTestServers` provisions or adopts servers; `/cluster/resources` lags changes by a few seconds, so use `waitForGuest()` before a sync and the live `status/current` for power states. On Windows, run one test per `--filter` (Herd's `php.bat` breaks on `|`). Servers created by the panel in a test are cleaned up too (`PROXMOTION_MIN_VMID` keeps them in the test range). Design and test list: [docs/guides/real-proxmox-verification.md](docs/guides/real-proxmox-verification.md).

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
- Guest Agent (`GuestAgentResource`): privileges are `VM.GuestAgent.*` since PVE 9 (`exec` and `set-user-password` need `VM.GuestAgent.Unrestricted`), `VM.Monitor` before (unverified). Array parameters such as `command` are sent as repeated form keys. `POST .../agent/ping`, `POST .../agent/file-write` (`file`, `content` max 61440 characters; the panel base64-encodes with `encode=0`, so at most 46080 bytes per write — chunk larger files), `POST .../agent/exec` (`command` as array, `input-data`) → `pid`, `GET .../agent/exec-status?pid=` (`exited`, `exitcode`, `out-data`, `err-data`), `GET .../agent/file-read?file=` (may be truncated — check `truncated`). Start long installs detached (`systemd-run`/`nohup`) and track them through `status.json`.
- Cloud-init custom data: `cicustom: vendor=<storage>:snippets/<file>.yaml` requires a storage with the `snippets` content type. Snippet files are believed not to be uploadable via the API — **verify against the API viewer**. ADR 0004 avoids snippets entirely: cloud-init uses only built-in fields, and first-boot work runs as Guest Agent post-boot hooks.
- Cluster resources: `GET /cluster/resources?type=vm|node|storage` for sync and adoption discovery.

Never guess ambiguous API behaviour; consult the official API viewer (https://pve.proxmox.com/pve-docs/api-viewer/) and write fixtures to match it.

---

## 10. Roadmap

- [x] **Phase 0 — Skeleton:** Laravel 13 Vue starter kit (Inertia 3, TS, Tailwind 4, Vite+), Fortify auth (2FA, passkeys; registration disabled), admin/client roles (client role removed later, ADR 0007), i18n (`en`/`tr`) with language switcher, Pint/PHPStan 8/Pest/Oxlint/Oxfmt/Vitest, CI (GitHub Actions), Docker Compose dev environment (not yet run), `LICENSE` (AGPL-3.0), `README`, `CONTRIBUTING`, `SECURITY`, `CODE_OF_CONDUCT`, Proxmotion logo/favicons, admin 2FA enforcement, self account deletion removed.
- [x] **Phase 1 — Infrastructure management (admin):** `app/Proxmox` client (token auth, host failover, typed errors) + `TaskWaiter`; locations; clusters (connection tested on save, standalone/cluster auto-detected, encrypted token); `ClusterSynchronizer` + `SyncCluster` job + `infra:sync` (every 5 min) for nodes, storages, bridges, QEMU templates; node placement settings (maintenance, over-commit, max servers); "use for servers" per storage; IP pools (v4/v6, bridged/routed/NAT, IPv4 addresses generated, reserve/MAC/notes); OS templates mapped to Proxmox templates; packages; admin dashboard. **Not yet verified against a real Proxmox server.**
- [x] **Phase 2 — Provisioning engine:** servers and tasks; per-cluster resource pool (token only needs VM rights on the pool); permission check listing missing **and excess** privileges; placement (online, not in maintenance, bridge, storage, CPU/memory/disk over-commit, max servers); VMID + IP allocation under a per-location lock; idempotent create (full clone → config + cloud-init → grow disk → anti-spoofing ipset → start), suspend/unsuspend, terminate (frees IPs, keeps history); retry after failure; admin server pages with polling progress; customers created by admins (replaced by billing ids, ADR 0007); adopt existing VMs from the pool without touching Proxmox; deletion guards. Limitations: one IPv4 + one IPv6 configured by cloud-init (extra IPs are assigned and allowed; since ADR 0009 the guest agent configures them inside Linux guests); routed /32 with on-link gateway relies on cloud-init and needs real-world verification; NAT not yet supported; Horizon not set up yet (`queue:work`).
- [x] **Phase 3 — Server operations** (built as a client panel, which was removed again in favour of billing modules — ADR 0007; the features stay in the admin panel and get API endpoints in Phase 4): power actions (start/shutdown/stop/reboot, shutdown never forced, live power state); noVNC console through `deploy/console-proxy` (ADR 0002: single-use tickets, one session per server, 60 min max / 15 min idle); Guest Agent client (`GuestAgentResource`: ping, file-write/read, exec + `run()`, set-user-password); root password reset through the agent; SSH key validation (`Support/Ssh/PublicKey`: ed25519, ECDSA, RSA ≥ 2048, no DSA); reinstall (OS template + pasted keys, hostname typed to confirm, same VMID/IPs/MAC, waits for the guest agent, ADR 0004); rDNS (ADR 0003: `DnsProvider` with `none` and `powerdns`, per-pool reverse zone, optional forward confirmation, PTRs removed on release); admin drift report (ADR 0005); `audit_logs` for console, power, password, reinstall, rDNS and drift actions. Not done here: drift notifications and "re-apply package" (with ChangePackage), app post-boot hooks (Phase 5b), Windows guests. **See the verification list below: nothing in Phases 1–3 has run against a real Proxmox server yet.**
- [ ] **Phase 4 — Billing integration:** ~~public API v1 + OpenAPI (catalog, servers, tasks, suspend/unsuspend/terminate, power, reinstall, password reset + fetch once, rDNS, signed single-use console links), billing systems with scoped hashed tokens (IP limits, expiry; admin page) that only see their own servers, rate limits, idempotency~~ (done, ADR 0008; tested with faked HTTP only); ~~**WHMCS module** (WHMCS 8–9, PHP 7.2+: provisioning, configurable options, client area with power, console, reinstall, password, rDNS)~~ (done with the API faked, not run in a real WHMCS yet); ~~webhooks~~ (done, ADR 0013: endpoints per billing system, signed deliveries with retries, admin delivery log with test event and send again; tested with faked HTTP only); ~~WHMCS webhook receiver~~ (done, `webhook.php`, tested with WHMCS and the API faked); ~~**WISECP module**~~ (done, ADR 0014: WISECP 5, server tools, add-ons, order answers, webhook receiver; tested with WISECP and the API faked, not run in a real WISECP yet); still to do: WHMCS `UsageUpdate` (Phase 6).
- [ ] **Phase 5 — App Marketplace:**
  - ~~5a: recipe schema, `RecipeLoader`, `apps:sync` / `apps:validate`, install-later flow through the API and billing modules; first recipes **OpenVPN, WireGuard, Docker**~~ (done 2026-10-17, ADR 0015: detached installer + `apps:poll`, `apps:secrets` scope, `app.*` webhooks, admin *Apps* page and server section, WHMCS client area, WISECP Apps tool; tested with the agent faked. Not done: app actions (5c)). Recipe format v2 (ADR 0016 phase 1, 2026-10-17): stored versions, template capabilities, `apps:make`, bundled recipes on format 2; uninstall and actions (phase 2, 2026-10-17: `AppRun`, API, webhooks, admin; WireGuard/OpenVPN client add/revoke/list, uninstall for all three; WHMCS client area and WISECP Apps tool with action forms, uninstall and recent runs); order-time installs (phase 4, 2026-10-18, = 5b below); upgrade (phase 3, 2026-10-18: `upgrade` runs to the published version when its `upgrade.from` range contains the installed one, new inputs asked for, `upgrade_failed` keeps the old version, `app.upgraded` / `app.upgrade_failed`, admin, WHMCS and WISECP; WireGuard, OpenVPN and Docker 1.3.0 with `upgrade.sh`). Script logs (ADR 0021, 2026-10-18: `run.log` copied every minute and live while an administrator watches, one encrypted, masked log per attempt and run, admin only, pruned after 90 days).) Windows runner (ADR 0029, 2026-10-20: `run.ps1` as a scheduled task, restarts and resume, IIS, SQL Server Express and Chocolatey recipes, `apps:make --platform=windows`, *New app* with a platform; tested with the agent faked). Cancel and stopping (ADR 0030, 2026-10-20: admin, API, WHMCS and WISECP; timeouts stop the script; a start stops the app's earlier script first; tested with the agent faked). Catalog, sources and signing: ADR 0017 (not built).
  - ~~5b: install at order time and reinstall + app as post-boot hooks (ADR 0004), WHMCS configurable option and paid-app entitlements~~ (done 2026-10-18, ADR 0016 §5 = phase 4, ADR 0019: `app_installations.mode`, post-boot step in the create and reinstall pipelines, `app`/`app_inputs` in the API and admin forms, `GET /apps?mode=order`, `app_entitlements` with API and admin grant/revoke, WHMCS and WISECP; tested with the agent and the modules' hosts faked).
  - 5c: admin custom recipe editor (ADR 0018: custom apps, copies of bundled ones, bundled apps edited in place, drafts and publishing, trial runs on test servers, run logs, zip import/export; phases 1 and 2, the back end with audited diffs and the editor page (Monaco Editor since 2026-10-19, js-yaml), built 2026-10-17, simplified 2026-10-19 to five tabs (Details, Requirements, Customer form, Scripts & actions, Try & history; *Edit as YAML* beside them) with the app's operating system versions ticked on the *Requirements* tab (saved at once, ADR 0026; `requires.os` only in *Edit as YAML*) and memory/disk in GB; ~~trial runs~~ (phase 3, 2026-10-18: test server flag on the server page, *Trial runs* tab in the editor installing a frozen copy of the saved draft, trials hidden from billing systems, *Reset after trial* reinstalling a test server from its template until snapshots exist; tested with the agent faked); ~~import/export~~ (phase 4, 2026-10-19: a version as a zip of its recipe directory, imported as a new custom app or into a draft, read in memory with strict limits)), remaining recipes (~~Portainer, Coolify, aaPanel, LAMP, LEMP, Node.js~~ done 2026-10-19: Docker + Portainer CE with the first administrator's bcrypt password and a `reset_password` action through Portainer's helper; Coolify through its installer with the first user from `ROOT_*` variables, no uninstall; aaPanel through its installer with the login read from its output, no uninstall or reset; LAMP and LEMP with one database and optional Let's Encrypt; Node.js 22/24 from NodeSource's repository set up by hand, optionally PM2; ~~WordPress~~ done 2026-10-18; ~~cPanel, Plesk~~ (`license: external`, official installers, no uninstall) and ~~WHMCS~~ (the customer's own download address and licence key, CLI installer, ionCube; new input type `url`) done 2026-10-18; ~~HestiaCP, CyberPanel~~ (official installers, free, `reset_password` action, no uninstall) done 2026-10-18; none run on a real guest yet) (app actions: done in the panel with ADR 0016 phase 2).
- [ ] **Phase 6 — Usage and quotas:** traffic collection, graphs, throttle/suspend on overrun, WHMCS `UsageUpdate`. ~~`ChangePackage` (upgrade/downgrade)~~ (done early with Phase 4: `ResourceChanger` + `ChangeResourcesPipeline`, `POST /servers/{id}/package`, admin *Change resources* dialog, WHMCS `ChangePackage`; CPU/memory of a running VM wait for a restart (`servers.reboot_required`), the disk only grows, the server stays on its node, the newest extra addresses are released; additional addresses are configured inside the guest through the agent, ADR 0009). Guest scripts per OS template (ADR 0010): events `provisioned`, `addresses_changed`, `disk_resized` (partition and file system grown right after an upgrade), `password_reset` (fails the reset when it fails); defaults in `resources/guest/{linux,windows}`, overrides edited under *OS templates → Scripts* (audited, trial run on a server; drafts, `sh -x` traces and a log of every run with its output, ADR 0023). Primary address changes (ADR 0011): `ip_addresses.is_primary`, swap with an additional address or replace from a pool (address count unchanged), applied by the address guest script with the default route; chosen primary at creation (`primary_ipv4`). Address edits (ADR 0028, 2026-10-20, admin only): *Edit IP addresses* on the server page adds the next free or a specific address of a pool on the server's bridge and VLAN, removes chosen addresses (also with *Remove* next to each additional one) and picks the primary per version (a kept address, or an added one, also the next free) in one `change_addresses` task, replacing the admin *Change primary address* button (the API's `primary-address` stays); the server keeps one address per version it has, a removed primary needs a new one; *Apply again* sets the firewall IP set to exactly the server's addresses and runs the address script again; audited as `server.addresses_changed` / `server.addresses_reapplied`; the API and billing modules are unchanged; tested with the fake only. Hostname changes (ADR 0012): `hostname_changed` guest script (Linux keeps `/etc/hosts` through a boot unit, Windows renames at the next restart or with `reboot`), PTRs equal to the old name follow, the Proxmox VM name never changes; admin, API and WHMCS client area (the service domain follows). Traffic quotas are stored but not enforced yet. Linked clones per OS template (ADR 0027, 2026-10-20: *Clone method* on the OS template form, `full=0` without `storage` when the template's single storage is the server's, available on its node (or shared for another node) and of type `lvmthin`, `zfspool`, `rbd` or qcow2 on `dir`/`nfs`/`cifs`; otherwise, or when Proxmox refuses the request, a full clone; tested with the fake only).
- [ ] **Phase 7 — Snapshots and backups:** snapshot CRUD, scheduled backups, Proxmox Backup Server, restore.
- [ ] **Phase 8 — Migration and production install:** Virtualizor importer (dry-run, idempotent, WHMCS module switch guide), native installer, production Docker images, upgrade command, admin branding settings.
- [ ] **Phase 9 — Expansion:** **NAT VPS** (NAT gateway per node, port ranges — ADR first), LXC, ISO upload/mount, rescue/recovery mode (ADR first), customer-managed firewall rules, live migration within clusters, reseller system, Blesta / HostBill / Paymenter / FOSSBilling modules, optional provider plugins (e.g. OVH vMAC).

Each phase must be usable on its own and complete with passing tests. Do not skip phases without the maintainers' agreement.

### Not yet verified against real systems

Everything above was tested with faked HTTP only (`FakeProxmoxCluster`, `FakesProxmox`, `Http::fake()`). Before customers use the panel, check these on a test Proxmox VE (8.x and 9.x where noted) in the `proxmotion-test` pool — step by step in [docs/guides/real-proxmox-verification.md](docs/guides/real-proxmox-verification.md) — and turn what you can into integration tests. Strike an item through with the date once it is verified.

**Proxmox API and permissions**
- [ ] Token with exactly the privileges the permission check asks for (incl. `VM.Console`, `VM.GuestAgent.Unrestricted` on 9.x / `VM.Monitor` on 8.x) can do every operation, and the excess-privilege warning is right. ~~The permission check passes for a pool-limited token (built-in `PVEVMAdmin` + `PVEPoolAdmin` on the pool, `PVEAuditor` on /, `PVEDatastoreUser` on /storage, `PVESDNUser`) and reads pool-inherited privileges correctly~~ (verified 2026-10-16, PVE 9.2.2, `PermissionTest`; it found that `/access/permissions` values are propagate flags). Still open: the operations themselves, PVE 8.x.
- [ ] `/cluster/resources?type=vm` fields used by sync, adoption and drift: ~~`pool`, `maxcpu`, `maxmem`, `maxdisk` are present~~ (verified 2026-10-16, PVE 9.2.2, `ConnectionTest`). `DriftTest` found `maxdisk: 0` for a stopped, freshly cloned VM: it is now treated as unknown (drift skips the disk, adoption reads the boot disk from the config), and it lags the config by a few seconds. For a running single-disk VM it matched the boot disk; still open: a VM with several disks.
- [x] ~~`GET /nodes/{node}/tasks?vmid=&source=active` is accepted and lists a running task (`waitForVm`)~~ (verified 2026-10-16, PVE 9.2.2, `ConnectionTest`, `TaskWaiterTest`).
- [x] ~~Cluster sync of nodes, storages, bridges and templates~~ (verified 2026-10-16, PVE 9.2.2, `ConnectionTest`).
- [x] ~~Form-encoded POST/PUT with repeated keys (the client no longer uses Laravel's `asForm()`): `agent/exec` `command`, and the clone, config, resize and firewall calls of provisioning~~ (verified 2026-10-16, PVE 9.2.2, `GuestAgentTest`, `ProvisioningTest`, `ReinstallTest`).

**Provisioning (Phases 1–2)**
- [ ] Full clone on local and shared storage, cloud-init (`ciuser=root`, `cipassword`, URL-encoded `sshkeys`), disk grow, `ipfilter`/`macfilter` ipset, start: ~~on the test storage, with the config read back from Proxmox~~ (verified 2026-10-16, PVE 9.2.2, `ProvisioningTest`); still open: shared storage and cloning to another node of a cluster.
- [ ] Bridged IPv4 + IPv6, and routed `/32` with the on-link gateway (cloud-init network config): ~~bridged IPv4 `ipconfig0` with gateway is set~~ (verified 2026-10-16, PVE 9.2.2, `ProvisioningTest`); still open: that the guest really gets online with it, IPv6, routed `/32`.
- [ ] Root login works on templates from `deploy/proxmox` (cloud images may keep `disable_root` / password SSH off) and from `docs/guides/iso-templates.md`.
- [x] ~~Suspend/unsuspend, terminate (VM deleted, address released); adoption without touching the VM~~ (verified 2026-10-16, PVE 9.2.2, `ProvisioningTest`, `PowerTest`: adoption starts no task and leaves the config unchanged).

**Phase 3**
- [ ] Console: ~~`vncproxy` with `generate-password` and the single-use ticket~~ (verified 2026-10-16, PVE 9.2.2, `ConsoleTicketTest`); still open: `vncwebsocket` with an API token through `deploy/console-proxy`, heartbeat closing suspended servers' sessions.
- [ ] Power: ~~shutdown without `forceStop`, reboot of a running VM, start of a stopped one, stop~~ (verified 2026-10-16, PVE 9.2.2, `PowerTest`; it found that the reboot task ends before the VM is started again, so `PowerPipeline` now waits up to 60 s for it). Still open: a guest that ignores the shutdown (120 s timeout fails cleanly).
- [ ] Guest agent: ~~ping, `file-write` with `encode=0` (base64 content), `file-read` decoding, `exec`/`exec-status`, `set-user-password`~~ (verified 2026-10-16, PVE 9.2.2, `GuestAgentTest` on one Linux template; it found that `file-read` returns one character per byte, now decoded in `GuestFile`, and that clones need `agent: enabled=1` when the template lacks it). Still open: each of Ubuntu, Debian and RHEL-family templates. RHEL-family qemu-ga blocks `guest-exec` and the file commands by default (reported on AlmaLinux 9, 2026-10-19); the template guide now clears `FILTER_RPC_ARGS` / `BLACKLIST_RPC` in `/etc/sysconfig/qemu-ga` and the panel names that fix in the error. With SELinux enforcing the agent's commands stay in `virt_qemu_ga_t` and could not create `/var/lib/proxmotion/apps/…` (reported on AlmaLinux 9, 2026-10-19): the guide now makes that domain permissive (`semanage permissive -a virt_qemu_ga_t`), and a failing `mkdir` before file writes now fails with its error. Still open: that the permissive domain is enough for `systemd-run` and the installers.
- [x] ~~Reinstall: delete and re-clone with the same VMID, MAC kept, firewall ipset recreated, agent answers within 10 minutes, old disk gone~~ (verified 2026-10-16, PVE 9.2.2, `ReinstallTest`, same template).
- [ ] Drift: ~~a VM deleted, resized (cores), stopped or added to the pool shows up after a sync and closes once fixed~~ (verified 2026-10-16, PVE 9.2.2, `DriftTest`); still open: migrated (needs a cluster).
- [ ] rDNS with a real PowerDNS: `PATCH` REPLACE/DELETE of PTR rrsets in an existing reverse zone, connection test.
- [ ] Generated hostnames (ADR 0031): a VM named `203.0.113.5` and the hostname cloud-init / Cloudbase-Init derive from it, the `hostname_changed` script right after the first boot (Linux and Windows with its restart), the PTRs published through PowerDNS right after creation, and the WHMCS / WISECP services taking the generated name.

**App Marketplace (ADR 0015)**
- [ ] `systemd-run --collect` starting `run.sh` through the guest agent and the unit surviving the agent's exec; `file-read` of `status.json` and binary-safe output files.
- [ ] The bundled recipes on Ubuntu 22.04/24.04 and Debian 12/13 (`recipes` integration group): WordPress (WP-CLI as `www-data`, nginx site, certbot), Docker repository, WireGuard and OpenVPN clients really connecting, IPv6 through the tunnel, re-running a recipe keeps its keys.
- [ ] cPanel (AlmaLinux 8–10, Rocky 8–9, Ubuntu 24.04) and Plesk (Debian 12+, Ubuntu 22.04+, AlmaLinux 8+, Rocky 8) through their official installers inside a run of at most 120 / 90 minutes, `plesk bin init_conf --set-admin-password` before the first login; WHMCS 9 with the CLI installer and its JSON configuration on standard input, the ionCube Loader through `phpenmod`, the `tblconfiguration` SystemURL row.
- [ ] HestiaCP (Debian 12–13, Ubuntu 22.04–26.04) with `hst-install.sh --interactive no` (no prompt left on a fresh cloud image, `v-change-user-password` afterwards) and CyberPanel (Ubuntu 22.04–26.04, AlmaLinux 8–10; refused on Rocky Linux, which `cyberpanel.net/install.sh` does not recognise; on 2026-10-20 the installer failed on AlmaLinux 8 because of code needing Python 3.7+ (its EL8 environment is Python 3.6), still open: that CyberPanel fixed it) with `install.sh --version ols` (silent, `adminPass` afterwards; its installer's exit status is not trusted, failures report the last Python exception it printed), each inside its 45 / 90 minute run.
- [ ] The 2026-10-19 recipes: Portainer's `--admin-password` bcrypt hash from `htpasswd -nbBC 10` and the `portainer/helper-reset-password` output ending in `login: <password>`; Coolify's installer creating the first user from `ROOT_USERNAME` / `ROOT_USER_EMAIL` / `ROOT_USER_PASSWORD` and `/api/health` answering; aaPanel's `install_7.0_en.sh` asking only once on standard input and printing the `Internet ... Address`, `username:` and `password:` lines read from it (also from `bt default`); LAMP and LEMP with certbot; NodeSource's `nodistro` repository with the pin, and `pm2 startup` creating `pm2-root`.
- [ ] Script logs (ADR 0021): `bash -c 'dd … iflag=skip_bytes,count_bytes | base64 -w0'` through `agent/exec` on each supported distribution (GNU dd), the 48 KiB chunks arriving whole in `out-data`, and reading a log of several MiB during a long install (cPanel).
- [x] ~~shellcheck on `recipes/**/*.sh`~~ (2026-10-18: every tracked script passes shellcheck 0.10.0 with `.shellcheckrc`, also in CI).
- [ ] Windows apps (ADR 0029): `start.ps1` through `agent/exec` registering and starting the scheduled task as SYSTEM (`-UserId S-1-5-18`) and the task outliving the agent's exec; `icacls` on `C:\ProgramData\Proxmotion\apps`; `cmd /c mkdir` with several directories; the restart through the start-up task and `Disable-ScheduledTask` of the running task; `read-log.ps1` chunks in `out-data`; UTF-8 output of the child `powershell.exe`; the IIS feature names on Server 2016–2025 and Windows 10/11; SQL Server Express setup with `SAPWD` on the command line, the TCP port in the registry and SYSTEM as sysadmin; Chocolatey's install script and .NET 4.8 on Server 2016; the `aka.ms/dotnet/<release>/dotnet-hosting-win.exe` links.
- [ ] Stopping scripts (ADR 0030): `stop.sh` through `agent/exec` (`systemctl show -p ActiveState --value`, systemd 230+; `systemctl kill` reaching processes installers daemonize inside the unit, e.g. cPanel's), and `stop.ps1` (`Win32_Process` command lines of `run.ps1` and its child, `Stop-ScheduledTask`, ending `msiexec` and setup children, a cancel during a restart); a cancelled installer leaving `dpkg` configurable again on retry.
- [ ] Upgrades (ADR 0016 phase 3): the bundled `upgrade.sh` scripts (`pm_apt_upgrade` with `--only-upgrade` and the old configuration files kept) on a server installed with 1.2.x, VPN clients still connecting afterwards.
- [ ] Apps chosen with the order or a reinstall (ADR 0016 §5): the installer started by the last step of the create/reinstall task right after the first boot (the runner now waits up to 10 minutes for `cloud-init status --wait` and the dpkg/apt or rpm locks, `pm_wait_for_boot`, and such installs get 10 extra minutes on their timeout; still open: that this is enough on real cloud images and that `fuser` or the `pgrep` fallback sees the locks), and the 10-minute agent wait of a template without an agent failing only the installation.

**Billing modules: order-time and paid apps (ADR 0016 §5, 0019)**
- [ ] WHMCS: `hooks.php` in the server module's directory is loaded; `AddonActivation`, `AddonCancelled` and `AddonTerminated` pass `id`, `userid`, `serviceid` and `addonid`; `logActivity` with a user id; `cart.php?gid=addons&pid=<service id>` opens the service's addons; the `app` configurable option and `app_<key>` custom fields arrive in `$params` as assumed.
- [ ] WISECP: the order answers `app` and `app_inputs` arrive in `requirement_params`; an add-on module value of type `value` may hold an app id (text) and arrives in `addon_params`; `upgrade()` runs when add-ons change; the *Reinstall with an app* form in the Apps tool.

**Linked clones (ADR 0027)**
- [ ] `full=0` without `storage` on LVM-thin, ZFS and a qcow2 directory storage; `storage` with `full=0` refused as assumed; across nodes on Ceph with `target`; `efidisk0`/`tpmstate0` of a Windows template linked; resize of a linked disk; deleting a template with linked clones refused; the time saved on the Windows Server 2022 template (full clone 2:00 on LVM-thin, 2026-10-20).

**Deployment and tooling**
- [ ] Docker Compose stack incl. `console-proxy` behind nginx (`/console-ws`), queue worker (Horizon not set up yet).
- [ ] Staged tasks (ADR 0032): delayed dispatches of `RunServerTask` on the database queue and on Redis (later with Horizon), several servers created at once with one worker, a Windows server whose setup outlasts one run, and the 1860 s `retry_after` keeping a long job from being handed out twice.
- [ ] `deploy/proxmox` scripts on a real node (`build-templates.sh`) and `prepare-images.sh` natively and via `images.Dockerfile`.
- [ ] ADR 0004 assumption that snippets cannot be uploaded through the API.

**Billing (Phase 4)**
- [ ] WHMCS module in a real WHMCS 8.x (PHP 7.4) and 9.x: module settings loaders, Create/Suspend/Unsuspend/Terminate/ChangePassword, configurable option names (`$params['configoptions']`), `suspendreason`, the client area template on the default themes, custom actions with the CSRF `token`, the console redirect, `localAPI('UpdateClientProduct')` storing the password.
- [ ] Webhooks (ADR 0013) against a real receiver: delivery through the queue worker with the delayed retries on Redis, the signature checked by a receiver in another language, behaviour with a slow receiver (10 s timeout). The WHMCS receiver (`webhook.php`) in a real WHMCS: `init.php` bootstrap without a session or CSRF token, `DecryptPassword`, `UpdateClientProduct` with `status`, `SendAdminEmail` with a custom message, the events table created through `Capsule::schema()`.
- [ ] WISECP module (ADR 0014) in a real WISECP 5: ~~the module is listed under Virtualization and the server form shows the relabelled fields~~ (verified 2026-10-17, WISECP 5.0-beta.3 on a local PHP 8.3 + MariaDB install; it found that WISECP always requires username and password, so the token moved to the password field). ~~Saving the server (token stored encrypted), Test Connection against a local panel, the module log with the token masked~~ (verified 2026-10-17, same install). ~~The product Module tab: packages, locations and OS templates listed from the panel, the settings saved in `module_data` with the keys `ServerOrder` reads and shown again~~ (verified 2026-10-17, same install; WISECP shows "No package" and "Default" last in the lists). Still open: order answers (`requirement_params`) and add-on values (`addon_params`) as assumed, create/suspend/unsuspend/cancel/upgrade/change password, the server tools (power, password reset, reinstall, hostname, reverse DNS, the console `redirect`), dashboard cards, the added Apps tool and its page script. The webhook route `POST /api/v1/proxmotion/webhook` from `hooks.php` reaching `api_webhook()` of a server module, `Services::change_status`, the activation-failure notice, the events table.
- [ ] Database: the virtual `live_external_id` column with its unique index on MariaDB 10.11 and MySQL 8 (only SQLite was tested).
- [ ] OS templates (ADR 0024, 0026): the migrations making `os_templates.distribution` NOT NULL (`->change()`) and moving templates into `operating_systems` (dropping `compatible_with` / `compatible_version`, ticking apps) on MariaDB 10.11 and MySQL 8 with existing templates (only SQLite was tested); the *Check against a server* `file-read` of `/etc/os-release` (a symlink to `/usr/lib/os-release` on most systems).
- [ ] Resource changes (`ResourceChangeTest`): ~~a stopped VM takes new cores at once; `cores`/`memory` of a running VM stay pending (no CPU/memory hotplug on the test template), `GET .../pending` lists them, a reboot through the panel applies them; the disk grows at once~~ (verified 2026-10-16, PVE 9.2.2; earlier runs lost the connection because new TCP connections to the hosted test node were sometimes dropped — the client now reuses connections and retries one that was never established). Still open: `rate=` change applied live, `DELETE .../firewall/ipset/ipfilter-net0/{cidr}`, what `/cluster/resources` shows while changes are pending.
- [ ] Additional addresses inside the guest (`GuestAddressesTest`, ADR 0009): ~~configured, kept across a reboot by the systemd unit, removed when released~~ (verified 2026-10-16, PVE 9.2.2, on the test template); still open: Ubuntu, Debian and RHEL-family templates each, whether the network service drops them on reconfiguration.
- [ ] Guest scripts (ADR 0010): ~~the default `disk.sh` grows the root file system of a running server right after an upgrade (`ResourceChangeTest` checks `df`), and the default `addresses.sh` with the new `addresses.vars` input works (`GuestAddressesTest`)~~ (verified 2026-10-16, PVE 9.2.2, on the test template). Still open: XFS and LVM root file systems, a template override and the trial run (with a draft and `sh -x`, ADR 0023), a password override. The Windows defaults (`addresses.ps1`, `disk.ps1`) and the Windows password user `Administrator` have never run on a Windows guest.
- [ ] cloud-init wait (`GuestScripts::CLOUD_INIT_STATUS_COMMAND`): `cloud-init status` through `agent/exec` printing `not started` and `running` during the first boot on Debian, Ubuntu and RHEL-family templates, and the hostname then kept by the `hostname_changed` script (on 2026-10-20 a Debian 13 server renamed 24 s after start, before cloud-init finished, seemed to lose the name).
- [ ] Windows setup wait (`GuestScripts::WINDOWS_SETUP_COMMAND`): ~~the server became *Active* a few seconds after the `cloudbase-init` service stopped, with one setup restart~~ (observed once 2026-10-20, Windows Server 2022, template per `docs/guides/windows-templates.md`). Still open: the PowerShell check through `agent/exec` during the specialize pass and after OOBE, `ImageState` reaching `IMAGE_STATE_COMPLETE`, the `cloudbase-init` service reported as `Stopped` once it has run, and whether the agent can answer between a restart and the start of that service (the check would pass too early).
- [ ] Primary address changes (ADR 0011): ~~changing `ipconfig0` of a booted guest and restarting gives cloud-init a new instance id and new SSH host keys~~ (verified 2026-10-17, Debian 13, cloud-init 25.1.4, PVE 9.2.2, `CloudInitIdentityTest`: the reason `ipconfig0` is never changed). ~~swap (default route through the new primary, which becomes the kernel's first address so `hostname -I` reports it; old one kept), kept across a reboot, replacement with the released primary removed inside the guest, `ipconfig0` unchanged~~ (verified 2026-10-17, PVE 9.2.2, `PrimaryAddressTest`, Debian 13 test template). Still open: IPv6, routed `/32` primaries, other distributions, Windows.
- [ ] Address edits (ADR 0028): removing a chosen additional address from `ipfilter-net0` and inside the guest, adding a specific address of another pool on the same bridge, *Apply again* removing stray IP set entries and restoring an address lost inside the guest.
- [ ] Hostname changes (ADR 0012): ~~`preserve_hostname` keeps a name changed inside the guest but cloud-init rewrites `/etc/hosts` at every boot; renaming the Proxmox VM gives a new instance id and new SSH host keys~~ (verified 2026-10-17, Debian 13, cloud-init 25.1.4, PVE 9.2.2, `HostnameIdentityTest`). ~~rename inside the guest, kept with `/etc/hosts` across a reboot by the boot unit, VM name unchanged~~ (verified 2026-10-17, PVE 9.2.2, `HostnameTest`, Debian 13 test template). Still open: other distributions (RHEL's hosts template), Windows `hostname.ps1`, the WHMCS client area form in a real WHMCS.

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
