# 23. Guest script drafts and run logs

- **Status:** Accepted
- **Date:** 2026-10-19
- **Builds on:** ADR 0010 (guest scripts per OS template).

## Context

Administrators write guest scripts for their templates (ADR 0010), but could not tell whether a script worked:

- A run kept only the first line of the script's error output, as a note on the task step. Standard output, the full error output and the exit code were dropped.
- The Scripts page started a trial run and showed nothing afterwards: the result was on the task of the server page, if the administrator went to look.
- A script had to be saved before it could be tried, and saving made it live: every following operation on every server of the template ran it.

## Decision

1. **Drafts.** `guest_scripts` gets `draft_content`; `content` (the published script) may be null while only a draft exists, and the event keeps its default meanwhile. *Save draft* writes the draft (`guest_script.draft_saved`), *Publish* moves it into `content` (`guest_script.updated`, as before), *Discard draft* drops it (`guest_script.draft_discarded`), *Reset to default* drops the published script and keeps a draft (`guest_script.reset`). All are audited with the old and new content. Operations only ever run the published script or the default; a draft runs only in a trial (`guest_script` task with `draft: true`).

   A draft is a script an administrator saved on the template and that the audit log records, so it stays within AGENTS.md §7 rule 9. It is written to its own path in the guest (`/usr/local/sbin/proxmotion-<name>-draft`, `C:\ProgramData\Proxmotion\<name>-draft.ps1`), so boot units that run the published script (ADR 0009) never pick it up. Its inputs are the same files as the published script's.

2. **Trace.** A trial on a Linux template may run the script with `sh -x` (`trace: true`): every command is printed to the error output before it runs. A traced run's note skips the trace lines. PowerShell has no equivalent that keeps the exit code as simply, so Windows templates offer no trace.

3. **Run logs.** Every run of a guest script, during an operation or a trial, gets a `guest_script_logs` row: server, template, task, event, source (`default`, `published` or `draft`), trace, status (`running`, `succeeded`, `failed`), exit code, a message when the script never ran or was killed (agent unreachable, unknown MAC, signal), the script that ran, its inputs, and stdout and stderr (encrypted, the last 64 KiB of each). The output of `password_reset` scripts is never kept, since a script may print the password it got on standard input; its exit code is. Logs are pruned after 90 days with the app logs (ADR 0021).

4. **Scripts page.** Each event lists its latest eight runs on the template's servers (status, server, trial or operation, source, time, duration, exit code). *Details* loads one run (`GET /admin/guest-script-logs/{log}`) with its output, inputs and script. After a trial is started, and while a run is listed as running, the page refreshes the runs every 2 seconds. A run that is still `running` after its task ended (the worker died) is shown as failed.

## Consequences

- Administrators see what a script printed and why it failed without logging in to the customer's server, also for runs during real operations.
- A script can be tried on a test server as often as needed before any operation uses it.
- Scripts run synchronously through `exec` (at most 5 minutes), so their output arrives when they end; unlike app scripts (ADR 0021) there is no live output.
- Not verified on a real guest: `sh -x` on each supported distribution (dash prints `+ ` like bash).
