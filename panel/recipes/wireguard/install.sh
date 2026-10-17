#!/bin/bash
# A WireGuard server (wg0) with a first client named "client". Keys are
# generated inside the server and kept when the script runs again; the
# configuration is rebuilt from the clients (lib.sh).
set -euo pipefail
# shellcheck source=/dev/null
. "$PROXMOTION_APP_DIR/proxmotion.sh"
# shellcheck source=lib.sh
. "$PROXMOTION_APP_DIR/lib.sh"

wg_settings

pm_step 10 "Installing WireGuard"
pm_apt_update
pm_apt_install wireguard wireguard-tools iptables

pm_step 50 "Creating keys"
wg_add_client client

pm_step 70 "Configuring the VPN"
wg_render
wg_client_conf client
pm_enable_forwarding wireguard

pm_step 90 "Starting the VPN"
systemctl enable wg-quick@wg0
systemctl restart wg-quick@wg0

pm_output_file client.conf "$WG_CLIENTS/client.conf"
pm_output endpoint "${endpoint}:${port}"
