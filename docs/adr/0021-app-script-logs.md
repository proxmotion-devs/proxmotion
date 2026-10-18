# 21. Logs of app scripts

- **Status:** Accepted
- **Date:** 2026-10-18
- **Builds on:** ADR 0015 (detached installer, `status.json`, `apps:poll`), ADR 0016 §4 (uninstalls, upgrades and actions in `runs/<run>/`).

## Context

`run.sh` already sends everything an install, uninstall, upgrade or action script prints to `run.log` in the run's directory inside the server. The panel never read it. It read only `status.json`, once a minute, so administrators saw a step name and a percentage. When a script failed, they saw the last line of the log. Finding out what went wrong meant logging in to the customer's server. A retry also emptied the file, so the earlier attempt's output was gone.

## Decision

1. **One log per script run** (`app_logs`): one row per install attempt (`attempt`) and one per uninstall, upgrade or action (`app_run_id`). It is opened when the runner has been started (`AppStarter`, `AppRunPipeline`). Starting a new attempt closes logs of earlier attempts that were never finished.
2. **Copied through the guest agent, in pieces.** The panel runs `dd` on the file from the offset it has read so far and pipes the output through `base64`. The file name, offset and length are positional arguments of a fixed `bash -c` script, never part of the script, so nothing is interpolated. Each command reads at most 48 KiB (base64 keeps the agent's output intact), and each read takes at most eight commands. While the script runs, only whole lines are kept, because the last line may still be being written. When the script has ended, the rest of the file is read. A missing file reads as empty.
3. **When it is read:** `apps:poll` copies new lines every minute. The pollers copy the rest when a script succeeds, fails or times out, and close the log. The log is closed with what was read when the guest no longer answers, and when the server is reinstalled or terminated.
4. **Watched live by administrators.** The server page has a *Watch* / *Log* button per installation (with a choice of attempts) and per run. The dialog asks `GET /admin/app-logs/{log}?cursor=` every 3 seconds while the script runs. That request runs the same poll as `apps:poll` (at most every 2 seconds per log), so the step and progress follow live and a script that ended is finished at once. The answer holds only the text after the viewer's cursor, or the whole log (`reset`) when the text cannot be continued. Pollers take a per-installation or per-run lock, so `apps:poll` and a viewer never finish a script twice. No WebSocket server is needed: Reverb is not set up yet, and a few seconds of delay is fine for installer output.
5. **Masked and encrypted.** Before text is stored, the values of secret inputs (type `password`) of the installation and the run are replaced with `••••••••`. When the script ends, the secret text outputs it reported are masked too, over the whole log, because installers often print a generated password before writing it to `outputs.json`. Values shorter than 4 characters are not masked. Logs are stored with the `encrypted` cast and are never logged or sent to billing systems.
6. **Readable and bounded.** Colour and cursor codes are removed, invalid UTF-8 is replaced, and of a line redrawn with carriage returns (progress bars) only its last state is kept. A log keeps at most 1 MiB: past that, the start (up to 128 KiB of whole lines) and the newest output stay, and the middle is left out with a note saying how much was dropped.
7. **Administrators only.** The billing API and the modules do not get logs. Customers keep seeing the short error message. Logs are pruned after 90 days (`model:prune`, daily).
8. `run.sh` writes its own steps and the final outcome (`==> [HH:MM:SS] Installed` / `Failed: …`) to `run.log`, and `pm_step` prefixes its lines with the time.

## Consequences

- Administrators can follow an installation as it happens and read why it failed without logging in to the server, also for earlier attempts.
- Each poll of a running script costs one or two more guest agent commands. Watching a log costs one poll every few seconds per open dialog.
- Masking only knows the values the panel knows. A secret an installer makes up and never reports as an output stays in the log, visible to administrators only.
- If logs are offered to billing systems later, that needs a new scope and its own masking review.
