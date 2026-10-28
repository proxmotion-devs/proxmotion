# 38. Panel settings page

- **Status:** Accepted (built 2026-10-22)
- **Date:** 2026-10-22

## Context

Every panel-wide setting so far was an `.env` variable read through `config/proxmotion.php`: the lowest VMID, the provisioning and move deadlines, the DNS name checked from inside, cross-cluster moves, the move bandwidth limit, protected networks, the drift grace period, console session limits and the documentation link. Changing one meant editing `.env` on the server, clearing the config cache and restarting the queue workers. That is fine for values tied to the installation, but not for operational choices administrators make while running the panel. Turning on cross-cluster moves (ADR 0037) is the obvious example.

## Decision

1. **A `settings` table** (`key`, JSON `value`, `updated_at`) holds the values saved on *Admin → Panel settings*. `App\Domain\Settings\PanelSettings` is the only place that reads it. `PanelSettings::KEYS` maps each setting to the `config/proxmotion.php` key it overrides, and `PanelSettings::get()` returns the saved value, else the config value. So a fresh or upgraded installation keeps its `.env` values until the page is saved, and tests that set `config()` keep working.
2. **Saving stores every setting on the page.** From then on the page decides and the `.env` variables are ignored. The page says so while some values still come from `.env`. A setting added later follows `.env` until the next save.
3. **Saved values are cached** (`Cache::rememberForever('panel-settings')`, forgotten on save). Queue workers, the scheduler and the web server therefore see a change at once, without a restart or `config:clear`.
4. **Security and deployment settings stay in `.env` only.** These are `PROXMOTION_REQUIRE_ADMIN_2FA`, `CONSOLE_PROXY_SECRET` and `CONSOLE_WEBSOCKET_URL`, plus Laravel's own (database, Redis, mail, Reverb, `APP_KEY`). A signed-in session must not be able to turn off two-factor authentication, and the console proxy secret is shared with another service. The page shows the state of the first two read-only.
5. **Changes are audited** as `settings.updated` with the old and new value of each changed setting. None of the settings is secret. Saving the same values again writes no entry.
6. The settings are validated with the same rules as before, or stricter. A VMID is 0 or at least 100, and the documentation link must be HTTPS. Protected networks use the location form's `NetworkList` rule.

## Consequences

- Administrators turn cross-cluster moves on or off, and change deadlines, without shell access.
- Two sources remain for the same values, but there is a clear order: the page, then `.env`, then the default in `config/proxmotion.php`. `.env.example` and `config/proxmotion.php` say which variables are only defaults.
- `config:cache` no longer freezes these values. The settings table must exist before the panel can read them, which the normal `migrate` step of installs and upgrades ensures.
- Branding settings (Phase 8) can be added to the same table and page.

## Addendum: generated root passwords (2026-10-26)

The root passwords the panel makes up itself (a new server ordered without one, a reinstall, a reset without a chosen password) follow a format set on the page. Four settings join the table: `password_length` (the random part, 12–64, default 20), `password_characters` (`lower`: letters and digits; `mixed`: also capitals, the default; `symbols`: also `!@#%+=.-_`), `password_prefix` and `password_suffix` (up to 32 characters each, from the same safe set). Their `.env` variables are `PROXMOTION_PASSWORD_LENGTH`, `PROXMOTION_PASSWORD_CHARACTERS`, `PROXMOTION_PASSWORD_PREFIX` and `PROXMOTION_PASSWORD_SUFFIX`; the defaults keep the previous behaviour.

The page refuses a format with fewer than three kinds of characters (lowercase, uppercase, digits, symbols), because Windows' default password policy refuses the others. Symbols exclude characters a shell or a console keyboard handles badly (`$ ` \ ' " ; & | < > ~ ^ *` and spaces). Passwords chosen by an administrator or a billing system are not affected.

## Addendum: one page per category (2026-10-27)

*Panel settings* is a menu entry that opens into its pages: *Servers* (creating, reinstalling and moving servers, generated passwords), *Network security* (protected networks), *Usage and quotas*, *Console, drift report and help*, and *Set in .env only* (shown only). `PanelSettings::CATEGORIES` lists the settings each page saves, and every setting is on exactly one page. `/admin/settings` opens the first page, and each page is `/admin/settings/{category}`.

Saving a page stores every setting of that page (point 2, per page). Settings of other pages sent with it are ignored, and their values stay as they were. Until every page has been saved, the settings of the pages not saved yet still follow `.env`, and each page says so for its own settings. Every save with changes is audited as `settings.updated`, so changing several pages gives one entry per page.
