#!/bin/bash
# Sets a new password for the administrator the app was installed with.
set -euo pipefail
# shellcheck source=/dev/null
. "$PROXMOTION_APP_DIR/proxmotion.sh"
# shellcheck source=../lib.sh
. "$PROXMOTION_APP_DIR/lib.sh"

ptero_installed || pm_fail "Pterodactyl is not installed on this server."

username=$(pm_app_input username)
username=${username:-admin}
ptero_user_exists "$username" || pm_fail "The panel has no user named $username."

pm_step 50 "Setting a new password"
password=$(ptero_password)
ptero_set_password "$username" "$password" || pm_fail "The panel did not accept the new password."

pm_output username "$username"
pm_output password "$password"
