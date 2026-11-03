# 54. Production Docker images

- **Status:** Accepted
- **Date:** 2026-11-03

## Context

Docker Compose is the primary way to run the panel in production (AGENTS.md §3.9). Until now `deploy/docker` held only a development setup: an image with Composer and Node that mounts the source, and a compose file that ran `php artisan horizon` without Horizon being installed. ADR 0052 gave both install paths the same `proxmotion:install` and `proxmotion:upgrade` commands and left the images for this step.

A published image is used by many installations, so nothing of one installation may be built into it. Two things were built in: the address and key browsers use for Reverb (`VITE_REVERB_*`, compiled into the JavaScript at build time), and the absence of a version number (ADR 0052 says the upgrade runs whatever migrations are new).

## Decision

1. **Three images on GHCR**, built from `deploy/docker/production/Dockerfile` (repository root as context) and `deploy/console-proxy/Dockerfile`:
   - `proxmotion`: PHP 8.4 (php-fpm, Debian bookworm) with the code, the dependencies without development packages and the built frontend. One **role** per container, chosen by the command (`entrypoint.sh`):
     - `web`: php-fpm on port 9000.
     - `worker`: `queue:work`, recycled every hour.
     - `scheduler`: `schedule:work`.
     - `reverb`: `reverb:start` on port 8080.
     - `migrate`: `proxmotion:upgrade --no-interaction --when-needed`. With `--when-needed` the command checks the requirements and, when no migration is pending, stops there: the panel stays open. Changed code comes with new containers, so only migrations need the maintenance mode, and `docker compose up -d` after an `.env` change or a `--scale` does not close the panel to the billing systems.
     - `install`: `proxmotion:install`.
     - Anything else runs as a command, e.g. `php artisan about`.

     The long-running roles cache the configuration, events, routes and views from their own environment when they start (`optimize`); the image holds no cache. Compiled views go to `bootstrap/cache/views` in each container (`VIEW_COMPILED_PATH`), not to `storage/framework/views` on the shared volume: containers starting together would write the same files, and during an upgrade the old and the new version would read each other's, while opcache does not check files again (`opcache.validate_timestamps=0`). The code belongs to root, and the panel (`www-data`) writes only `storage/`, a volume, and `bootstrap/cache/`.
   - `proxmotion-nginx`: nginx with the public files, in front of php-fpm, Reverb (`/app/`) and the console proxy (`/console-ws`). Only `index.php` is passed to PHP; any other `.php` path is `404`. Upstreams are looked up through Docker's DNS every 10 seconds rather than once at start: a recreated container comes back with a new address, and nginx would otherwise send `/app/` to whatever container got the old one (seen while testing). Addresses and the resolver can be changed with `PROXMOTION_FPM`, `PROXMOTION_REVERB`, `PROXMOTION_CONSOLE_PROXY` and `PROXMOTION_RESOLVER`.
   - `proxmotion-console-proxy`: the existing console proxy image (ADR 0002).

   nginx and php-fpm are separate images, one process per container, so Docker's restart policy supervises each and no process manager is needed inside.
2. **Queue workers are plain `queue:work`, not Horizon.** Horizon needs PHP's `pcntl` and `posix` extensions to be installed with Composer at all, which Windows does not have, so development installs on Windows would need fake platform entries in `composer.json` that also hide a missing `pcntl` on Linux. One `default` queue needs no balancing, and failed jobs already show as failed tasks plus `queue:failed`. Horizon can still be added later. A stopped worker finishes its job first: compose's `stop_grace_period` of 31 minutes is longer than the longest job timeout (`RunServerTask`, 30 minutes), since a worker killed inside a job would fail its task. Most jobs end within seconds (staged tasks, ADR 0032), so stopping rarely waits. Since Horizon is not used, `proxmotion:upgrade` no longer signals `horizon:terminate`.
3. **`deploy/docker/production/compose.yml`** with `.env.example` next to it: the panel roles, the console proxy, nginx, MariaDB 11.8 and Redis 8 (append-only file), the volumes `storage`, `mariadb` and `redis`. The panel containers read the same `.env` (`env_file`); compose uses it for the image version and the database password. Ordering: `migrate` runs on every `up` after MariaDB and Redis are healthy, and the other panel containers start only after it succeeded. Installing is `docker compose run --rm migrate install` once, interactively: the generated administrator password is printed to that terminal, not kept in a container log (ADR 0052 §3). An upgrade is a new `PROXMOTION_VERSION`, `docker compose pull` and `docker compose up -d`. While `migrate` runs pending migrations, the old containers answer from maintenance mode, which the containers share through Redis (`APP_MAINTENANCE_DRIVER=cache`, ADR 0052 §5).
4. **TLS ends in front of nginx**, at a proxy of the administrator's choice. nginx listens on `PROXMOTION_HTTP_BIND` (default port 80). Forwarded headers count only from the addresses in **`TRUSTED_PROXIES`** (`App\Http\Middleware\TrustProxies`, read per request; empty by default). Trusting more would let any client choose the address that API token address limits, rate limits and audit logs see.
5. **Reverb settings reach the browser with the page.** A shared `reverb` prop (`App\Support\Broadcasting\ReverbClient`, only for signed-in administrators, null unless the panel broadcasts through Reverb) carries the app key and `REVERB_PUBLIC_HOST`, `REVERB_PUBLIC_PORT` and `REVERB_PUBLIC_SCHEME`. When these are empty the browser uses the panel's own host, scheme and port, which is right behind nginx. They replace `VITE_REVERB_*`, which are no longer read: a local `.env` needs the new names to keep live figures.
6. **A version number:** `panel/VERSION` (`0.1.0`), read into `config('proxmotion.version')`, shown by `php artisan about` and in the install and upgrade messages, and set as the images' `org.opencontainers.image.version` label.
7. **Releases:** `.github/workflows/release.yml` runs on a tag `v<version>`, refuses a tag that differs from `panel/VERSION`, and pushes the three images as `<version>` and, for releases without a pre-release suffix, `<major>.<minor>` and `latest` (`docker/metadata-action`). `.github/workflows/images.yml` builds the three images without pushing them, for pushes and pull requests that change what goes into them (`panel/`, `deploy/docker/production/`, `deploy/console-proxy/`).
8. **The build context is limited** by `Dockerfile.dockerignore`: only `panel/` and `deploy/docker/production/` go in, never an `.env` file, `vendor/`, `node_modules/`, built assets, `storage/` or the tests.

## Consequences

- One image fits every installation: settings, caches, the administrator and Reverb's address all come from the environment when a container starts.
- Running the stack the first time on MariaDB found three migrations that had only worked on SQLite. Two dropped a unique index that a foreign key used: one when allowing reused external ids, one in the billing systems migration's `down()`. One foreign key name was longer than 64 characters. Those migrations were fixed in place: nothing has been released yet. Every migration now runs up, down and up again on MariaDB 11.8.
- The `/app` location of the development nginx also matched `/apple-touch-icon.png`; both configurations now use `/app/`. The `Link` headers of preloaded assets (about 8 KB) exceeded nginx's default FastCGI header buffer and gave `502` on every page; both now allow 32 KB.
- The panel image is about 800 MB unpacked. Most of it is the official Debian php-fpm image, whose compilers are removed from the final layer but remain in the base layers. An Alpine variant would be smaller but is not done.
- Only `linux/amd64` images are built.
- Tested on Docker Desktop (Docker 29.9) with locally built images:
  - Install, `up -d`, sign-in and the dashboard.
  - The Reverb WebSocket handshake through nginx, before and after the containers were recreated.
  - The scheduler running the scheduled commands, and the worker running a queued job.
  - nginx refusing `.env` and other `.php` paths.

  Not tested:
  - The release workflow and the GHCR push.
  - A TLS proxy in front, and the console relayed to a real Proxmox.
  - An upgrade from one published version to the next.
  - MySQL 8.
