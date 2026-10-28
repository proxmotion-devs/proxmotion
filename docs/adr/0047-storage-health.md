# 47. Storage health

- **Status:** Accepted (built 2026-10-28)
- **Date:** 2026-10-28

## Context

The cluster page showed, per node, each storage's size, usage and whether Proxmox reports it as available. That says nothing about the state of what lies beneath: a ZFS pool running on one disk of a mirror, an LVM thin pool whose metadata is nearly full (it then stops writing whatever data space is left), a Ceph cluster in `HEALTH_WARN`, or a disk whose SMART check failed or whose SSD wear is near its end. Administrators had to open Proxmox's GUI node by node to find out, and usually did only after a server broke.

Proxmox reports all of it through its API, readable with the privileges the panel's token already has (`Sys.Audit` and `Datastore.Audit` from `PVEAuditor` on `/`):

| Call | What it gives |
|---|---|
| `GET /storage` | each storage's configuration: the zpool dataset (`pool`) of a `zfspool`, `vgname`/`thinpool` of an `lvmthin`, and `monhost` of a Ceph storage of a Ceph cluster Proxmox does not manage |
| `GET /nodes/{node}/disks/list` | the node's physical disks with smartctl's overall result (`health`) and, for SSDs and NVMe, `wearout` (the rated life left in per cent; Proxmox's GUI shows 100 minus it as "Wearout") |
| `GET /nodes/{node}/disks/zfs` | the node's zpools with their state (`ONLINE`, `DEGRADED`, `FAULTED`, …) |
| `GET /nodes/{node}/disks/lvmthin` | the node's thin pools with `metadata_used` and `metadata_size` |
| `GET /cluster/ceph/status` | the health of the Ceph cluster Proxmox manages, with its checks; fails where Ceph is not set up |

## Decision

1. **Read with the cluster sync.** After `ClusterSynchronizer` has mirrored nodes and storages, `StorageHealthCollector` reads the calls above: `/storage` once, `/cluster/ceph/status` only when a storage uses the Ceph cluster Proxmox manages (`rbd`/`cephfs` without `monhost`), the pool calls for each online node at every sync and `disks/list` at most every 30 minutes per node (`StorageHealthCollector::DISK_INTERVAL_MINUTES`; SMART changes slowly and smartctl reads every disk). It runs outside the sync's transaction, because `disks/list` runs smartctl and can take a few seconds per node. A call that fails never fails the sync: the node records why (`nodes.disk_health_error`, shown on its card), and what could not be read (the storage configuration, a node's pools, the Ceph status) keeps its last known state instead of turning healthy. Any other error of the collector, e.g. an answer missing a field, is reported to the log and the sync goes on to drift detection.
2. **Judged by `StorageHealthCheck`** into `ok`, `warning`, `critical` or `unknown` (`App\Enums\StorageHealth`), with the problems as English keys and placeholders, so the page and the mails show them in the reader's language:

   | What | Warning | Critical |
   |---|---|---|
   | Storage not available | | always |
   | Space used | ≥ warning % | ≥ critical % |
   | ZFS pool (`zfspool` storages, zpool from the dataset's first part) | `DEGRADED` | any other state than `ONLINE` |
   | Thin pool metadata used (`lvmthin` storages, by volume group and thin pool) | ≥ warning % | ≥ critical % |
   | Ceph (storages of the managed Ceph cluster) | `HEALTH_WARN` | `HEALTH_ERR` |
   | Disk SMART | | `FAILED` |
   | Disk rated life used (100 − `wearout`) | ≥ warning % | ≥ critical % |
   | Disk no longer reported | always | |

   A disk without a SMART result (`UNKNOWN`, e.g. behind a RAID controller or virtual) is `unknown` unless its wear says otherwise. The thresholds are *Panel settings → General*, `storage_warning_percent` (85) and `storage_critical_percent` (95), `PROXMOTION_STORAGE_WARNING_PERCENT` / `…_CRITICAL_PERCENT` (ADR 0038); the critical one must be higher. A level once reached is kept until the value drops 2 points below its threshold (`StorageHealthCheck::HYSTERESIS_POINTS`), so a storage hovering around 85 % does not flip and mail again and again.
3. **Stored on the sync's rows.** `storages` get `health`, `health_problems`, `pool_state` (the zpool's state or the Ceph health) and `metadata_used_bytes`/`metadata_total_bytes`; `clusters` get `ceph_health` and `ceph_checks` (Ceph's own English messages, muted checks left out); a new `node_disks` table holds each node's disks, matched by serial number (by `devpath` only without one, since Linux gives a device path to another disk after a reboot or a removed disk) (model, serial, type, size, what uses it, SMART result, wearout, health, problems, `is_present`). None of these columns are fillable.
4. **A vanished disk is kept.** A disk Proxmox stops reporting may have failed, so it stays with `is_present` false and a warning until an administrator forgets it (*Delete* next to it, `DELETE /admin/node-disks/{disk}`, only for disks no longer reported, audited as `node_disk.forgotten` on the node).
5. **Where it shows.** The cluster page gets a *Storage health* badge (the worst of its storages, disks and Ceph), a Ceph box with its checks, a *Health* column in each node's storage table (badge, pool state, problems, thin pool metadata under the usage) and a disk table per node. The cluster list marks clusters whose storage health is a warning or critical, and the dashboard counts storages and disks that are, a shared storage once per cluster. Both count online nodes only: an offline node is shown as such, and a node removed from the cluster keeps its last rows.
6. **Email** (ADR 0044): a new event `storage_unhealthy` (*A storage or disk is in worse health*, on by default) mails administrators when a storage or disk got worse since the previous sync, from `ok` or `unknown` to `warning` or `critical`, or from `warning` to `critical`, listing each one with its problems. One mail per cluster and sync, not throttled: since only a change for the worse is mailed, a mail held back by a throttle would never be sent later. A shared storage is listed once, not once per node. Staying unhealthy sends nothing more, so a problem is mailed again only after it was fixed and came back.

## Consequences

- Administrators see failing pools and disks in the panel and by mail, without opening Proxmox node by node.
- Every sync asks each online node two more calls, and every 30 minutes `disks/list`, which runs smartctl on every disk. Whether that wakes disks that were spun down is unverified; Proxmox's own GUI makes the same call whenever its disk panel is opened. A failing disk is therefore noticed up to 30 minutes late.
- A state kept because it could not be read may be stale; the node shows the read error.
- Ceph checks are shown in Ceph's own English; they are not translated.
- Not covered: hardware RAID controllers (no SMART through Proxmox), storages outside Proxmox's view (NFS servers, an external Ceph cluster's own health), history or graphs of health. Moving placement away from unhealthy storages is left to administrators (*Use for servers*).
- Nothing of this has run against a real Proxmox server; field names and units are taken from the API viewer and Proxmox's source and are tested with fixtures only.
