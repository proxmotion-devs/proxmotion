# Proxmotion Features

This page describes everything Proxmotion can do today, how the pieces fit together, and what is still planned. It is written for hosting providers evaluating or running Proxmotion, and for contributors who want a map before reading the code.

> **Status.** Proxmotion is in active development and not yet ready for production. Most features below are built and covered by automated tests that fake Proxmox, WHMCS and WISECP. Only some of them have been checked against a real Proxmox VE server so far; see [Verification status](#17-verification-status). Features that are planned but not built are marked **(planned)**.

## Contents

1. [What Proxmotion is](#1-what-proxmotion-is)
2. [Core concepts](#2-core-concepts)
3. [Admin panel](#3-admin-panel)
4. [Infrastructure: clusters, nodes, storages](#4-infrastructure-clusters-nodes-storages)
5. [Locations, placement groups and placement](#5-locations-placement-groups-and-placement)
6. [Networking: IP pools, network models, rDNS](#6-networking-ip-pools-network-models-rdns)
7. [OS templates and guest scripts](#7-os-templates-and-guest-scripts)
8. [Packages](#8-packages)
9. [Servers: lifecycle and operations](#9-servers-lifecycle-and-operations)
10. [Browser console](#10-browser-console)
11. [App Marketplace](#11-app-marketplace)
12. [Billing API v1](#12-billing-api-v1)
13. [Webhooks](#13-webhooks)
14. [Billing modules: WHMCS and WISECP](#14-billing-modules-whmcs-and-wisecp)
15. [Migration: adopting VMs and drift reports](#15-migration-adopting-vms-and-drift-reports)
16. [Security](#16-security)
17. [Verification status](#17-verification-status)
18. [Operations: commands, scheduler, settings, deployment](#18-operations-commands-scheduler-settings-deployment)
19. [Roadmap](#19-roadmap)
20. [Further reading](#20-further-reading)

---

## 1. What Proxmotion is

Proxmotion is an open-source (AGPL-3.0), multi-tenant **VPS management panel for Proxmox VE**, in the spirit of Virtualizor and VirtFusion.

- Hosting providers connect their **Proxmox nodes and clusters** to the panel.
- When a customer buys a VPS in a **billing system** (WHMCS or WISECP today), the billing module calls the Proxmotion API and the server is **provisioned automatically**.
- The customer manages the server from the **billing system's client area**: power, console, reinstall, password, rDNS, apps and more.

Proxmotion has **two faces**:

| Face | Who uses it | What it is |
|---|---|---|
| **Admin panel** | Staff only | A web UI (Vue 3 + Inertia) for infrastructure, IP pools, packages, OS templates, apps, servers, billing systems, audit trail |
| **Billing API + modules** | Billing systems, and through them customers | A versioned REST API (`/api/v1`) and ready-made WHMCS and WISECP server modules |

**There is no client panel and there are no customer accounts** ([ADR 0007](../adr/0007-billing-only-no-client-panel.md)). Customers only ever see their billing system. The one page a customer can reach in Proxmotion is the browser console, through a signed, single-use link.

Nothing company-specific is built in: no hard-coded names, IP ranges or branding. The UI is available in **English and Turkish**.

---

## 2. Core concepts

| Concept | Meaning |
|---|---|
| **Location** | A place servers are sold in (e.g. a data centre). IP pools, nodes and placement groups belong to a location. |
| **Cluster** | One Proxmox API connection. A standalone node is a cluster of type `standalone` with exactly one node; a real cluster has type `cluster` and may list several API hosts for failover. |
| **Node** | A Proxmox node of a cluster, with capacity limits and a state (active / maintenance). |
| **Storage** | A Proxmox storage. The `shared` flag decides how cloning works; *Use for servers* decides whether automatic placement may put disks there. |
| **Placement group** | A panel-only, named set of nodes of one location (optionally with their storages) that packages can be pinned to. |
| **IP pool** | An IPv4 or IPv6 range with a network model, gateway, netmask, VLAN, location and rDNS settings. |
| **Package** | A plan: CPU, memory, disk, traffic quota, port speed, CPU and disk limits, IP counts, allowed network models, optional placement groups. |
| **OS template** | A Proxmox template VM (cloud-init + QEMU guest agent) mapped per node or cluster, with its distribution, version and family. |
| **Server** | A VPS managed by the panel. Identified publicly by a UUID, and by the billing system's service id (`external_id`). |
| **Task** | One asynchronous operation on a server (create, reinstall, power, …), with step-by-step progress. |
| **Billing system** | A connected WHMCS / WISECP installation. Owns API tokens, webhook endpoints and its servers. |
| **App recipe** | A packaged application (OpenVPN, Docker, cPanel, …) that can be installed inside a server. |

**How an order becomes a server**

```
Billing system ──POST /api/v1/servers──▶ Panel: validate, place, reserve VMID + IPs  ──▶  Task (queued)  ──▶  202
                                                                                             │
                                                                     Queue worker runs the pipeline:
                                                   full clone → config + cloud-init → grow disk → anti-spoofing → start
                                                   → wait for guest agent → guest scripts → (optional) app install
                                                                                             │
Billing system ◀──── webhook server.provisioned / task.succeeded ◀───────────────────────────┘
               (or polls GET /tasks/{id})
```

Every change to Proxmox runs **in the queue**. HTTP requests never wait for Proxmox: they create a `Task` and return at once. Admin pages poll while a task runs; billing modules poll `GET /tasks/{id}` or receive webhooks.

---

## 3. Admin panel

The admin panel is for staff. **Every panel user is an administrator**; there are no roles.

### 3.1 Sign-in and accounts

- Login, password reset, email verification and **passkeys** (Laravel Fortify).
- **TOTP two-factor authentication is mandatory**: an administrator must set it up before any admin page opens. (`PROXMOTION_REQUIRE_ADMIN_2FA=false` exists for local development only.)
- **No public registration.** Administrators are created by other administrators (*Admin → Users*); creating one is audited.
- Administrators cannot delete their own account.
- Each user picks a UI language (English or Turkish).
- Session cookies are `Secure`, `HttpOnly`, `SameSite=Lax`.

### 3.2 Pages

| Page | What it does |
|---|---|
| **Dashboard** | Overview of the installation |
| **Help** | Setup order with what is already done, the OS template guide (Linux / Windows / why not macOS, with every command), questions and answers; links to the full guides when a documentation address is set (*Panel settings*) |
| **Locations** | Create and edit locations |
| **Clusters** | Connect clusters and standalone nodes, test the connection, check permissions, sync now; edit node and storage settings; storage and disk health (ADR 0047) |
| **Placement groups** | Named node sets per location |
| **IP pools** | Pools, addresses (generate, bulk edit, reserve, per-IP MAC, notes), rDNS export |
| **DNS providers** | rDNS drivers (`none`, `powerdns`) with a connection test |
| **OS templates** | Templates, guest script overrides, *Check against a server* |
| **Packages** | Plans |
| **Apps** | App Marketplace catalog and the recipe editor |
| **Servers** | List, create wizard, adopt existing VMs, server page with every operation |
| **Drifts** | Differences between the panel and Proxmox |
| **Reports** | Capacity (nodes, storages, IP pools after over-commit), traffic and usage of a monthly period (per server, location, package, billing system; usage violations), server inventory (by status, location, package, OS template, billing system; created and deleted per month), operations (tasks and app installations of the last 7–365 days with failure rates and median durations, failed tasks); every table as CSV (ADR 0046) |
| **Billing systems** | Billing systems, API tokens, webhook endpoints and delivery log |
| **Users** | Administrators |
| **Panel settings** | Panel-wide settings: cross-cluster moves, deadlines, lowest VMID, DNS check name, protected networks, drift grace period, storage health thresholds, console limits, documentation address, format of generated root passwords (ADR 0038); shows the `.env`-only security settings read-only |

Pages that need explaining start with a **guide box**: a short how-to that administrators can hide (remembered per browser) with a link into *Help*.

### 3.3 Audit log

Security-relevant actions are written to the audit log with their actor (an administrator or an API token): console sessions, power actions, password resets, reinstalls, rDNS changes, drift actions, guest script changes, recipe changes (with a version diff), revealing app outputs, administrator creation and more.

---

## 4. Infrastructure: clusters, nodes, storages

### 4.1 Connecting Proxmox

An administrator adds a **cluster** with:

- one or more **API hosts** (several for a real cluster — the client fails over between them),
- the port,
- an **API token** (`USER@REALM!TOKENID` + secret). The secret is stored encrypted and never shown again, never logged and never sent to the browser,
- the Proxmox **resource pool** the token works in.

On save the panel **tests the connection** and **detects automatically** whether the host is a standalone node or a cluster.

**Least-privilege tokens.** Proxmotion only needs VM rights on its resource pool. The **permission check** (*Clusters → Check permissions*) lists both **missing** and **excess** privileges, so a root-equivalent token is flagged. The guide documents the exact roles (for example `PVEVMAdmin` + `PVEPoolAdmin` on the pool, `PVEAuditor` on `/`, `PVEDatastoreUser` on `/storage`, `PVESDNUser`), plus `VM.Console` and the guest agent privileges (`VM.GuestAgent.*` on PVE 9, `VM.Monitor` on PVE 8).

**No SSH to hypervisors.** The panel talks to Proxmox only through its HTTP API, and to the inside of VMs only through the QEMU guest agent via that API.

### 4.2 Synchronisation

`php artisan infra:sync` (scheduled every 5 minutes, or *Sync now* on a cluster) reads from Proxmox:

- nodes with their online state and capacity,
- storages (type, shared flag, content types, size),
- storage health: ZFS pool states, LVM thin pool metadata, the Ceph cluster Proxmox manages, and each node's physical disks with their SMART result and SSD wear ([ADR 0047](../adr/0047-storage-health.md)),
- network bridges,
- QEMU templates (offered when adding OS templates, with a name guess for distribution and version),
- running VMs (used for adoption and drift reports).

Columns owned by the sync cannot be changed through forms.

### 4.3 Node settings

Per node, administrators set:

- **Active / maintenance** — nodes in maintenance are skipped by automatic placement,
- **over-commit ratios** for CPU, memory and disk,
- **maximum number of servers**,
- **API address** — where servers moved in from other clusters land; empty uses the node's address from the sync ([ADR 0037](../adr/0037-moving-servers.md)).

### 4.4 Storage settings

- **Use for servers**: whether automatic placement may put disks on it.
- **Shared vs. local**: on local storage (LVM-thin, ZFS, dir) the template must exist on the target node; on shared storage (Ceph RBD, NFS, iSCSI) any node of the cluster can use it.

### 4.5 Storage health

Each sync judges every storage and disk as healthy, warning or critical ([ADR 0047](../adr/0047-storage-health.md)): space used and LVM thin pool metadata used against the thresholds on *Panel settings → General* (85 % and 95 % by default), the state of a ZFS pool (`DEGRADED` is a warning, `FAULTED` and the like critical), the health of the Ceph cluster Proxmox manages, a failed SMART check, SSD wear, and disks Proxmox stopped reporting. The cluster page shows the result per storage and per disk with what is wrong, plus Ceph's own checks; the cluster list and the dashboard point at clusters that need attention. Administrators get an email (event *A storage or disk is in worse health*) when something gets worse. A disk that disappeared stays listed until an administrator deletes it.

---

## 5. Locations, placement groups and placement

Each location may have a **hostname template** ([ADR 0031](../adr/0031-generated-hostnames.md)), e.g. `ip-{ip}.example.com`. A server ordered there without a hostname (admin form, API, or a WHMCS/WISECP order without a valid domain) is named from its primary address (`ip-203-0-113-5.example.com`; `{vmid}` and `{location}` work too), and that name becomes the PTR of its primary IPv4 and IPv6 addresses. Its Proxmox VM is named after that hostname, so cloud-init (or Cloudbase-Init on Windows) sets it at the first boot; the VM's notes and tags carry its primary addresses, so it can still be found by address in Proxmox. Without a template a hostname is required, and the billing modules fall back to `vps<service id>`.

### 5.1 Placement groups

A **placement group** ([ADR 0020](../adr/0020-placement-groups.md)) is a named set of nodes of one location, optionally with the storages disks may go to (otherwise the nodes' *Use for servers* storages). Typical uses: keep a plan on one CPU generation, or on NVMe nodes only.

- Packages may name one or more groups; only the groups of the ordered location count.
- A group that a package uses cannot be deleted.
- Groups live only in the panel; nothing is written to Proxmox.

### 5.2 Automatic placement

When a server is ordered through the API (or created from a package in the admin panel), the **placer** picks a node that:

- belongs to the requested location (and to one of the package's placement groups, when it names any),
- is online and **not in maintenance**,
- has the required network bridge and a usable storage,
- has enough free **vCPU, memory and disk after over-commit**, and a free **server slot**.

Among those, the node with the **least memory used after placement** wins. Every refusal names the check that failed (`InsufficientCapacity`, `NodeUnavailable`, …).

### 5.3 Atomic reservation

VMID, IP addresses and node capacity are reserved **inside a database transaction and a per-location lock**, so two orders arriving at the same moment never receive the same VMID or IP. VMIDs come from Proxmox's `cluster/nextid` and are additionally locked panel-side; *Panel settings* sets the lowest VMID the panel uses.

---

## 6. Networking: IP pools, network models, rDNS

### 6.1 IP pools

An **IP pool** has: version (IPv4 / IPv6), network model, location, CIDR, gateway, netmask, optional VLAN tag, bridge and an rDNS driver.

For IPv4 pools the panel **generates the addresses** of the range. Each address can be **reserved** (never allocated), given a **MAC address** (for routed/failover IPs with a provider-issued virtual MAC) and **notes**. Addresses can be edited in bulk, and *Keep only* trims a pool to a sub-range.

### 6.2 Allocation

- **IPv4**: from the first pool of the location and network model with room — or, in the admin create form, from a chosen pool, optionally with addresses ticked by hand (the first is the primary).
- **IPv6**: the next free address of an IPv6 pool. IPv6 is **optional per location**: if there is no active IPv6 pool for the network model, the server gets IPv4 only. An IPv6 pool that has run out fails the operation.
- A package says how many IPv4 and IPv6 addresses a server gets. **Additional addresses** are configured **inside the guest** by the guest agent ([ADR 0009](../adr/0009-additional-addresses-inside-the-guest.md)) and kept across reboots by a systemd unit.
- On termination addresses are released (their PTR records removed).

### 6.3 Network models

| Model | How it works | State |
|---|---|---|
| **Bridged public IP** | The VPS gets a public IPv4 directly on a bridge (optional VLAN), shared subnet gateway | Built |
| **Routed / failover IP** | Extra IPs routed to the host (OVH/Hetzner style): `/32` with an on-link gateway route, or a provider virtual MAC stored on the address | Built; needs real-world verification |
| **IPv6** | Per-VPS addresses from a pool; combinable with any IPv4 model | Built (address mode) |
| **NAT VPS** | Private IP behind a per-node NAT gateway VM with a port range per customer | **(planned, Phase 9)** |

### 6.4 Anti-spoofing

Every VPS gets the Proxmox firewall with **`ipfilter` and `macfilter`** enabled and an ipset of exactly its own addresses, so a customer can only use the IPs assigned to them. The ipset follows address changes.

([ADR 0033](../adr/0033-anti-spoofing-that-works.md)) What this stops and what keeps it working:

- **ARP spoofing**: Proxmox's ebtables rules drop ARP packets whose sender address is not in the ipset, so a server cannot answer ARP for a neighbour's or the gateway's address. **IP and MAC spoofing**: other source addresses and MAC addresses are dropped.
- **Rogue router advertisements** (`radv: 0`) and **rogue DHCP servers** (two outgoing DROP rules for DHCP and DHCPv6 server replies) are stopped too. All options are written explicitly.
- **The datacenter firewall must be on, with ebtables.** The sync reads both; while either is off, the cluster page and list warn and **no new servers are placed on that cluster** (a chosen node is refused). The panel does not turn it on itself.
- **Changes made in Proxmox are noticed**: each sync compares the VM firewall of 20 servers (checked longest ago first) and reports a *firewall* drift: firewall, IP or MAC filter off, router advertisements allowed, `net0` without the firewall flag, DHCP rules missing, or an ipset that differs from the panel. Servers with one are marked in the server list.
- **Enable anti-spoofing** on the server page (adopted servers and servers with a firewall drift) and on the drift turns it on again after a confirmation listing the addresses. Adopted VMs keep their own firewall policies; nothing runs inside the server. *Apply again* also restores `net0`'s firewall flag. Both read the firewall back when they finish, so the drift closes and the page shows *Anti-spoofing on* at once.
- **Not covered:** NDP target spoofing on a shared IPv6 `/64` (Proxmox does not check the target address of neighbour advertisements); give each server a routed `/64`.

#### 6.4.1 Isolation

([ADR 0034](../adr/0034-guest-isolation.md)) Anti-spoofing stops a server from pretending to be another; isolation stops it from **talking** to others:

- **Neighbours**: an IP pool's *Isolate servers from each other* (off by default, as at most providers; anti-spoofing already keeps servers from taking each other's addresses) keeps servers with its addresses from reaching each other at all, also through the gateway and also when they belong to one customer. The gateway stays reachable; servers in other pools are reached through the router as before. Turn it on only for a pool whose servers must not reach each other at all.
- **Protected networks**: servers cannot open connections to the Proxmox nodes of their location (their bridge addresses, learned by the sync), to the location's *Protected networks* (location form, one address or CIDR per line, e.g. a management network) or to the protected networks on *Panel settings* (every location, e.g. the panel's address). This also means no DNS or NTP from a node that is the servers' gateway.
- **How**: up to three IP sets (`pm-isolated`, `pm-gateways`, `pm-protected`) and five rules on top of each VM firewall, written with anti-spoofing; only the ones a server needs, so turning a pool's isolation off removes its neighbour rules and sets. Nothing is written to the datacenter firewall and the token needs no new privileges. Address changes keep the sets right.
- **Existing servers** change only when an administrator presses *Enable anti-spoofing*: the firewall drift lists missing or reordered isolation rules and IP sets that differ (also after a pool's isolation or a location's protected networks change).

#### 6.4.2 Server firewall

([ADR 0035](../adr/0035-server-firewall.md)) A firewall per server that customers (billing API and modules) and administrators (server page) manage, in the spirit of VirtFusion's:

- **Rules always apply**, top to bottom. What happens to traffic no rule matches is the server's **default policy**: *allow* (the default, everything else comes in as before; e.g. one `block 3389/tcp` rule closes RDP) or *block* (only what the rules allow comes in). What the server sends is never filtered, and answers to its own connections come back.
- **Rules**: inbound only, at most 50, in order: allow or block, TCP, UDP, ICMP or any, a port or range (`22`, `1000-2000`), a source address or CIDR (empty: anywhere), a comment. Every change is an `apply_firewall` task and is audited.
- **Default rule**: switched to *block* without a rule that allows anything, a server gets SSH (`22/tcp`) on Linux or RDP (`3389/tcp`) on Windows, an ordinary rule that can be narrowed or deleted. A reinstall onto the other platform swaps it while it is the only, untouched rule; otherwise the task says that no rule lets SSH or RDP in.
- **Rule sets** (*Admin → Firewall rule sets*): prepared rules with a default policy that customers apply in one go (replacing their rules and policy, or adding to their rules); a package can start its new servers with one. The page warns about rule sets that let neither SSH nor RDP in.
- **App ports**: the `firewall` ports of the server's apps (e.g. WireGuard's UDP port) are opened while it blocks by default, as soon as the installer starts, and closed once the app is uninstalled.
- **How**: the panel writes one block of rules on top of the VM firewall (isolation, DHCP, app ports, the server's rules), all with comments starting with `Proxmotion`, and rewrites it whenever it differs; rules added in Proxmox by hand stay below it. The firewall check reports changed rules and an open policy as a firewall drift.
- The console never goes through the network: a customer who locks themselves out can still get in.

### 6.5 Changing the primary address

([ADR 0011](../adr/0011-changing-the-primary-address.md)) The billing API (`POST /servers/{id}/primary-address`) can make another address the **primary**; administrators do the same with *Edit IP addresses* (6.5.1):

- **swap** with one of the server's additional addresses, or
- **replace** it with a free address from a pool (the old primary is released).

The change is applied inside the guest (new default route; the new primary becomes the first address so `hostname -I` reports it). The cloud-init `ipconfig0` is deliberately left unchanged, because changing it would give cloud-init a new instance id and regenerate SSH host keys.

### 6.5.1 Editing a server's addresses

([ADR 0028](../adr/0028-editing-server-addresses.md)) On the admin server page, *IP addresses* offers:

- **Edit IP addresses**: in one change, add the next free or a specific address of a pool on the server's bridge and VLAN, remove chosen addresses, and choose the primary of each IP version. The server keeps at least one address of each version it has; a removed primary needs a new one in the same change.
- **Remove** next to each additional address.
- **Apply again**: sets the firewall IP set to exactly the server's addresses and configures them inside the guest again, changing nothing in the panel (for a guest that lost an address).

The server's address counts follow the change; the package keeps its own. This is admin-only: billing systems change the number of addresses through *Change resources*.

### 6.6 Reverse DNS

([ADR 0003](../adr/0003-reverse-dns-drivers.md))

- PTRs can be set or removed for any **public** address of a server (admin page, API, WHMCS and WISECP client areas).
- Each IP pool chooses a **DNS provider**:
  - `none` — the panel records PTRs and offers a **CSV export** plus *Mark as applied* for providers who manage DNS elsewhere;
  - `powerdns` — PTRs are published to a PowerDNS reverse zone through its API (connection test included).
- Optional **forward confirmation**: the hostname must resolve to the address before the PTR is accepted.
- PTRs are removed when an address is released, and PTRs equal to the old hostname follow a hostname change.
- A hostname generated from the location's template is set as the PTR of the primary addresses at creation (no forward check: the template is the administrators' own domain).

---

## 7. OS templates and guest scripts

### 7.1 OS templates

An **OS template** maps a Proxmox template VM (per node, or per cluster on shared storage) to a named operating system offered to customers.

Requirements of every template: **cloud-init** and the **QEMU guest agent** installed, with `agent: 1`. Guides:

- [OS templates](../guides/os-templates.md) — from cloud images, with `deploy/proxmox/prepare-images.sh` (build images off-node, natively or in Docker) and `build-templates.sh` (create templates on the node with `qm`/`pvesh`).
- [ISO templates](../guides/iso-templates.md) — building a Linux template by hand from an ISO.
- [Windows templates](../guides/windows-templates.md).

Operating systems are the administrators' own list ([ADR 0026](../adr/0026-operating-systems-of-administrators.md)): in the *Operating systems* section of the page they add a system (a name such as "Ubuntu", and Linux or Windows) and its versions (26.04, 24.04, …). The list starts empty. Each template is of one of those versions, picked from a single list; its system, version and platform (which picks the guest scripts) come from it.

Apps are offered on the versions administrators tick for each app, on the editor's *Requirements* tab or with *Choose systems* on the *Apps* page; a tick is saved at once, no publishing needed. What a recipe says it runs on is only a suggestion there (*Tick the suggested ones*).

**Name guess.** When templates are found on Proxmox, the panel guesses the distribution and version from the template's name and fills in the form.

**Check against a server.** For a Linux template, the panel reads `/etc/os-release` of one of its active servers through the guest agent and fills the edit form with what it finds (never saving automatically).

**Inactive templates** are hidden from customers and the API but can be used in the admin create form to **try a template**: such a server has no billing system and is a **test server**.

### 7.2 Guest scripts

([ADR 0010](../adr/0010-guest-scripts-per-os-template.md), [ADR 0023](../adr/0023-guest-script-drafts-and-run-logs.md))

After certain events, the panel runs a script inside the server through the guest agent:

| Event | Default script does |
|---|---|
| `provisioned` | First-boot work after creation or reinstall |
| `addresses_changed` | Configures additional / changed addresses and the default route |
| `disk_resized` | Grows the partition and file system right after a disk upgrade |
| `password_reset` | Changes the root (Windows: `Administrator`) password; failing fails the reset |
| `hostname_changed` | Renames the host, keeps `/etc/hosts` across reboots (Windows: rename at next restart) |

Defaults ship for Linux (`resources/guest/linux`) and Windows (`resources/guest/windows`). Under *OS templates → Scripts* administrators can:

- write an **override** per template and event (audited),
- keep it as a **draft** and **try it** on a server (optionally with an `sh -x` trace),
- **publish** or discard the draft,
- read the **log of every run** (source default / published / draft, exit code, inputs, encrypted stdout/stderr; nothing for password scripts), kept for 90 days.

The page opens with a timeline of when each script runs during a create or reinstall and on later changes, and shows each script's inputs and time limit (5 minutes; 1 for the password script).

**Custom scripts** ([ADR 0043](../adr/0043-custom-guest-scripts.md)): besides the five fixed scripts, administrators add their own scripts to a template, as many as they need. Each has a name and its triggers: **run by hand** (then it is offered under *Run script* on the server page of the template's servers) and/or **after** any of the five events, where it runs after the fixed script (also when the event has none), in the order of the list. Drafts, trials and run logs work as above; a failing custom script only leaves a note on the operation, while a run by hand fails its task. Custom scripts get `server`, `hostname`, `mac`, `trigger` (the event, `manual` or `trial`), `addresses` and the event's inputs, never a password. Billing systems can neither see nor run them.

Only these scripts and recipe scripts ever run inside a VPS. Free-form commands from customers or the API are never executed.

### 7.3 Building templates from ISO in the panel

**(planned)** — [ADR 0006](../adr/0006-template-builder-from-iso.md) describes a *Build from ISO* pipeline; today templates are built with the scripts and guides above.

---

## 8. Packages

A **package** (plan) defines:

- vCPU cores, memory (MiB), disk (MiB),
- traffic quota (bytes) and port speed (Mbps → Proxmox `rate=`),
- optional **CPU and disk limits** ([ADR 0034](../adr/0034-guest-isolation.md)): a CPU limit in cores (`cpulimit`, e.g. 1.5), a CPU weight on a busy node (`cpuunits`), and the boot disk's read and write speed in MB/s and operations per second (`mbps_rd`/`mbps_wr`, `iops_rd`/`iops_wr`); empty is unlimited. Servers take them from their package on creation and package changes; custom servers and *Change resources* take them by hand. The billing API accepts no overrides for them,
- number of IPv4 and IPv6 addresses,
- backup quota,
- allowed **network models**,
- optional **placement groups**,
- optional **OS templates** ([ADR 0040](../adr/0040-package-os-templates.md)): the templates its servers may be created or reinstalled with; none means any. A server keeps its template when its package changes; the new package applies on the next create or reinstall,
- optional **reinstall limit** ([ADR 0045](../adr/0045-reinstall-limits.md)): how often the billing system may reinstall a server within the last N days (0: never; empty: unlimited). Unlike resources it is not copied: editing the package changes it for all its servers at once, unless a server has its own limit (*Reinstall limit* on the server page, which also resets the count). Only reinstalls through the billing API count, failed ones do not, and administrators are never limited. Over the limit the API answers `409` with `error: reinstall_limit_reached` and `available_at`; `GET /servers/{id}` returns `reinstalls`, and the WHMCS client area shows how many are left.

Units are consistent everywhere: disk and memory in **MiB**, traffic in **bytes**, port speed in **Mbps**.

The billing API can also send **resource overrides** on top of a package (e.g. WHMCS configurable options for extra RAM or IPs), or no package at all with full resources and a network model.

> Traffic quotas are counted and enforced since [ADR 0039](../adr/0039-usage-limits.md): see *Traffic quotas and usage limits* in section 9.

---

## 9. Servers: lifecycle and operations

### 9.1 Server states

```
pending → provisioning → active ⇄ suspended
active → reinstalling → active
*      → terminating → terminated
*      → error
```

A new, reinstalled or unsuspended server becomes `active` once its guest agent answers (or after a wait of 10 minutes; 3 for unsuspend).

### 9.2 Tasks

- One **Task** per operation; it records each pipeline step and its progress.
- Only **one task at a time per server**; a second request gets `409`.
- Pipelines are **idempotent**: a failed or interrupted task can be **retried** and continues where it stopped.
- A failed creation can also be **rebuilt from scratch** (ADR 0032 §7): the VM it left behind is deleted (only one Proxmotion created for that server) and the server is created again with the same VMID, IP addresses and MAC address. The hostname must be typed to confirm; audited as `server.rebuilt`.
- A stuck task (queued, or running without progress for longer than a queue job may run, or past its deadline) can be ended with **Mark as failed**.
- Long Proxmox operations return a UPID; the task waiter follows it until it finishes and handles failures and timeouts explicitly.
- **Creating and reinstalling run in stages** (ADR 0032): while they wait for a clone, the guest agent, Windows setup or cloud-init, the queue worker is free and the task picks up again a few seconds later. Such a task must finish within 120 minutes by default (*Panel settings*).
- The server page lists **every step a task will go through**, including those that depend on the server (hostname script, Windows restart, additional addresses, the template's script, an ordered app), shows **how long each finished step took**, and for a running create or reinstall **roughly how long is left**, from earlier runs with the same OS template (after three of them).
- After every create and reinstall the server is **checked from inside** (ADR 0032 §6): its IP addresses are on its network interface, its gateways answer, DNS resolves (the name on *Panel settings*), its hostname is right and its root file system has the disk's size. The result is a checklist on the server page with **Check again**; a failed check only warns.

### 9.3 Creating a server

From the **billing API** (`POST /servers`) or the **admin create wizard** (*Servers → Create*), in six steps:

1. **Type & location** — *Package* or *Custom* resources.
2. **Resources & placement**
   - *Package*: exactly the package's resources, placed automatically like a billing order; the step shows where it would go and on how many nodes it fits.
   - *Custom*: resources typed by hand; choose a **placement group** (best node inside it) or a **single node** (even one in maintenance) and optionally its storage. A capacity note warns when the server does not fit.
3. **Operating system & app** — template (inactive ones listed last for testing) and optionally an app to install after the first boot.
4. **Network & IPs** — IPv4 pool and hand-picked addresses (optional).
5. **Access & billing** — hostname (optional when the location has a hostname template), root password, SSH keys, billing system and service ids.
6. **Review**.

The **create pipeline**: full clone (or a linked clone when the OS template asks for one and the storage allows it, ADR 0027) → VM config + cloud-init (`ciuser`, password, SSH keys, network, nameservers, guest agent) → grow disk → anti-spoofing firewall → start → wait for the guest agent (Linux: until cloud-init has finished; Windows: until setup has finished) → guest scripts → checks from inside (addresses, gateways, DNS, hostname, disk; only a warning when one fails) → optional app install.

**SSH keys** are validated: ed25519, ECDSA, RSA ≥ 2048 bits; DSA is refused.

### 9.4 Operations on a server

All of these are available on the admin server page; the ones marked ✓ are also in the billing API (and the WHMCS/WISECP client areas).

| Operation | API | Notes |
|---|---|---|
| **Power**: start, shutdown, stop, reboot | ✓ | Shutdown is never forced; live power state shown |
| **Suspend / unsuspend** (with reason) | ✓ | Suspending also closes open console sessions |
| **Terminate** | ✓ | Deletes the VM, releases addresses and PTRs, keeps the history |
| **Reinstall** | ✓ | New OS template (or the same), SSH keys, optional app; same VMID, IPs and MAC; the hostname must be typed to confirm in the admin UI |
| **Root password reset** | ✓ | To a password chosen in the billing system, or random; through the guest agent |
| **Fetch the password once** | ✓ | `GET /servers/{id}/password` returns it once, then `410` |
| **Change resources** (upgrade / downgrade) | ✓ | Disk only grows (and is grown inside the guest at once); CPU and memory of a running VM apply at the next restart unless `reboot` is sent; the newest extra addresses are released on a downgrade; the server stays on its node |
| **Change hostname** | ✓ | Renamed inside the guest; PTRs that matched the old name follow; the Proxmox VM name stays ([ADR 0012](../adr/0012-changing-the-hostname.md)) |
| **Change primary address** | ✓ | Swap or replace (see 6.5); in the admin panel part of *Edit IP addresses* |
| **Edit IP addresses**, **Apply again** | — | Add, remove, choose the primary; apply the addresses again (see 6.5.1) |
| **Move** | — | To another node, cluster or location (see below) |
| **rDNS** | ✓ | Per public address |
| **Console** | ✓ (link) | See [Browser console](#10-browser-console) |
| **Apps** | ✓ | Install, cancel, retry, uninstall, upgrade, actions, entitlements |
| **Retry** a failed task | — | Continues the idempotent pipeline |
| **Mark task as failed** | — | For stuck tasks |
| **Test server flag** | — | Lets app drafts be tried on it; never for a server of a billing system |
| **Reset after trial** | — | Reinstalls a test server from its template |

Destructive operations (terminate, reinstall, node/cluster removal) ask for extra confirmation and are audited.

**Moving a server** ([ADR 0037](../adr/0037-moving-servers.md)): *Move* on the server page (active or suspended servers, no other task or running app script) sends it to a chosen node, or to the least used node of a placement group. Three kinds:

| To | How | Addresses | VMID |
|---|---|---|---|
| Another node of its cluster | Proxmox `migrate`, live while it runs; local disks are copied, a disk on shared storage stays | kept | kept |
| Another cluster of its location | Proxmox `remote_migrate` (experimental in Proxmox), live while it runs; the VM firewall is written again on the target | kept | kept when free there |
| A cluster of another location | shut down, copied, started there | new ones from that location; the old primary's PTR moves to the new one | kept when free there |

Moves to other clusters need *Allow moves to other clusters and locations* on *Panel settings*, an *incoming move token* on the target cluster (*Servers from other clusters* on the cluster page: a separate token with `Sys.Incoming`, used for nothing else) and the hostname typed to confirm. Proxmox deletes the old VM once its copy succeeded. Servers with addresses of a routed pool need a tick that the provider routes them to the new node. If a step after the copy fails, *Finish the move* runs the rest again. The server keeps its history of moves; billing systems get `server.moved`. Tested with a faked Proxmox only.

**Live usage** ([ADR 0036](../adr/0036-live-server-stats.md)): the *Overview* tab of an active or suspended server starts with cards for CPU, memory, network (in and out, against the port speed) and disk I/O, each with the last hour as a small line chart, plus the uptime. With Reverb running, `servers:stats` pushes new figures every 10 seconds to every open page of that server; without it the page asks every 10 seconds. Only servers whose page is open are asked for. Under them, a *Disk space* card shows how full a running server's file systems are (the root one first, with a bar per file system), read from its guest agent at most once a minute, since Proxmox reports no disk usage for VMs. Admin panel only; not tested against a real Proxmox or Reverb yet.

**Traffic quotas and usage limits** ([ADR 0039](../adr/0039-usage-limits.md)): `usage:collect` reads `/cluster/resources` of every cluster every 5 minutes and adds each server's traffic since the previous run to its month (from the *traffic reset day* of *Panel settings* on; counted in + out, out or in). A server over its quota gets a violation and, when *When a server is over its quota* is *Suspend the server*, is suspended with the reason; the panel unsuspends it again when the next month starts or its quota is raised, and an administrator or billing system that unsuspends it earlier lets it run for the rest of the month. Optional **usage limits** (CPU and memory in per cent, disk I/O in MB/s, network in Mbps) apply to the average over a time window (15–1440 minutes): over one, the server is only recorded, stopped (the customer may start it again) or suspended (until someone unsuspends it). The server page shows the month's traffic against the quota, the latest violations and why the panel suspended it, with *Exempt from usage limits* (recorded, never acted on); the dashboard lists servers over their limits. Billing systems get `server.usage_exceeded`, the usual `server.suspended` / `server.unsuspended`, and the month's traffic and `suspension_cause` on every server; a billing system that suspends a server the panel suspended takes the suspension over. Everything is off until set on *Panel settings*. Tested with a faked Proxmox only.

### 9.5 Windows guests

Windows templates are supported by the data model and default guest scripts exist (`addresses.ps1`, `disk.ps1`, `hostname.ps1`, password for `Administrator`), with a [Windows template guide](../guides/windows-templates.md). They have **not run on a real Windows guest yet**.

---

## 10. Browser console

([ADR 0002](../adr/0002-console-websocket-proxy.md))

- A **noVNC** console in the browser for KVM servers.
- The customer's browser **never connects to Proxmox**. A small Node.js relay (`deploy/console-proxy`) sits between them.
- The panel asks Proxmox for a VNC ticket and stores it under a **single-use, 15-second ticket**. The browser receives only that ticket and a one-time VNC password — never the Proxmox ticket or token.
- **One session per server**; sessions end after **60 minutes**, or **15 minutes idle**, or as soon as the server stops being active (heartbeat every 15 s).
- **Customers** get the console through `POST /servers/{id}/console` (scope `servers:secrets`): a **signed, single-use link valid for at most 60 seconds** that the billing module opens in the customer's browser. This is the panel's only customer-facing page; there is no SSO into the panel.

LXC terminal consoles (xterm.js) are **(planned)** with LXC support.

---

## 11. App Marketplace

The App Marketplace is the equivalent of OVH's "install with an application": one-click installation of software inside a VPS, **at order time** or **later** from the billing system's client area.

### 11.1 Bundled apps

| App | Category | Notes |
|---|---|---|
| **OpenVPN** | VPN | Actions: add client (returns a `.ovpn` profile), revoke client, list clients; uninstall; upgrade |
| **WireGuard** | VPN | Actions: add client (returns a `.conf`), revoke client, list clients; uninstall; upgrade |
| **Docker** | Container | Choice of version; uninstall; upgrade |
| **Docker + Portainer** | Container | Portainer CE with the first administrator; action: reset password |
| **Coolify** | Container | Official installer, first user created; no uninstall |
| **cPanel & WHM** | Control panel | `license: external` (licence sold separately); official installer; no uninstall |
| **Plesk** | Control panel | `license: external`; action: reset password; no uninstall |
| **aaPanel** | Control panel | Official installer, login read from its output; no uninstall |
| **HestiaCP** | Control panel | Choice of Apache, mail, language; action: reset password; no uninstall |
| **CyberPanel** | Control panel | OpenLiteSpeed edition; action: reset password; no uninstall |
| **Pterodactyl Panel** | Control panel | Game server panel 2.0 (open beta), optional Let's Encrypt; action: reset password; uninstall. Wings nodes are installed separately |
| **FASTPANEL** | Control panel | Choice of database server |
| **LAMP** | Web | Apache, PHP, one database; optional Let's Encrypt |
| **LEMP** | Web | nginx, PHP, one database; optional Let's Encrypt |
| **WordPress** | Web | nginx site, WP-CLI, optional Let's Encrypt; action: reset password |
| **Node.js** | Web | Node.js 22 or 24 from NodeSource, optionally PM2 |
| **WHMCS** | Billing / web | `license: external`; the customer supplies their download address and licence key |
| **IIS** | Web (Windows) | IIS with ASP.NET 4.x and IIS Manager, optionally the ASP.NET Core Hosting Bundle 8.0 or 10.0; uninstall |
| **SQL Server Express** | Web (Windows) | SQL Server 2022 Express, `sa` password, fixed TCP port; action: reset password; uninstall |
| **Chocolatey** | Container (Windows) | Package manager; actions: install, remove and list packages; uninstall |

The last three are Windows apps (Windows Server 2016 and later, Windows 10/11): PowerShell scripts run by the Windows runner ([ADR 0029](../adr/0029-windows-app-runner.md)); they are offered on Windows templates only, and Linux apps on Linux templates only.

None of the bundled recipes has run on a real guest yet; see [Verification status](#17-verification-status).

### 11.2 How installing works

([ADR 0015](../adr/0015-app-marketplace.md), [ADR 0004](../adr/0004-post-boot-delivery-via-guest-agent.md))

1. **Eligibility.** The panel checks that the server's operating system version is ticked for the app, its resources (memory, disk, cores) and network model. Apps that do not fit are **not offered** to customers and requests for them are rejected with `422`. The admin server page lists them greyed out with the reason.
2. **Inputs** declared by the recipe (port, enum, slug, email, domain, password, HTTPS URL, …) are **validated by type** and written into the VPS as `inputs.json`. They are **never interpolated into a script**.
3. A short `install_app` task uses the **guest agent** to write the recipe into the VPS and start the runner (`run.sh`) detached with `systemd-run`. On Windows the recipe goes to `C:\ProgramData\Proxmotion\apps` (readable by SYSTEM and Administrators only) and `run.ps1` runs as a scheduled task as SYSTEM; a script that needs a restart asks for one, and the runner restarts Windows and runs the script again (ADR 0029).
4. The script reports progress to `status.json`; every minute `apps:poll` reads it through the guest agent. **There is no phone-home**: the VPS needs no network path to the panel and no panel token is ever placed on it.
5. **Outputs** (panel URL, usernames, passwords, `.ovpn`/`.conf` files) are read from `outputs.json` / `outputs/` and **stored encrypted**. Billing systems fetch them with the `apps:secrets` scope; administrators see them with *Show details* (audited).
6. The **firewall** ports the recipe declares are opened.

**Install paths**

| Path | How |
|---|---|
| **At order time** | `POST /servers` with `app` + `app_inputs` (WHMCS configurable option, WISECP order answer, admin create form). The last step of the create task starts the install once the guest agent answers; a failed start fails only the installation, not the server. |
| **Later** | *Install app* in the billing client area (API) or on the admin server page. One installation at a time per server. |
| **With a reinstall** | Choose an app while reinstalling. |

Installation states: `queued → installing → installed`; `failed` is retryable; `uninstalling`, `upgrading`, `uninstall_failed`, `upgrade_failed`; `removed` when uninstalled or the server is reinstalled or terminated.

**Cancel and timeouts** ([ADR 0030](../adr/0030-stopping-and-cancelling-app-scripts.md)): administrators (server page) and billing systems (API, WHMCS client area, WISECP Apps tool) can cancel an installing app or a running uninstall, upgrade or action. It fails at once (*Cancelled by …*, the usual `app.*` failure webhook) and the script is stopped inside the server (`systemctl kill` of its unit on Linux, its scheduled task and processes on Windows). A script past its timeout is stopped the same way, and a retry, uninstall, upgrade or action first stops the app's earlier script. Nothing the script did is undone: retry, uninstall or reinstall afterwards.

### 11.3 Uninstall, upgrade, actions

([ADR 0016](../adr/0016-recipe-format-v2.md))

- **Uninstall** runs the recipe's uninstall script (where the recipe has one).
- **Upgrade** moves an installation to the newest published version whose `upgrade.from` range contains its version, asking for any inputs the new version adds. A failed upgrade keeps the old version (`upgrade_failed`).
- **Actions** are labelled scripts with their own inputs and outputs — e.g. add / revoke / list VPN clients, reset a control panel password. They leave the installation `installed`.

Uninstalls, upgrades and actions run as **runs** with their own status; the API returns the latest ten.

### 11.4 Logs

([ADR 0021](../adr/0021-app-script-logs.md)) Everything an installer, uninstall, upgrade or action prints goes to `run.log` inside the VPS. The panel copies new lines every minute (and live, every 2 seconds, while an administrator watches with *Watch* / *Log*), **masks secret inputs and outputs**, and keeps one **encrypted log per attempt and run** (at most 1 MiB, pruned after 90 days). Billing systems never get logs.

### 11.5 Paid apps and licences

([ADR 0019](../adr/0019-paid-apps-and-entitlements.md))

- `billing: free` — always installable.
- `billing: paid` — needs an **entitlement** for that server. Ordering a server with the app grants one automatically; otherwise the billing system grants it (`POST /servers/{id}/app-entitlements`; WHMCS product addons, WISECP add-on values) or an administrator does on the server page. Revoking never removes an installed app. The catalog lists paid apps with `entitled`, and the modules show **Buy** when it is false.
- `license: external` (cPanel, Plesk, WHMCS) — the panel only installs; the licence is sold separately. The modules show a "Licence required" notice.

### 11.6 Recipe format

Each app is a directory `<slug>/` in the recipes repository [`proxmotion-recipes`](https://github.com/proxmotion-devs/proxmotion-recipes) with

- identity, category, localized description, maintainers, homepage,
- `requires`: platform, OS families and distributions, `min_versions` / `max_versions` per distribution,
- `resources` (minimum memory, disk, cores), `network_models`, `install_modes` (`order`, `post`),
- `billing` and `license`,
- typed `inputs` and `outputs` (outputs can be `secret`),
- `firewall` ports,
- `lifecycle` (install / uninstall / upgrade scripts with timeouts, `upgrade.from` range),
- `actions` with their own inputs and outputs.

Scripts start with `set -euo pipefail`, are idempotent, use the `_lib/proxmotion.sh` helpers for inputs, status and outputs, and pass shellcheck. `php artisan apps:make <slug> --path=<sources>` creates a skeleton; `apps:pack --path=<sources> --out=<repo>` checks recipes against the JSON Schema and packs each into `<repo>/<slug>/<slug>.proxapp`, and `apps:validate --path=<repo>` checks the packages of a recipes repository, which holds nothing else.

**Versions are immutable.** Every version is stored and installations keep theirs; a changed bundled recipe needs a higher `version` (`apps:check-versions` enforces this in CI).

### 11.7 Recipe editor

([ADR 0018](../adr/0018-admin-recipe-editor.md)) Administrators can:

- create **custom apps** (created inactive), **copy** a bundled app, or **edit a bundled app in place** (versions numbered `<bundled>-local.N`; *Back to bundled version* undoes it; later bundled releases are stored without being published, and the editor offers to compare),
- edit a **draft** in five tabs — *Details*, *Requirements* (OS versions ticked from the panel's own templates, memory and disk in GB), *Customer form*, *Scripts & actions* (Monaco editor), *Try & history* — or as raw YAML,
- **try a draft** on a test server: a frozen copy (`<version>-trial.N`) is installed; trials are hidden from billing systems and webhooks,
- **publish**, **discard**, view a **diff** between versions,
- **export** a version as a zip, and **import** a zip as a new custom app or into a draft (read in memory with strict limits).

Every change is audited with a version diff. A custom recipe can never override a bundled recipe's slug.

**(planned)** Remote recipe catalogs with signing ([ADR 0017](../adr/0017-recipe-catalog-and-signing.md)).

---

## 12. Billing API v1

([ADR 0008](../adr/0008-billing-api-tokens-and-idempotency.md)) The full contract is in [`docs/api/openapi.yaml`](../api/openapi.yaml).

### 12.1 Authentication and isolation

- Base path `/api/v1`, `Authorization: Bearer <token>`.
- Tokens belong to a **billing system** and only see **its** servers; every other server is `404`. Several billing systems can share one panel.
- Tokens are **scoped**: `catalog:read`, `servers:read`, `servers:write`, `servers:secrets`, `apps:secrets`.
- Tokens are stored **hashed** (SHA-256 of a 48-character random secret, shown once), can have **IP/CIDR restrictions** and an **expiry**, and can be revoked.
- **Rate limits** per token, and per address for failed authentications.

### 12.2 Conventions

- Every write endpoint accepts an **`Idempotency-Key`** header; replaying the same key never creates a second server.
- Operations that change Proxmox answer **`202`** with the task. State conflicts and running tasks are **`409`**, unreachable Proxmox **`503`**, ineligible apps **`422`**.
- Servers are found by UUID or by `external_id` (unique per billing system).
- v1 never gets breaking changes; additions only.

### 12.3 Endpoints

| Area | Endpoints |
|---|---|
| **Catalog** | `GET /packages`, `/locations`, `/os-templates` (`?package=` or `?server=` for the templates a package or server may use, ADR 0040), `GET /apps?os_template=&package=&server=&mode=` |
| **Servers** | `POST /servers`, `GET /servers?external_id=&external_client_id=&status=`, `GET /servers/{id}`, `DELETE /servers/{id}` |
| **Lifecycle** | `POST /servers/{id}/suspend`, `/unsuspend`, `/power`, `/reinstall`, `/package` |
| **Identity & network** | `POST /servers/{id}/hostname`, `/primary-address`, `/rdns` |
| **Secrets** | `POST` + `GET /servers/{id}/password`, `POST /servers/{id}/console` |
| **Apps** | `POST`/`GET /servers/{id}/apps`, `GET /servers/{id}/apps/{installation}`, `POST …/retry`, `POST …/cancel`, `DELETE …` (uninstall), `POST …/upgrade`, `POST …/actions/{key}`, `GET …/runs`, `GET …/runs/{run}`, `POST …/runs/{run}/cancel` |
| **Entitlements** | `POST`/`GET /servers/{id}/app-entitlements`, `DELETE /servers/{id}/app-entitlements/{app}` |
| **Tasks** | `GET /tasks/{id}` |
| **Usage** | The current month's traffic and `suspension_cause` on every server ([ADR 0039](../adr/0039-usage-limits.md)); `GET /servers/{id}/usage` with history **(planned, Phase 6)** |

Tasks and audit log entries record the API token that started them.

---

## 13. Webhooks

([ADR 0013](../adr/0013-webhooks.md)) Configured per billing system under *Admin → Billing systems*.

**Events**

- Servers: `server.provisioned`, `server.suspended`, `server.unsuspended`, `server.reinstalled`, `server.terminated`, `server.failed`
- Tasks: `task.succeeded`, `task.failed`
- Apps: `app.installed`, `app.failed`, `app.uninstalled`, `app.uninstall_failed`, `app.upgraded`, `app.upgrade_failed`, `app.action_succeeded`, `app.action_failed`

Events are sent for operations started by administrators too.

**Delivery**

- Signed with `Proxmotion-Signature: t=<unix>,v1=<HMAC-SHA256 of "t.body">` and a per-endpoint secret (`whsec_…`, rotatable).
- Only `2xx` counts as delivered; **8 attempts over about 22 hours**.
- **At least once and unordered**: receivers drop repeats by event id.
- Payloads **never carry secrets** (no passwords, no app outputs).
- The admin page has a **delivery log**, a **test event** and **send again**; deliveries are pruned after 30 days.

---

## 14. Billing modules: WHMCS and WISECP

Both modules are thin clients of the API: business logic stays in the panel, and secrets (app outputs, console links) are fetched on demand and **never stored** in the billing database. Module logs mask tokens, passwords and app inputs/outputs.

### 14.1 WHMCS server module

`integrations/whmcs/modules/servers/proxmotion/` — for **WHMCS 8 and 9**, written in **PHP 7.2** syntax, no Composer dependencies. Setup and option names: the module's `README.md`.

- **Provisioning**: Create, Suspend, Unsuspend, Terminate, ChangePackage (with optional restart after upgrades), ChangePassword, TestConnection. The WHMCS password becomes the root password when it has 12+ characters; otherwise Proxmotion's is stored on the service.
- **Configurable options**: OS, location, extra IPs, extra RAM and other resources, **app** (`app` option plus `app_<key>` custom fields for inputs; the option can be generated from the panel's app list).
- **Client area**: power, console link, reinstall (optionally with an app), password reset, hostname, rDNS, **apps** (install with an input form, status and progress, cancel, retry, outputs and file downloads, uninstall, upgrade, actions, recent runs, *Buy* for paid apps).
- **Paid apps**: module setting *Paid app addons* maps WHMCS addons to apps; `hooks.php` grants on `AddonActivation` and revokes on `AddonCancelled` / `AddonTerminated`.
- **Admin**: services tab fields and custom buttons.
- **Traffic** ([ADR 0039](../adr/0039-usage-limits.md)): `UsageUpdate` writes each service's traffic of the month and its quota into WHMCS's usage statistics (`bwusage`, `bwlimit`) with the daily cron; the client area shows the traffic against the quota and why the panel suspended a server over its quota or a usage limit.
- **Webhook receiver** (`webhook.php`): verifies signature and timestamp, drops repeats, sets a pending service *Active* on `server.provisioned`, keeps *Active* ⇄ *Suspended* in step, notifies administrators on terminations and failures. The signing secret goes into the WHMCS server's **Access Hash**.
- Writes carry an `Idempotency-Key` and are retried once after a lost connection.
- **Not done**: graphs (Phase 6). **Not run in a real WHMCS yet.**

### 14.2 WISECP server module

`integrations/wisecp/coremio/modules/Servers/Proxmotion/` — for **WISECP 5, PHP 8.2** ([ADR 0014](../adr/0014-wisecp-module.md)). Setup: [`integrations/wisecp/README.md`](../../integrations/wisecp/README.md).

- **Server form**: panel address, API token (in the password field), webhook secret.
- **Product Module tab**: package, location, OS template, network model, restart after upgrades, resource values. Order answers set OS, location, hostname, SSH key, root password, app and app inputs; add-on values `extra_*` add resources and `paid_app` grants paid apps.
- **Functions**: create, suspend, unsuspend, cancel (terminate), upgrade, change password.
- **Client area tools**: power, root password reset, reinstall, VNC console, hostname, reverse DNS, and an added **Apps** tool (install, cancel, retry, outputs, uninstall, upgrade, actions, *Reinstall with an app*).
- **Traffic**: the service page's bandwidth gauge and resource row show the month's traffic against the quota ([ADR 0039](../adr/0039-usage-limits.md)).
- **Webhook receiver** through a public route of WISECP's API.
- Module listing, server form, Test Connection and the product Module tab were checked in a real WISECP 5 beta; everything else is **not run in a real WISECP yet**.

### 14.3 Later

Blesta, HostBill, Paymenter and FOSSBilling modules **(planned, Phase 9)**.

---

## 15. Migration: adopting VMs and drift reports

### 15.1 Adopting existing Proxmox VMs

*Servers → Adopt* lists VMs in the panel's resource pool that the panel does not manage. An administrator assigns a package, IP addresses (matched to pools) and optionally a billing system and service id. Adoption **only records** the VM (`origin: adopted`): it does not reconfigure, restart or resize it.

### 15.2 Drift reports

([ADR 0005](../adr/0005-drift-reporting.md)) The panel database is the source of truth. After each sync the panel compares it with Proxmox and lists differences under *Admin → Drifts*: a VM deleted, resized, stopped (after a grace period set on *Panel settings*), moved, or an unknown VM in the pool. Administrators can **accept** the Proxmox state, **mark the server as error**, **apply the expected power state**, or **dismiss**. Drift is **never repaired automatically** and nothing is deleted. A drift closes by itself once fixed.

### 15.3 Virtualizor import

**(planned, Phase 8)** — import users, VPS, IPs and plans from the Virtualizor Admin API with a dry-run report and idempotent re-runs, plus a guide to switch WHMCS services to the Proxmotion module while keeping service ids. Imports never delete anything.

---

## 16. Security

The panel manages customer infrastructure and is internet-facing. Key properties:

- **Tenant isolation** through scoped tokens per billing system; public ids are UUIDs or the billing `external_id`. Every API endpoint has wrong-scope, wrong-token and other-billing-system tests.
- **Proxmox token secrets**, root passwords, app outputs, app logs and guest script output are **encrypted at rest** and never appear in API responses, page props or logs. Root passwords are shown once.
- **Console**: browsers never reach Proxmox; single-use tickets, signed single-use customer links (≤ 60 s).
- **Anti-spoofing** (`ipfilter`, `macfilter`) on every VPS, and **isolation**: servers of one pool cannot reach each other, and no server can open connections to the Proxmox nodes or other protected networks.
- **Strict input validation** for hostnames, rDNS names, SSH keys, cloud-init data and app inputs; nothing raw goes into Proxmox parameters or a shell.
- **Only reviewed scripts run in guests**: recipe scripts, default guest scripts, and audited overrides and custom scripts of OS templates. Never free-form commands.
- **Mandatory 2FA** for administrators; audit log for every sensitive action; extra confirmation for destructive operations.
- **No SSH to hypervisors**; least-privilege, pool-limited Proxmox tokens with an excess-privilege warning.
- Vulnerabilities are reported per [`SECURITY.md`](../../SECURITY.md), never in public issues.

---

## 17. Verification status

All features are covered by automated tests with faked HTTP (an in-memory Proxmox cluster with a fake guest agent, faked WHMCS and WISECP hosts). A real-Proxmox integration suite exists and runs against a test pool only. The authoritative checklist is in [AGENTS.md §10](../../AGENTS.md#not-yet-verified-against-real-systems) and the procedure in [Real Proxmox verification](../guides/real-proxmox-verification.md).

**Checked on a real Proxmox VE 9.2** (October 2026): connection, cluster sync, permission check for a pool-limited token, task tracking, provisioning on the test storage, suspend/unsuspend/terminate, adoption, power actions, console ticket, guest agent basics, reinstall, drift detection, resource changes, additional addresses, disk growth, primary address swap, hostname change (Debian 13 test template).

**Not yet checked on real systems** (among others): Proxmox VE 8, shared storage and cross-node clones, routed `/32` and IPv6 inside the guest, the console relay end to end, PowerDNS, every bundled app recipe, Windows guests, the WHMCS module, most of the WISECP module, real webhook receivers, MariaDB/MySQL-specific migrations, the Docker Compose stack.

---

## 18. Operations: commands, scheduler, settings, deployment

### 18.1 Artisan commands

| Command | Purpose |
|---|---|
| `php artisan infra:sync` | Sync clusters (scheduled every 5 minutes) |
| `php artisan apps:poll` | Follow running app installs, uninstalls, upgrades and actions; copy logs (every minute) |
| *Apps → Install package* | Install an app from its `.proxapp` package (`proxmotion-recipes/packages`); an update shows its changes first (ADR 0042) |
| `php artisan apps:validate --path=<repo>` | Validate the `<slug>/<slug>.proxapp` packages of a recipes repository |
| `php artisan apps:check-versions --base-path=<repo> --path=<repo>` | Require a higher version for changed packages (CI of `proxmotion-recipes`) |
| `php artisan apps:pack --path=<sources> --out=<repo>` | Validate recipe sources and pack them into a recipes repository (ADR 0042) |
| `php artisan apps:make <slug>` | Create a format 2 recipe skeleton |
| `php artisan servers:stats` | Send live figures of the servers whose page is open (every 10 seconds; only with `BROADCAST_CONNECTION=reverb`) |
| `php artisan reverb:start` | WebSocket server for live figures |

The **scheduler** and a **queue worker** must run; `composer dev` starts both locally.

### 18.2 Settings

**Panel settings** (*Admin → Panel settings*, [ADR 0038](../adr/0038-panel-settings.md)) take effect at once, also for queue workers, and changes are written to the audit log. Until the page is saved, each value comes from its `.env` variable, which is then only a default:

| Setting | `.env` default | Meaning |
|---|---|---|
| Allow moves to other clusters and locations | `PROXMOTION_CROSS_CLUSTER_MOVES` | Through Proxmox's experimental remote migration (default off) |
| Time limit / bandwidth limit of a move | `PROXMOTION_MOVE_DEADLINE_MINUTES`, `PROXMOTION_MOVE_BWLIMIT_KIB` | How long a move may take (default 720 minutes) and its bandwidth limit in KiB/s (empty: Proxmox's own) |
| Lowest VMID of new servers | `PROXMOTION_MIN_VMID` | 0: Proxmox's next free ID |
| Time limit of a creation or reinstall | `PROXMOTION_PROVISIONING_DEADLINE_MINUTES` | Default 120 minutes |
| DNS name checked from inside | `PROXMOTION_VERIFY_DNS_NAME` | Default `example.com`; empty: no DNS check |
| Protected networks | `PROXMOTION_PROTECTED_NETWORKS` | Networks servers of every location cannot reach |
| Report stopped servers after | `PROXMOTION_DRIFT_STOPPED_GRACE_MINUTES` | How long a VM may be unexpectedly stopped before it is reported as drift (default 60) |
| Console session limits | `CONSOLE_MAX_MINUTES`, `CONSOLE_IDLE_MINUTES` | Default 60 and 15 minutes |
| Documentation address | `PROXMOTION_DOCS_URL` | Base URL of the published guides; *Help* links to them when set |
| Generated root passwords | `PROXMOTION_PASSWORD_LENGTH`, `PROXMOTION_PASSWORD_CHARACTERS`, `PROXMOTION_PASSWORD_PREFIX`, `PROXMOTION_PASSWORD_SUFFIX` | Format of the root passwords the panel makes up for new servers, reinstalls and resets: a random part of 20 mixed characters by default, with an optional prefix and suffix; at least three kinds of characters, as Windows requires |

**Only in `.env`** (they protect the panel or depend on the installation):

| Variable | Meaning |
|---|---|
| `PROXMOTION_REQUIRE_ADMIN_2FA` | Enforce TOTP for administrators (default `true`; turn off for local development only) |
| `CONSOLE_PROXY_SECRET`, `CONSOLE_WEBSOCKET_URL` | Shared secret and public address of the console proxy |
| `BROADCAST_CONNECTION`, `REVERB_*`, `VITE_REVERB_*` | `reverb` and its settings push live server figures; `log` makes the server page poll instead |

See `panel/.env.example` for the full list (database, Redis, mail, …).

### 18.3 Deployment

- **Docker Compose** (`deploy/docker/compose.yml`): app (php-fpm + nginx), console-proxy, queue/Horizon, scheduler, Reverb (nginx forwards `/app`), MariaDB, Redis. Not yet run end to end.
- **Local development**: SQLite works out of the box (WAL mode and immediate transactions so web, worker and scheduler can write at once). See the [README](../../README.md#development).
- **Native Debian/Ubuntu installer**, production images on GHCR, `proxmotion:install` / `proxmotion:upgrade` commands and admin branding settings **(planned, Phase 8)**.

---

## 19. Roadmap

| Phase | Scope | State |
|---|---|---|
| 0 | Skeleton: auth, 2FA, i18n, CI, tooling | Done |
| 1 | Infrastructure management | Done |
| 2 | Provisioning engine, placement, adoption | Done |
| 3 | Server operations, console, reinstall, rDNS, drift | Done |
| 4 | Billing API, WHMCS and WISECP modules, webhooks | Done |
| 5 | App Marketplace: install, order-time apps, paid apps, uninstall/upgrade/actions, logs, editor, trials, import/export, 16 recipes | Mostly done; catalogs and signing (ADR 0017) open |
| 6 | Usage and quotas: traffic collection, graphs, throttle/suspend, `UsageUpdate` | Resource changes, guest scripts, primary address and hostname changes done early; traffic collection, quotas and usage limits with suspend/stop, WHMCS `UsageUpdate` and traffic in the billing modules done (ADR 0039); graphs and throttling not started |
| 7 | Snapshots and backups, Proxmox Backup Server | Planned |
| 8 | Virtualizor import, native installer, production images, branding | Planned |
| 9 | NAT VPS, LXC, ISO mount, rescue mode, customer firewall, moving servers (pulled forward, see 9.4), reseller system, more billing modules | Planned |

---

## 20. Further reading

- [AGENTS.md](../../AGENTS.md) — architecture, rules and the detailed roadmap
- [Architecture Decision Records](../adr/) — the reasoning behind each feature
- [OpenAPI reference](../api/openapi.yaml)
- Guides: [OS templates](../guides/os-templates.md) · [ISO templates](../guides/iso-templates.md) · [Windows templates](../guides/windows-templates.md) · [Real Proxmox verification](../guides/real-proxmox-verification.md)
- [WHMCS module README](../../integrations/whmcs/modules/servers/proxmotion/README.md) · [WISECP module README](../../integrations/wisecp/README.md)
- [CONTRIBUTING.md](../../CONTRIBUTING.md) · [SECURITY.md](../../SECURITY.md)
