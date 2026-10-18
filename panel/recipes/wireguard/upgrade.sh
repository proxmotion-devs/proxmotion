#!/bin/bash
# Upgrades WireGuard from an earlier version of this recipe: newer packages,
# and wg0.conf rebuilt from the clients with this version's lib.sh. Keys,
# clients and their configurations stay as they are.
set -euo pipefail
# shellcheck source=/dev/null
. "$PROXMOTION_APP_DIR/proxmotion.sh"
# shellcheck source=lib.sh
. "$PROXMOTION_APP_DIR/lib.sh"

[ -s "$WG_DIR/server.key" ] || pm_fail "WireGuard is not set up on this server; reinstall the app."

wg_settings

pm_step 20 "Upgrading WireGuard"
pm_apt_update
pm_apt_upgrade wireguard wireguard-tools

pm_step 60 "Configuring the VPN"
wg_render
pm_enable_forwarding wireguard

pm_step 90 "Restarting the VPN"
systemctl enable wg-quick@wg0
systemctl restart wg-quick@wg0
