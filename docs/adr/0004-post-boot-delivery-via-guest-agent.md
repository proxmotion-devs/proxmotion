# 4. First-boot work through the Guest Agent instead of cloud-init snippets

- **Status:** Accepted
- **Date:** 2026-10-16

## Context

Some work must happen inside a VPS after it is created or reinstalled: installing an app chosen at order time (Phase 5b), reinstall with an app, and later small first-boot tasks.

Custom cloud-init data needs `cicustom: vendor=<storage>:snippets/<file>.yaml`. That requires a storage with the `snippets` content type, and as far as we know the Proxmox API cannot upload snippet files (`POST /nodes/{node}/storage/{storage}/upload` accepts ISO images and container templates). Putting the file there would need SSH or a shared filesystem mounted on the panel, which breaks the "Proxmox API only" rule (AGENTS.md 3.3/8) or adds a fragile deployment requirement. **This must still be checked against the API viewer for the Proxmox VE versions we support.**

Every panel template already ships `qemu-guest-agent` with `agent: 1` (AGENTS.md 3.5), and the "install later" path uses the Guest Agent anyway.

## Decision

1. **Cloud-init is used only through its built-in Proxmox fields:** `ciuser`, `cipassword`, `sshkeys`, `ipconfigN`, `nameserver`, `searchdomain`. No `cicustom`, no snippets.
2. Create and reinstall pipelines end with a **`WaitForGuestAgent`** step (`agent/ping` with backoff, timeout configurable, default 10 minutes) followed by **post-boot hooks**. A hook is a recorded, idempotent pipeline step (AGENTS.md 3.3/4).
3. An app chosen at order time or at reinstall is a post-boot hook that runs **exactly the same flow as "install later"**: `file-write` the recipe and `inputs.json`, `exec` the installer detached, track `status.json` with `file-read`. There is one install path to build, test and debug.
4. The server becomes `active` once the OS is up and the agent answers. An app install that is still running or failed does not block or fail the server; it has its own `AppInstallation` status.
5. If the agent never answers, the server still becomes `active` (it booted), the `AppInstallation` is marked `failed` with the reason "guest agent not reachable", and it can be retried from the panel.

## Consequences

- No snippet storage, shared mounts or SSH are needed; the panel keeps talking only to the Proxmox API.
- The Guest Agent client (`ping`, `file-write`, `file-read`, `exec`, `exec-status`, `set-user-password`) is needed earlier than Phase 5. It moves to **Phase 3**, where root password reset and reinstall readiness use it.
- The Phase 3 reinstall pipeline is built with the `WaitForGuestAgent` step and an (initially empty) list of post-boot hooks, so Phase 5b only adds a hook.
- Templates without a working guest agent cannot get apps. This is already a documented requirement.
- Order-time installs start a little later than cloud-init would start them (after the agent answers instead of during cloud-init). We accept that for a single delivery path.
- If a later Proxmox version allows uploading snippets through the API, a new ADR can revisit this; the hook model does not depend on the transport.
