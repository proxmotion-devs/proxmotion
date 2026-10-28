#!/bin/bash
# Upgrades OpenVPN from an earlier version of this recipe: newer packages, a
# fresh CRL (it expires) and a restart. The certificate authority, the
# server configuration and the clients' profiles stay as they are.
set -euo pipefail
# shellcheck source=/dev/null
. "$PROXMOTION_APP_DIR/proxmotion.sh"
# shellcheck source=lib.sh
. "$PROXMOTION_APP_DIR/lib.sh"

[ -f "$OVPN_PKI/pki/ca.crt" ] || pm_fail "OpenVPN is not set up on this server; reinstall the app."

pm_step 20 "Upgrading OpenVPN"
pm_apt_update
pm_apt_upgrade openvpn easy-rsa openssl

pm_step 70 "Renewing the revocation list"
ovpn_publish_crl

pm_step 90 "Restarting the VPN"
systemctl daemon-reload
systemctl restart proxmotion-openvpn-nat.service openvpn-server@server
