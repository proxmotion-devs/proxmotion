#!/bin/bash
# Removes PM2 (its boot service and the saved process list), Node.js, npm and
# NodeSource's repository. Applications and their node_modules directories
# are kept.
set -euo pipefail
# shellcheck source=/dev/null
. "$PROXMOTION_APP_DIR/proxmotion.sh"
# shellcheck source=lib.sh
. "$PROXMOTION_APP_DIR/lib.sh"

pm_step 20 "Removing PM2"
if command -v pm2 >/dev/null 2>&1; then
    pm2 kill >/dev/null 2>&1 || true
    pm2 unstartup systemd -u root --hp /root >/dev/null 2>&1 || true
    npm uninstall --global pm2 >/dev/null 2>&1 || true
fi
systemctl disable --now pm2-root >/dev/null 2>&1 || true
rm -f /etc/systemd/system/pm2-root.service
systemctl daemon-reload

pm_step 60 "Removing Node.js"
DEBIAN_FRONTEND=noninteractive apt-get -o DPkg::Lock::Timeout=600 remove -y -q --autoremove nodejs
rm -f "$NODE_SOURCES" "$NODE_KEYRING" "$NODE_PIN"
pm_apt_update
