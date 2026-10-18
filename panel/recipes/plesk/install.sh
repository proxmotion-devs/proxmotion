#!/bin/bash
# Plesk Obsidian through Plesk's one-click installer
# (https://autoinstall.plesk.com/one-click-installer), which installs the
# latest stable release with its recommended components and takes 30 to 60
# minutes. The administrator finishes the setup (contact details, licence
# agreement, licence or trial) on the first login.
set -euo pipefail
# shellcheck source=/dev/null
. "$PROXMOTION_APP_DIR/proxmotion.sh"
# shellcheck source=lib.sh
. "$PROXMOTION_APP_DIR/lib.sh"

# shellcheck source=/dev/null
. /etc/os-release

case "${ID:-}" in
    debian | ubuntu | almalinux | rocky) ;;
    *) pm_fail "Plesk does not support this operating system: ${PRETTY_NAME:-unknown}." ;;
esac

if command -v plesk >/dev/null 2>&1 && plesk version >/dev/null 2>&1; then
    pm_step 85 "Plesk is already installed"
else
    pm_step 5 "Checking the server"
    for service in apache2 httpd nginx; do
        if systemctl is-active --quiet "$service" 2>/dev/null; then
            pm_fail "$service is running: Plesk can only be installed on a freshly installed server."
        fi
    done

    pm_step 10 "Downloading the Plesk installer"
    command -v curl >/dev/null 2>&1 || pm_pkg_install curl
    installer=$(mktemp)
    curl -fsSL https://autoinstall.plesk.com/one-click-installer -o "$installer"

    pm_step 15 "Installing Plesk (this takes 30 to 60 minutes)"
    if ! sh "$installer"; then
        rm -f "$installer"
        pm_fail "The Plesk installer failed; Plesk needs a freshly installed server. Details are in /var/log/plesk/install/autoinstaller3.log."
    fi
    rm -f "$installer"
fi

command -v plesk >/dev/null 2>&1 || pm_fail "The Plesk installer finished but Plesk is missing."

pm_step 90 "Setting the administrator password"
password=$(plesk_password)
plesk_set_admin_password "$password"

address=$(pm_public_address)
case "$address" in
    *:*) address="[$address]" ;;
esac
pm_output panel_url "https://$address:8443/"
pm_output username admin
pm_output password "$password"
pm_output notice "On the first login Plesk asks for the administrator's contact details and a licence; a trial licence can be chosen there."
