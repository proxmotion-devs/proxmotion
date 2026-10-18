#!/bin/bash
# Sets a new password for the administrator the app was installed with.
set -euo pipefail
# shellcheck source=/dev/null
. "$PROXMOTION_APP_DIR/proxmotion.sh"
# shellcheck source=../lib.sh
. "$PROXMOTION_APP_DIR/lib.sh"

if [ ! -x "$WP_CLI" ] || ! wp core is-installed 2>/dev/null; then
    pm_fail "WordPress is not installed."
fi

admin_user=$(pm_app_input admin_user)
admin_user=${admin_user:-admin}
password=$(wp_password)
wp_set_admin_password "$admin_user" "$password"

pm_output username "$admin_user"
pm_output password "$password"
