#!/bin/bash
# Runs a recipe's install.sh (docs/adr/0015). The panel writes it into the
# app's directory and starts it detached with systemd-run; the outcome ends up
# in status.json, which the panel reads through the guest agent.
set -uo pipefail

PROXMOTION_APP_DIR="$(cd "$(dirname "$0")" && pwd)"
export PROXMOTION_APP_DIR
export DEBIAN_FRONTEND=noninteractive
# shellcheck source=/dev/null
. "$PROXMOTION_APP_DIR/proxmotion.sh"

cd "$PROXMOTION_APP_DIR" || exit 1
chmod 700 "$PROXMOTION_APP_DIR"
rm -f failure state outputs.json
rm -rf outputs
mkdir -p outputs
: >install.log

pm_step 0 "Preparing"

if ! command -v jq >/dev/null 2>&1; then
    if command -v apt-get >/dev/null 2>&1; then
        { pm_apt_update && pm_apt_install jq; } >>install.log 2>&1
    elif command -v dnf >/dev/null 2>&1; then
        dnf install -y -q jq >>install.log 2>&1
    fi
fi

if ! command -v jq >/dev/null 2>&1; then
    pm_status failed 0 "Preparing" "jq could not be installed."
    exit 1
fi

echo '{}' >outputs.json

if bash ./install.sh >>install.log 2>&1; then
    pm_status installed 100 "Installed"
    exit 0
else
    code=$?
fi

if [ -s failure ]; then
    reason=$(head -n 1 failure)
else
    reason="exit code $code: $(grep -v '^[[:space:]]*$' install.log | tail -n 1)"
fi

pm_status failed "$(pm_current progress)" "$(pm_current step)" "${reason:0:500}"
exit "$code"
