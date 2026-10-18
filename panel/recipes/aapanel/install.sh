#!/bin/bash
# aaPanel through its official installer (https://www.aapanel.com/new/download.html),
# which installs the panel itself in a few minutes; web servers, PHP and
# databases are chosen in the panel after the first login. The installer
# picks a random port and security path and prints them with a generated
# login, which this script reads from its output.
set -euo pipefail
# shellcheck source=/dev/null
. "$PROXMOTION_APP_DIR/proxmotion.sh"
# shellcheck source=lib.sh
. "$PROXMOTION_APP_DIR/lib.sh"

# shellcheck source=/dev/null
. /etc/os-release

case "${ID:-}:${VERSION_ID%%.*}" in
    debian:12 | ubuntu:22 | ubuntu:24 | almalinux:8 | almalinux:9 | rocky:8 | rocky:9) ;;
    *) pm_fail "aaPanel does not support this operating system: ${PRETTY_NAME:-unknown}." ;;
esac

# The installer's output, kept apart from run.log because it shows the password.
log="$PROXMOTION_RUN_DIR/aapanel-install.log"
touch "$log"
chmod 0600 "$log"

if aapanel_installed; then
    pm_step 90 "aaPanel is already installed"
    /etc/init.d/bt default >"$log" 2>&1 || true
    if ! aapanel_login "$log"; then
        pm_output notice "aaPanel was already installed and no longer shows its password; run bt and choose 5 on the server to set a new one."
    fi
    rm -f "$log"
    exit 0
fi

pm_step 5 "Checking the server"
if [ -e /www ]; then
    pm_fail "/www already exists: aaPanel can only be installed on a freshly installed server."
fi
for service in apache2 httpd nginx mysql mariadb; do
    if systemctl is-active --quiet "$service" 2>/dev/null; then
        pm_fail "$service is running: aaPanel can only be installed on a freshly installed server."
    fi
done

pm_step 10 "Downloading the aaPanel installer"
pm_pkg_install curl
workdir=$(mktemp -d)
curl -fsSL https://www.aapanel.com/script/install_7.0_en.sh -o "$workdir/install.sh"

pm_step 20 "Installing aaPanel (this takes 5 to 15 minutes)"
# The installer asks once whether to install to /www; answer yes, a few times
# over so that a second question cannot wait for ever on an empty input.
if ! (cd "$workdir" && printf 'y\n%.0s' 1 2 3 4 5 | bash install.sh aapanel >"$log" 2>&1); then
    rm -rf "$workdir"
    pm_fail "The aaPanel installer failed: $(grep -v '^[[:space:]]*$' "$log" | tail -n 1)"
fi
rm -rf "$workdir"

aapanel_installed || pm_fail "The aaPanel installer ended without installing the panel: $(grep -v '^[[:space:]]*$' "$log" | tail -n 1)"

pm_step 95 "Reading the login"
if aapanel_login "$log"; then
    pm_output notice "aaPanel uses a self-signed certificate: your browser asks you to accept it once. Choose a web server stack after the first login."
else
    pm_output notice "aaPanel is installed but its login could not be read; run bt default on the server to see it."
fi
# The log holds the password; the panel keeps it encrypted in the outputs.
rm -f "$log"
