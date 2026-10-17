#!/bin/bash
# Stops the VPN and deletes its configuration, keys and clients. The
# WireGuard packages stay installed.
set -euo pipefail
# shellcheck source=/dev/null
. "$PROXMOTION_APP_DIR/proxmotion.sh"

pm_step 20 "Stopping the VPN"
systemctl disable --now wg-quick@wg0 2>/dev/null || true

pm_step 60 "Removing the configuration"
rm -rf /etc/wireguard/wg0.conf /etc/wireguard/server.key /etc/wireguard/clients
rm -f /etc/sysctl.d/99-proxmotion-wireguard.conf
