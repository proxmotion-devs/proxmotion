#!/bin/bash
# Removes a client: its keys are deleted and it can no longer connect.
set -euo pipefail
# shellcheck source=/dev/null
. "$PROXMOTION_APP_DIR/proxmotion.sh"
# shellcheck source=../lib.sh
. "$PROXMOTION_APP_DIR/lib.sh"

name=$(pm_input name)
wg_check_name "$name"
[ -e "$WG_CLIENTS/$name.ip" ] || pm_fail "There is no client named $name."
wg_settings

pm_step 30 "Removing the client"
rm -f "$WG_CLIENTS/$name".*
wg_render

pm_step 80 "Applying"
wg_apply
