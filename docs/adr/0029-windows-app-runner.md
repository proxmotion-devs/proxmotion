# 29. Windows app runner

- **Status:** Accepted
- **Date:** 2026-10-20

## Context

Recipe format 2 (ADR 0016) already knows `requires.platform: windows` and `.ps1` scripts, and ADR 0016 §3 sketched a PowerShell runner "started detached with a scheduled task" for later. Until now Windows recipes loaded but were never offered (`AppEligibility` refused every recipe that was not `linux`), because everything after the upload assumed Linux: the directory `/var/lib/proxmotion/apps/<installation>`, `mkdir -m 700`, `systemd-run` with `run.sh`, the log reader (`dd | base64`) and `rm -rf` after an uninstall.

Windows servers exist since the Windows templates guide (`docs/guides/windows-templates.md`): the guest agent runs as `LocalSystem`, `guest-exec` and the file commands work, and the panel already runs PowerShell guest scripts there (ADR 0010). The maintainers want the App Marketplace on every Windows Server and Windows desktop release the panel offers, with the whole lifecycle (install, uninstall, upgrade, actions, logs) the Linux runner has, and three first apps: IIS with ASP.NET, SQL Server Express and Chocolatey.

## Decision

### 1. Same contract, other runner

Nothing the panel reads changes: the run's directory holds `inputs.json`, `server.json`, `status.json`, `outputs.json`, `outputs/` and `run.log`, with the same meaning and JSON as on Linux (ADR 0015, 0016 §4, 0021). Pollers, outputs, logs, webhooks, the API and the billing modules are platform-blind. Only the panel code that starts, reads and removes things inside the guest asks for the platform, and it takes it from the **recipe version** of the installation (`AppInstallation::platform()`), not from the server's template, which an administrator may change.

| | Linux | Windows |
|---|---|---|
| App directory | `/var/lib/proxmotion/apps/<uuid>` | `C:/ProgramData/Proxmotion/apps/<uuid>` (forward slashes: the Windows file API and PowerShell accept them; commands for `cmd.exe` get backslashes) |
| Library (`recipes/_lib`) | `proxmotion.sh`, `run.sh` | `proxmotion.ps1`, `run.ps1`, `start.ps1`, `read-log.ps1` |
| Directories | `mkdir -p -m 700` | `cmd /c mkdir` (an existing directory fails harmlessly, the following file write would not), then `icacls` on `C:\ProgramData\Proxmotion\apps`: inheritance removed, full control for `SYSTEM` (`*S-1-5-18`) and `Administrators` (`*S-1-5-32-544`) only. SIDs, so localized Windows names do not matter. Inputs may hold passwords; `C:\ProgramData` is readable by every user by default. |
| Start | `systemd-run --collect run.sh …` | `powershell -File start.ps1 <task> <script> <run dir>` (below) |
| Log chunk | `bash -c 'dd … \| base64 -w0'` | `powershell -File read-log.ps1 <file> <offset> <count>` (file, offset and length are arguments, never part of a command string) |
| After an uninstall | `rm -rf --one-file-system` | `cmd /c rmdir /s /q` |

PowerShell 5.1 runs `.ps1` files without a byte order mark in the ANSI code page, so the panel writes every `.ps1` file with a UTF-8 BOM when it has none (recipe texts are stored as written). JSON files the runner writes have **no** BOM (`[IO.File]::WriteAllText` with `UTF8Encoding($false)`; `Set-Content -Encoding UTF8` would add one); the panel still strips one when it reads `status.json` and `outputs.json`.

### 2. Started by a scheduled task

`guest-exec` waits for nothing, but a process it starts is not meant to outlive a restart, and Windows installers restart. `start.ps1` registers a task `\Proxmotion\app-<installation>-<attempt>` (`app-run-<run>` for uninstalls, upgrades and actions) that runs `run.ps1` as `SYSTEM` with the highest run level, no execution time limit and the battery conditions off, starts it and exits. It first unregisters Proxmotion tasks that are ready without a trigger (finished runs) or disabled (start-up tasks already used), so tasks do not pile up (the counterpart of `--collect`); start-up tasks waiting for a restart stay. The `ScheduledTasks` module exists on every system PowerShell 5.1 runs on.

### 3. The runner

`run.ps1 [script run-dir]` does what `run.sh` does: writes `Preparing`, waits for a busy Windows Installer (`Global\_MSIExecute`, at most 10 minutes like `pm_wait_for_boot`), writes `{}` to `outputs.json`, runs the script in a **child** `powershell.exe -NoProfile -NonInteractive -ExecutionPolicy Bypass -File run.ps1 … -Child` (the child calls the script and gives the exit code `-File` would, and writes the first line of an error nobody caught to `failure`, so the reason is the error, not the last line of PowerShell's report) with `PROXMOTION_APP_DIR` and `PROXMOTION_RUN_DIR` in its environment, appends everything it prints to `run.log` as UTF-8 (PowerShell's own `>>` writes UTF-16), and ends with `installed`/`succeeded` or `failed` with the first line of `failure`, else the last line of the log. Path checks are the same (no absolute script, no `..`).

Recipes dot-source `proxmotion.ps1` and get `Pm-Step`, `Pm-Input`, `Pm-AppInput`, `Pm-Server`, `Pm-Output`, `Pm-OutputFile`, `Pm-Fail`, `Pm-PublicAddress`, plus Windows helpers: `Pm-Exec` (a native program that fails on an unexpected exit code; native programs never stop PowerShell on their own), `Pm-IsServer`, `Pm-Feature` (Server: `Install-WindowsFeature`; desktop: `Enable-WindowsOptionalFeature -All`; returns whether a restart is needed), `Pm-Download` (HTTPS only, TLS 1.2 enabled), `Pm-FirewallPort` (one inbound rule per app, named after the installation, replaced when it exists) and `Pm-RequestRestart`. Scripts start with `$ErrorActionPreference = 'Stop'` and `Set-StrictMode -Version 3.0`, the counterpart of `set -euo pipefail`.

### 4. Restarts

A script that needs a restart (a Windows feature, SQL Server setup's pending reboot) calls `Pm-RequestRestart`, which exits with **3010**, Windows Installer's "restart required". The runner then counts the restart in `restarts` of the run directory, writes the step `Restarting`, adds a start-up trigger to its own task and restarts the computer. After the boot the task runs `run.ps1` again with the same arguments; it sees `restarts`, removes the trigger, keeps the log and runs the script **from the beginning**, so scripts must be idempotent (as on Linux) and keep anything they generated (passwords) in the app's directory. More than 3 restarts in one run fail it (`The script asked for a restart too often.`). `start.ps1` deletes `restarts` of a new run.

While the guest restarts the agent does not answer; the pollers already treat that as "try again next time" until the deadline. The deadline is not extended for restarts: recipes that restart set a timeout that includes them.

### 5. Eligibility

The `linux`-only rule in `AppEligibility` is replaced by: the recipe's platform must be the template's platform. Administrators could only tick versions of the recipe's platform already (ADR 0026), but trials skip the ticks (ADR 0018 §7), so a Windows draft can no longer be tried on a Linux test server or the other way round. Resources and network models are checked as before.

Which Windows versions an app is offered on stays the administrators' choice (ADR 0026). The bundled Windows recipes leave out `requires.os`, so every Windows version is suggested, and their READMEs name the releases they were written for. The runner needs Windows PowerShell 5.1: present on Windows Server 2016 and later and on Windows 10 and 11; Windows Server 2012 R2 only with WMF 5.1 installed.

### 6. Authors and the editor

`apps:make <slug> --platform=windows` writes a Windows skeleton (`install.ps1`, `uninstall.ps1`, an action). The loader keeps refusing `.sh` in Windows recipes and `.ps1` in Linux ones. The editor shows `.ps1` files with PowerShell highlighting and a new script gets the platform's extension and skeleton. Line ends are normalized to LF as for shell scripts (PowerShell does not mind). shellcheck does not apply. `PowerShellScriptsTest` parses every bundled `.ps1` file with PowerShell's own parser (`pwsh`, which GitHub's Ubuntu runners have; Windows PowerShell locally; skipped without either) and checks that recipe scripts set `$ErrorActionPreference = 'Stop'` and load the helpers. PSScriptAnalyzer is not added (a new dependency).

### 7. The first recipes

All official sources (no third-party mirrors):

- **iis** (`web`): IIS with ASP.NET 4.x and the management tools (`Web-Server`, `Web-Asp-Net45`, `Web-Mgmt-Tools` on Server; `IIS-WebServerRole`, `IIS-ASPNET45`, `IIS-ManagementConsole` on the desktop), optionally the ASP.NET Core Hosting Bundle from Microsoft's download link, a firewall rule for HTTP and HTTPS, the default site's URL as output. Uninstall removes the features (the site's files stay).
- **sqlserver-express** (`web`): SQL Server 2022 Express through Microsoft's installer (`SQL2022-SSEI-Expr.exe /ACTION=Download /MEDIATYPE=Core`, then the extracted `setup.exe /Q /ACTION=Install /FEATURES=SQLEngine`), instance `SQLEXPRESS`, mixed authentication with a generated `sa` password (output, secret), a static TCP port (input, default 1433) with a firewall rule, `reset_password` action through `System.Data.SqlClient` as `SYSTEM` (made a sysadmin by setup). SQL Server 2022 needs Windows Server 2016 / Windows 10 or later. Uninstall runs setup with `/ACTION=Uninstall` (databases stay on disk).
- **chocolatey** (`container`): Chocolatey through its official install script (`https://community.chocolatey.org/install.ps1`), actions `install_package`, `remove_package` and `list_packages`. Package ids come in a `string` input and the script refuses anything but `[A-Za-z0-9][A-Za-z0-9._-]*` before handing it to `choco` as one argument. Uninstall removes Chocolatey itself; installed packages stay. Chocolatey is not Microsoft's, but only this recipe uses it; the other recipes never depend on it.

## Consequences

- The Windows runner, the helpers and the three recipes have **only run against the fake** (`FakeProxmoxCluster` with Windows servers). Not verified on a real guest: the scheduled task started through `guest-exec` and surviving it; `icacls` on `C:\ProgramData\Proxmotion\apps`; the restart and resume through the start-up trigger; `read-log.ps1` chunks through `out-data`; output encodings (`[Console]::OutputEncoding` UTF-8 in the child); `cmd /c mkdir` with several directories; `Install-WindowsFeature` / `Enable-WindowsOptionalFeature` names on each release; SQL Server Express setup with `SAPWD` on the command line, the TCP port in the registry and `sqlservr` as `SYSTEM` being sysadmin; Chocolatey's script on Server 2016 (TLS 1.2). They are listed under *Not yet verified* in `AGENTS.md`.
- Recipe authors write the same idempotent scripts as on Linux, plus restart safety. A script that restarts without `Pm-RequestRestart` (for example an installer that reboots by itself) is not resumed: the run fails at its deadline.
- Billing modules and the API need no change: they already show any app the catalog lists.
