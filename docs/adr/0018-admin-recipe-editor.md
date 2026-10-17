# 18. Admin recipe editor: custom apps, copies, drafts and trial runs

- **Status:** Accepted (phases 1 and 2 built 2026-10-17)
- **Date:** 2026-10-17
- **Builds on:** ADR 0015 (App Marketplace), ADR 0016 (recipe format v2, stored versions, uninstall and actions). Distribution between panels: ADR 0017.

## Context

Apps come only from `panel/recipes`, written by the project and shipped with panel releases. Providers want an OVH-style catalog they control: offer their own apps, change how a bundled app is installed (packages, configuration, defaults), and do it without a release or a shell on the panel host.

OVH offers three things: a provider catalog of "distribution + application" images that customers pick at order or reinstall time (customers cannot change them), and, for customers, post-installation scripts and cloud-init user data. Proxmotion already runs recipes after provisioning and through the billing modules; what is missing is the provider side of the catalog: writing and changing recipes in the panel. Order-time installs (the OVH "distribution + application" choice) are Phase 5b, decided separately.

## Decision

### 1. Who

Only panel administrators (2FA, ADR 0007) create and change apps. There are no roles today; every administrator may edit apps and every change is audited (section 6). A finer permission (`apps.manage`) can come with roles later. **Customers never provide scripts**: AGENTS.md §7 rule 9 stays — only recipe scripts that an administrator saved run inside a VPS, with customer values passed as `inputs.json`.

### 2. Sources

- `bundled`: shipped in `panel/recipes`, synced by `apps:sync`, and **editable in place** (*revised 2026-10-17*; the first version made them read-only, so changing how a bundled app installs needed a copy under a new slug). Editing a bundled app opens a draft on the same app; publishing it makes a local version numbered after the bundled version it started from (`1.2.1-local.1`, which sorts before `1.2.1`), stored with `origin: local` (bundled versions from `panel/recipes` have `origin: upstream`). While the published version is local, `apps:sync` stores new bundled versions as archived without offering them (`held` in its output); the editor shows "a newer bundled version is available" so the administrator can compare in the history and merge by hand, and **Back to bundled version** publishes the newest bundled version again (audited, the draft is dropped, installations keep theirs). Bundled apps cannot be deleted, only deactivated.
- `custom`: written in the panel. Slugs are unique across sources; a custom app never replaces a bundled one.
- **Copy** (still possible, no longer needed to change a bundled app): any version of an app (bundled or custom) can be copied into a new custom app with a new slug and name; the copy starts as a draft `1.0.0` with the same definition and scripts. Panel updates never touch copies; the editor shows when the bundled original has a newer version, so the provider can compare and merge by hand.

### 3. Versions: draft → published → archived

- `app_recipe_versions.status` gets `draft`, `published` and `archived` for custom apps (bundled versions are `published` when synced; `pending_review`/`rejected` stay for ADR 0017 sources).
- An app has at most one draft. Saving the editor updates the draft; a draft is never offered to customers or billing systems.
- **Publish** validates the draft (section 4), gives it the next version (patch, minor or major bump chosen by the admin, higher than every earlier version) and makes it the app's `latest_version_id`. The previously published version becomes `archived`; installations keep the version they were installed with (ADR 0016 §2), so uninstalls and actions of older installations still use their scripts.
- Published and archived versions are immutable. Editing a published app opens a new draft copied from it.
- An app is deactivated, not deleted, while installations use any of its versions; an app without installations can be deleted with its versions.

### 4. Validation

The editor saves a recipe definition and its files; the same rules as `apps:validate` apply. `RecipeLoader` gets a method that validates a definition and a file map in memory (the directory loader uses it too), so bundled and custom recipes can never diverge. A draft may be saved while invalid (errors are listed); it cannot be published or tried.

### 5. Editor

*Admin → Apps → New app / Copy / Edit:*

- **General:** name, slug (fixed after the first publish), category, description (en, tr), homepage, maintainers.
- **Compatibility:** platform, operating systems (checkboxes for the groups debian, rhel, suse, arch and for single families, ADR 0016 §3) and minimum versions per family, minimum CPU, memory and disk, network models, install modes.
- **Inputs and outputs:** a builder for keys, types, labels (en, tr), defaults, options, limits, secret flags.
- **Scripts:** install, uninstall, includes and actions (each with its own inputs and outputs), edited in a code editor (CodeMirror 6, MIT); the shared helpers of `recipes/_lib` are shown read-only with their documentation.
- **YAML view** of the whole definition, and **import/export** of a recipe as a zip of the format 2 directory (same layout as `panel/recipes/<slug>`, ready for ADR 0017 catalogs).
- **History:** every version with who published it and when, and a diff between any two versions.

### 6. Audit

Saving a draft, publishing, copying, deactivating and deleting are written to `AuditLog` with the version and, for drafts and publishes, a unified diff of `recipe.yaml` and every script against the previous published version.

### 7. Trial runs and logs

- Administrators mark servers as **test servers** (a flag on the server, shown on its page). Test servers are ordinary servers, typically ones without a billing service.
- A draft can be installed, uninstalled and its actions run on a test server only; such installations are flagged `trial` and are never shown to billing systems.
- The runner already writes `run.log` (install and runs); the panel reads its last 64 KiB through the guest agent and shows it to administrators on the installation and run pages, also for normal installations. Logs are never sent to billing systems or webhooks (they may contain secrets the script printed).
- Publishing does not require a successful trial run; the editor shows whether the draft has one.

## Consequences

- Providers run their own catalog without releases; the project's recipes become a starting point they can copy.
- Root scripts on customer servers are only as safe as the administrators who write them: the editor warns about this, and every change is audited with its diff.
- More admin UI (a sizeable Vue page) and one frontend dependency (CodeMirror 6, MIT).
- Order-time installs (5b) and signed catalogs (ADR 0017) reuse the same versions: an app chosen at order time is its published version, an imported catalog app is a custom app with a source.

## Phases

1. **Back end:** sources and copy, version statuses and publish, in-memory validation, deactivate and delete rules, audit with diffs; admin endpoints and tests. *Built 2026-10-17:* `RecipeEditor` (create from `RecipeSkeleton`, copy, save and discard the draft, publish with a patch/minor/major bump, delete), `RecipeLoader::validate()` for recipes in memory, `TextDiff` for the audit diffs (recipe.yaml without its version, and every file), `app_recipes.copied_from_version_id`, statuses `draft`, `published`, `archived` (earlier `approved` versions migrated), `apps:sync` archives the previous bundled version and skips a slug a custom app holds. Admin routes under `admin.apps.*` (`store`, `copy`, `draft.save` with YAML or a mapping plus files, `draft.discard`, `publish`, `destroy`) answer like the other admin forms (redirect, errors per field); the editor page comes with phase 2.
2. **Editor UI:** app list (bundled/custom, copy, new), editor with builders and code editor, YAML view, history and diffs. *Built 2026-10-17:* *Admin → Apps* has *New app* and *Edit/View*; `admin/apps/Edit` has tabs General, Compatibility, Inputs and outputs, Scripts (file list, lifecycle and includes, `_lib` helpers read-only), Actions, YAML (js-yaml) and History (versions and `admin.apps.diff`); code is edited in CodeMirror 6. The page sends recipe.yaml as YAML text with the files; bundled apps open read-only with *Copy*.
3. **Trial runs and logs:** test server flag, trial installations of drafts, `run.log` viewer.
4. **Import/export** as zip.
5. Then **order-time and reinstall installs** (ADR 0016 phase 4 = Phase 5b) and the **catalog** of ADR 0017.
