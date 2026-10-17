#!/bin/bash
# FASTPANEL through its official installer (https://fastpanel.direct/), which
# refuses servers with a web or database server already installed.
set -euo pipefail
# shellcheck source=/dev/null
. "$PROXMOTION_APP_DIR/proxmotion.sh"

# shellcheck source=/dev/null
. /etc/os-release

case "${ID:-}:${VERSION_ID:-}" in
    debian:12 | ubuntu:22.04 | ubuntu:24.04) ;;
    *) pm_fail "FASTPANEL does not support this operating system: ${PRETTY_NAME:-unknown}." ;;
esac

if dpkg-query -W -f '${Status}' fastpanel2 2>/dev/null | grep -q 'install ok installed'; then
    pm_step 80 "FASTPANEL is already installed"
else
    pm_step 5 "Downloading the FASTPANEL installer"
    pm_apt_update
    pm_apt_install ca-certificates wget
    installer=$(mktemp)
    wget -q https://repo.fastpanel.direct/install_fastpanel.sh -O "$installer"

    pm_step 15 "Installing FASTPANEL and its services"
    database=$(pm_input database)
    options=()
    [ -z "$database" ] || [ "$database" = default ] || options=(--mysql "$database")
    if ! DEBIAN_FRONTEND=noninteractive bash "$installer" "${options[@]}"; then
        rm -f "$installer"
        pm_fail "The FASTPANEL installer failed; FASTPANEL needs a freshly installed server. Details are in /tmp/install_fastpanel.debug."
    fi
    rm -f "$installer"
fi

pm_step 90 "Setting the administrator password"
command -v mogwai >/dev/null || pm_fail "FASTPANEL was installed but its mogwai tool is missing."
password=$(head -c 48 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | cut -c 1-20)
mogwai chpasswd -u fastuser -p "$password" >/dev/null || pm_fail "The FASTPANEL password could not be set."

address=$(pm_public_address)
case "$address" in
    *:*) address="[$address]" ;;
esac
pm_output panel_url "https://$address:8888/"
pm_output username fastuser
pm_output password "$password"
