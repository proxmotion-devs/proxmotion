#!/bin/bash
# Removes the WordPress site: its files, database, nginx site and certificate.
# nginx, PHP and MariaDB stay installed, since other sites may use them.
set -euo pipefail
# shellcheck source=/dev/null
. "$PROXMOTION_APP_DIR/proxmotion.sh"
# shellcheck source=lib.sh
. "$PROXMOTION_APP_DIR/lib.sh"

pm_step 20 "Removing the nginx site"
rm -f /etc/nginx/sites-enabled/proxmotion-wordpress "$WP_NGINX_SITE"
domain=$(pm_app_input domain)
if [ -n "$domain" ] && command -v certbot >/dev/null 2>&1; then
    certbot delete --non-interactive --cert-name "$domain" >/dev/null 2>&1 || true
fi
if command -v nginx >/dev/null 2>&1 && nginx -t >/dev/null 2>&1; then
    systemctl reload nginx || true
fi

pm_step 50 "Removing the database"
if command -v mariadb >/dev/null 2>&1; then
    mariadb <<'SQL'
DROP DATABASE IF EXISTS wordpress;
DROP USER IF EXISTS 'wordpress'@'localhost';
SQL
fi

pm_step 80 "Removing the files"
rm -rf "$WP_ROOT" /var/www/.wp-cli
rm -f "$WP_CLI"
