#!/bin/bash
# Coolify through its official installer (https://coolify.io/docs/get-started/installation),
# which installs Docker and runs Coolify's containers under /data/coolify. The
# first user is created by the installer from ROOT_USERNAME, ROOT_USER_EMAIL
# and ROOT_USER_PASSWORD, so the dashboard is never left open for anyone to
# register first.
set -euo pipefail
# shellcheck source=/dev/null
. "$PROXMOTION_APP_DIR/proxmotion.sh"
# shellcheck source=lib.sh
. "$PROXMOTION_APP_DIR/lib.sh"

# shellcheck source=/dev/null
. /etc/os-release

case "${ID:-}" in
    ubuntu | debian) ;;
    *) pm_fail "This operating system is not supported: ${ID:-unknown}." ;;
esac

email=$(pm_input email)
username=$(pm_input username)
username=${username:-admin}
[ -n "$email" ] || pm_fail "An administrator email is required."

if coolify_installed; then
    pm_step 90 "Coolify is already installed"
    pm_output panel_url "$(coolify_url)"
    pm_output email "$email"
    pm_output notice "Coolify was already installed; its users and password were kept. Use Forgot password on the login page if needed."
    exit 0
fi

pm_step 5 "Checking the server"
for service in apache2 nginx; do
    if systemctl is-active --quiet "$service" 2>/dev/null; then
        pm_fail "$service is running and holds port 80 or 443, which Coolify's proxy needs."
    fi
done

pm_step 10 "Downloading the Coolify installer"
pm_apt_update
pm_apt_install ca-certificates curl
workdir=$(mktemp -d)
curl -fsSL https://cdn.coollabs.io/coolify/install.sh -o "$workdir/install.sh"

pm_step 20 "Installing Coolify (this takes 5 to 15 minutes)"
password=$(coolify_password)
if ! env ROOT_USERNAME="$username" ROOT_USER_EMAIL="$email" ROOT_USER_PASSWORD="$password" \
    DEBIAN_FRONTEND=noninteractive bash "$workdir/install.sh" </dev/null; then
    rm -rf "$workdir"
    pm_fail "The Coolify installer failed; its log is in /data/coolify/source/installation-*.log."
fi
rm -rf "$workdir"

pm_step 90 "Waiting for Coolify"
for _ in $(seq 1 60); do
    if curl -fs -o /dev/null http://127.0.0.1:8000/api/health; then
        break
    fi
    sleep 5
done
if ! coolify_installed || ! curl -fs -o /dev/null http://127.0.0.1:8000/api/health; then
    pm_fail "Coolify did not start; see docker logs coolify."
fi

pm_output panel_url "$(coolify_url)"
pm_output email "$email"
pm_output password "$password"
pm_output notice "Log in with the email address. Set a domain for Coolify under Settings to get HTTPS for the dashboard."
