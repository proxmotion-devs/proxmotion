# 24. OS families and distributions

- **Status:** Accepted
- **Date:** 2026-10-19
- **Supersedes:** ADR 0022 (derivative distributions). **Amends:** ADR 0016 §3 (operating systems: families and groups).

## Context

An OS template has a field called `family`, picked from `ubuntu`, `debian`, `almalinux`, `rocky`, `centos`, `fedora`, `opensuse`, `archlinux`, `windows`, `other`. Most of these values are distributions, not families. The real families (the systems that share packages, tools and `ID_LIKE` in `/etc/os-release`) only existed inside `App\Domain\Apps\OsFamilies` as recipe "groups": `debian`, `rhel`, `suse`, `arch`. Administrators never saw them.

That one field had four jobs (the label, the platform, recipe matching and the key of version limits), and it caused these problems:

- **AlmaLinux, Rocky and Fedora were shown as families.** They are distributions of the RHEL family.
- **`debian` meant two things.** In a recipe's `os` list it is the group of Debian and Ubuntu. In `min_versions` it is the distribution. A recipe for Debian without Ubuntu had to be written as `os: [debian]` plus `max_versions: { ubuntu: '0' }` (the recipe editor's OS picker did this).
- **ADR 0022 turned the words around.** `distribution` only held the ID of a derivative (Pardus), so a Rocky template had no distribution. RHEL and Oracle Linux were stored as "derivatives of AlmaLinux", although RHEL is where that family starts.

## Decision

### 1. An OS template says what it is, like `/etc/os-release`

| Column | Meaning | Example (Pardus 23.4) |
|---|---|---|
| `distribution` | Always set: the `ID` of its `/etc/os-release` (lowercase letters, digits, `.`, `_`, `-`, at most 32 characters) | `pardus` |
| `version` | Its own `VERSION_ID` | `23.4` |
| `family` | **Derived:** the family of the distribution in the catalog (§2). Only a distribution the catalog does not know asks the administrator for it | `debian` |
| `platform` | Derived from the family: `windows` or `linux` (unchanged) | `linux` |
| `compatible_with`, `compatible_version` | The distribution and version apps are matched as, when its own versions are not those of the system it is built on (§3) | `debian`, `12` |

`distribution_version` is dropped: the template's own version is now just `version`.

### 2. A built-in catalog of distributions

`App\Domain\Infrastructure\Distributions` lists the distributions the panel knows. Each entry has a display name, a family (`App\Enums\OsFamily`) and, for distributions built on another one, that base. It replaces the groups of `OsFamilies`.

| Family (`OsFamily`) | Distributions | os-release IDs the family accepts in the guest |
|---|---|---|
| `debian` | `debian`, `ubuntu`; built on another: `pardus` (Debian), `linuxmint` (Ubuntu), `kali` (Debian) | `debian`, `ubuntu` |
| `rhel` | `almalinux`, `rocky`, `centos` (CentOS Stream), `fedora`; built on another: `rhel`, `ol` (Oracle Linux), both as AlmaLinux of the same version | `rhel`, `centos`, `fedora`, `almalinux`, `rocky`, `ol` |
| `suse` | `opensuse-leap`, `opensuse-tumbleweed` | `suse`, `opensuse` |
| `arch` | `arch` | `arch` |
| `windows` | `windows` | none |
| `other` | none (for a distribution the panel cannot place) | none |

A distribution that is not in the catalog can still be used. The administrator gives its os-release `ID` and picks its family. Adding a distribution to the catalog is a code change, the same as adding a family.

### 3. Compatible distribution

Recipes compare versions per distribution (`min_versions: { debian: '12' }`). A derivative with its own version numbers (Pardus 23 is Debian 12, Linux Mint 22 is Ubuntu 24.04) therefore needs the distribution and version it is compatible with.

- A catalog distribution built on another one **requires** it. The form fills in the base. For RHEL and Oracle Linux it also fills in the same version as AlmaLinux, because RHEL 9 is AlmaLinux 9. For the others the administrator enters the base version, because the panel keeps no table of derivative releases.
- A distribution outside the catalog **may** name one. Without it, the template only gets apps that name its own distribution (§4).
- Base systems (Debian, Ubuntu, AlmaLinux, …) and Windows have none.

### 4. Recipes name families and distributions separately

Format 2 `requires.os` becomes an object:

```yaml
requires:
  platform: linux
  os:
    families: [debian]            # every distribution of the family
    distributions: [almalinux]    # single distributions
  min_versions: { debian: '12', ubuntu: '22.04', almalinux: '8' }   # keys are distributions
  max_versions: { debian: '12' }
```

A template is matched as its **matching distribution and version**: `compatible_with` and `compatible_version` when set, otherwise `distribution` and `version`. A recipe fits when the platform matches and:

- **OS:** `os` is omitted (any OS of the platform), or the matching distribution is a catalog distribution of one of the `families`, or the matching distribution or the template's own distribution is in `distributions`.
- **Versions:** when the limits name the template's own distribution, its own version is compared with them. Otherwise the matching version is compared with the limits of the matching distribution. As before, a maximum is compared by as many parts as it has.
- Every key of `min_versions` / `max_versions` must be covered by `os`.

"Debian only" is now `os: { distributions: [debian] }`. The `ubuntu: '0'` marker is gone.

**Older recipes keep loading.** A list `os` (and the deprecated `families` and `capabilities`) is read with its old meaning: the group names `debian`, `rhel`, `suse` and `arch` become families, and any other name becomes a distribution. The old names `archlinux` and `opensuse` become the families `arch` and `suse`. Version keys `archlinux` and `opensuse` become `arch` and `opensuse-leap`. Format 1 recipes compare their `family` entries with the matching distribution (old names translated the same way). The recipe editor writes the new form when it saves. Bundled recipes move to the new form with a new version.

**Checked again inside the server** (ADR 0016 §3): `server.json` `os_release` holds the os-release IDs of the named families, the named distributions and, when the recipe names any operating system, the template's own distribution.

### 5. The admin form

The form has one *Operating system* select grouped by family, plus *Other…*. After it come:

- *Version*, with an example for the chosen distribution.
- The family, shown and not editable. It can only be picked for *Other…*, which also asks for the os-release `ID`.
- *Compatible with* and *its version*, shown only when the distribution needs or allows them.

The name is suggested from the distribution and version until the administrator types one. The guess for templates found on Proxmox fills in the same fields. The list shows the system as "Pardus 23.4 · Debian family · apps as Debian 12".

### 6. API v1 stays compatible

`OsTemplate` in API v1 keeps `family` and `version` with their old meaning: the distribution and version apps are matched as, with the old names (`archlinux`, `opensuse`, `other` for a distribution outside the catalog without a compatible one). `distribution` is now always set and `distribution_version` is the template's own version. The new field `os_family` gives the family. `family` is deprecated in the OpenAPI document.

`App` keeps `os` (format 2: the distributions the recipe covers, with any version) and `requires.os` (a flat list of the names it gives). It adds `requires.os_families` and `requires.distributions`.

### 7. Migration

Existing templates are converted in place:

- `family` = `ubuntu` / `debian` / `almalinux` / `rocky` / `centos` / `fedora` / `windows` → the same `distribution`.
- `opensuse` → `opensuse-leap`, `archlinux` → `arch`.
- A derivative of ADR 0022 keeps its `distribution`. Its old family and version become `compatible_with` and `compatible_version`, and its `distribution_version` becomes `version` (or the old version when it had none).
- `other` without a distribution → distribution `linux` (the default `ID` of os-release) with the family `other`.

### 8. Checking a template against a server

*Added 2026-10-19.* Each Linux template has a *Check against a server* action (`GET /admin/os-templates/{template}/os-release`, `App\Domain\Infrastructure\OsReleaseCheck`). It works like this:

- It reads `/etc/os-release` from an active server of the template through the guest agent (`file-read`), falling back to `/usr/lib/os-release` as os-release(5) says. Test servers are tried first, then the newest. The administrator can pick another of the latest 20.
- It compares `ID`, `VERSION_ID` and `ID_LIKE` with the template and shows the fields that differ:
  - A template version that only adds parts to `VERSION_ID` matches, because Debian reports `12` for 12.7.
  - The family of a distribution outside the catalog comes from `ID_LIKE`.
  - A catalog distribution built on another one gets that one as compatible. For RHEL and Oracle Linux this includes the same version.
- *Use the server's values* opens the edit form with them. Nothing is saved until the administrator saves the template.

To make trying a template before customers see it possible, the admin server wizard also offers **inactive** templates, listed last as "(inactive)". A server created from one, or reinstalled onto one, has no billing system and becomes a **test server** (ADR 0018 §7). The wizard hides the billing fields, and the panel refuses an inactive template for a server of a billing system (the request, `ServerCreator` and `ServerReinstaller`). The billing API still lists and accepts active templates only, so a customer never gets one. When no server runs the template yet, the check links to the wizard with the template preselected (`?os_template=`). After a Linux template is created, the panel says to do exactly that.

The form asks only what it cannot know:

- Nothing more for a base system.
- Nothing either for RHEL and Oracle Linux: they are AlmaLinux of the same version.
- Only the base's version for Pardus, Linux Mint and Kali.
- An optional compatible system and version for a distribution outside the catalog.

The name follows the system while it is still the suggested one, so applying a check renames "Debian 12" to "Pardus 23.4" but keeps a name the administrator wrote.

Only that fixed path is read: nothing typed by the administrator reaches the guest. Windows templates have no `/etc/os-release` and are not checked. A server whose guest agent does not answer is reported as such.

## Consequences

- The page, recipes and code use "family" and "distribution" the way `/etc/os-release` does. The `debian` ambiguity and the OS picker's marker are gone.
- RHEL and Oracle Linux are distributions of the RHEL family. Recipes written for AlmaLinux are offered on them through the compatible distribution.
- Every bundled recipe gets a new version for the new `os` form, although what it installs is unchanged.
- Billing modules see no change in v1. New consumers should read `os_family` and `distribution`.
- Administrators can confirm a template against `/etc/os-release` of one of its servers (§8), which catches a wrong distribution or version before apps are offered on it.
