#!/bin/bash
# Lists the clients that can connect.
set -euo pipefail
# shellcheck source=/dev/null
. "$PROXMOTION_APP_DIR/proxmotion.sh"
# shellcheck source=../lib.sh
. "$PROXMOTION_APP_DIR/lib.sh"

clients=$(ovpn_clients | paste -sd ',' - | sed 's/,/, /g')
pm_output clients "${clients:-none}"
