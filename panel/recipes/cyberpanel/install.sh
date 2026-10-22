#!/bin/bash
# CyberPanel with OpenLiteSpeed through its official installer
# (https://cyberpanel.net/KnowledgeBase/home/install-cyberpanel/), which takes
# 20 to 60 minutes. The installer exits with status 0 on many errors, so the
# result is checked afterwards.
set -euo pipefail
# shellcheck source=/dev/null
. "$PROXMOTION_APP_DIR/proxmotion.sh"
# shellcheck source=lib.sh
. "$PROXMOTION_APP_DIR/lib.sh"

# shellcheck source=/dev/null
. /etc/os-release

major=${VERSION_ID:-}
major=${major%%.*}

case "${ID:-}:${major}" in
    almalinux:8 | almalinux:9 | almalinux:10) ;;
    # cyberpanel.net/install.sh does not recognise Rocky Linux.
    rocky:*)
        pm_fail "CyberPanel's installer does not recognise Rocky Linux: use AlmaLinux 8 to 10, or Ubuntu 22.04, 24.04 or 26.04."
        ;;
    ubuntu:22 | ubuntu:24 | ubuntu:26)
        case "${VERSION_ID:-}" in
            22.04 | 24.04 | 26.04) ;;
            *) pm_fail "CyberPanel supports the Ubuntu LTS releases 22.04, 24.04 and 26.04 only." ;;
        esac
        ;;
    *) pm_fail "CyberPanel does not support this operating system: ${PRETTY_NAME:-unknown}." ;;
esac

[ "$(uname -m)" = x86_64 ] || pm_fail "CyberPanel needs an x86_64 server."

installer_log="$PROXMOTION_RUN_DIR/cyberpanel-install.log"
throwaway=

# cyberpanel_error: the last Python exception or ERROR line the installer
# printed (it ends a traceback with "TypeError: ..."), without colours and the
# throwaway password.
cyberpanel_error() {
    local line=

    if [ -s "$installer_log" ]; then
        line=$(sed -e 's/\x1b\[[0-9;]*m//g' -e 's/\r//g' "$installer_log" \
            | grep -E '^([A-Za-z_][A-Za-z0-9_.]*(Error|Exception)|ERROR):' | tail -n 1 || true)
    fi
    [ -z "$throwaway" ] || line=${line//"$throwaway"/***}

    echo "${line:-it printed no error}" | cut -c 1-300
}

if cyberpanel_installed; then
    pm_step 90 "CyberPanel is already installed"
else
    pm_step 5 "Checking the server"
    if [ -e /usr/local/CyberCP ]; then
        pm_fail "An earlier CyberPanel installation did not finish; reinstall the server and try again."
    fi
    for service in apache2 httpd nginx mysqld mysql mariadb; do
        if systemctl is-active --quiet "$service" 2>/dev/null; then
            pm_fail "$service is running: CyberPanel can only be installed on a freshly installed server."
        fi
    done

    pm_step 10 "Downloading the CyberPanel installer"
    # The installer puts acme.sh into $HOME/.acme.sh but then runs
    # /root/.acme.sh/acme.sh, and systemd-run sets no HOME. acme.sh refuses
    # to install without crontab, which minimal cloud images lack, and the
    # installer ignores that and retries the missing acme.sh 50 times.
    export HOME=/root
    if command -v apt-get >/dev/null 2>&1; then
        pm_pkg_install curl git cron
    else
        pm_pkg_install curl git cronie
    fi
    systemctl enable --now cron 2>/dev/null || systemctl enable --now crond 2>/dev/null || true
    # The installer downloads the actual installer into the current directory.
    workdir=$(mktemp -d)
    cd "$workdir"
    curl -fsSL https://cyberpanel.net/install.sh -o install.sh

    # OpenLiteSpeed: LiteSpeed Enterprise needs a licence key and is not offered.
    # A throwaway password: the real one is set afterwards.
    throwaway=$(cyberpanel_password)
    options=(--version ols --password "$throwaway")
    [ "$(pm_input services)" != false ] || options+=(--minimal)
    [ "$(pm_input addons)" != true ] || options+=(--addons)

    pm_step 15 "Installing CyberPanel (this takes 20 to 60 minutes)"
    # A copy of the output for the error message; the output still goes to
    # the run's log.
    bash install.sh "${options[@]}" </dev/null 2>&1 | tee "$installer_log" || true
    cd /
    rm -rf "$workdir"
fi

if ! cyberpanel_installed; then
    pm_fail "The CyberPanel installer failed: $(cyberpanel_error). Its log is /var/log/installLogs.txt; reinstall the server before trying again."
fi

pm_step 90 "Setting the administrator password"
password=$(cyberpanel_password)
cyberpanel_set_admin_password "$password"

address=$(pm_public_address)
case "$address" in
    *:*) address="[$address]" ;;
esac
pm_output panel_url "https://$address:8090/"
pm_output username admin
pm_output password "$password"
pm_output notice "CyberPanel uses a self-signed certificate until one is issued in the panel. Restart the server once to finish the installation."
