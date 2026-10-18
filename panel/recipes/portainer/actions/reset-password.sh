#!/bin/bash
# A new password for Portainer's first administrator.
set -euo pipefail
# shellcheck source=/dev/null
. "$PROXMOTION_APP_DIR/proxmotion.sh"
# shellcheck source=../lib.sh
. "$PROXMOTION_APP_DIR/lib.sh"

if ! command -v docker >/dev/null 2>&1 || ! portainer_exists; then
    pm_fail "Portainer is not installed on this server."
fi

pm_step 50 "Setting a new password"
password=$(portainer_reset_password)
pm_output username admin
pm_output password "$password"
