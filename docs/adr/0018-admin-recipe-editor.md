# 18. Admin recipe editor: custom apps, copies, drafts and trial runs

- **Status:** Accepted (phases 1 and 2 built 2026-10-17, phase 3 built 2026-10-18, phase 4 built 2026-10-19)
- **Date:** 2026-10-17
- **Revised 2026-10-26 (ADR 0041):** bundled recipes come from `proxmotion-recipes` through the App Store, not from `panel/recipes` and `apps:sync`; the rules below for bundled apps otherwise stand.
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
- **Scripts:** install, uninstall, includes and actions (each with its own inputs and outputs), edited in a code editor (Monaco Editor, MIT; CodeMirror 6 until 2026-10-19); the shared helpers of `recipes/_lib` are shown read-only with their documentation.
- **YAML view** of the whole definition, and **import/export** of a recipe as a zip of the format 2 directory (same layout as `panel/recipes/<slug>`, ready for ADR 0017 catalogs).
- **History:** every version with who published it and when, and a diff between any two versions.

### 6. Audit

Saving a draft, publishing, copying, deactivating and deleting are written to `AuditLog` with the version and, for drafts and publishes, a unified diff of `recipe.yaml` and every script against the previous published version.

### 7. Trial runs and logs

- Administrators mark servers as **test servers** (a flag on the server, shown on its page). Test servers are ordinary servers without a billing system (*revised 2026-10-18*: "typically" became a rule, so a customer never sees what is tried).
- A draft can be installed, uninstalled and its actions run on a test server only; such installations are flagged `trial` and are never shown to billing systems.
- The runner already writes `run.log` (install and runs); the panel reads its last 64 KiB through the guest agent and shows it to administrators on the installation and run pages, also for normal installations. Logs are never sent to billing systems or webhooks (they may contain secrets the script printed).
- Publishing does not require a successful trial run; the editor shows whether the draft has one.

## Consequences

- Providers run their own catalog without releases; the project's recipes become a starting point they can copy.
- Root scripts on customer servers are only as safe as the administrators who write them: the editor warns about this, and every change is audited with its diff.
- More admin UI (a sizeable Vue page) and one frontend dependency (Monaco Editor, MIT, bundled locally and loaded only by editor pages; CodeMirror 6 at first).
- Order-time installs (5b) and signed catalogs (ADR 0017) reuse the same versions: an app chosen at order time is its published version, an imported catalog app is a custom app with a source.

## Phases

1. **Back end:** sources and copy, version statuses and publish, in-memory validation, deactivate and delete rules, audit with diffs; admin endpoints and tests. *Built 2026-10-17:* `RecipeEditor` (create from `RecipeSkeleton`, copy, save and discard the draft, publish with a patch/minor/major bump, delete), `RecipeLoader::validate()` for recipes in memory, `TextDiff` for the audit diffs (recipe.yaml without its version, and every file), `app_recipes.copied_from_version_id`, statuses `draft`, `published`, `archived` (earlier `approved` versions migrated), `apps:sync` archives the previous bundled version and skips a slug a custom app holds. Admin routes under `admin.apps.*` (`store`, `copy`, `draft.save` with YAML or a mapping plus files, `draft.discard`, `publish`, `destroy`) answer like the other admin forms (redirect, errors per field); the editor page comes with phase 2.
2. **Editor UI:** app list (bundled/custom, copy, new), editor with builders and code editor, YAML view, history and diffs. *Built 2026-10-17:* *Admin → Apps* has *New app* and *Edit/View*; `admin/apps/Edit` has tabs General, Compatibility, Inputs and outputs, Scripts (file list, lifecycle and includes, `_lib` helpers read-only), Actions, YAML (js-yaml) and History (versions and `admin.apps.diff`); code is edited in CodeMirror 6 (replaced by Monaco Editor on 2026-10-19, also used for the guest scripts of OS templates). The page sends recipe.yaml as YAML text with the files; bundled apps open read-only with *Copy*.
   *Simplified 2026-10-19:* the editor has five tabs: **Details** (name, category, descriptions, homepage, billing and licence in plain words), **Requirements**, **Customer form** (inputs and outputs), **Scripts & actions** and **Try & history** (trial runs and versions); the YAML view opens with *Edit as YAML* at the right of the tab bar. Requirements no longer show the platform, OS group and family checkboxes and free-text versions: the administrator ticks versions of the panel's active Linux OS templates (`osTemplates`), and `lib/osPicker.ts` writes plain families with the lowest and highest version (a family's first version also allows newer ones, *Also newer versions added later*). What is ticked is read back with the rules of `Recipe::supports()`; families without a template in the panel keep their range. Because `debian` in a recipe is the group of Debian and Ubuntu, Debian without Ubuntu is written as `os: [debian]` with `max_versions: { ubuntu: '0' }`. Memory and disk are entered in GB with presets (still stored in MiB); Windows and the NAT network model are not offered until they are supported (a recipe's `nat` is kept).
3. **Trial runs and logs:** test server flag, trial installations of drafts, `run.log` viewer. *Built 2026-10-18* (the log viewer came with ADR 0021): `servers.is_test`, set on the server page's Apps tab (`admin.servers.test`, audited `server.test_marked` / `server.test_unmarked`) and refused for a server of a billing system, so billing systems never see a test server at all. `AppTrials::try()` (editor tab *Trial runs*, `admin.apps.trial`) freezes the saved draft into a version with status `trial`, stored as `<draft version>-trial.N` with `trial_of_id` pointing at the draft (recipe.yaml inside keeps the draft's version, since the format allows no `-trial` suffix), reusing the newest copy while the draft is unchanged, and installs it through `AppInstaller::installTrial()` with `app_installations.is_trial` (audited `app.trial_requested`). Saving the draft later never changes a copy, so uninstalls, actions and retries of a trial run the scripts it installed. Trials skip the active flag and paid-app entitlements but keep every other eligibility check; they are never upgraded; the API leaves them out (`404`) and webhooks skip them. Trial copies are left out of the history; the editor lists the latest trials with whether each installed the draft as it is saved now. A custom app whose only installations are removed trials can still be deleted. *Reset after trial* (added 2026-10-19, `AppTrials::resetTestServer()`, `admin.servers.trial-reset`, confirmed in the browser, audited `server.trial_reset` besides `server.reinstall`) reinstalls an active test server from its current OS template with its SSH keys and a new root password, which removes every app on it. Trials are not a sandbox: until snapshots (Phase 7) allow a real rollback, this reinstall is the way back to a clean server. Not built: trying an upgrade of a draft on an installation of the published version.
4. **Import/export** as zip. *Built 2026-10-19:* `RecipeArchive` writes a version as `<slug>/recipe.yaml` plus its files (`admin.apps.export`, the published version by default, `?version=draft` or a version number; trial copies are not offered; audited `app.exported`) and reads uploads entry by entry with ZipArchive, never extracting them: at most 1 MiB per zip and 60 entries, recipe.yaml at most 64 KiB and each script at most 45 KiB (one guest agent write), UTF-8 only, files either at the top or in one folder, `README.md` skipped. It refuses absolute paths, `..`, backslashes, symbolic links and any file other than recipe.yaml and `.sh`/`.ps1` paths the format allows. *Admin → Apps → Import app* makes a new custom app with a draft 1.0.0 (`admin.apps.import`, slug and name from the recipe unless given), and *Import zip* in the editor replaces an app's draft (`admin.apps.draft.import`; its slug stays). Both are audited `app.imported` with the diff. An imported recipe that does not validate is kept as a draft with its errors listed, like a saved one.
5. Then **order-time and reinstall installs** (ADR 0016 phase 4 = Phase 5b) and the **catalog** of ADR 0017.
