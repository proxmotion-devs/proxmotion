#!/bin/bash
# A new password for Plesk's admin user.
set -euo pipefail
# shellcheck source=/dev/null
. "$PROXMOTION_APP_DIR/proxmotion.sh"
# shellcheck source=../lib.sh
. "$PROXMOTION_APP_DIR/lib.sh"

command -v plesk >/dev/null 2>&1 || pm_fail "Plesk is not installed on this server."

pm_step 50 "Setting a new password"
password=$(plesk_password)
plesk_set_admin_password "$password"
pm_output password "$password"
