# LAMP

Installs Apache with mod_php, MariaDB and PHP (with the common extensions: mysql, curl,
gd, intl, mbstring, xml, zip) from the distribution's packages on Debian 12+ or Ubuntu
22.04+, and an Apache site serving `/var/www/html` with `.htaccess` files honoured.

Outputs: the site address, the document root, the database name (also its user's name),
the database password and a notice.

- **Database:** the `database` input (default `site`; dashes become underscores) names a
  MariaDB database and a user with the same name and a generated password, allowed from
  `localhost` only. The name and password are kept in `/etc/proxmotion/lamp-database`
  (root only), so running the app again keeps them.
- **Domain and HTTPS:** with a `domain`, Apache answers for it; with `letsencrypt`
  certbot gets a certificate and redirects HTTP to HTTPS. The domain must point to the
  server first; when it does not, the install still succeeds with a notice.
- **First page:** an empty document root gets an `index.php` that shows the PHP version.
  Existing files are never replaced.
- **Fresh server:** the recipe refuses a server where nginx is running (use LEMP there).
- **Uninstall** removes the site, the certificate, the database and its user, and the
  Apache, PHP and MariaDB packages (not purged). The files in `/var/www/html` and any
  other databases stay.
- Not offered on NAT VPS, where ports 80 and 443 are not reachable directly.

Not run on a real server yet (`recipes` integration group).
