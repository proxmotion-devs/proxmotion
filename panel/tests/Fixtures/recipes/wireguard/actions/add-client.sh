#!/bin/bash
# Adds a client and returns its configuration file.
set -euo pipefail
# shellcheck source=/dev/null
. "$PROXMOTION_APP_DIR/proxmotion.sh"
# shellcheck source=../lib.sh
. "$PROXMOTION_APP_DIR/lib.sh"

name=$(pm_input name)
wg_check_name "$name"
[ ! -e "$WG_CLIENTS/$name.ip" ] || pm_fail "A client named $name already exists."
wg_settings

pm_step 30 "Creating the client"
wg_add_client "$name"
wg_render
wg_client_conf "$name"

pm_step 80 "Applying"
wg_apply

pm_output_file client.conf "$WG_CLIENTS/$name.conf"
