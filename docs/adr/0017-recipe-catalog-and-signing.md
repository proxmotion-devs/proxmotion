# 17. Recipe catalog, sources and signing

- **Status:** Accepted (phase 2 is built in an interim form by ADR 0041: the official recipes live in their own repository and are downloaded in the App Store)
- **Date:** 2026-10-17
- **Builds on:** ADR 0015 (App Marketplace), ADR 0016 (recipe format v2 and stored versions).

## Context

Recipe scripts run as root inside customers' servers. Today they come only from the panel's repository, so a new app needs a panel release. An open-source project needs recipes that the community can publish and providers can add from their own sources, without making it easy to run a script nobody reviewed.

## Decision

### 1. Sources

A **source** (`app_sources`) is where recipe versions come from:

| Kind | Content | Default trust |
|---|---|---|
| `bundled` | `panel/recipes/` of the installed release | approved on `apps:sync` |
| `official` | the project's catalog (configured by default, can be removed) | signed by the project's key; new versions need an admin's approval unless auto-approve is on for this source |
| `remote` | any catalog an admin adds (a provider's own, a third party's) | signed by the key pinned when the source was added; every version needs an admin's approval |
| `custom` | recipes edited in the panel (Phase 5c editor) | written by an administrator in the panel; drafts are published by an administrator (ADR 0018) |

- A slug is unique per source; the catalog shows the source, and the same slug from two sources are two apps. A source can never replace a bundled recipe (ADR 0015).
- Only administrators add, remove and configure sources; every change is audited.

### 2. Catalog format (static)

A catalog is a static directory served over HTTPS, built from a Git repository by CI:

```
index.json            # list of recipes and versions with package URLs, sizes and SHA-256
index.json.minisig    # minisign signature of index.json
packages/<slug>-<version>.tar.gz
packages/<slug>-<version>.tar.gz.minisig
```

- `index.json` carries `format`, `generated_at`, `expires_at` (a stale index is refused, against replaying old versions) and per version the package URL, size, SHA-256 and minimum panel version.
- Packages are plain tar.gz of the recipe directory (ADR 0016 §1); the loader extracts them in memory, refuses links, absolute paths and files over the size limits, and validates them like `apps:validate`.
- The project keeps its catalog in a separate repository (`recipes`), with CI that validates, shellchecks and runs install tests on test VMs before publishing.

### 3. Signing

- **minisign (Ed25519)**, verified in PHP with `sodium` (no extra dependency; `ext-sodium` is part of PHP). Both `index.json` and each package are signed; the package's SHA-256 must also match the index.
- A source's public key is pinned when the source is added (the admin pastes it or confirms its fingerprint). Key rotation: the index may carry a new key signed by the old one; the panel asks the admin to accept it.
- A version that fails verification is never stored. The verified signature and key id are stored with the version (`app_recipe_versions.signature`, `signed_by`).

### 4. Review and approval

- `php artisan apps:fetch` (scheduled, e.g. every 6 hours) downloads new versions of every source into `app_recipe_versions` with status `pending_review`.
- Admins see pending versions under *Admin → Apps* with the **diff** of `recipe.yaml` and every script against the previous approved version (or the full content for a new recipe), and approve or reject; both are audited. `official` sources may be set to auto-approve.
- Only approved versions are installable, upgradable (ADR 0016) or listed to billing systems. Rejecting a version never touches installations of earlier versions.

## Consequences

- New apps reach providers without a panel release; providers stay in control of what runs on their customers' servers.
- The panel makes outbound HTTPS requests to its sources (configurable; none for an install with only `bundled` and `custom`).
- The project needs a release key kept offline and a catalog repository with CI.
- The admin editor (5c) writes `custom` versions through the same storage and review screens.

## Phases

1. Sources, `apps:fetch`, index and package verification, review screen with diffs (after ADR 0016 phase 1).
2. The official catalog repository with CI (validation, shellcheck, install tests) and the bundled recipes moved there; the panel keeps bundled copies for offline installs.
3. Admin recipe editor (`custom` source) with trial runs on a test server.
