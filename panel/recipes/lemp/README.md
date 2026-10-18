# LEMP

Installs nginx, PHP-FPM and MariaDB (PHP with the common extensions: mysql, curl, gd,
intl, mbstring, xml, zip) from the distribution's packages on Debian 12+ or Ubuntu
22.04+, and an nginx site serving `/var/www/html` that sends requests for missing files
to `index.php` (front controllers such as Laravel's `public/` need the root changed).

Outputs: the site address, the document root, the database name (also its user's name),
the database password and a notice.

- **Database:** the `database` input (default `site`; dashes become underscores) names a
  MariaDB database and a user with the same name and a generated password, allowed from
  `localhost` only. The name and password are kept in `/etc/proxmotion/lemp-database`
  (root only), so running the app again keeps them.
- **Domain and HTTPS:** with a `domain`, nginx answers for it; with `letsencrypt` certbot
  gets a certificate and redirects HTTP to HTTPS. The domain must point to the server
  first; when it does not, the install still succeeds with a notice.
- **First page:** an empty document root gets an `index.php` that shows the PHP version.
  Existing files are never replaced.
- **Fresh server:** the recipe refuses a server where Apache is running (use LAMP there)
  or where the WordPress app serves the web ports.
- **Uninstall** removes the site, the certificate, the database and its user, and the
  nginx, PHP and MariaDB packages (not purged). The files in `/var/www/html` and any other
  databases stay.
- Not offered on NAT VPS, where ports 80 and 443 are not reachable directly.

Not run on a real server yet (`recipes` integration group).
