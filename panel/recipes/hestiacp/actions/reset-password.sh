#!/bin/bash
# A new password for HestiaCP's admin user.
set -euo pipefail
# shellcheck source=/dev/null
. "$PROXMOTION_APP_DIR/proxmotion.sh"
# shellcheck source=../lib.sh
. "$PROXMOTION_APP_DIR/lib.sh"

hestia_installed || pm_fail "HestiaCP is not installed on this server."

pm_step 50 "Setting a new password"
password=$(hestia_password)
hestia_set_admin_password "$password"
pm_output password "$password"
