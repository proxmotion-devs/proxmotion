#!/bin/bash
# Stops the VPN and deletes its configuration, certificate authority and
# client profiles. The OpenVPN packages stay installed.
set -euo pipefail
# shellcheck source=/dev/null
. "$PROXMOTION_APP_DIR/proxmotion.sh"

pm_step 20 "Stopping the VPN"
systemctl disable --now openvpn-server@server proxmotion-openvpn-nat.service 2>/dev/null || true

pm_step 60 "Removing the configuration"
rm -f /etc/systemd/system/proxmotion-openvpn-nat.service /etc/sysctl.d/99-proxmotion-openvpn.conf /etc/openvpn/client.ovpn
rm -rf /etc/openvpn/easy-rsa /etc/openvpn/clients
rm -f /etc/openvpn/server/server.conf /etc/openvpn/server/ca.crt /etc/openvpn/server/server.crt /etc/openvpn/server/server.key /etc/openvpn/server/tls-crypt.key /etc/openvpn/server/crl.pem
systemctl daemon-reload
