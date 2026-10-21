# 37. Moving servers between nodes and clusters

- **Status:** Proposed (2026-10-22, not built yet)
- **Date:** 2026-10-22

## Context

A server stays on the node it was created on. `AGENTS.md` plans "live migration within clusters" for Phase 9 and moves across clusters "backup/restore based"; neither exists. Today a VM moved by hand in Proxmox only shows up as a `node_mismatch` drift (ADR 0005), and a VM moved to another cluster looks like a deleted one. Providers need moves to empty a node for maintenance, to balance load and to retire old clusters. The maintainers asked whether a server can also move **between clusters**.

Proxmox offers two API calls. Their details were read from the API viewer data (`apidoc.js`) and `qemu-server` (`PVE/API2/Qemu.pm`, `PVE/QemuMigrate.pm`, `master`, 2026-10-22):

**`POST /nodes/{node}/qemu/{vmid}/migrate`** (within one cluster): `target` node, `online` (live, ignored for a stopped VM), `with-local-disks` (live storage migration of local disks), `targetstorage` (a storage map), `bwlimit`. Privilege: `VM.Migrate` on `/vms/{vmid}`. `GET` on the same path reports the preconditions: `running`, `allowed_nodes`, `not_allowed_nodes` (with `unavailable_storages`), `local_disks` and `local_resources` (PCI, USB, which block a move). The VMID stays the same. The VM's firewall file (`/etc/pve/firewall/<vmid>.fw`) is cluster-wide, so its firewall does not move at all.

**`POST /nodes/{node}/qemu/{vmid}/remote_migrate`** (to another cluster, **marked EXPERIMENTAL** by Proxmox):
- `target-endpoint` (`apitoken=PVEAPIToken=…,host=…[,fingerprint=…][,port=…]`). The VM lands on **the node that answers at `host`**: Proxmox reads `/cluster/status` there and takes the `local` node.
- `target-storage` and `target-bridge` are both required maps. An identity storage map (`1`) is refused.
- `target-vmid` (defaults to the source VMID), `online`, `bwlimit`.
- `delete` (default `0`: "the original VM is kept on the source cluster in a stopped state").
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

- `server_moves` holds one row per move:
  - `server_id` and `kind` (`node`, `cluster`, `location`);
  - the source `cluster_id`, `node_id`, `storage_id` and `vmid`, and the target `cluster_id`, `node_id`, `storage_id` and `vmid`;
  - `online`, `status` (`queued`, `running`, `succeeded`, `failed`, `rolled_back`), the Proxmox UPID, the address plan (§3) and `source_removed_at`.
  It is the history of where a server has been, and it **reserves** the target VMID and addresses while the move runs.
- A move is one staged `move` task (ADR 0032). The long Proxmox task is a stage that checks its UPID once per run (`StageRunner::proxmoxTask()`), so no worker waits through a disk copy. The deadline is `PROXMOTION_MOVE_DEADLINE_MINUTES` (default 720), because copying disks can take hours. `PROXMOTION_MOVE_BWLIMIT_KIB` is passed as `bwlimit` when set.
- Like any task, it blocks other tasks on the server, so billing writes get `409` meanwhile. The server keeps its status (`active` or `suspended`), and its page shows the move.
- A move is audited as `server.move_started` / `server.moved` / `server.move_failed`, with source and target. Steps B and C copy the whole server to other hardware and delete the source copy, so they are confirmed by typing the hostname (§7.8, like a reinstall).
- When a move finishes, the webhook `server.moved` (new, additive) carries the server with its node, location and addresses. WHMCS and WISECP receivers only log it, as they do for other events they do not act on.

### 2. Checks before a move

These run in the request (refusals name the check that failed) and again in the first stage:

- **Target placement.** A target node chosen by the administrator, or a placement group of the target location (best node by `NodePlacer`). The node must be online and not in maintenance, and the server must fit (`NodePlacer::freeCapacity()`, as the *Custom* create form warns today). The target storage must take VM disks; it defaults to the node's *Use for servers* storage. Step A on shared storage keeps the disks where they are.
- **Bridges.** The target node must have the bridge of every pool the server has addresses in (steps A and B), or of the pools the new addresses come from (step C).
- **Firewall.** The target cluster's datacenter firewall and ebtables must be on (ADR 0033 §1), as for a new server.
- **Proxmox's preconditions.** `GET …/migrate` must list no `local_resources`, and the target must not be in `not_allowed_nodes` (step A). The server must not be HA-managed or replicated (steps B and C). The panel creates neither, but adopted VMs may have them.
- **The server itself.** No app installation `installing` and no app run `running` (an offline move would cut it off). No snapshots: their handling is left to Phase 7, and storage migration of disks with snapshots is not verified.
- **Linked clones** (ADR 0027): step A refuses a linked clone on local storage until moving one is verified. Steps B and C copy the disk, so the result is a full clone; the server's `clone_mode` no longer applies to it.
- **OS template.** When the server's OS template has no template VM on the target cluster or node, the move is still allowed. It warns that reinstalls will fail until the template is mapped there.
- **Routed addresses.** For a server with addresses from a `routed` pool, the provider routes them to one host. The panel cannot move such a route (provider plugins are out of core, §3.7), so the move needs an extra tick: *The provider routes these addresses to the new node.*

### 3. IP addresses

- **Steps A and B keep every address, the MAC and `net0`.** IP pools belong to a location, and a pool's bridge and VLAN are already assumed to reach every node of the location (placement relies on it). The bridge map is identity per bridge (`vmbr0=vmbr0`).
- **Step C gives the server new addresses.** It gets the same number per IP version from the target location's active pools for its network model (`IpAllocator`). Like the create form, an IPv4 pool and specific addresses may be chosen. They are reserved under the target location's lock when the move is requested and recorded in the move's address plan. The old addresses stay the server's until the move succeeds, then they are released (their PTRs are removed, ADR 0003).
  - The new primary gets the PTR the old primary had, published by the new pool's driver. Other PTRs are not carried over.
  - The VM's notes and tags are rewritten with the new primaries (`VmLabels`).
  - Inside the guest, the `addresses_changed` script applies the new addresses and default route through the guest agent (ADR 0009, 0011).
  - `ipconfig0` is **not** changed, because a new `ipconfig0` gives the guest a new cloud-init instance ID and new SSH host keys (ADR 0011).
  - The bridge map and `net0`'s `tag=` follow the new pool.
  - Nameservers: the default address script does not set resolvers today. The guest keeps the ones cloud-init wrote, which may not be reachable from the new location. Before step C is built, the address script learns to write the pool's nameservers, or the move warns.
  - A hostname generated from the old address (ADR 0031) is **not** renamed: renaming is a separate action (ADR 0012).
- Billing systems learn the new addresses from `server.moved` and `GET /servers/{id}`. API v1 is unchanged.

### 4. VMIDs

- **Step A:** the VMID stays.
- **Steps B and C:** VMIDs are per cluster (§3.6). The target VMID is the **same VMID when it is free** on the target cluster, in the panel (servers and running moves of that cluster) and at or above `PROXMOTION_MIN_VMID`. Otherwise it is the first free one, found the same way `ServerCreator::firstFreeVmid()` does, which then also skips VMIDs reserved by running moves. It is reserved under the target location's lock and passed as `target-vmid`. The server's `cluster_id`, `node_id`, `storage_id` and `vmid` change only when the move succeeds. The unique index on `(cluster_id, vmid)` stays the final guard.
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

  The cluster page checks it like the main token (`/access/permissions`). The excess-privilege warning applies to it too, apart from the rows above. Without an incoming token, steps B and C do not offer the cluster as a target.
- **Where the secret goes.** The panel sends the incoming token of the *target* to the *source* cluster's API as `target-endpoint`, built only by `QemuResource::remoteMigrate()`. The source cluster's Proxmox therefore sees the target's secret. That is acceptable only because both clusters are the same provider's infrastructure, and it is why the incoming token is separate and narrow: it cannot read, power or delete the panel's servers. Still to verify: that the secret does not appear in the source's task log or task list.
- **TLS.** `host` is the address of the chosen target node: `nodes.api_host`, a new field filled by the sync from the node's address on `/cluster/status`, which an administrator can override. When the cluster's certificate is not publicly trusted, `fingerprint` is required: `clusters.tls_fingerprint` (SHA-256), entered on the cluster form and compared with the certificate the panel sees on its connection test. The source nodes must reach the target node's API port; the move fails early with that reason when they cannot (unverified how Proxmox reports it).

### 6. Firewall state

- **Step A:** nothing to do. The VM firewall file is cluster-wide and moves with the VM, and the isolation sets (ADR 0034) stay right because protected networks are per location.
- **Steps B and C:** Proxmox copies the firewall file **as it is**: the options (anti-spoofing), the panel's rule block (ADR 0035), `ipfilter-net0` and the isolation IP sets. So the server is filtered from its first second on the target. Two things are still wrong there:
  - `pm-protected` lists the **source location's** node addresses and protected networks (step B: same location, but other nodes);
  - in step C, `ipfilter-net0`, `pm-isolated` and `pm-gateways` still hold the old addresses and pools, which would drop the new addresses' traffic.

  Right after the Proxmox task, a stage rewrites the whole VM firewall through the **target** cluster's main token, exactly as *Enable anti-spoofing* does (ADR 0033 §4): the options, IP sets from the panel's view of the target, the rule block and the input policy. It then reads it back (`FirewallChecker::recheck()`). In step C this runs **before** the VM is started on the target (§7).
- `FirewallChecker` and `DriftDetector` skip a server while its move runs, and skip the target VMID on the target cluster. Otherwise the copy in progress would show up as an unknown VM, a missing VM or a `node_mismatch`.

### 7. The stages

- **Step A:** `check` → `migrate` (UPID; `online` when running; `with-local-disks` and `targetstorage` for local disks) → `record` (node and storage from `/cluster/resources`, not assumed) → `verify` (`ServerVerifier`, warnings only).
- **Step B:** `check` → `remote_migrate` (`online` when running, `delete=0`) → `adopt` (the incoming token puts the VM into the target's resource pool) → `firewall` → `record` (cluster, node, storage, VMID) → `verify` → `remove_source`.
- **Step C:** `check` → `shutdown` (the usual clean shutdown; a stuck guest fails the move without touching anything) → `remote_migrate` (offline, `delete=0`) → `adopt` → `configure` (`net0` bridge and tag, nameservers, VM notes and tags) → `firewall` → `record` (also location, addresses, PTRs) → `start` → `addresses` (guest script) → `verify` → `remove_source`.

`remove_source` deletes the stopped source copy through the source cluster's main token. That token can do it because the VM is still in its pool. It runs only once the target VM was recorded, and records `source_removed_at`.

- **Before `record`**, a failed move leaves the server where it was. Its source VM is still there, because `delete=0` keeps it; a VM half-created on the target is deleted when the panel created it for this move (it is in the move record). Proxmox cleans up its own failed migration; the panel only removes what is left of it.
- **After `record`**, the server lives on the target. While the source copy exists, *Return to the old copy* (admin, audited) starts it again, deletes the target VM and restores the recorded location; in step C, the old addresses are only released at `remove_source`, so they can still be returned to. A failed `verify` keeps the source copy and leaves that choice to the administrator. A server that verifies cleanly loses its old copy at once: two copies of one server with the same MAC and addresses must not run in the same network, and the adoption list (§3.8) must never offer the leftover.

## Consequences

- Administrators can empty a node, balance clusters and retire hardware without reinstalling customers' servers. Within a location this happens live, apart from Proxmox's short switchover.
- Steps B and C depend on a feature Proxmox still calls experimental. The panel says so next to the action and in the docs, and they stay behind `PROXMOTION_CROSS_CLUSTER_MOVES` (default off) until they are verified on PVE 8.x and 9.x.
- A cluster that receives servers needs a second token with `Sys.Incoming` and `VM.Allocate` on `/vms`. Its secret is handed to the source cluster's Proxmox during each move.
- Moving between locations changes a server's addresses. That is a visible event for the customer (DNS, firewalls elsewhere, licences bound to an IP), so it is never automatic.
- Routed addresses need the provider to reroute them. The panel only asks for confirmation.
- The server keeps its history: `server_moves` stays when a move finishes.
- Not verified on a real Proxmox yet:
  - `migrate` with `with-local-disks` and `targetstorage` on LVM-thin and ZFS, and of a linked clone;
  - `remote_migrate` online and offline;
  - the source copy left stopped and unlocked with `delete=0`;
  - the copied firewall file applied on the target before the first start;
  - the copied cloud-init drive keeping the instance ID (otherwise every step-B/C move regenerates SSH host keys, which would change the design);
  - the token secret kept out of the source's task logs;
  - `PUT /pools` adding a VM with `Pool.Allocate` (Proxmox also wants "the right to modify permissions" on the VM);
  - how an unreachable target port is reported;
  - conntrack state (`with-conntrack-state`) not carried over.
