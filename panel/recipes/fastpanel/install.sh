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

debug_log=/tmp/install_fastpanel.debug
installer_output=

fastpanel_installed() {
    dpkg-query -W -f '${Status}' fastpanel2 2>/dev/null | grep -q 'install ok installed'
}

# fastpanel_error: the first error in the installer's log, else its last line.
fastpanel_error() {
    local line=

    if [ -s "$debug_log" ]; then
        line=$(grep -m 1 -E '^(E: |error: |ERROR)' "$debug_log" || true)
        [ -n "$line" ] || line=$(grep -v '^[[:space:]]*$' "$debug_log" | tail -n 1 || true)
    fi

    echo "${line:-details are in $debug_log}"
}

if fastpanel_installed; then
    pm_step 80 "FASTPANEL is already installed"
else
    pm_step 5 "Downloading the FASTPANEL installer"
    pm_apt_update
    pm_apt_install ca-certificates wget
    installer=$(mktemp)
    installer_output=$(mktemp)
    wget -q https://repo.fastpanel.direct/install_fastpanel.sh -O "$installer"

    pm_step 15 "Installing FASTPANEL and its services"
    database=$(pm_input database)
    # Without --mysql the installer picks per OS (Percona 8.0 on Debian 12, the
    # OS's own server on Ubuntu); its "default" is the OS's own server.
    # MariaDB 10.11 is not offered: FASTPANEL takes it from MariaDB's frozen
    # legacy repository, whose downloads can redirect to a web page.
    options=()
    case "$database" in
        '' | default) ;;
        distribution) options=(--mysql default) ;;
        *) options=(--mysql "$database") ;;
    esac
    # The downloaded script only fetches and runs the real installer and then
    # removes it, so it exits 0 even when the installer failed. Its status is
    # not trusted: the checks below decide.
    DEBIAN_FRONTEND=noninteractive bash "$installer" "${options[@]}" 2>&1 | tee "$installer_output" || true
    rm -f "$installer"
fi

failed=
if ! fastpanel_installed; then
    failed="FASTPANEL is not installed"
elif [ ! -x /usr/sbin/nginx ]; then
    failed="nginx is missing"
elif [ -n "$installer_output" ] && grep -q "Oops! I've failed" "$installer_output"; then
    failed="the installer reported a failure"
fi
[ -z "$installer_output" ] || rm -f "$installer_output"

if [ -n "$failed" ]; then
    if [ -s "$debug_log" ]; then
        echo "Last lines of $debug_log:"
        tail -n 40 "$debug_log"
    fi

    pm_fail "The FASTPANEL installer failed ($failed): $(fastpanel_error)"
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
