# 16. Recipe format v2: versions, lifecycle and operating systems

- **Status:** Accepted (phases 1, 2 and 4 built 2026-10-17 and 2026-10-18)
- **Date:** 2026-10-17
- **Builds on:** ADR 0015 (App Marketplace). Distribution and trust: ADR 0017.

## Context

ADR 0015 shipped a first App Marketplace: three bundled recipes, installed after provisioning through the guest agent. For an open-source panel whose recipes will come from a community catalog, other sources and an admin editor (ADR 0017), the format is too narrow:

- **One row per app.** `app_recipes` keeps only the latest copy of a recipe. An installation cannot be uninstalled or upgraded with the scripts it was installed with, and a recipe change silently changes what a retry runs.
- **Install only.** There is no uninstall, upgrade or action (adding a VPN client); `actions` is declared in the schema but never run.
- **Compatibility is a list of OS names.** `os: [{family: ubuntu, versions: [...]}]` must be edited in every recipe when a template is added, and cannot express Windows or the RHEL family without new runners.
- **Order-time and reinstall installs (Phase 5b)** are not possible yet.

The format has to settle before third parties write recipes: changing it later breaks their recipes.

## Decision

### 1. Recipe package

A recipe is a directory (later an archive, ADR 0017) with `recipe.yaml` and its scripts. `recipe.yaml` gets `format: 2`; v1 recipes (no `format`) keep loading and are read as v2 with `install` only.

```yaml
format: 2
slug: wireguard
version: 1.2.0                   # semver of the recipe, not of the app
name: WireGuard
category: vpn
description: { en: "...", tr: "..." }
maintainers: [{ name: Proxmotion, url: https://example.com }]
homepage: https://www.wireguard.com
license: none                    # none | external
billing: free                    # free | paid
requires:                        # matched against the template's family (section 3)
  platform: linux                # linux | windows
  os: [debian, rhel]             # families or groups; omitted = any OS of the platform
  min_versions: { ubuntu: "22.04", debian: "12" }  # optional, per family
  max_versions: { debian: "12" }                   # optional, per family
resources: { min_cores: 1, min_memory_mib: 512, min_disk_mib: 5120 }
network_models: [bridged, routed, nat]
install_modes: [order, post]
inputs: [...]                    # unchanged types (ADR 0015 §5)
outputs: [...]
firewall: [...]
lifecycle:
  install: { script: install.sh, timeout_minutes: 10 }
  uninstall: { script: uninstall.sh, timeout_minutes: 5 }
  upgrade: { script: upgrade.sh, timeout_minutes: 10, from: ">=1.0.0 <1.2.0" }
actions:
  - key: add_client
    label: { en: Add a client, tr: İstemci ekle }
    script: actions/add-client.sh
    inputs: [{ key: name, type: slug, required: true }]
    outputs: [{ key: client_config, type: file, filename: client.conf, secret: true }]
    timeout_minutes: 2
```

- Script paths are relative, inside the package, and match `^[a-z0-9][a-z0-9_-]*(/[a-z0-9][a-z0-9_-]*)*\.(sh|ps1)$`; the loader refuses anything else. Shared code the scripts source is listed under `includes` (e.g. `includes: [lib.sh]`) and written with them.
- `upgrade.from` is a semver range of installed versions the script can upgrade; outside it, the panel offers no upgrade (the customer reinstalls the app).
- Action outputs belong to the run (`GET /servers/{id}/apps/{installation}/runs/{run}`, `apps:secrets`); they are not merged into the installation's outputs, so several runs of the same action (two VPN clients) never overwrite each other.

### 2. Stored versions

- `app_recipes` keeps one row per slug and source (identity, active flag, latest version). New table **`app_recipe_versions`**: recipe, version, definition, files, checksum (SHA-256 of the canonical package), `signature` and `signed_by` (ADR 0017), `status` (`draft | published | archived` since ADR 0018; `pending_review | rejected` for ADR 0017 sources), `reviewed_by`, `reviewed_at`.
- An installation points to the **version** it was installed with (`app_recipe_version_id`). Uninstall, actions and retry always use that version; upgrade moves the pointer when it succeeds.
- Versions are never deleted while an installation uses them.

### 3. Operating systems: families and groups

*Revised 2026-10-17.* The first version had templates list capabilities (`systemd`, `apt`, `dnf`, `bash`, …) that recipes asked for. That asked administrators for something the template's family already says, and a wrong tick made an app fail inside the server. Recipes now name operating systems the way Linux distributions describe themselves in `/etc/os-release` (`ID`, and `ID_LIKE` for the distribution they derive from):

- `requires.os` lists template families (`ubuntu`, `rocky`, …) or **groups**: `debian` (Debian, Ubuntu), `rhel` (RHEL, AlmaLinux, Rocky, CentOS, Fedora), `suse` (openSUSE), `arch` (Arch Linux). Omitted: any OS of the platform. `min_versions` gives the lowest version per family; every family in it must be covered by `os`. `max_versions` (added 2026-10-17) gives the highest, for software whose vendor has no packages for newer releases yet (FASTPANEL on Debian 13); a template version is compared by as many parts as the maximum has, so `12` covers `12.7` and `24.04` covers `24.04.1`, and the same coverage rule applies. The map from groups to families and to os-release IDs lives in one place (`App\Domain\Apps\OsFamilies`).
- Templates need nothing besides `platform`, `family` and `version`; `os_templates.capabilities` is dropped. A recipe fits a template when the platform matches, the family is covered by `os` and the version is at least its `min_versions` entry and at most its `max_versions` entry. The other checks of ADR 0015 §6 (resources, network model, install mode, billing) stay.
- **Checked again inside the server:** the panel writes the accepted os-release IDs to `server.json` (`os_release`); `run.sh` compares them with `ID` and `ID_LIKE` of `/etc/os-release` before any script runs and fails with "This app does not support <PRETTY_NAME>" instead of half-installing on a mislabelled template.
- Linux recipes may rely on systemd and bash (every supported family has them). Recipes written with the first version keep loading: `families` is read as `os`, package-manager capabilities as groups (`apt` → debian, `dnf` → rhel, `zypper` → suse, `pacman` → arch); the editor converts them when the recipe is saved.
- Helpers in `recipes/_lib` have a package-manager-neutral `pm_pkg_install` so one script covers `apt` and `dnf` when the recipe wants to.
- **Windows** (later phase): a PowerShell runner (`_lib/run.ps1`, started detached with a scheduled task) writing the same `status.json`; `.ps1` scripts are allowed only for `platform: windows`. The format is ready for it now; the runner is built when Windows recipes are.

### 4. Lifecycle operations

All run like the install of ADR 0015: a short `app_run` server task writes the package of the installation's version (scripts, includes, helpers, install `inputs.json`, `server.json`) into `/var/lib/proxmotion/apps/<installation>/`, the run's own `inputs.json` and `status.json` into `runs/<run>/`, and starts `run.sh <script> <run directory>` detached; `apps:poll` follows the run's `status.json`. Helpers write status and outputs to `PROXMOTION_RUN_DIR`; `pm_input` reads the run's inputs and `pm_app_input` the install inputs. One run at a time per installation and one install or uninstall at a time per server.

| Operation | API | Status flow |
|---|---|---|
| Install | `POST /servers/{id}/apps` (unchanged) | `queued → installing → installed \| failed` |
| Uninstall | `DELETE /servers/{id}/apps/{installation}` | `installed\|failed → uninstalling → removed \| uninstall_failed` |
| Upgrade | `POST /servers/{id}/apps/{installation}/upgrade` | `installed → upgrading → installed (new version) \| upgrade_failed (old version kept)` |
| Action | `POST /servers/{id}/apps/{installation}/actions/{key}` | the installation stays `installed`; the run has its own record (`app_runs`: kind, action, inputs, status, step, error, outputs) |

- Uninstall without an `uninstall` script is refused (`422`); recipes without one say so in the catalog.
- Outputs of failed operations are never stored; secrets follow ADR 0015 (encrypted, `apps:secrets`).
- Webhooks: `app.uninstalled`, `app.uninstall_failed`, `app.action_succeeded`, `app.action_failed` with the installation and the run, without outputs; `app.upgraded` comes with phase 3.
- Billing modules (WHMCS, WISECP) get uninstall, upgrade (shown when the catalog has a newer version the installed one can upgrade from) and action forms built from the recipe's action inputs.

### 5. Order-time and reinstall installs (Phase 5b)

- `POST /servers` and `POST /servers/{id}/reinstall` accept `app` and `app_inputs` (*revised 2026-10-18*: `inputs` would be ambiguous next to the server's own fields). The panel checks eligibility against the chosen template, resources and network model before anything is reserved (`422` under `app`, invalid inputs under `app_inputs.<key>`), stores a `queued` installation with `mode: order` in the same transaction as the server or the reinstall task, and the create/reinstall pipeline starts it as its last step once the guest agent answers (ADR 0004 post-boot hook; *revised 2026-10-18*: a step of the same task instead of a queued `install_app` task, so nothing else can start in between). A failed app start never fails the server's creation or the reinstall. A reinstall drops an app still queued for a system that never booted. `GET /apps?mode=order` lists the apps that can come with a new system.
- Right after the first boot cloud-init may still be updating packages, so the runner (`run.sh`, `pm_wait_for_boot` in `_lib/proxmotion.sh`) first waits for `cloud-init status --wait` and for the dpkg/apt or rpm locks to be free, at most 10 minutes for both, shown as the step *Waiting for the system to finish starting* (*added 2026-10-18*). On a server that booted long ago it returns at once. The wait counts against the recipe's `timeout_minutes`, so install timeouts should leave room for it.
- The WHMCS configurable option `app` maps to the slug and app inputs come from custom fields named `app_<key>`. WISECP's order answers are fixed by the module, so it has the answers `app` and `app_inputs` (one `key=value` per line) (*revised 2026-10-18*). Paid apps: ADR 0019.

### 6. Validation and tooling

- `recipe.schema.json` becomes `recipe-v2.schema.json`; `apps:validate` checks either version, script paths, `upgrade.from` ranges, that action/upgrade/uninstall scripts exist, and runs shellcheck when available.
- `php artisan apps:make <slug>` writes a recipe skeleton (v2, all lifecycle scripts) for authors.

## Consequences

- Installations keep working across recipe updates; uninstall and upgrade use the right scripts.
- Adding a distribution means tagging templates, not editing recipes; the RHEL family works as soon as recipes use `pm_pkg_install` or `dnf`.
- More API surface and billing-module work (WHMCS, WISECP) per lifecycle operation; the OpenAPI document and both modules change in the same releases.
- v1 recipes stay valid; bundled recipes move to v2 with uninstall scripts and WireGuard/OpenVPN client actions.
- Not decided here: where versions come from and how they are trusted (ADR 0017).

## Phases

1. **v2 format and stored versions:** schema, loader (v1 compatible), `app_recipe_versions`, installation → version, OS requirements and eligibility, `apps:make`; bundled recipes to v2. *Built 2026-10-17:* `recipes/_schema/recipe-v2.schema.json`; `apps:sync` stores each version (published; ADR 0018 archives the previous one) and refuses a changed version that installations use (raise the version; an unused one is replaced in place); a retry keeps the installation's version; `os_templates.capabilities` (later replaced by OS families and groups, §3); `pm_pkg_install` in `_lib/proxmotion.sh`; the bundled recipes are format 2, version 1.1.0, and accept Ubuntu ≥ 22.04 and Debian ≥ 12 through apt. Windows recipes load but are not offered until the runner exists. The API's `App` gained `format` and `requires`. Uninstall, upgrade and action scripts are validated and stored but not run yet.
2. **Uninstall and actions:** runner operations, API, webhooks, admin UI, WHMCS and WISECP; WireGuard/OpenVPN client actions. *Built 2026-10-17 in the panel:* `app_runs` (`AppRun`, `AppRunner`, `AppRunPipeline`, `RunPoller`), `app_run` task, uninstall statuses `uninstalling` and `uninstall_failed`, API (`DELETE /servers/{id}/apps/{installation}`, `POST .../actions/{key}`, `GET .../runs/{run}`), the four webhooks, admin buttons and dialogs; a finished uninstall deletes the installation's directory in the guest. WireGuard and OpenVPN 1.2.0: several clients (add, revoke, list), uninstall; Docker 1.2.0: uninstall (images and volumes kept). The WHMCS client area and the WISECP Apps tool show action forms, uninstall and the latest runs (`GET .../runs`) with downloads of run outputs. **Not done yet:** runs against a real Proxmox, WHMCS or WISECP.
3. **Upgrade:** version ranges, API, billing modules.
4. **Order-time and reinstall installs (5b).** *Built 2026-10-18:* `app_installations.mode`, `AppStarter` (shared by `install_app` and the post-boot step), order eligibility (`AppEligibility::forOrder`), `ServerCreator`/`ServerReinstaller` with an app, admin create form and reinstall dialog (`OrderedAppOptions`), API fields and `mode=order`, WHMCS (`app` option, `app_<key>` fields, reinstall form) and WISECP (order answers, *Reinstall with an app* in the Apps tool); paid apps and entitlements with ADR 0019. **Not done yet:** runs against a real Proxmox, WHMCS or WISECP.
5. **Windows runner** when the first Windows recipe is written.
