# Installing Proxmotion on Debian or Ubuntu

`install.sh` installs the panel directly on a server, without Docker ([ADR 0055](../adr/0055-native-installer.md)). For Docker Compose, see [docker-install.md](docker-install.md).

> Tested so far only in containers with locally built archives, not on a real server, with a downloaded release or with Let's Encrypt.

## Requirements

- A fresh server of its own with Debian 12 or 13 or Ubuntu 22.04 or 24.04, amd64 or arm64. The panel becomes nginx's default site.
- 2 GB of memory and 10 GB of disk for the panel, its database and logs.
- For HTTPS with Let's Encrypt: a DNS name pointing to the server, and ports 80 and 443 reachable from the internet.
- Root access.

The installer adds the package repositories of Ondřej Surý (PHP 8.4) and NodeSource (Node.js 22), plus MariaDB's own on Ubuntu 22.04.

## Install

Download the installer of the release you want from the [releases page](https://github.com/proxmotion-devs/proxmotion/releases), read it, then run it:

```bash
curl -fsSLO https://github.com/proxmotion-devs/proxmotion/releases/latest/download/install.sh
sudo bash install.sh --domain panel.example.com --letsencrypt --email you@example.com
```

`--letsencrypt` accepts the Let's Encrypt Subscriber Agreement. Without it the panel is served over HTTP: use that only with a TLS proxy in front, and set `TRUSTED_PROXIES` in `.env` to its address.

The installer asks for the first administrator's name, email and password; a password left empty is generated and shown once. Without a terminal, give `--admin-name` and `--admin-email`. Do not save the installer's output to a file: it holds that password.

When it is done, sign in at the address it prints and set up two-factor authentication. *Admin → Help* shows the setup order.

### Options

| Option | |
|---|---|
| `--domain NAME` | The panel's host name; without it, any name over HTTP |
| `--letsencrypt` | A Let's Encrypt certificate for `--domain`, and HTTPS |
| `--email ADDRESS` | Contact address for Let's Encrypt |
| `--version X.Y.Z` | The release to install (default: the latest) |
| `--archive FILE` | Install from a downloaded `proxmotion-X.Y.Z.tar.gz` |
| `--admin-name`, `--admin-email` | The first administrator, without questions |

`--domain`, `--letsencrypt` and `--email` are remembered for later runs.

## Settings

The settings are in `/opt/proxmotion/shared/.env`, written once by the first install. Set the `MAIL_*` lines to your SMTP server for password resets and notifications. After changing the file, run the installer again: it rebuilds the cached configuration and restarts the services.

Most other settings are on *Admin → Panel settings*.

## Upgrades

Read the release notes, then run the installer of the new release, or any installer with `--version`:

```bash
sudo bash install.sh --version 0.2.0
```

The installer puts the panel into maintenance mode, switches to the new release, runs its migrations, restarts the services and opens the panel again. The billing systems get `503` for those seconds and try again. When a migration fails, the panel stays in maintenance mode and the installer says why. Fix the cause, then run the installer again.

## What goes where

| Path | |
|---|---|
| `/opt/proxmotion/current` | The running release (a link into `releases/`) |
| `/opt/proxmotion/releases/` | The last three releases |
| `/opt/proxmotion/shared/.env` | Settings |
| `/opt/proxmotion/shared/storage/` | Uploaded logos, logs, sessions |
| `/etc/nginx/sites-available/proxmotion`, `/etc/nginx/snippets/proxmotion-panel.conf` | nginx, written again on every run |
| `/etc/php/8.4/fpm/pool.d/proxmotion.conf` | The php-fpm pool, written again on every run |
| `/etc/systemd/system/proxmotion-*.service` | Worker, scheduler, Reverb, console proxy |

Files the installer writes are replaced on every run. Put your own nginx settings into separate files.

## Day to day

- Status: `systemctl status 'proxmotion-*'`.
- Logs: `journalctl -u 'proxmotion-*'` and `/opt/proxmotion/shared/storage/logs/`.
- Artisan: `cd /opt/proxmotion/current/panel && sudo -u proxmotion php artisan about`.
- Back up the `proxmotion` database (`mariadb-dump proxmotion`), `/opt/proxmotion/shared/.env` and `/opt/proxmotion/shared/storage/`. `APP_KEY` in `.env` decrypts the stored Proxmox tokens and passwords: without it, a restored database is unusable.
- The database user `proxmotion@localhost` may only read and write rows and change the schema (`SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX, REFERENCES` on `proxmotion.*`). Every run of the installer sets exactly these grants again, so extra ones you add to that user are removed.
