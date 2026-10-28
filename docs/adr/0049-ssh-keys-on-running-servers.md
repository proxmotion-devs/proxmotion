# 49. SSH keys on running servers

- **Status:** Accepted
- **Date:** 2026-10-28

## Context

Public SSH keys reach a server only when it is created or reinstalled: they are pasted on the admin form or sent as `ssh_keys` to the billing API, stored on the server (`servers.ssh_keys`, one `authorized_keys` line each) and handed to cloud-init as Proxmox's `sshkeys`. Afterwards nobody can add a key (a new colleague) or remove one (a lost laptop) without logging in, and the panel's list of keys goes stale.

ADR 0007 §4 removed saved keys (`ssh_keys` per customer account) along with customer accounts. This ADR does not bring them back: keys still belong to one server.

Changing `sshkeys` in the VM's configuration is not an option: it is part of the cloud-init user data, and a changed user data makes cloud-init see a new instance at the next boot and regenerate the SSH host keys (measured for `ipconfig0` and the VM name, ADR 0011 and 0012).

## Decision

1. **The server's keys are the keys the panel manages on it:** `servers.ssh_keys` as before, at most as many as *Panel settings* allow (§12). Administrators (server page), the billing API and the billing modules add and delete them. Keys added inside the server by its owner are not listed and never touched, unless one of them is also a key the panel removes.
2. **A new guest script event `ssh_keys_changed`** (ADR 0010, file `ssh-keys`) with defaults for Linux, FreeBSD and Windows. Its inputs are `username` (root, Windows: Administrator), `ssh_keys` (the keys the server should have) and `removed_ssh_keys` (the panel's keys that it should no longer have). The script removes every line of the user's `authorized_keys` that contains a removed key's base64 blob (also lines with options), then appends each wanted key whose blob is not there yet; lines it does not know stay. Safe to run any number of times.
   - **Linux and FreeBSD** (`ssh-keys.sh`, POSIX `sh` and `awk`): `~root/.ssh/authorized_keys`, `0700` / `0600`, owned by root, replaced through a temporary file in the same directory.
   - **Windows** (`ssh-keys.ps1`): OpenSSH reads the keys of administrators from `C:\ProgramData\ssh\administrators_authorized_keys` and ignores that file unless only SYSTEM and Administrators may access it, so the script writes that file with that ACL; removed keys are also taken out of `C:\Users\Administrator\.ssh\authorized_keys`, where Cloudbase-Init puts the keys of a new server.
3. **A change runs as a `change_ssh_keys` task** with the wanted keys as its input. The pipeline computes the removed keys from the stored list when it runs, and **stores the new list only after the script succeeded**. The event is critical: a failing script or an agent that does not answer fails the task and leaves the stored list as it was, so sending the same list again retries the removal of a key that might still be on the server. Custom scripts bound to the event run afterwards.
4. **Only active servers:** the guest agent must answer. The billing API answers `409` otherwise, and while another task runs.
5. **Whole lists:** both the admin page and the API send the complete list (`PUT`), like the firewall (ADR 0035). Sending an unchanged list runs the script again. Keys are validated with `PublicKey` (ed25519, ECDSA, RSA ≥ 2048 bits, security-key types; never DSA) and normalized; duplicates collapse by fingerprint.
6. **API:** `GET /servers/{id}/ssh-keys` (`servers:read`): the keys with type, fingerprint, comment and the line. `PUT /servers/{id}/ssh-keys` (`servers:write`, idempotent) with `ssh_keys` → `202` + the task. Additive, so v1 stays compatible.
7. **Audit:** `server.ssh_keys_changed` with the fingerprints added and removed, written when the change is requested.
8. **Billing modules:** the WHMCS client area and a WISECP tool list the keys with *Delete* and a form to add one; both read the current list and send the whole new list.
9. **Reinstalls keep the keys** unless they are given others. `POST /servers/{id}/reinstall` without `ssh_keys` (or with `null`) installs the server's current keys on the new system; a list replaces them and an empty list removes them. Before this ADR a missing `ssh_keys` meant no keys, which would silently drop every key added since; clients that relied on that send `[]`. The admin reinstall dialog starts with the current keys. WISECP's reinstall leaves `ssh_keys` out; a key typed into the WHMCS reinstall form is added to the current keys (the module reads them and sends both).
10. **Windows ACL:** the script resets the file's ACL to exactly SYSTEM and Administrators (`icacls /inheritance:r` alone keeps explicit entries such as Users, and OpenSSH then ignores the file).
11. **Reading the keys inside a server** (admin only, *Read from the server* on the SSH keys tab, `GET /admin/servers/{server}/ssh-keys/guest`): the guest agent's `file-read` of `/root/.ssh/authorized_keys` (Windows: `administrators_authorized_keys` and the Administrator's own file), on request, like *Check against a server* (ADR 0024 §8); nothing is stored or audited. Lines may start with options; comments and keys the panel refuses (DSA, short RSA) are only counted. The tab then marks managed keys found in no file (*Not in the server*; the next change writes them again) and lists keys only the server has, which an administrator may let the panel **manage**: a normal change with the key added to the list, which the script finds already there, so the line, options included, stays as it is. The billing API does not offer this: customers see what the panel manages.
12. **How many keys:** *Most keys per server* on *Panel settings → Servers* (`ssh_keys_max`, default 20 from `PROXMOTION_SSH_KEYS_MAX`, 1–100, ADR 0038), applied wherever keys are given: creating and reinstalling (admin and API), the SSH keys tab and `PUT /servers/{id}/ssh-keys` (`SshKeyLimit`); `GET /servers/{id}/ssh-keys` says it as `max_keys`. The keys together may be at most 20 KB (`ServerSshKeys::MAX_BYTES`), whatever the setting: a change writes the wanted keys and the removed ones (at most as much again) in one input file, and the guest agent writes at most 45 KB at once (`GuestAgentResource::MAX_WRITE_BYTES`). Twenty typical keys take 2–15 KB. Servers keep the keys they have when the limit is lowered; a change then has to bring them under it.

## Consequences

- No cloud-init change and no new SSH host keys; the change is in effect at once.
- The guest agent must be able to write root's files: the same requirement as the password reset.
- The panel cannot see keys its owner added inside the server, so its list is "keys the panel installed", not "every key that works".
- Proxmox keeps the keys of the last create or reinstall in `sshkeys`; nothing reads them again before the next reinstall, which writes new ones.
- Not verified on a real guest: the defaults on Debian, Ubuntu, RHEL-family and FreeBSD templates, `administrators_authorized_keys` with Windows OpenSSH Server on Server 2019–2025, and both billing modules in a real WHMCS and WISECP.
