# 55. Native installer

- **Status:** Accepted
- **Date:** 2026-11-03

## Context

Besides Docker Compose (ADR 0054), the panel is meant to run directly on a Debian or Ubuntu server (AGENTS.md §3.9), as many hosting providers run their tools. It needs nginx, PHP with php-fpm, MariaDB, Redis, the queue worker, the scheduler, Reverb and the console proxy. Installing these by hand is long and easy to get wrong, and an upgrade has to replace the code without serving the old code against the new database or the other way round. `proxmotion:install` and `proxmotion:upgrade` (ADR 0052) cover the panel's own steps; the operating system around them was missing.

## Decision

1. **`deploy/installer/install.sh`**, run as root, for **Debian 12 and 13 and Ubuntu 22.04 and 24.04** on amd64 and arm64. Other systems are refused. Running it again upgrades to a newer version, and running it with the installed version repairs the configuration. Options:
   - `--domain`, `--letsencrypt` and `--email`, remembered in `shared/installer.conf` for later runs.
   - `--version`, or `--archive` for a downloaded archive. The default is the latest GitHub release.
   - `--admin-name` and `--admin-email` for an install without questions.
2. **Packages:**
   - nginx and Redis from the distribution.
   - PHP 8.4 (php-fpm, cli and the extensions of ADR 0052 §2) from Ondřej Surý: packages.sury.org on Debian, the `ondrej/php` PPA on Ubuntu. The same builds everywhere, and the distributions' own PHP is 8.2 or older on three of the four.
   - Node.js 22 from NodeSource for the console proxy. The distributions' own Node.js is older than the proxy's `engines`.
   - MariaDB from the distribution, except on Ubuntu 22.04: there MariaDB's own 11.4 LTS repository, since 22.04 ships 10.6 and the panel needs 10.11 or newer.
   - `certbot` with `--letsencrypt`.

   Repositories are added with their own keyrings in `/etc/apt/keyrings` (`signed-by`).
3. **Release archives:** `proxmotion-<version>.tar.gz` holds:
   - the panel as in the panel image (dependencies without development packages, the built frontend);
   - the console proxy with its dependencies;
   - `installer/`: the script and the templates it writes.

   The archive is built by the production Dockerfile's `release` stage, so archive and images cannot differ. The release workflow attaches it to the GitHub release with a `.sha256` file and `install.sh`. The installer checks the checksum. The checksum comes from the same release, so it catches broken downloads, not a replaced release; signed releases are not done.
4. **Layout:**
   - The code is unpacked into `/opt/proxmotion/releases/<version>/`. `/opt/proxmotion/current` is a symlink, replaced atomically (`mv -T`).
   - `/opt/proxmotion/shared/` holds what every release shares: `.env`, `storage/`, `installer.conf` and `console-proxy.env`, each linked into the release.
   - The code belongs to root. The system user `proxmotion` runs the panel and may write only `storage/` and its release's `bootstrap/cache/`. `.env` is `root:proxmotion 0640`, so the panel cannot rewrite its own settings.
   - The last three releases are kept.
5. **Services:**
   - php-fpm runs a pool `proxmotion` as that user on `/run/php/proxmotion.sock`.
   - nginx serves `current/panel/public` and passes only `index.php` to PHP, through `$realpath_root`, so a switched release takes effect at the next request. It forwards `/app/` to Reverb on `127.0.0.1:8080` and `/console-ws` to the console proxy on `127.0.0.1:8090`.
   - The console proxy reaches the panel through a second nginx server on `127.0.0.1:8081` that answers only `/internal/`: its POST requests must not meet the HTTPS redirect.
   - systemd units run the worker (`queue:work`, stopped gracefully within 31 minutes like the Docker worker), the scheduler, Reverb and the console proxy, sandboxed (`ProtectSystem`, `PrivateTmp`, `NoNewPrivileges`).
   - Templates are written again on every run, so an upgrade can change them: local edits belong in other files.
6. **TLS** with `--letsencrypt`: `certbot certonly --webroot` and an nginx site of the installer's own (`site-https.conf`). It is not `certbot --nginx`, which would rewrite a file the installer writes again on every run. The certificate is renewed by certbot's timer, and a deploy hook reloads nginx. Without `--letsencrypt` the panel is served over HTTP, for a TLS proxy in front (`TRUSTED_PROXIES`, ADR 0054).
7. **First install:**
   - The installer creates the database and its user with a random password.
   - It writes `.env` once: production settings, a generated `APP_KEY` and Reverb and console proxy secrets, Redis for cache, sessions and the queue, the file maintenance driver, and the `log` mailer (to be set by the administrator).
   - It runs `proxmotion:install`: interactively in a terminal, otherwise with the administrator options.
   - It switches `current` and starts the units.
   - Later runs never change `.env`.
8. **Upgrade:**
   - The new release goes into maintenance mode. The mode is a file in the shared `storage/`, so the old release, still current, answers 503 too.
   - `current` is switched and php-fpm reloaded.
   - `proxmotion:upgrade` runs: migrations, caches, and restart signals that the worker and Reverb follow onto the new release. The scheduler and the console proxy are restarted.
   - The panel opens again.

   Neither release ever serves the other's database. A maintenance mode an administrator set before stays. A failed upgrade leaves the panel in maintenance mode, says which release was current before, and warns that going back after migrations may not work.

## Consequences

- One command installs a complete panel on a fresh server, and the same command upgrades it.
- The installer assumes a server of its own: it makes the panel nginx's default site and removes the distribution's default site.
- Changes to `.env` (mail, trusted proxies) take effect after running the installer again, which rebuilds the cached configuration.
- Tested in systemd containers on Docker Desktop with locally built archives:
  - Debian 12: a first install, an upgrade to a version with a new migration (maintenance mode while it ran, every unit on the new release afterwards), a run with the installed version, and a run while an administrator had closed the panel (it stayed closed).
  - Ubuntu 22.04 and 24.04 and Debian 13: a first install.
  - Each time: sign-in page, the Reverb handshake through nginx, the internal route refusing requests without the secret, the scheduler and a queued job.

  Not tested:
  - Let's Encrypt.
  - A download from a published release and its checksum.
  - arm64.
  - A real server (rather than a container) and a console relayed to Proxmox.
