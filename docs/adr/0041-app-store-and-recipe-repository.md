# 41. App Store and the recipe repository

- **Status:** Superseded in part by ADR 0042 (2026-10-27): the App Store and its git sources were removed; the recipe repository (decision 1), the loader's checks (6) and the repository's CI (7) stay.
- **Date:** 2026-10-26
- **Builds on:** ADR 0015 (App Marketplace), ADR 0016 (recipe format v2 and stored versions), ADR 0017 (recipe catalog, sources and signing: this ADR builds its phase 2 in an interim form), ADR 0018 (admin recipe editor).

## Context

The recipes lived in `panel/recipes`, next to the panel's code. Every new app or fix to one needed a panel change and a release, and the panel repository collected scripts that have nothing to do with the panel. The App Store needs recipes that can come from a place other than the panel's own repository, without an administrator running a command on the server to take them in.

## Decision

1. **The recipes move to their own repository,** `proxmotion-devs/proxmotion-recipes`, with the history of `panel/recipes` (split with `git subtree split`). The panel keeps what it writes into servers or validates against: `panel/recipes/_lib` (runners, helpers, stop scripts) and `panel/recipes/_schema` (the JSON Schemas).
2. **A source** is a recipe repository read at a ref, or a local directory read as it is. The official source is configured with `.env` variables (security and deployment values, ADR 0038): `PROXMOTION_RECIPES_REPOSITORY`, `PROXMOTION_RECIPES_REF`, and `PROXMOTION_RECIPES_PATH` for a local directory (recipe authors, tests, air-gapped installs; a relative path is relative to `panel/`). The table `app_catalog_sources` holds one row per source with the commit and the last error; only `official` exists for now.
3. **Reading a source** is a shallow `git fetch` of the ref into `storage/app/recipe-sources/<name>`. Only `https://` and `ssh://` addresses are fetched and the ref must not look like an option. The recipes that read are stored in `app_catalog_entries` with their scripts, so a download never reads the checkout again. A source that cannot be read keeps its entries and shows why. Reading runs when an administrator presses *Refresh* and from the schedule every six hours (`RefreshAppCatalog`).
4. **Downloading is the administrator's approval.** Nothing from a source is installable until it is downloaded. A download stores the version as ADR 0015 and 0016 describe (published, with its scripts). An update shows the diff against the downloaded version first. A version that installations use cannot change without a new version number. An app an administrator edited in place keeps its edits: the new version is stored, held, and not offered (ADR 0018 §2).
5. **Removing** an app deletes it with its versions, unless installations use it: then it can only be deactivated (`RecipeEditor::delete()` has the same rule for custom apps). The recipe stays in the catalog and can be downloaded again. An app whose recipe left the source is deactivated at the next read.
6. **Recipes from a checkout are read carefully.** The loader refuses links, script paths with `..` or absolute paths, and files that resolve outside their recipe directory: a link to `.env` in a recipe must never be stored and written into a server.
7. **The recipes repository has its own CI** (since ADR 0042 on packages only)**:** the schema, the version rule for changed recipes (`apps:check-versions` against the base revision), shellcheck, and a parse of every PowerShell script. The panel's schemas and runner scripts come from the panel's `main` branch.

## Consequences

- New apps and fixes reach providers without a panel release; the administrator decides when each one is downloaded.
- The panel makes outbound git requests to GitHub on refresh. Without access the refresh reports an error and nothing else changes; a local directory (`PROXMOTION_RECIPES_PATH`) removes the dependency.
- The trust in the official source is HTTPS to the project's repository, review in its CI and the administrator's download. Signed catalogs and the review of other sources (ADR 0017 §3–4) remain for later.
- The panel's tests use four fixture recipes in `panel/tests/Fixtures/recipes` and never fetch. The full recipe set is validated in the recipes repository's CI.
- **Deviations from ADR 0017 phase 2:** the catalog is a git checkout, not a signed static index; a refresh runs in the request that starts it (a shallow fetch of a small repository), so the page needs no polling; `app_recipes.source` stays `bundled` for apps of the official source, and no column links an app to its source yet.

## Phases

1. **Built (2026-10-26):** the repository move, sources and the catalog entries, refresh and schedule, the App Store page (download, update with changes, deactivate, remove), the loader's checks for checkouts.
2. **Open:** administrator-added sources with their keys (ADR 0017 §1 and 3), the approval queue for new versions of other sources (ADR 0017 §4), and a static signed index (ADR 0017 §2) for sources that are not git repositories.
