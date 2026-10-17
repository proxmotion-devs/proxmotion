# 12. Changing a server's hostname

- **Status:** Accepted
- **Date:** 2026-10-17

## Context

Customers want to rename their server after ordering it. The hostname lives in the panel (`servers.hostname`), as the Proxmox VM name (Proxmox gives it to cloud-init as `hostname`/`fqdn` in the user-data, with `manage_etc_hosts: true`), inside the operating system, and often as the PTR of the server's addresses.

Measured on the test Proxmox (`HostnameIdentityTest`, Debian 13, cloud-init 25.1.4, PVE 9.2.2):

- Changing the name inside the guest (`hostnamectl`) with `preserve_hostname: true` keeps it across a restart, and cloud-init keeps its instance id; but cloud-init **rewrites `/etc/hosts` with the old name at every boot**.
- Renaming the Proxmox VM changes the user-data: after a restart cloud-init sees a **new instance** and **regenerates the SSH host keys** (as with `ipconfig0`, ADR 0011).

## Decision

1. A new guest script event `hostname_changed` (ADR 0010) with defaults for both platforms; its inputs are `hostname` (the new one) and `previous_hostname`.
   - **Linux** (`hostname.sh`): `hostnamectl set-hostname`, `preserve_hostname: true` in `/etc/cloud/cloud.cfg.d/99-proxmotion-hostname.cfg`, the `127.0.1.1` line of `/etc/hosts`, and a systemd unit (`proxmotion-hostname.service`, after cloud-init) that sets `/etc/hosts` again at every boot. No restart.
   - **Windows** (`hostname.ps1`): waits for Cloudbase-Init, then `Rename-Computer` with the first label (NetBIOS names have at most 15 characters). Windows applies it at the next restart: the panel restarts the server when asked (`reboot`), otherwise it flags `reboot_required`.
2. **The Proxmox VM name is never changed** on an existing server; a reinstall names the new VM after the current hostname.
3. The panel's hostname changes at once (it is the source of truth); PTRs of the server's addresses that equal the old hostname follow it through the address's DNS provider (ADR 0003). Other PTRs are left alone.
4. A change runs as a `change_hostname` task; failing scripts leave a note (the name is already changed in the panel). Requested by administrators (server page), the billing API (`POST /servers/{id}/hostname`, `servers:write`) and the WHMCS client area; written to the audit log.

## Consequences

- No SSH host key change and no cloud-init re-run; on Linux the change is immediate.
- Proxmox shows the old VM name until a reinstall; the panel lists the new one.
- `reboot_required` now also covers a pending Windows rename, not only CPU and memory: the notices say "changes take effect at the next restart".
- If Cloudbase-Init's user-data plugin runs again (it does after a restart it requested itself), it sets the old name from Proxmox's user-data. The Windows guide recommends running that plugin only in the specialize pass.
- The Windows default has not run on a Windows guest yet.
