#!/bin/bash
# Shared by the WHMCS scripts.
# The paths are used by the scripts that source this file.
# shellcheck disable=SC2034

WHMCS_ROOT=/var/www/whmcs
WHMCS_NGINX_SITE=/etc/nginx/sites-available/proxmotion-whmcs
WHMCS_CRON=/etc/cron.d/proxmotion-whmcs
# Written once the installation has finished; a directory without it is a failed attempt.
WHMCS_DONE=$WHMCS_ROOT/.proxmotion-installed

# whmcs_random LENGTH: random letters and digits.
whmcs_random() {
    head -c 256 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | cut -c "1-$1"
}

# whmcs_drop_database: the database and its user (a failed attempt or an uninstall).
whmcs_drop_database() {
    command -v mariadb >/dev/null 2>&1 || return 0
    mariadb <<'SQL'
DROP DATABASE IF EXISTS whmcs;
DROP USER IF EXISTS 'whmcs'@'localhost';
SQL
}
