#!/bin/bash
# cPanel & WHM through cPanel's official installer
# (https://docs.cpanel.net/installation-guide/install/). The installer wants a
# freshly installed server and takes 30 to 60 minutes; there is no uninstall.
# WHM is reached as root with the server's root password.
set -euo pipefail
# shellcheck source=/dev/null
. "$PROXMOTION_APP_DIR/proxmotion.sh"

# shellcheck source=/dev/null
. /etc/os-release

major=${VERSION_ID:-}
major=${major%%.*}

case "${ID:-}:${major}" in
    almalinux:8 | almalinux:9 | almalinux:10 | rocky:8 | rocky:9) ;;
    ubuntu:24) [ "${VERSION_ID:-}" = 24.04 ] || pm_fail "cPanel supports Ubuntu 24.04 only." ;;
    *) pm_fail "cPanel does not support this operating system: ${PRETTY_NAME:-unknown}." ;;
esac

if [ -x /usr/local/cpanel/cpanel ]; then
    pm_step 90 "cPanel is already installed"
else
    pm_step 5 "Checking the server"
    for service in apache2 httpd nginx mysqld mariadb; do
        if systemctl is-active --quiet "$service" 2>/dev/null; then
            pm_fail "$service is running: cPanel can only be installed on a freshly installed server."
        fi
    done

    # cPanel's requirements: no OS firewall during the install (Ubuntu) and the multi-user target.
    if command -v ufw >/dev/null 2>&1; then
        ufw disable >/dev/null 2>&1 || true
        systemctl disable --now ufw >/dev/null 2>&1 || true
    fi
    systemctl set-default multi-user.target >/dev/null

    pm_step 10 "Downloading the cPanel installer"
    command -v curl >/dev/null 2>&1 || pm_pkg_install curl
    cd /home
    curl -fsSL -o latest https://securedownloads.cpanel.net/latest

    pm_step 15 "Installing cPanel & WHM (this takes 30 to 60 minutes)"
    # CloudLinux is a licensing decision of the provider; the conversion is never started here.
    if ! sh latest --skip-cloudlinux; then
        pm_fail "The cPanel installer failed; cPanel needs a freshly installed server. Details are in /var/log/cpanel-install.log."
    fi
    rm -f /home/latest
fi

[ -x /usr/local/cpanel/cpanel ] || pm_fail "The cPanel installer finished but cPanel is missing. Details are in /var/log/cpanel-install.log."

address=$(pm_public_address)
case "$address" in
    *:*) address="[$address]" ;;
esac
pm_output whm_url "https://$address:2087/"
pm_output username root
pm_output notice "Log in with the server's root password. cPanel starts a 15-day trial licence for this address; a licence has to be bought for it afterwards."
