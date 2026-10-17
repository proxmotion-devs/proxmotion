#!/bin/bash
# Adds a client certificate and returns its profile.
set -euo pipefail
# shellcheck source=/dev/null
. "$PROXMOTION_APP_DIR/proxmotion.sh"
# shellcheck source=../lib.sh
. "$PROXMOTION_APP_DIR/lib.sh"

name=$(pm_input name)
ovpn_check_name "$name"
grep -q "/CN=${name}\$" "$OVPN_PKI/pki/index.txt" && pm_fail "A client named $name exists or existed; choose another name."
ovpn_settings

pm_step 30 "Creating the certificate"
(cd "$OVPN_PKI" && ./easyrsa build-client-full "$name" nopass)

pm_step 80 "Creating the profile"
ovpn_profile "$name"

pm_output_file client.ovpn "$OVPN_CLIENTS/$name.ovpn"
