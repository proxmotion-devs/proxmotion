# 10. Guest scripts per OS template

- **Status:** Accepted
- **Date:** 2026-10-16

## Context

ADR 0009 runs one fixed shell script inside the guest, through the QEMU guest agent, to configure additional IP addresses. More operations need the same mechanism, and none of them works the same way on every template:

- **Disk resize:** Proxmox grows the virtual disk, but the partition and file system only grow at the next boot (cloud-init `growpart`), and only for a plain root partition (not LVM, not Windows).
- **Additional addresses:** the script of ADR 0009 is Linux only.
- **After installation:** providers want template-specific preparation (packages, settings) without rebuilding the template.
- **Password reset:** the agent's `set-user-password` changes `root` on Linux; Windows templates use `Administrator`, and some templates need a different user or extra steps.

Providers build their own templates (`deploy/proxmox`, `docs/guides/iso-templates.md`), so the panel cannot ship a script that is right for all of them, and changing a script must not need a panel release.

## Decision

1. **Events.** Scripts run on four events (`GuestScriptEvent`):

   | Event | When | Default | On failure |
   |---|---|---|---|
   | `provisioned` | After creation and after a reinstall, once the agent answers | none | note on the task step |
   | `addresses_changed` | After creation and reinstall, and after a resource change, when the server has additional addresses (or the change released some) | `resources/guest/{linux,windows}/addresses.*` (replaces the ADR 0009 script) | note |
   | `disk_resized` | Right after a resource change grew the disk, if the agent answers | `resources/guest/{linux,windows}/disk.*` | note |
   | `password_reset` | Instead of the agent's `set-user-password` | none: `set-user-password` for `root` (Linux) or `Administrator` (Windows) | **the task fails** and no new password is stored or shown |

   When the guest agent goes away while a script runs (the guest restarted, as Windows does during its first-boot setup), the panel waits for it and runs the script again, up to three times; critical scripts are never repeated. The Windows defaults also wait for cloudbase-init to finish, and the address script re-runs at every startup through a scheduled task.

   A failing script never fails the other operations: the resources, addresses and firewall are already changed in Proxmox, and the step says what to do by hand. A password that may not have been set must never be shown as the new one, hence the exception.

2. **Default plus override per template.** The defaults live in the repository and are updated with releases. An OS template has a `platform` (`linux` or `windows`) that picks the default and the interpreter, and may override the script of any event (`guest_scripts`: one row per template and event). Templates without an override, and adopted servers without a template (treated as Linux), use the default; events without a default and without an override do nothing.

3. **Editing.** Administrators (all of them pass TOTP 2FA, ADR 0007) edit overrides in the admin panel (*OS templates → Scripts*), can reset an event to the default, and can run a script once on a server of that template (a `guest_script` task). Since ADR 0023 they save drafts, try them, publish them, and see every run with its output. Every change is written to the audit log with the previous and the new content. Scripts are limited to what one `file-write` accepts (46080 bytes).

4. **Delivery.** For each run the panel writes, with `file-write`:
   - the script: `/usr/local/sbin/proxmotion-<name>` on Linux, `C:\ProgramData\Proxmotion\<name>.ps1` on Windows (`<name>`: `provisioned`, `addresses`, `disk`, `password`; the addresses path is the one of ADR 0009, so units installed earlier keep working);
   - its inputs, next to the script's configuration: `/etc/proxmotion/<name>.json` and a line-based `/etc/proxmotion/<name>.vars` (`key=value`, list values repeat the key) for shell scripts without `jq`; on Windows only `C:\ProgramData\Proxmotion\<name>.json`.

   Then it runs `sh <script>` or `powershell.exe -NoProfile -NonInteractive -ExecutionPolicy Bypass -File <script>` with `exec`. Inputs come only from the panel's database (server uuid, hostname, MAC of `net0`, addresses, disk size, user name), are validated there and are **never interpolated into the script**. The password of `password_reset` is never written to a file: it is passed on standard input (`input-data`).

5. Scripts run as root (Linux) or SYSTEM (Windows) inside the customer's server. This is a deliberate exception to "only recipe scripts run inside a VPS" (AGENTS §7.9): the other source is an override written by an administrator, recorded in the audit log. Customers and API tokens can never supply or choose a script.

## Consequences

- The file system grows right after an upgrade on running servers, also on LVM and Windows templates, as far as the default scripts handle them; on a stopped server or without an agent the step says so (Linux: cloud-init grows the root partition at the next boot).
- Providers fix template quirks (another network renderer, another administrator user, extra packages) without a release.
- An override is the provider's responsibility: a broken one can make the addresses or the disk change fail inside the guest (noted on the task) or make every password reset of the template fail.
- The Windows defaults are written without a Windows template to test on; they must be verified on a real Windows guest before Windows templates are offered.
- App recipes (Phase 5) stay a separate mechanism with their own status files; they may reuse the delivery code.
