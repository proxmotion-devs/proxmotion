# 25. Operating systems managed by administrators

- **Status:** Accepted
- **Date:** 2026-10-20
- **Amends:** ADR 0024 §2 (the catalog) and §5 (the admin form).

## Context

ADR 0024 put the distributions the panel knows in code (`Distributions::CATALOG`), and the first form asked administrators for the version, the family of an unknown distribution and the system a derivative is compatible with. That was too much to ask. A list of releases fixed in code (Ubuntu 24.04, Debian 12, …) was simpler, but it was still the panel deciding what administrators can offer: a new release (Ubuntu 26.04) or a distribution the list does not have needed a panel update.

## Decision

### 1. Distributions and their releases live in the database

`distributions` (os-release `ID` as `slug`, name, family, optional `base` with `same_version`, order) and `distribution_releases` (version, `base_version` for a derivative with its own numbers). The migration fills them with the list the panel shipped with. `Distributions` keeps its static methods and reads them through `DistributionCatalog`, loaded once per request or job and forgotten when a distribution or release is saved or deleted.

### 2. Administrators manage them on the OS templates page

The *Operating systems* section lists each system with its family, the system it is built on and its versions as chips:

- *Add operating system*: name, ID (fixed once saved, templates refer to it), family, optionally *Built on* (a system of the same family not built on another one) and *Its versions are the same* (RHEL 9 is AlmaLinux 9).
- *+ Version*: a version, which also covers its point releases (12 covers 12.7; `rolling` covers every version). A derivative with its own numbers picks the release of its base it is matched as (Zorin 17 → Ubuntu 22.04).
- A version can be removed at any time: templates keep their version and the form shows it as an extra option. A system used by an OS template, or that another system is built on, cannot be deleted.
- Editing a system applies its family and base to its templates at once (`DistributionChanges`), as adding a version does.

### 3. The OS template form stays one list

The form lists every version of every system (ADR 0024 §5) plus *Other…* (ID and version, family `other`). The family and the system apps are matched as are set by the server from the catalog.

## Consequences

- New releases and distributions need no panel update.
- Recipes keep naming families and distributions by os-release ID. A recipe naming a distribution administrators deleted simply matches no template.
- Name guesses for templates found on Proxmox also look for the IDs of added systems.
- The catalog is read from the database wherever eligibility is checked; unit tests touching it became feature tests.
