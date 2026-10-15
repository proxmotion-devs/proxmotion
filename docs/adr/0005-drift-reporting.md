# 5. Drift reporting between the panel and Proxmox

- **Status:** Accepted
- **Date:** 2026-10-16

## Context

The panel database is the source of truth, and Proxmox state is reconciled periodically; drift is reported to admins and never auto-deleted (AGENTS.md 3.3/6). The sync (`infra:sync`, every 5 minutes) already reads `GET /cluster/resources?type=vm` for every cluster, but today it only uses the result for templates and adoption. Nothing tells an admin when a VM was deleted, moved, resized or powered on outside the panel.

## Decision

1. After each successful cluster sync, a **`DriftDetector`** (`app/Domain/Infrastructure/`) compares the panel's servers on that cluster with the guest list the sync already fetched. It makes no extra API calls.
2. **Drift types** (enum `DriftType`):
   | Type | Condition |
   |---|---|
   | `missing_vm` | An `active`/`suspended` server's VMID is not on the cluster |
   | `node_mismatch` | The VM runs on a different node than recorded (migrated outside the panel) |
   | `resource_mismatch` | `maxcpu`, `maxmem` or `maxdisk` differ from the server's recorded resources |
   | `power_mismatch` | A `suspended` server's VM is running, or an `active` server's VM has been stopped for longer than a configurable grace period |
   | `unmanaged_vm` | A VM in the panel's resource pool that is not a server and not a template (can be adopted) |
3. Findings are stored in a **`server_drifts`** table (cluster, optional server, VMID, type, expected/actual as JSON, `first_seen_at`, `last_seen_at`, `resolved_at`). A finding that is no longer detected is resolved automatically; the same drift is never stored twice while open.
4. **No automatic repair.** The admin sees open drifts on the dashboard and on the server page and chooses an action per type:
   - `node_mismatch` → "accept new node" (updates the record).
   - `resource_mismatch` → "accept Proxmox values" or "re-apply package" (a queued task).
   - `power_mismatch` → "re-apply power state" (a queued task) or dismiss.
   - `missing_vm` → mark the server as `error`; terminating it frees its IPs. Nothing is recreated automatically.
   - `unmanaged_vm` → open the adoption wizard.
   Every action is written to `AuditLog`.
5. Servers with a running task are skipped, so an in-flight provision, resize or reinstall is never reported as drift.
6. Admins are notified of **new** drifts only (mail/database notification, later webhooks), not on every sync.

## Consequences

- Cheap: one comparison per sync using data already loaded.
- `power_mismatch` for stopped servers needs the grace period, because customers may shut down their own VMs from inside the OS. By default this type is shown but not notified.
- Usage-based checks (traffic) and config-level checks (firewall, `net0` options) are out of scope for this ADR; they need per-VM API calls and can be added later as a slower, separate job.
- Planned for Phase 3 (admin side), since the data exists already and it protects Phase 4 billing from acting on stale state.
