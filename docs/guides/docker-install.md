# Installing Proxmotion with Docker Compose

The production images run the panel, its queue worker, scheduler, Reverb, the console proxy and nginx, with MariaDB and Redis next to them ([ADR 0054](../adr/0054-production-docker-images.md)). You need a Linux host with Docker Engine and the Compose plugin (2.23.1 or newer, for the inline `configs`), and a TLS proxy in front of it (Caddy, Traefik, nginx, or your load balancer) for the panel's HTTPS address.

> Tested so far only with locally built images on Docker Desktop, not with a published release or behind a real TLS proxy.

## 1. Files

Copy `deploy/docker/production/compose.yml` and `deploy/docker/production/.env.example` into an empty directory on the host, e.g. `/opt/proxmotion`, and name the second one `.env`:

```bash
cp .env.example .env
chmod 600 .env
```

## 2. Settings

Fill in `.env`:

| Setting | Value |
|---|---|
| `PROXMOTION_VERSION` | The release to run, e.g. `0.1.0` |
| `APP_KEY` | Output of `docker compose run --rm --no-deps migrate php artisan key:generate --show` |
| `APP_URL` | The panel's HTTPS address, as administrators and billing systems reach it |
| `DB_PASSWORD` | A long random string (`openssl rand -hex 24`) |
| `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET` | Three random strings (`openssl rand -hex 16`); without them the server page polls instead of showing live figures |
| `CONSOLE_PROXY_SECRET` | A random string (`openssl rand -hex 32`) |
| `MAIL_*` | Your SMTP server, for password resets and notifications |
| `PROXMOTION_HTTP_BIND` | Where nginx listens: `80`, or `127.0.0.1:8080` when the TLS proxy runs on the same host |
| `TRUSTED_PROXIES` | The TLS proxy's address or network (see below) |

Keep `REVERB_PUBLIC_*` empty: browsers reach Reverb through nginx at `/app` on the panel's address.

### The TLS proxy

Forward the panel's address to nginx, including WebSocket upgrades (`/app/` for Reverb, `/console-ws` for the browser console), and pass `X-Forwarded-For`, `X-Forwarded-Proto` and `X-Forwarded-Host`. Allow uploads of at least 64 MB and keep WebSocket connections open for an hour.

Set `TRUSTED_PROXIES` to the address the proxy connects from, as nginx sees it. For a proxy on the same host, that is usually the Docker network's gateway, e.g. `172.16.0.0/12`. The panel believes forwarded headers only from those addresses. Without the setting it ignores them, so a link or redirect may use `http`. With a wider setting, any client could choose the address that API token limits and the audit log see.

## 3. Install

Once, interactively. It checks the requirements, creates the tables and asks for the first administrator:

```bash
docker compose run --rm migrate install
```

When you leave the password empty, a generated one is printed once in this terminal. Then start the panel:

```bash
docker compose up -d
docker compose ps
```

Sign in at `APP_URL` and set up two-factor authentication. Continue with *Admin → Help* for the setup order.

## 4. Upgrades

Read the release notes, set the new `PROXMOTION_VERSION` in `.env`, then:

```bash
docker compose pull
docker compose up -d
```

The `migrate` service runs `php artisan proxmotion:upgrade --when-needed` first. When the new version brings migrations, it puts the panel into maintenance mode, migrates, and opens the panel again; otherwise it only checks the requirements ([ADR 0052](../adr/0052-install-and-upgrade-commands.md)). The new panel containers start after it. When a migration fails, the panel stays in maintenance mode: `docker compose logs migrate` says why.

## 5. Day to day

- Logs: `docker compose logs -f web worker scheduler`.
- Artisan: `docker compose exec web php artisan about`.
- Failed queue jobs: `docker compose exec web php artisan queue:failed`.
- Back up the `mariadb` and `storage` volumes (database, uploaded logos, logs) and `.env`. `APP_KEY` decrypts the stored Proxmox tokens and passwords: without it, a restored database is unusable.
- The panel's database user may only read and write rows and change the schema (`SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX, REFERENCES` on its database), set by the `mariadb-privileges` config when MariaDB creates the database. A stack whose database was created before that keeps the image's `ALL PRIVILEGES`; to narrow it, run the two statements of that config in `compose.yml` as root (`docker compose exec mariadb mariadb -uroot -p`, with the `GENERATED ROOT PASSWORD` from the first `docker compose logs mariadb`).
