#!/bin/bash
# Removes the panel: the queue worker, the scheduler, the nginx site and its
# certificate, the panel's files and its database, then the PHP, nginx,
# MariaDB and Redis packages (not purged). Wings on the nodes is not touched;
# its servers stay where they are but can no longer be managed.
set -euo pipefail
# shellcheck source=/dev/null
. "$PROXMOTION_APP_DIR/proxmotion.sh"
# shellcheck source=lib.sh
. "$PROXMOTION_APP_DIR/lib.sh"

pm_step 10 "Stopping the queue worker and the scheduler"
systemctl disable --now pteroq.service 2>/dev/null || true
rm -f "$PTERO_WORKER" "$PTERO_CRON"
systemctl daemon-reload

pm_step 25 "Removing the nginx site"
rm -f /etc/nginx/sites-enabled/proxmotion-pterodactyl "$PTERO_SITE"
domain=$(pm_app_input domain)
if [ -n "$domain" ] && command -v certbot >/dev/null 2>&1; then
    certbot delete --non-interactive --cert-name "$domain" >/dev/null 2>&1 || true
fi

pm_step 40 "Removing the panel and its database"
if [ -n "$(ptero_state 2)" ]; then
    rm -rf "$PTERO_DIR" "$PTERO_DIR.download"
fi
if command -v mariadb >/dev/null 2>&1; then
    mariadb <<SQL || true
DROP DATABASE IF EXISTS \`${PTERO_DATABASE}\`;
DROP USER IF EXISTS '${PTERO_DATABASE_USER}'@'127.0.0.1';
SQL
fi
rm -f "$PTERO_STATE"

pm_step 60 "Removing PHP, nginx, MariaDB and Redis"
systemctl disable --now nginx mariadb redis-server 2>/dev/null || true
packages=(nginx mariadb-server redis-server python3-certbot-nginx)
# The PHP 8.3 or 8.4 packages that are installed (dpkg also lists removed ones).
mapfile -t php_packages < <(dpkg-query -W -f '${Status} ${Package}\n' 'php8.[34]-*' 2>/dev/null | awk '$3 == "installed" { print $4 }')
DEBIAN_FRONTEND=noninteractive apt-get -o DPkg::Lock::Timeout=600 remove -y -q --autoremove \
    "${packages[@]}" "${php_packages[@]}"
rm -f /usr/local/bin/composer
