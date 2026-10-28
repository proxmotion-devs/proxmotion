# Verifying the panel on a test Proxmox VE

Everything in Phases 1–3 was developed against faked HTTP. This runbook checks the
"Not yet verified against real systems" list in [AGENTS.md](../../AGENTS.md) on a real
test node, step by step, and describes the integration tests that keep it verified.

> Run this on a **test** node or cluster only. The panel creates, reconfigures and deletes
> VMs. Everything happens in the `proxmotion-test` resource pool, but a token with the
> wrong ACLs could reach more than that.

**Contents**

1. [What you need](#1-what-you-need)
2. [Prepare Proxmox: pool, templates and API token](#2-prepare-proxmox-pool-templates-and-api-token)
3. [Prepare the panel](#3-prepare-the-panel)
4. [Manual checks](#4-manual-checks)
5. [Recording results](#5-recording-results)
6. [Integration tests to write](#6-integration-tests-to-write)

## 1. What you need

- A test Proxmox VE **9.x** node (ideally also an **8.x** node, for the `VM.Monitor` path),
  not used for customers. A two-node cluster with local storage is a bonus for the
  "migrated VM" drift check.
- A few spare public IPv4 addresses (and an IPv6 range if you want to test IPv6) on the
  node's bridge, or a private test range if the VMs only need to reach each other. Routed
  `/32` checks need a provider with failover IPs.
- The panel running locally (`composer dev` or Docker Compose) **with a queue worker**
  (`php artisan queue:work`), and `deploy/console-proxy` running for the console checks.
- Optional: a PowerDNS Authoritative server with the HTTP API enabled and a reverse zone
  for your test addresses.

## 2. Prepare Proxmox: pool, templates and API token

All commands run as root in a node shell.

### 2.1 Resource pool

```bash
pveum pool add proxmotion-test --comment "Proxmotion verification"
```

### 2.2 Templates

Build at least one Linux template with the cloud-image scripts
([os-templates.md](os-templates.md)) and, if you can, one from an ISO
([iso-templates.md](iso-templates.md)). Put them into the pool so the token may clone them:

```bash
POOL=proxmotion-test ./build-templates.sh        # or add existing templates:
pveum pool modify proxmotion-test --vms 9000,9001
```

### 2.3 User, roles and token

The panel asks for least privilege and flags excess ones. Create a dedicated user whose
token inherits its permissions (`--privsep 0`):

```bash
pveum user add proxmotion@pve --comment "Proxmotion panel"
```

Role for managing servers in the pool. **Proxmox VE 9.x:**

```bash
pveum role add ProxmotionServers --privs "VM.Audit,VM.Allocate,VM.Clone,VM.PowerMgmt,VM.Console,VM.Config.CPU,VM.Config.Memory,VM.Config.Disk,VM.Config.Network,VM.Config.Cloudinit,VM.Config.Options,VM.Snapshot,VM.Snapshot.Rollback,VM.GuestAgent.Unrestricted,Pool.Allocate"
```

**Proxmox VE 8.x:** the same, with `VM.Monitor` instead of `VM.GuestAgent.Unrestricted`.

Role for allocating disks:

```bash
pveum role add ProxmotionStorage --privs "Datastore.AllocateSpace,Datastore.Audit"
```

ACLs:

```bash
pveum acl modify / --users proxmotion@pve --roles PVEAuditor
pveum acl modify /pool/proxmotion-test --users proxmotion@pve --roles ProxmotionServers
pveum acl modify /storage --users proxmotion@pve --roles ProxmotionStorage
pveum acl modify /sdn/zones/localnetwork --users proxmotion@pve --roles PVESDNUser
```

Token (the secret is shown once; keep it in your password manager, never in the repository):

```bash
pveum user token add proxmotion@pve panel --privsep 0
```

> If the panel's permission check later reports a missing or excess privilege that this
> list does not match, that is a finding: note which operation needed what.

**Alternative with built-in roles** (verified to pass the permission check on Proxmox VE
9.2.2): instead of the two custom roles, give the user `PVEVMAdmin` and `PVEPoolAdmin` on
`/pool/proxmotion-test` and `PVEDatastoreUser` on `/storage`. This grants a bit more than the
panel needs (e.g. migrate, snapshots, backups of the pool's VMs), but nothing outside the pool.

```bash
pveum acl modify /pool/proxmotion-test --users proxmotion@pve --roles PVEVMAdmin,PVEPoolAdmin
pveum acl modify /storage --users proxmotion@pve --roles PVEDatastoreUser
```

## 3. Prepare the panel

1. *Admin → Locations*: add a test location.
2. *Admin → Clusters*: add the node or cluster with the token
   (`proxmotion@pve!panel`), resource pool `proxmotion-test`. The connection is tested on
   save.
3. Run a sync, then **Check permissions** on the cluster page.
   *Expected:* no missing privileges, no excess privileges.
4. On the node: enable "use for servers" on the storage you want.
5. *Admin → IP pools*: add a pool with only the spare addresses (list mode).
6. *Admin → OS templates*: map the templates; *Admin → Packages*: a small package
   (1 vCPU, 1 GiB, 10 GiB).

## 4. Manual checks

For each check: do the steps, compare with *Expected*, and look at the task log in the
Proxmox UI and the VM's *Hardware*, *Cloud-Init* and *Firewall* tabs when something differs.
The numbers match the groups of the list in AGENTS.md.

### 4.1 Proxmox API and permissions

| # | Steps | Expected |
|---|---|---|
| A1 | Permission check after section 3 | Nothing missing, nothing excess; on 8.x it asks for `VM.Monitor` |
| A2 | Remove `VM.Console` from the role, check again, open a console | Check lists it as missing; console shows a readable error |
| A3 | Sync with a non-template VM in the pool | Adoption wizard lists it with cores, memory and disk matching the Proxmox UI (`maxdisk` = boot disk?) |
| A4 | Start a backup or shutdown of a server in the Proxmox UI, then suspend it in the panel | Suspend waits for the running task instead of failing with "can't lock file" |
| A5 | Covered by B/C below: every POST/PUT goes through the new form encoding | No "parameter verification failed" errors in tasks |

### 4.2 Provisioning

| # | Steps | Expected |
|---|---|---|
| B1 | Create a server (bridged IPv4) on local storage | Task succeeds; VM in the pool; `ipconfig0`, `nameserver`, `sshkeys` set; disk grown; firewall enabled with `ipfilter`/`macfilter` and the ipset; VM running |
| B2 | Same on shared storage (if available) and on another node of a cluster | Clone with `target` works |
| B3 | Log in with the password and with an SSH key | Both work for `root` — if not, the templates need the cloud-init/sshd settings from iso-templates.md |
| B4 | IPv6 and routed `/32` (if available) | Address and gateway configured; ping out works |
| B5 | Suspend, unsuspend, delete | VM stops/starts; delete removes VM and disks and frees the IPs |
| B6 | Adopt an existing VM in the pool | Recorded without any write in the Proxmox task log |

### 4.3 Phase 3

| # | Steps | Expected |
|---|---|---|
| C1 | Open the console from the server page | Screen appears through `/console-ws`; one session per server; suspending the server closes it within ~15 s |
| C2 | Power: shutdown, start, reboot, stop | States change; a shutdown of a VM that ignores ACPI fails after 2 minutes and Stop works |
| C3 | Server page "Guest agent" | *Reachable* on agent templates, *Not reachable* on a template without agent |
| C4 | Reset root password, show it once, log in | New password works; old one does not; second "show" is refused |
| C5 | Reinstall with another template and one saved key | Same VMID, IPs and MAC; disks new; ipset present; agent step done; new password and the key work |
| C6 | Drift: in the Proxmox UI delete a VM, change cores of another, stop a third, migrate one (cluster), add a VM to the pool; run `php artisan infra:sync` | Each appears in *Drift report* (the stopped one after the grace period); accept/mark failed/apply power behave as described; drifts close after fixing |
| C7 | rDNS on a record-only pool | PTR pending; CSV export correct; "Mark all as applied" clears it |
| C8 | rDNS with PowerDNS (provider, reverse zone on the pool) | *Test connection* ok; PTR appears (`dig -x <ip>`); clearing removes it; deleting the server removes it |

### 4.4 Deployment and tooling

| # | Steps | Expected |
|---|---|---|
| D1 | `docker compose -f deploy/docker/compose.yml up -d`, sign in, open a console | App, worker and console proxy work behind nginx |
| D2 | `deploy/proxmox` scripts on the node; `prepare-images.sh` on Linux and through `images.Dockerfile` | Templates built as described; CI shellcheck green |
| D3 | API viewer / try: upload a file with content type `snippets` through `POST /nodes/{node}/storage/{storage}/upload` | Refused (as ADR 0004 assumes) — if it works, note it in ADR 0004 |

## 5. Recording results

- In AGENTS.md, strike an item through with the date and PVE version once verified,
  e.g. `- [x] ~~Console …~~ (verified 2026-10-20, PVE 9.0.6)`.
- For each finding open an issue (no real IPs, hostnames or tokens in it — use
  documentation ranges), or fix it right away with a test that reproduces it in
  `FakeProxmoxCluster`.
- Update fixtures in `tests/Fixtures/proxmox/` with real (anonymized) responses where they
  differ.

## 6. Integration tests to write

Automate the checks that only need the Proxmox API, so they can be re-run after every
change that touches `app/Proxmox` or the pipelines. Manual checks remain for the console
in a browser, logins into the VM, DNS lookups and deployment.

### 6.1 Design

- A separate PHPUnit suite **`Integration`** in `tests/Integration/`, not part of the default
  run (`php artisan test` stays offline; CI does not run it). Run it with
  `php artisan test --testsuite=Integration`.
- A base `IntegrationTestCase` that **skips** unless all of these are set (in
  `panel/.env.integration`, never committed):

  | Variable | Example |
  |---|---|
  | `PROXMOX_TEST_HOST` | `pve-test.example.com` |
  | `PROXMOX_TEST_TOKEN_ID` | `proxmotion@pve!panel` |
  | `PROXMOX_TEST_TOKEN_SECRET` | — |
  | `PROXMOX_TEST_VERIFY_TLS` | `false` for a self-signed test node |
  | `PROXMOX_TEST_NODE`, `PROXMOX_TEST_STORAGE`, `PROXMOX_TEST_BRIDGE` | `pve1`, `local-lvm`, `vmbr0` |
  | `PROXMOX_TEST_TEMPLATE_VMID` | `9000` (agent template in the pool) |
  | `PROXMOX_TEST_VMID_FROM` | `990000` — the tests only use VMIDs from here up |
  | `PROXMOX_TEST_IPV4` | `CIDR` of one spare address with its gateway, for provisioning tests |

- Safety rules, enforced in the base class:
  - only VMIDs ≥ `PROXMOX_TEST_VMID_FROM`, and only VMs in `proxmotion-test`;
  - refuse to run if the token has any of the dangerous privileges the permission check
    knows (`Sys.Modify`, `Permissions.Modify`, …);
  - delete every VM a test created in `tearDown`, also after failures; a final
    `CleanupTest` removes leftovers in the VMID range.
- Tests use the real `ProxmoxClient` through `ProxmoxClientFactory`, with an in-memory
  SQLite database for panel records (`RefreshDatabase`), and a synchronous queue.
- Keep them slow-but-few: one full clone shared by the tests of a class where possible.

### 6.2 Tests

| Test | Covers |
|---|---|
| `ConnectionTest` — version, cluster status, nodes, storages, bridges; sync runs | A1, A3 (field shapes of `/cluster/resources`), sync |
| `PermissionTest` — permission check of the test token reports nothing missing or excess | A1 |
| `ProvisioningTest` — `ServerCreator::create()` end to end; assert VM config (`ipconfig0`, `sshkeys`, `agent`, disk size), firewall options and ipset; then suspend, unsuspend, terminate | B1, B5 |
| `TaskWaiterTest` — start a VM, run `waitForVm` while a shutdown task runs | A4 |
| `PowerTest` — start, shutdown (no force), reboot, stop through `PowerPipeline` | C2 |
| `GuestAgentTest` — wait for ping on the new VM; `writeFile`/`readFile` round trip (binary data), `run(['cat', '/etc/os-release'])`, `setUserPassword` then `run(['passwd', '-S', 'root'])` | A5, C3, C4 |
| `ReinstallTest` — reinstall the server; same VMID and MAC, new disk, ipset, `wait_agent` step done | C5 |
| `DriftTest` — change cores via `QemuResource::updateConfig`, stop the VM, run the detector; delete a VM; expect the drifts, then fix and expect them closed | C6 |
| `ConsoleTicketTest` — `vncProxy` returns port, ticket and password for a running VM (the WebSocket itself stays manual) | C1 (partly) |

rDNS against a real PowerDNS can get its own optional group (`PDNS_TEST_URL`,
`PDNS_TEST_KEY`, `PDNS_TEST_ZONE`) with a `PowerDnsDriverTest` that sets, replaces and
deletes one PTR in the test zone.

### 6.3 Order of work

1. ~~Base class, env handling, safety checks and cleanup, plus `ConnectionTest` and
   `PermissionTest`~~ — done (`tests/Integration`). Copy `panel/.env.integration.example`
   to `panel/.env.integration`, fill it in and run `composer test:integration` inside
   `panel/`. Without that file (or without the composer script) every test is skipped.
2. ~~`ProvisioningTest` and `GuestAgentTest`~~ — written. `GuestAgentTest` full-clones the
   test template, starts it and runs every agent call the panel uses (about a few
   minutes). `ProvisioningTest` creates a server through `ServerCreator` with one spare
   address (`PROXMOX_TEST_IPV4`, `PROXMOX_TEST_IPV4_GATEWAY`; skipped when empty), then
   suspends, unsuspends and terminates it. Both delete their VMs afterwards; if a run is
   interrupted, delete VMs from `PROXMOX_TEST_VMID_FROM` up in the test pool by hand.
   Run a single one directly (PowerShell:
   `$env:PROXMOX_INTEGRATION=1; php artisan test --testsuite=Integration --filter=GuestAgentTest`,
   bash: `PROXMOX_INTEGRATION=1 php artisan test --testsuite=Integration --filter=GuestAgentTest`).
3. ~~`PowerTest`, `TaskWaiterTest`, `ReinstallTest`, `DriftTest`, `ConsoleTicketTest`~~ —
   written. Helpers to provision a server (`provisionServer()`, needs the spare address)
   or adopt a clone of the test template (`adoptTestVm()`, no address needed) live in
   `tests/Integration/Concerns/ManagesTestServers.php`.
   - `PowerTest` adopts a clone and checks that adoption starts no Proxmox task and leaves
     the config unchanged (B6), then start, shutdown (not forced), reboot of a stopped and
     of a running VM, stop.
   - `TaskWaiterTest` starts a shutdown, expects it in the active task list and waits for it.
   - `ConsoleTicketTest` opens a console session on an adopted, running clone and redeems
     the ticket once.
   - `DriftTest` stops an adopted server and changes its cores, puts both back, clones an
     unmanaged VM into the pool, then deletes the server's VM, syncing after each step.
   - `ReinstallTest` (spare address) reinstalls a provisioned server and checks the VMID,
     MAC, address, ipset, the guest agent and that a file from the old system is gone.
4. Optional PowerDNS group.
