#!/bin/bash
# Revokes a client certificate: the client can no longer connect, and
# connected sessions are dropped.
set -euo pipefail
# shellcheck source=/dev/null
. "$PROXMOTION_APP_DIR/proxmotion.sh"
# shellcheck source=../lib.sh
. "$PROXMOTION_APP_DIR/lib.sh"

name=$(pm_input name)
ovpn_check_name "$name"
ovpn_has_client "$name" || pm_fail "There is no client named $name."

pm_step 30 "Revoking the certificate"
(cd "$OVPN_PKI" && ./easyrsa revoke "$name")
ovpn_publish_crl
rm -f "$OVPN_CLIENTS/$name.ovpn"

pm_step 80 "Restarting the VPN"
systemctl restart openvpn-server@server
