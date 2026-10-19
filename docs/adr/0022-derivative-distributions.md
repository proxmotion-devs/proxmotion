# 22. Derivative distributions on OS templates

- **Status:** Superseded by ADR 0024 (OS families and distributions)
- **Date:** 2026-10-19
- **Amends:** ADR 0016 §3 (operating systems: families and groups).

## Context

An OS template has a `family`, picked from a fixed list (`ubuntu`, `debian`, `almalinux`, `rocky`, `centos`, `fedora`, `opensuse`, `archlinux`, `windows`, `other`), and a `version`. That one field does three jobs: it picks the platform (`windows` or Linux), it says which recipes fit (recipes name families or groups like `debian` = Debian + Ubuntu), and with `version` it is what recipes compare `min_versions` / `max_versions` against.

That works for the distributions on the list. It does not work for a distribution built on one of them, such as Pardus (`ID=pardus`, `ID_LIKE=debian`, Pardus 23 built on Debian 12), Linux Mint (on Ubuntu) or Oracle Linux and RHEL (binary compatible with AlmaLinux):

- With the family `other` no app is ever offered, although most apt recipes run fine on Pardus.
- With the family `debian` the template claims to be something it is not, and its version is wrong for recipes in both directions: Pardus 23 compared as Debian 23 passes every `min_versions` and fails FASTPANEL's `max_versions: {debian: "12"}`, although it is Debian 12 underneath. An older Pardus based on Debian 10 passes `min_versions: {debian: "12"}`.
- Nothing on the template records what the system really is.

## Decision

1. **`family` and `version` name the system apps are matched against.** For a derivative that is the system it is based on: Pardus 23 is `debian` `12`. Recipes, eligibility (`Recipe::supports()`), the recipe editor's OS picker and the platform are unchanged.
2. **Two new, optional columns say what the template really is:** `os_templates.distribution` (the `ID` of its `/etc/os-release`: lowercase letters, digits, `.`, `_`, `-`, at most 32 characters) and `distribution_version` (its own version, free text). Both are null for the systems on the family list. A distribution cannot be a name from the family list (a Debian template is just `debian`), and Windows templates have none.
3. **Unknown base: no apps.** A derivative whose base the administrator does not know uses the family `other`, as before: it can be provisioned and its guest scripts run, but no app fits it.
4. **Recipes name bases only.** Recipes cannot ask for or exclude a derivative. A recipe that does not work on a derivative fails in its script, like on any mislabelled template.
5. **The check inside the server accepts the derivative too.** The panel writes the os-release IDs the recipe accepts to `server.json` (ADR 0016 §3). When the template has a distribution and the recipe names operating systems, its ID is added, because the panel already decided that the base fits, and some derivatives do not name their base in `ID_LIKE`.
6. **The admin form** has *Based on another system*: when ticked, it asks for the distribution and its version, and the family and version fields become *Based on* and *Base version*. The name guess for templates found on Proxmox knows a few derivatives (Pardus, Linux Mint, Kali on Debian or Ubuntu; Oracle Linux and RHEL on AlmaLinux). It fills in the base version only where it is the same number (RHEL 9 = AlmaLinux 9) and leaves it to the administrator otherwise.
7. **API v1** adds `distribution` and `distribution_version` (nullable) to `OsTemplate`. `family` and `version` keep their meaning for billing modules: the base system. This is an addition, not a breaking change.

## Consequences

- Pardus, Mint, Oracle Linux and similar systems get the apps of their base without editing recipes and without labels that lie.
- Whether a derivative really runs a recipe is the administrator's call when they name its base. The panel does not check it. A wrong base version shows up as a failed install with the script's log (ADR 0021).
- Base versions of derivatives have to be looked up by the administrator. The panel does not keep a table of derivative releases, because it would go out of date.
- Not done: reading `/etc/os-release` from the first server of a template to confirm or suggest the base. It can be added later without changing this model.
