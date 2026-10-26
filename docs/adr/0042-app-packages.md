# 42. App packages (.proxapp)

- **Status:** Accepted (built 2026-10-27)
- **Date:** 2026-10-27
- **Supersedes:** ADR 0041 in part: the App Store and its git sources are removed; the recipe repository, the loader's checks and the repository's CI stay.
- **Builds on:** ADR 0015 (App Marketplace), ADR 0016 (stored versions), ADR 0018 (admin recipe editor, zip import and export).

## Context

ADR 0041 moved the recipes to `proxmotion-recipes` and let the panel read that repository with git (the App Store). That made every panel depend on outbound git, on a working temporary directory for git's output, and on GitHub being reachable. The maintainers prefer a file the administrator holds: an app is installed by uploading it, as with any other software package.

## Decision

1. **A `.proxapp` file is a zip of one recipe directory,** laid out as the editor's archives (ADR 0018 §5): a folder named after the slug with `recipe.yaml`, the scripts it uses and its `README.md`. It is read entry by entry in memory with the limits of `RecipeArchive` (no links, nothing outside the recipe, at most 1 MiB) and validated like a recipe directory.
2. **Installing a package:** *Apps → Install package* uploads the file; the next page shows the app, what installing it would do and the diff against the newest installed version, and *Install* publishes it (the administrator's approval). What it would do is one of: a new app, an update, installed already, an older version (stored, not offered), edited by an administrator (stored, held, ADR 0018 §2), a version in use changed (refused: a new version number is needed) or a custom app with the slug (refused). The validated package waits in the cache for 30 minutes, for the administrator who uploaded it only. Apps installed from packages keep the source `bundled`.
3. **Removing** an app installed from a package deletes it with its versions, unless installations use it: then it can only be deactivated.
4. **Exports are packages:** the editor's export downloads `<slug>-<version>.proxapp`. *Import app* still makes a custom draft from a package or a zip.
5. **The recipes repository keeps a package of every app** in `packages/<slug>.proxapp`, next to the sources, built with `php artisan apps:pack --path=<dir>`. Its CI runs `apps:pack --check`, which fails when a package is missing, has no recipe, or holds another version than its recipe (recipe.yaml and the files it uses; a README is not part of a version). `RecipeLoader` does not read `packages/` as a recipe.
6. **Line endings:** reading an archive turns CRLF into LF for everything but PowerShell scripts, which keep theirs (`*.ps1 eol=crlf` in the repository). Otherwise a Windows app's package would differ from the version already stored from its directory.
7. **Removed:** the App Store page, `app_catalog_sources` and `app_catalog_entries` (dropped by a new migration), the scheduled refresh, `RecipeFetcher` and `PROXMOTION_RECIPES_*`.

## Consequences

- The panel makes no outbound request for apps; an air-gapped panel installs apps the same way as any other.
- Updates are not discovered by the panel: an administrator installs the newer package.
- A package carries no signature: the trust is the administrator's choice of file and the review in the recipe repository's CI. Signed packages (ADR 0017 §3) remain possible later, as a signature next to the package.
- The panel's tests install the fixture recipes directly (`Tests\TestCase::syncRecipes()`); `AppPackageTest` covers upload, preview and install, `PackAppsTest` the command.
