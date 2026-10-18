#!/bin/bash
# Removes WHMCS: its files, database, cron job, nginx site and certificate.
# nginx, PHP, the ionCube Loader and MariaDB stay installed, since other sites
# may use them.
set -euo pipefail
# shellcheck source=/dev/null
. "$PROXMOTION_APP_DIR/proxmotion.sh"
# shellcheck source=lib.sh
. "$PROXMOTION_APP_DIR/lib.sh"

pm_step 20 "Removing the nginx site and the cron job"
rm -f /etc/nginx/sites-enabled/proxmotion-whmcs "$WHMCS_NGINX_SITE" "$WHMCS_CRON"
domain=$(pm_app_input domain)
if [ -n "$domain" ] && command -v certbot >/dev/null 2>&1; then
    certbot delete --non-interactive --cert-name "$domain" >/dev/null 2>&1 || true
fi
if command -v nginx >/dev/null 2>&1 && nginx -t >/dev/null 2>&1; then
    systemctl reload nginx || true
fi

pm_step 50 "Removing the database"
whmcs_drop_database

pm_step 80 "Removing the files"
rm -rf "$WHMCS_ROOT"
