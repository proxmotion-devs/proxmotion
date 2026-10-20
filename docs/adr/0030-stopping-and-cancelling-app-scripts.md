# 30. Stopping and cancelling app scripts

- **Status:** Accepted
- **Date:** 2026-10-20

## Context

App scripts (installs, uninstalls, upgrades and actions) run detached inside the server: `systemd-run` starts `run.sh` (ADR 0015), a scheduled task starts `run.ps1` on Windows (ADR 0029), and the panel only reads `status.json`. Nothing could stop one:

- **Timeouts** failed the installation or run in the panel only. The script kept running inside the server.
- **Retry** after a timeout started a new attempt in the same directory while the old one could still be running: `run.sh` empties `run.log` and removes `outputs/`, and both installers fight over the package manager's locks. An uninstall of a `failed` installation could likewise run next to its still running installer.
- **Administrators and customers** had no way to stop a script that hangs (an installer waiting for input, a download that never ends) other than waiting for the timeout, which is up to two hours for cPanel.

## Decision

### 1. Stopping a script inside the server

`AppStopper` stops scripts by the name the panel gave them at start: `proxmotion-app-<installation>-<attempt>` for an install attempt, `proxmotion-app-run-<run>` for an uninstall, upgrade or action (`AppGuest::installName()`, `runName()`). A name that is not running (finished, never started, a reinstalled system) counts as stopped, so stopping is always safe to repeat.

- **Linux:** `recipes/_lib/stop.sh`, run through the guest agent as `bash -c <stop.sh> proxmotion-stop <name>…` (no file is written, so a stop never rewrites a file another stop is reading). It sends `SIGTERM` to every process of the unit `<name>.service` (`systemctl kill`, the whole control group, so child processes started by the installer go too), waits up to 10 seconds, then sends `SIGKILL`, and exits non-zero only when a unit is still active.
- **Windows:** `recipes/_lib/stop.ps1`, written into the app's directory and run with `powershell -File stop.ps1 <name>…`. It disables the task `\Proxmotion\<name>` and its start-up tasks `<name>-boot-<n>` (so a restart does not resume the script), stops them, ends the runner processes (their command line carries `-Task <name>`) with every process they started (by parent process id, younger than their parent), and unregisters the tasks. It exits non-zero when one of those processes is still alive after 10 seconds.

Stopping does not undo anything the script did: packages it installed stay, a package manager killed in the middle may need `dpkg --configure -a`. Scripts are idempotent, so a retry continues; otherwise the app can be uninstalled (when its recipe can) or the system reinstalled.

### 2. Timeouts stop the script

When `InstallationPoller` or `RunPoller` fails an installation or run past its timeout, they queue `StopAppScript`, which stops it inside the server (3 attempts a minute apart, for an agent that does not answer for a moment or a script that outlived the stop; after the last one it gives up, §3 covers the rest). The outcome in the panel does not wait for it.

### 3. Nothing starts next to an earlier script

Before `AppStarter` uploads a new install attempt and before `AppRunPipeline` uploads a run, they stop the installation's latest install attempt and its latest earlier run. If one of them is still running afterwards, the start fails with *A previous script of this app is still running inside the server.* This holds also when a timeout's stop could not reach the server.

### 4. Cancelling

An administrator (server page) or a billing system (API) can cancel an installation that is `installing` and a run that is `running`. Queued ones cannot be cancelled: their task is about to start them, or they belong to the creation or reinstall of the server.

- The installation or run **fails at once** with *Cancelled by an administrator.* or *Cancelled by the billing system.* (installation: `failed`, retryable; uninstall: the installation becomes `uninstall_failed`; upgrade: `upgrade_failed` on the old version; action: the run fails), under the same lock as the pollers, so a poll that finds the script finished at that moment wins and the cancel is refused (`409`).
- The usual webhooks are sent (`app.failed`, `app.uninstall_failed`, `app.upgrade_failed`, `app.action_failed`), the log is closed, and the cancel is audited as `app.install_cancelled` or `app.run_cancelled`.
- Then `StopAppScript` is queued, as for a timeout.

A queued job instead of a server task (like `PublishPtr`): stopping changes no Proxmox configuration, must not wait for or be refused by another task of the server (a reboot, a password reset), and §3 covers a stop that never reached the server.

### 5. API and billing modules

- `POST /servers/{id}/apps/{installation}/cancel` → `202` with the installation; `POST /servers/{id}/apps/{installation}/runs/{run}/cancel` → `202` with the run. Scope `servers:write`, `Idempotency-Key` supported, `409` when it is not running. No field of v1 changes: a cancelled one is `failed` with the error *Cancelled by the billing system.* (or *… an administrator.*)
- The WHMCS client area and the WISECP Apps tool show *Cancel* next to an installing app and a running run, with a confirmation.

## Consequences

- A timeout or a cancel leaves the server with a stopped script, never a hidden one; a retry never runs next to an earlier attempt.
- A cancelled app is often half installed; the confirmation says so, and retry, uninstall or a reinstall clean up.
- Starting a script costs one more guest agent call (on Windows three: directories, `stop.ps1`, running it).
- Unverified on real guests: `systemctl kill` reaching installers that daemonize, `systemctl show -p ActiveState --value` (systemd 230+), and on Windows `Win32_Process` command lines of the runner, `Stop-ScheduledTask` and ending `msiexec` children.
