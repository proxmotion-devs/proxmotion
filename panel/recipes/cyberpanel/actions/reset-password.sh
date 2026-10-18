#!/bin/bash
# A new password for CyberPanel's admin user.
set -euo pipefail
# shellcheck source=/dev/null
. "$PROXMOTION_APP_DIR/proxmotion.sh"
# shellcheck source=../lib.sh
. "$PROXMOTION_APP_DIR/lib.sh"

cyberpanel_installed || pm_fail "CyberPanel is not installed on this server."

pm_step 50 "Setting a new password"
password=$(cyberpanel_password)
cyberpanel_set_admin_password "$password"
pm_output password "$password"
