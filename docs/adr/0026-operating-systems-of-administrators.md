# 26. Operating systems are the administrators' own list

- **Status:** Accepted
- **Date:** 2026-10-20
- **Supersedes:** ADR 0025 (never released). **Amends:** ADR 0024 §1–§5 and §8, ADR 0016 §3 (the runner's operating system check).

## Context

ADR 0024 and 0025 kept a catalog of distributions with families, derivatives "built on" others and versions of a base they are matched as (Pardus 23 → Debian 12). Recipes named families and distributions, and the panel worked out which apps fit a template from that. Administrators found it confusing: to add a template they had to understand families, compatible systems and base versions, and the panel decided for them which systems exist and which apps run where.

What administrators want is simpler: their own list ("Ubuntu" with 26.04 and 24.04, "Debian" with 13, 12 and 11), templates of those versions, and for each app the versions it is offered on.

## Decision

### 1. Operating systems and versions

`operating_systems` (name, Linux or Windows, a slug made from the name) and `operating_system_versions` (version). The list starts empty; administrators manage it in the *Operating systems* section of the OS templates page. Nothing else is asked: no family, no "built on", no base version. The platform picks the guest scripts (password, addresses, disk) and cannot change while templates use the system. A system or version that templates use cannot be deleted.

### 2. An OS template is of one version

`os_templates.operating_system_version_id`. The form is one select of the listed versions. `distribution` (the system's slug), `version` and `platform` are copied from it on save, so guest scripts, API v1 and older code keep working. `compatible_with` and `compatible_version` are gone. `family` stays only for API v1's `os_family`: the family of a well-known slug (`ubuntu` → `debian`), else `other`.

### 3. Administrators tick the versions each app is offered on

`app_recipe_operating_system_version`, ticked in one component (`AppSystemsPicker`) shown on the editor's *Requirements* tab and in *Apps → Choose systems*. A tick is saved at once and audited as `app.systems_changed`; it is not part of the recipe, so drafts and publishing do not touch it. (The first version of this change kept the editor's old OS picker, which wrote `requires.os` into the draft; administrators ticked there, published and saw nothing change. That picker is gone; `requires.os` is edited only in *Edit as YAML*.) Eligibility (catalog, install, order, reinstall, upgrade) asks only whether the template's version is ticked for the app; trial runs of drafts on test servers skip the check. A Linux app cannot be ticked for a Windows system and the other way round.

A recipe's `requires.os`, `min_versions` and `max_versions` stay in the recipe format but only **suggest** versions: the dialog marks them and *Tick the suggested ones* ticks them. A system is matched to the recipe's os-release IDs by its slug when the panel knows it, else by its name (`TemplateNameGuess`: "Rocky Linux" → `rocky`). New apps and new versions are ticked by nobody: an app is offered nowhere until an administrator ticks a version.

### 4. No operating system check inside the server

`server.json` carries no `os_release` list any more, so `run.sh` (unchanged) checks nothing against `/etc/os-release`. The administrator's choice is final.

### 5. *Check against a server* only shows

It reads `/etc/os-release` of a running server of the template and shows it next to the template's system and version, with whether `VERSION_ID` agrees. Nothing is suggested or saved.

### 6. API v1

`os-templates`: `distribution` and `distribution_version` are the system's slug and version; deprecated `family` is the slug with v1's old names (`archlinux`, `opensuse`) and `version` the version. `apps`: `os` lists the ticked versions grouped by system slug (`[{family: "ubuntu", versions: ["24.04"]}]`); `requires` still shows what the recipe says. No field was removed or renamed.

### 7. Migration

Each distinct `distribution` of the existing templates becomes a system (with a readable name for known IDs) and each distinct version a version of it. Every app is ticked for the versions of the templates it was offered on before, so customers see no change (Pardus 23 still gets the apps of Debian 12).

## Consequences

- Administrators decide everything about systems; the panel no longer knows "better" which apps run where.
- After adding a version (Ubuntu 26.04), the apps that should run on it have to be ticked; the toast says so.
- Recipes keep their OS requirements as documentation and suggestions; `apps:validate` still checks them.
