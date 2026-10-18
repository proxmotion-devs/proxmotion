#!/bin/bash
# Hestia Control Panel through its official installer
# (https://hestiacp.com/docs/introduction/getting-started), which takes 10 to
# 30 minutes and refuses servers with a web, database or mail server already
# installed. It is never run with --force, which would remove such packages.
set -euo pipefail
# shellcheck source=/dev/null
. "$PROXMOTION_APP_DIR/proxmotion.sh"
# shellcheck source=lib.sh
. "$PROXMOTION_APP_DIR/lib.sh"

# shellcheck source=/dev/null
. /etc/os-release

case "${ID:-}:${VERSION_ID:-}" in
    debian:12 | debian:13 | ubuntu:22.04 | ubuntu:24.04 | ubuntu:26.04) ;;
    *) pm_fail "HestiaCP does not support this operating system: ${PRETTY_NAME:-unknown}." ;;
esac

# The installer asks again for a hostname without two dots or an email address
# it does not accept, which would never end here: check them first.
servername=$(pm_input hostname)
[ -n "$servername" ] || servername=$(pm_server hostname)
servername=${servername%.}
if [[ ! "$servername" =~ ^[A-Za-z0-9-]+(\.[A-Za-z0-9-]+){2,}$ ]] || [[ "$servername" =~ ^[0-9.]+$ ]]; then
    pm_fail "HestiaCP needs a fully qualified hostname such as panel.example.com; \"$servername\" is not one. Give one in the panel hostname field."
fi
email=$(pm_input email)
email=${email:-admin@$servername}
if [[ ! "$email" =~ ^[A-Za-z0-9._%+-]+@[[:alnum:].-]+\.[A-Za-z]{2,63}$ ]]; then
    pm_fail "HestiaCP does not accept the email address $email."
fi

# yes_no KEY: a boolean input as the installer's yes or no (default yes).
yes_no() {
    if [ "$(pm_input "$1")" = false ]; then echo no; else echo yes; fi
}

if hestia_installed; then
    pm_step 90 "HestiaCP is already installed"
else
    pm_step 5 "Checking the server"
    if [ -e /usr/local/hestia ]; then
        pm_fail "An earlier HestiaCP installation did not finish; reinstall the server and try again."
    fi
    if grep -q '^admin:' /etc/passwd /etc/group; then
        pm_fail "A user or group named admin exists: HestiaCP can only be installed on a freshly installed server."
    fi
    for service in apache2 nginx mysql mariadb exim4 postfix; do
        if systemctl is-active --quiet "$service" 2>/dev/null; then
            pm_fail "$service is running: HestiaCP can only be installed on a freshly installed server."
        fi
    done

    pm_step 10 "Downloading the HestiaCP installer"
    pm_apt_update
    pm_apt_install ca-certificates curl wget lsb-release
    # The installer downloads the distribution's installer into the current directory.
    workdir=$(mktemp -d)
    cd "$workdir"
    wget -q https://raw.githubusercontent.com/hestiacp/hestiacp/release/install/hst-install.sh -O hst-install.sh

    mail=$(yes_no mail)
    language=$(pm_input language)
    # A throwaway password: the real one is set afterwards, so it never shows
    # up in the installer's arguments, log or email.
    options=(
        --interactive no
        --hostname "$servername"
        --email "$email"
        --username admin
        --password "$(hestia_password)"
        --lang "${language:-en}"
        --apache "$(yes_no apache)"
        --exim "$mail"
        --dovecot "$mail"
        --spamassassin "$mail"
        --clamav no
    )

    pm_step 15 "Installing HestiaCP (this takes 10 to 30 minutes)"
    if ! bash hst-install.sh "${options[@]}" </dev/null; then
        cd /
        rm -rf "$workdir"
        pm_fail "The HestiaCP installer failed; HestiaCP needs a freshly installed server. Details are in /root/hst_install_backups/."
    fi
    cd /
    rm -rf "$workdir"
fi

hestia_installed || pm_fail "The HestiaCP installer finished but HestiaCP is missing. Details are in /root/hst_install_backups/."

pm_step 90 "Setting the administrator password"
password=$(hestia_password)
hestia_set_admin_password "$password"

address=$(pm_public_address)
case "$address" in
    *:*) address="[$address]" ;;
esac
pm_output panel_url "https://$address:8083/"
pm_output username admin
pm_output password "$password"
pm_output notice "HestiaCP set the server's hostname to $servername and uses a self-signed certificate until one is issued in the panel. Restart the server once to finish the installation."
