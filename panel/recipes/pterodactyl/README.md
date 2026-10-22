# Pterodactyl Panel

Installs the [Pterodactyl](https://pterodactyl.io/) 2.0 game server panel on Debian 12+ or
Ubuntu 24.04+, following its
[installation guide](https://docs.pterodactyl.io/v2/panel/getting-started). **Pterodactyl
2.0 is an open beta** (this recipe installs `v2.0.0-beta.2`); its developers advise keeping
production panels on 1.x until the final release.

Outputs: the panel address, the administrator's username and password, and a notice.

- **Packages:** PHP-FPM with the extensions the guide lists, MariaDB, Redis and nginx from
  the distribution. When the distribution has neither PHP 8.3 nor 8.4 (Debian 12, Ubuntu
  26.04), PHP 8.3 comes from Ondřej Surý's repository (`packages.sury.org` on Debian, the
  `ondrej/php` PPA on Ubuntu). Ubuntu 22.04 is not offered: its MariaDB 10.6 is older than
  the 10.11 the panel needs.
- **Download:** the release's `panel.tar.gz` from GitHub, checked against the SHA-256 in
  `lib.sh`; Composer 2 from getcomposer.org, checked against its published SHA-256. The
  panel lives in `/var/www/pterodactyl`.
- **Configuration:** a database `panel` with the user `pterodactyl@127.0.0.1` and a generated
  password (kept in `/etc/proxmotion/pterodactyl`, root only), Redis for the cache, sessions
  and queue, the settings editor in the admin area turned on, anonymous telemetry turned
  off (`PTERODACTYL_TELEMETRY_ENABLED` in `.env`), the queue worker `pteroq.service` and the
  scheduler in `/etc/cron.d/proxmotion-pterodactyl` (as `www-data`), the default eggs.
- **Administrator:** created with `p:user:make` from the `email` and `username` inputs (at
  least 3 characters) and a generated password. The _Reset the administrator password_
  action sets a new one through `artisan tinker`.
- **Domain and HTTPS:** with a `domain`, nginx answers for it and the panel's URL uses it;
  with `letsencrypt`, certbot gets a certificate (the administrator email is its contact)
  and redirects HTTP to HTTPS. When the domain does not point to the server yet, the install
  still succeeds over HTTP with a notice. Wings nodes behind HTTPS need the panel on HTTPS
  too.
- **Not done by the app:** email (`php artisan p:environment:mail` or the admin area),
  Wings, which runs the game servers on separate nodes
  ([Wings guide](https://docs.pterodactyl.io/v2/wings/installing)), and backing up
  `APP_KEY` from `/var/www/pterodactyl/.env`, which encrypts node tokens, API keys and 2FA
  secrets and cannot be recovered.
- **Running it again** keeps the panel, its key, its database and its users (an existing
  administrator keeps its password); it never replaces a `/var/www/pterodactyl` it did not
  install. Upgrades to newer releases come with new versions of this recipe.
- **Fresh server:** the recipe refuses a server where Apache is running or where the LEMP
  or WordPress app serves the web ports.
- **Uninstall** removes the worker, the scheduler, the nginx site and certificate, the panel
  directory, its database and user, and the PHP, nginx, MariaDB and Redis packages (not
  purged). Wings nodes are not touched.
- Not offered on NAT VPS, where ports 80 and 443 are not reachable directly.

Not run on a real server yet (`recipes` integration group).
