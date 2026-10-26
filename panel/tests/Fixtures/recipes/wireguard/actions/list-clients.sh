#!/bin/bash
# Lists the client names.
set -euo pipefail
# shellcheck source=/dev/null
. "$PROXMOTION_APP_DIR/proxmotion.sh"
# shellcheck source=../lib.sh
. "$PROXMOTION_APP_DIR/lib.sh"

clients=$(wg_clients | paste -sd ',' - | sed 's/,/, /g')
pm_output clients "${clients:-none}"
