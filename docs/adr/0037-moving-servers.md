# 37. Moving servers between nodes and clusters

- **Status:** Accepted (built 2026-10-22; tested with a faked Proxmox only)
- **Date:** 2026-10-22

## Context

A server stays on the node it was created on. `AGENTS.md` plans "live migration within clusters" for Phase 9 and moves across clusters "backup/restore based"; neither exists. Today a VM moved by hand in Proxmox only shows up as a `node_mismatch` drift (ADR 0005), and a VM moved to another cluster looks like a deleted one. Providers need moves to empty a node for maintenance, to balance load and to retire old clusters. The maintainers asked whether a server can also move **between clusters**.

Proxmox offers two API calls. Their details were read from the API viewer data (`apidoc.js`) and `qemu-server` (`PVE/API2/Qemu.pm`, `PVE/QemuMigrate.pm`, `master`, 2026-10-22):

**`POST /nodes/{node}/qemu/{vmid}/migrate`** (within one cluster): `target` node, `online` (live, ignored for a stopped VM), `with-local-disks` (live storage migration of local disks), `targetstorage` (a storage map), `bwlimit`. Privilege: `VM.Migrate` on `/vms/{vmid}`. `GET` on the same path reports the preconditions: `running`, `allowed_nodes`, `not_allowed_nodes` (with `unavailable_storages`), `local_disks` and `local_resources` (PCI, USB, which block a move). The VMID stays the same. The VM's firewall file (`/etc/pve/firewall/<vmid>.fw`) is cluster-wide, so its firewall does not move at all.

**`POST /nodes/{node}/qemu/{vmid}/remote_migrate`** (to another cluster, **marked EXPERIMENTAL** by Proxmox):
- `target-endpoint` (`apitoken=PVEAPIToken=…,host=…[,fingerprint=…][,port=…]`). The VM lands on **the node that answers at `host`**: Proxmox reads `/cluster/status` there and takes the `local` node.
- `target-storage` and `target-bridge` are both required maps. An identity storage map (`1`) is refused.
- `target-vmid` (defaults to the source VMID), `online`, `bwlimit`.
- `delete` (default `0`: "the original VM is kept on the source cluster in a stopped state"). The source VM gets `lock: migrate` when the migration begins (`phase1`), and nothing clears that lock on the source after a successful remote migration (only the target is unlocked through the tunnel). Starting, unlocking or deleting a locked VM needs `skiplock`, which only `root@pam` may use.
- Every disk is copied (`with-local-disks` is forced, transported over a websocket tunnel to the target's API port).
- The VM config and the **VM firewall file are copied as they are** (`firewall-config` in the tunnel's `config` command).
- Source privileges: `VM.Audit`, `VM.Config.Disk` and `VM.Migrate` on `/vms/{vmid}`. With `delete`, also `VM.Allocate`. `VM.Config.Cloudinit` too when the VM has `cipassword` or `cicustom`, which every panel server has.
- HA-managed and replicated VMs are refused.
- Target privileges, checked by the `mtunnel` endpoint for the token in `target-endpoint`: `VM.Allocate` on `/vms/{vmid}` and **`Sys.Incoming` on `/`**, plus storage and bridge access ("further permission checks happen during the actual migration"). The target puts the new VM into no pool.

The panel's own token per cluster only works inside its resource pool. Its permission check warns about `VM.Allocate` on `/vms` (ADR 0008, `PermissionChecker`), and a VMID that does not exist yet is not in any pool. The panel's token therefore cannot receive a remote migration without becoming much broader.

## Decision

Moving a server is an **administrator action** ("Move server" on the server page; also used later to empty a node). Billing systems get no endpoint: customers do not choose nodes, and the API keeps describing the server as before. The panel never calls these "migrations" in its UI, because *Migration* already means adopting VMs and importing from Virtualizor (§3.8). The feature is **moving**, its records are `server_moves`, its task type is `move`.

It comes in three steps, built and verified in this order:

| Step | From → to | Proxmox call | Addresses | Live |
|---|---|---|---|---|
| A | node → node of the same cluster | `migrate` | kept | yes, when running |
| B | cluster → another cluster **of the same location** | `remote_migrate` | kept | yes, when running |
| C | cluster → a cluster **of another location** | `remote_migrate` | **new** | no: the server is stopped |

### 1. The move record and the task

- `server_moves` (`ServerMove`) holds one row per move:
  - `server_id`, the administrator (`user_id`) and `kind` (`node`, `cluster`, `location`);
  - the source `location_id`, `cluster_id`, `node_id`, `storage_id` and `vmid`, and the same for the target;
  - `status` (`queued`, `running`, `succeeded`, `failed`), `was_running` (whether the VM ran when the move began), `migrated_at` (Proxmox moved the VM), `recorded_at` (the server was recorded at the target), `finished_at` and `error`.
  It is the history of where a server has been. While it runs it **reserves** the target VMID (`ServerMove::heldVmids()`) and, for step C, the new addresses (`ip_addresses.server_move_id`). Its tasks point to it (`tasks.server_move_id`).
- A move is one staged `move` task (ADR 0032, `MoveServerPipeline`). The long Proxmox task is a stage that checks its UPID once per run (`StageRunner::proxmoxTask()`), so no worker waits through a disk copy. The deadline is the move time limit of *Panel settings* (ADR 0038; default `PROXMOTION_MOVE_DEADLINE_MINUTES`, 720, `HasOwnDeadline`), because copying disks can take hours. Its bandwidth limit (default `PROXMOTION_MOVE_BWLIMIT_KIB`) is passed as `bwlimit` when set.
- Like any task, it blocks other tasks on the server, so billing writes get `409` meanwhile. The server keeps its status (`active` or `suspended`), and its page shows the move.
- A move is audited as `server.move_started` / `server.moved` / `server.move_failed` (and `server.move_resumed`, §7), with source and target. Steps B and C copy the whole server to other hardware and delete the source copy, so they are confirmed by typing the hostname (§7.8, like a reinstall); so is a move to a placement group that has nodes of another cluster.
- When a move finishes, the webhook `server.moved` (new, additive) carries the server with its node, location and addresses. WHMCS and WISECP receivers only log it, as they do for other events they do not act on.

### 2. Checks before a move

These run in the request (`ServerMover`, refusals name the check that failed and show on the dialog); the first stage checks again that the VM is on its node and the target VMID is free.

- **Target placement** (`NodePlacer::placeMove()`). A node chosen by the administrator (also one in maintenance, like a chosen node of a new server), or the least used node of a placement group (nodes in maintenance skipped). Never the server's own node. The node must be online and in an active cluster, and the server must fit (CPU, memory, server slots and disk after over-commit). The target storage must take VM disks; it defaults to the node's *Use for servers* storage. A disk on shared storage that a node of the same cluster has too stays where it is.
- **Clusters.** A node of another cluster only with cross-cluster moves allowed (*Panel settings*, ADR 0038; default `PROXMOTION_CROSS_CLUSTER_MOVES`) and an incoming move token on that cluster (§5), and with a known API address of the node.
- **Bridges.** The target node must have the bridge of the server's primary pool (steps A and B), or of the pool the new addresses come from (step C).
- **Firewall.** The target cluster's datacenter firewall and ebtables must be on (ADR 0033 §1), as for a new server.
- **Proxmox's preconditions.** Step A asks `GET …/migrate`: no `local_resources`, and the target is not in `not_allowed_nodes`. HA-managed and replicated VMs are refused by Proxmox itself for steps B and C (the panel creates neither, but adopted VMs may have them); the task then fails with Proxmox's message and nothing moved.
- **The server itself.** Active or suspended, no other task, no app installation queued, installing, uninstalling or upgrading and no app run queued or running (an offline move would cut it off). No snapshots (a `parent` key in the VM config): their handling is left to Phase 7, and storage migration of disks with snapshots is not verified.
- **Linked clones** (ADR 0027): step A refuses a server whose boot disk is a linked clone (`base-…/vm-…`) on local storage until moving one is verified. Steps B and C copy the disk, so the result is a full clone.
- **OS template.** When the server's OS template has no template VM on the target cluster, the move is still allowed; reinstalls fail there until the template is mapped. The dialog does not warn about it yet.
- **Routed addresses.** For a server with addresses from a `routed` pool, the provider routes them to one host. The panel cannot move such a route (provider plugins are out of core, §3.7), so the move needs an extra tick: *The provider routes the addresses of this server to the new node.*

### 3. IP addresses

- **Steps A and B keep every address, the MAC and `net0`.** IP pools belong to a location, and a pool's bridge and VLAN are already assumed to reach every node of the location (placement relies on it). The bridge map is the primary pool's bridge (one name maps every bridge of the VM, which has only `net0`).
- **Step C gives the server new addresses.** It gets the same number per IP version from the target location's active pools for its network model (`IpAllocator::assign()` with the move as holder). An IPv4 pool may be chosen; IPv6 comes from a pool on the IPv4 pool's bridge and VLAN, and a server never loses a version of its addresses (the move is refused without one). They are held for the move under the target location's lock (`ip_addresses.server_move_id`, taken by nothing else) and handed to the server when the move is recorded (`IpAllocator::switchToMoveAddresses()`); then the old ones are released with their PTRs (ADR 0003). A move that fails before it is recorded frees them again (`ServerMove::markFailed()`).
  - The new primary of each version gets the PTR the old primary had, published by the new pool's driver. Other PTRs are not carried over.
  - The VM's notes and tags are rewritten with the new primaries (`VmLabels`).
  - Inside the guest, the `addresses_changed` script applies the new addresses and default route through the guest agent (ADR 0009, 0011). A server that was stopped stays stopped: the step says to use *Apply again* after starting it.
  - `ipconfig0` is **not** changed, because a new `ipconfig0` gives the guest a new cloud-init instance ID and new SSH host keys (ADR 0011).
  - `net0` (bridge, `tag=`, a provider MAC of the new primary) and `nameserver` follow the new pool.
  - Nameservers inside the guest: the default address script does not set resolvers. The guest keeps the ones cloud-init wrote, which may not be reachable from the new location. Not solved yet (see Consequences).
  - A hostname generated from the old address (ADR 0031) is **not** renamed: renaming is a separate action (ADR 0012).
- Billing systems learn the new addresses from `server.moved` and `GET /servers/{id}`. API v1 is unchanged.

### 4. VMIDs

- **Step A:** the VMID stays.
- **Steps B and C:** VMIDs are per cluster (§3.6). The target VMID is the **same VMID when it is free** on the target cluster, in the panel (servers and running moves of that cluster) and at or above `PROXMOTION_MIN_VMID`. Otherwise it is the first free one (`VmidAllocator`, which new servers use too and which skips VMIDs held by running moves). It is reserved under the target location's lock and passed as `target-vmid`. The server's `cluster_id`, `node_id`, `storage_id` and `vmid` change only when the move is recorded. The unique index on `(cluster_id, vmid)` stays the final guard.
- Nothing is visible to billing systems: they know servers by UUID and `external_id`, never by VMID.

### 5. Tokens

- The panel's existing token per cluster stays **pool-limited** and is not widened. It needs one more privilege on the pool: `VM.Migrate`. That is added to the required list of the permission check. The built-in `PVEVMAdmin` role already has it.
- A cluster that should **receive** servers from other clusters gets a second, **optional incoming move token**: `clusters.incoming_token_id` and `clusters.incoming_token_secret` (encrypted cast, never in props, logs or errors, §7.2). Administrators create it in Proxmox for a separate user, with exactly:

  | Path | Privileges | Why |
  |---|---|---|
  | `/` | `Sys.Incoming`, `Sys.Audit` | the tunnel endpoint; `/cluster/status` to find the node |
  | `/vms` | `VM.Allocate` | the new VMID is in no pool yet |
  | `/pool/<pool>` | `Pool.Allocate` | the panel puts the moved VM into the cluster's resource pool |
  | `/storage` | `Datastore.AllocateSpace` | the copied disks |
  | `/sdn/zones/localnetwork` | `SDN.Use` | the bridges |

  The cluster page has a section *Servers from other clusters* to save it (it must reach Proxmox, like the main token; an empty token ID removes it; audited as `cluster.incoming_token_saved` / `cluster.incoming_token_removed`) and checks it like the main token (`PermissionChecker::checkIncoming()`), warning about `VM.PowerMgmt`, `VM.Console`, `VM.Monitor`, `VM.GuestAgent.Unrestricted` and `VM.Config.Disk` on `/vms` and the dangerous privileges on `/`. Without an incoming token, steps B and C refuse the cluster as a target.
- **Where the secret goes.** The panel sends the incoming token of the *target* to the *source* cluster's API as `target-endpoint` (`RemoteEndpoint`, used only by `QemuResource::remoteMigrate()`, which masks the secret in any error message). The source cluster's Proxmox therefore sees the target's secret. That is acceptable only because both clusters are the same provider's infrastructure, and it is why the incoming token is separate and narrow: it cannot read, power or delete the panel's servers. Still to verify: that the secret does not appear in the source's task log or task list.
- **TLS.** `host` is the API address of the chosen target node (`Node::apiHost()`): `nodes.api_host` when an administrator set it (node settings on the cluster page), else `nodes.address`, filled by the sync from `ip` in `/cluster/status`, else the cluster's host for a standalone node. When the cluster's certificate is not publicly trusted, `fingerprint` is passed: `clusters.tls_fingerprint` (SHA-256), entered with the incoming token. The panel does not compare it with the certificate itself. The source nodes must reach the target node's API port; how Proxmox reports it when they cannot is unverified.

### 6. Firewall state

- **Step A:** nothing to do. The VM firewall file is cluster-wide and moves with the VM, and the isolation sets (ADR 0034) stay right because protected networks are per location.
- **Steps B and C:** Proxmox copies the firewall file **as it is**: the options (anti-spoofing), the panel's rule block (ADR 0035), `ipfilter-net0` and the isolation IP sets. So the server is filtered from its first second on the target. Two things are still wrong there:
  - `pm-protected` lists the **source location's** node addresses and protected networks (step B: same location, but other nodes);
  - in step C, `ipfilter-net0`, `pm-isolated` and `pm-gateways` still hold the old addresses and pools, which would drop the new addresses' traffic.

  Right after the VM is in the target's pool, a stage rewrites the whole VM firewall through the **target** cluster's main token, as *Enable anti-spoofing* does (ADR 0033 §4): the options, `net0`'s firewall flag, the address IP set (extra entries removed), the isolation IP sets from the panel's view of the target, the rule block and the input policy. It then reads it back (`FirewallChecker::recheck()`). In step C this runs **before** the VM is started on the target (§7).
- `FirewallChecker` and `DriftDetector` already skip a server while a task of it runs. The drift report and the adoption list also leave the VMIDs held by moves into a cluster alone (`ServerMove::heldVmids()`), so a copy in progress never shows up as an unmanaged VM or is offered for adoption.

### 7. The stages

`MoveServerPipeline` has one list of stages; those that do not apply to a kind of move do nothing:

- **Step A:** `check` → `migrate` (UPID; `online` when running; `with-local-disks` and `targetstorage` unless the disk stays on shared storage) → `record` (only once `/cluster/resources` shows the VM on the target node) → `verify` (`ServerVerifier`, warnings only, when it runs).
- **Step B:** `check` → `migrate` (`remote_migrate`, `online` when running, `delete=1`) → `record` (cluster, node, storage, VMID) → `adopt` (the incoming token puts the VM into the target's resource pool) → `firewall` → `verify`.
- **Step C:** `check` → `shutdown` (a clean shutdown without forcing; a guest that does not stop fails the move and nothing changed) → `migrate` (offline, `delete=1`) → `record` (also location, addresses, PTRs) → `adopt` → `configure_network` (`net0`, `nameserver`, VM notes and tags) → `firewall` → `start` → `wait_agent` → `guest_addresses` (address script) → `verify`. The last four only for a server that ran.

**`delete=1`, not a kept copy.** The first version of this ADR kept the source VM (`delete=0`) to return to it. Reading `qemu-server` showed that the kept copy stays locked (`lock: migrate`), and only root can unlock, start or delete a locked VM. A panel token could neither go back to it nor clean it up, and a stopped copy with the server's MAC and addresses would sit in the source cluster's pool. So Proxmox deletes the source once its migration succeeded, and there is no "return to the old copy".

- **Before Proxmox finished** (`migrated_at` empty), a failed move leaves the server where it was: Proxmox cleans up its own failed migration and keeps the source. In step C the panel starts the VM it shut down again. The held VMID and addresses are free again.
- **After Proxmox finished**, the server lives on the target. A later stage that fails (adding it to the pool, the firewall, the guest script) fails the task and the move; the move record says that the VM was moved, and *Finish the move* on the server page (`ServerMover::finish()`, audited as `server.move_resumed`) runs a new task for the same move. Every stage looks first at what is done (`migrated_at`, `recorded_at`), so Proxmox moves the VM only once.

## Consequences

- Administrators can empty a node, balance clusters and retire hardware without reinstalling customers' servers. Within a location this happens live, apart from Proxmox's short switchover.
- Steps B and C depend on a feature Proxmox still calls experimental. The panel says so next to the action and in the docs, and they stay behind a switch on *Panel settings* (ADR 0038; default `PROXMOTION_CROSS_CLUSTER_MOVES`, off) until they are verified on PVE 8.x and 9.x.
- A cluster that receives servers needs a second token with `Sys.Incoming` and `VM.Allocate` on `/vms`. Its secret is handed to the source cluster's Proxmox during each move.
- Moving between locations changes a server's addresses. That is a visible event for the customer (DNS, firewalls elsewhere, licences bound to an IP), so it is never automatic.
- Routed addresses need the provider to reroute them. The panel only asks for confirmation.
- The server keeps its history: `server_moves` stays when a move finishes; the server page lists the last five.
- There is no way back to the source after Proxmox moved the VM (`delete=1`); a server moved by mistake is moved back with another move.
- Open: the guest keeps its old DNS resolvers after a move to another location, and the dialog does not warn when the server's OS template is not mapped on the target cluster.
- Not verified on a real Proxmox yet:
  - `migrate` with `with-local-disks` and `targetstorage` on LVM-thin and ZFS, and of a linked clone on shared storage;
  - `remote_migrate` online and offline, with `delete=1`;
  - that the source VM really keeps `lock: migrate` with `delete=0` (read from the source, the reason for `delete=1`);
  - the copied firewall file applied on the target before the first start;
  - the copied cloud-init drive keeping the instance ID (otherwise every step-B/C move regenerates SSH host keys, which would change the design);
  - the token secret kept out of the source's task logs;
  - `PUT /pools` adding a VM with `Pool.Allocate` (Proxmox also wants "the right to modify permissions" on the VM);
  - how an unreachable target port is reported;
  - conntrack state (`with-conntrack-state`) not carried over.
