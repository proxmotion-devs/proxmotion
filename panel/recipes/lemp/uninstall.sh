#!/bin/bash
# Removes the nginx site, the certificate, the database and its user, then the
# nginx, PHP and MariaDB packages. The files in /var/www/html and other
# databases in /var/lib/mysql are kept; delete them by hand when they are no
# longer needed.
set -euo pipefail
# shellcheck source=/dev/null
. "$PROXMOTION_APP_DIR/proxmotion.sh"
# shellcheck source=lib.sh
. "$PROXMOTION_APP_DIR/lib.sh"

pm_step 15 "Removing the nginx site"
rm -f /etc/nginx/sites-enabled/proxmotion-lemp "$STACK_SITE"
domain=$(pm_app_input domain)
if [ -n "$domain" ] && command -v certbot >/dev/null 2>&1; then
    certbot delete --non-interactive --cert-name "$domain" >/dev/null 2>&1 || true
fi

pm_step 35 "Removing the database"
if [ -f "$STACK_CREDENTIALS" ] && command -v mariadb >/dev/null 2>&1; then
    name=$(sed -n 1p "$STACK_CREDENTIALS")
    mariadb <<SQL
DROP DATABASE IF EXISTS \`${name}\`;
DROP USER IF EXISTS '${name}'@'localhost';
SQL
fi
rm -f "$STACK_CREDENTIALS"

pm_step 60 "Removing nginx, PHP and MariaDB"
systemctl disable --now nginx mariadb 2>/dev/null || true
DEBIAN_FRONTEND=noninteractive apt-get -o DPkg::Lock::Timeout=600 remove -y -q --autoremove \
    nginx mariadb-server php-fpm php-cli python3-certbot-nginx
