# 52. Install and upgrade commands

- **Status:** Accepted
- **Date:** 2026-11-02

## Context

Phase 8 adds two ways to run the panel in production: Docker images published to GHCR and a native installer for Debian/Ubuntu (AGENTS.md §3.9). Both need the same steps after the code is in place: check that PHP and the services are usable, create the application key, migrate, create the first administrator, cache the configuration and, on later versions, run the new migrations and restart the long-running processes. Until now these steps lived in `composer setup` (development only), `php artisan migrate --seed` (whose seeder creates `admin@example.com` with the password `password` and must never run in production) and the README.

Writing the steps twice, once in a container entrypoint and once in a shell script, would let them drift apart. They belong in the panel, where they can be tested.

## Decision

1. **Two Artisan commands** that the Docker images, the native installer and administrators doing it by hand all run: `php artisan proxmotion:install` and `php artisan proxmotion:upgrade`. The `.env` contract is the same for every path.
2. **Requirements first** (`App\Domain\Installation\Requirements`): both commands check before they change anything. PHP 8.3 or newer. The extensions the code uses (`ctype`, `curl`, `dom`, `fileinfo`, `filter`, `intl`, `mbstring`, `openssl`, `pdo`, `tokenizer`, `xml`, `zip`), the PDO driver of the configured connection, and `redis` when a queue, the cache, the maintenance state (§5) or sessions use phpredis. Writable `storage/` and `bootstrap/cache/`. `APP_KEY`. A working database connection, and Redis when it is used. A missing requirement stops the command with the list. Some checks only warn and never stop it:
   - `pcntl`, which lets queue workers stop jobs at their timeout.
   - `CONSOLE_PROXY_SECRET`, without which the browser console does not work.
   - In production also `APP_DEBUG` on, an `APP_URL` without `https`, a `log` or `array` mailer, and a `sync` queue.
3. **`proxmotion:install`** is safe to run again:
   - **Application key:** generated into `.env` when `APP_KEY` is empty and the file has an empty `APP_KEY=` line. A file that holds a key is never touched: with an empty `APP_KEY` in the environment, which wins over the file, `key:generate` would put the new key in front of the old one. Without a `.env` file (containers get their settings from the environment), the key must be given and `key:generate --show` prints one.
   - **Migrations:** `migrate --force`.
   - **First administrator:** created only while there is none. Name and email come from `--admin-name` and `--admin-email` or are asked for. The password is asked for when the command is interactive; when it is left empty, or the command runs without questions, a random 24-character password is generated and printed once. The administrator is created by the same action as *Administrators → New* (`App\Actions\CreateAdministrator`, its rules and `Password::defaults()`; a generated password skips the strength rules and their breach lookup, which needs no network for a random password), counts as verified like administrators created there, must set up two-factor authentication at the first sign-in (`PROXMOTION_REQUIRE_ADMIN_2FA`), and is audited as `user.created` without an actor (`source: install`). No email is sent: nobody else is there to read it.
   - **Caches:** in production, `optimize` (configuration, events, routes and views).
   - **No seeder runs:** `admin@example.com` stays a development account.
4. **`proxmotion:upgrade`**, run after the code changed:
   - **Requirements:** checked first. A missing one stops the upgrade before the panel goes into maintenance mode.
   - **Maintenance:** the panel goes into maintenance mode (`down --retry=60`); when it cannot, the upgrade stops there and changes nothing. Admin pages, the billing API (`503`, which the billing modules treat as unreachable and retry) and the console proxy's internal routes answer `503`. Queue workers and the scheduler pause, since Laravel runs neither in maintenance mode.
   - **Migrations:** `migrate --force`. When a migration fails the panel stays in maintenance mode and the command says so: opening it on a half-migrated database could do more harm than the outage. The administrator fixes the cause and runs the upgrade again, or opens the panel with `php artisan up`.
   - **Caches:** rebuilt in production (`optimize`).
   - **Restarts:** signalled with `queue:restart`, plus `horizon:terminate` and `reverb:restart` when those commands exist. Paused workers check the signal, quit, and their supervisor (systemd, Docker's restart policy) starts them on the new code before the panel opens.
   - **Failures after the migrations** (caches, restart signals) also keep the panel in maintenance mode with the same message; exceptions are reported and printed, never left as a bare stack trace with the panel closed.
   - **Reopening:** the panel leaves maintenance mode. A maintenance mode that an administrator set before the upgrade is kept.
5. **Several machines or containers** share the maintenance state only through the cache driver (`APP_MAINTENANCE_DRIVER=cache` with Redis). The file driver keeps it per filesystem. The production images and the installer's documentation will set this.
6. **A cached configuration is never used.** A process that loaded the configuration from `bootstrap/cache/config.php` (cached by the previous version) would check and migrate with stale settings: missing new keys, an old database address. Both commands then remove the cache and run themselves again in a fresh PHP process with the same options, which reads the configuration files; production caches are built again at the end (`optimize`).
7. **One run at a time.** Both commands hold the cache lock `proxmotion:prepare` (at most an hour), and a second run waits for it up to 15 minutes. Containers starting together therefore migrate one after the other, and the second install finds the administrator the first created. On a fresh database whose cache is the `database` store, the lock table does not exist yet and the first install runs without the lock: parallel first installs need a Redis cache, which the production images will use.

## Consequences

- One tested place for installing and upgrading. The Docker entrypoint and `install.sh` stay thin wrappers.
- Administrators created by the installer and on the admin page share one action, so their rules cannot drift apart.
- A generated administrator password appears once in the command's output. Installers must not write that output to a log file.
- The panel is unavailable to the billing systems for the length of the migrations. Most upgrades take seconds; a long migration should say so in the release notes.
- Not done yet: the production images, the native installer, branding and the Virtualizor importer (the rest of Phase 8). There is no version number yet: the upgrade runs whatever migrations are new, not a version-to-version path.
- Tested with the in-memory SQLite database of the test suite only; not run against MariaDB/MySQL, Redis or a real deployment.
