# 43. Custom guest scripts per OS template

- **Status:** Accepted
- **Date:** 2026-10-27

## Context

ADR 0010 gives every OS template five fixed script slots, one per event (`provisioned`, `addresses_changed`, `disk_resized`, `password_reset`, `hostname_changed`); four of them replace a default that the panel needs (addresses, disk, hostname, password). Administrators asked for more: scripts of their own, as many as they need (install a monitoring agent, register with a backup service, clear a cache), each run either when they choose on one server or at certain events.

## Decision

1. **Custom scripts.** An OS template has, besides the five fixed slots, a list of custom scripts (`custom_scripts`): a name (unique per template), the content, a draft (ADR 0023: only published content runs outside trials), a position, and its triggers:
   - **manual:** listed under *Run script* on the server page of the template's servers;
   - **events:** any of the five events of ADR 0010. A custom script never replaces the fixed slot or its default: it runs after it, at the same moments, also when the slot has no script (`provisioned` has no default, a password reset without a script is set by the guest agent).

   A script without triggers is kept and can still be tried.

2. **Who runs them.** Only administrators: from the server page (manual scripts, published content) and from the template's *Scripts* page (trials of the draft or the published content on one server, as in ADR 0023). Billing systems and their API tokens can neither see nor run them; AGENTS §7.9 stays as it is (scripts administrators saved on an OS template, audited).

3. **Order and failure.** At an event the fixed slot runs first, then the custom scripts bound to it in their order, each as its own task step (`script:<name>`). A failing custom script leaves a note on its step and never fails the operation, also after a password reset (the password is set and stored first). A manual run is a `guest_script` task, which fails when the script fails, as trials do. If the guest agent does not answer, the remaining custom scripts of that event are noted, not waited for one by one.

4. **Inputs.** Like ADR 0010: `server`, `hostname`, `mac`, plus `trigger` (the event, `manual` or `trial`) and `addresses` (all of the server's addresses); at an event also that event's inputs (`reason`, `disk_mib`, `previous_hostname`, `username`, …). The password of a reset is never given to a custom script. Inputs go to `/etc/proxmotion/script-<id>.json` and `.vars` (Windows: `C:\ProgramData\Proxmotion\script-<id>.json`), the script to `/usr/local/sbin/proxmotion-script-<id>` (`script-<id>.ps1`); drafts get a `-draft` suffix. The time limit of ADR 0010 applies (5 minutes).

5. **Logs.** Runs are `guest_script_logs` rows with `custom_script_id` (null once the script is deleted) and the script's name at the time; `event` is the triggering event, null for manual runs and trials. The template page lists the latest runs per custom script; the server page lists the latest manual runs of the server.

6. **Audit.** `custom_script.created`, `.updated` (name and triggers), `.draft_saved`, `.published`, `.draft_discarded`, `.deleted`, `.moved`; `custom_script.run` for manual runs and trials.

## Consequences

- Administrators add and remove template-specific work without touching the defaults the panel relies on.
- A slow event-bound script makes the operation slower by up to 5 minutes per script; longer work belongs in the background (ADR 0010).
- Customer-triggered scripts (through the billing modules) are not offered; they would need typed inputs, a per-script flag and an API, and are left for a later ADR.
