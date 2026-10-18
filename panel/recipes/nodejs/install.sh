#!/bin/bash
# Node.js and npm from NodeSource's apt repository
# (https://github.com/nodesource/distributions), set up by hand instead of
# piping NodeSource's setup script into a shell, and optionally PM2
# (https://pm2.keymetrics.io/) started at boot. Running it again updates
# Node.js within the chosen release line.
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

version=$(pm_input version)
version=${version:-24}
case "$version" in
    22 | 24) ;;
    *) pm_fail "Node.js $version is not offered." ;;
esac
pm2=$(pm_input pm2)

pm_step 10 "Adding NodeSource's package repository"
pm_apt_update
pm_apt_install ca-certificates curl gnupg
install -m 0755 -d /etc/apt/keyrings
curl -fsSL https://deb.nodesource.com/gpgkey/nodesource-repo.gpg.key | gpg --dearmor --yes -o "$NODE_KEYRING"
chmod a+r "$NODE_KEYRING"
echo "deb [signed-by=${NODE_KEYRING}] https://deb.nodesource.com/node_${version}.x nodistro main" >"$NODE_SOURCES"
# Prefer NodeSource's nodejs over the distribution's, whatever their version numbers.
cat >"$NODE_PIN" <<PIN
Package: nodejs
Pin: origin deb.nodesource.com
Pin-Priority: 600
PIN

pm_step 40 "Installing Node.js ${version}"
pm_apt_update
pm_apt_install nodejs
installed=$(node --version)
case "$installed" in
    "v${version}."*) ;;
    *) pm_fail "Node.js $installed is installed instead of $version; going back to an older release line needs a reinstall of the server." ;;
esac

notice="Global npm packages go to /usr/lib/node_modules."
if [ "$pm2" != false ]; then
    pm_step 70 "Installing PM2"
    npm install --global --no-fund --no-audit pm2 >/dev/null
    pm2 startup systemd -u root --hp /root >/dev/null
    systemctl enable pm2-root >/dev/null 2>&1 || true
    notice="$notice Start apps with pm2 start app.js, then pm2 save to keep them across restarts."
fi

pm_output node_version "$installed"
pm_output npm_version "$(npm --version)"
pm_output notice "$notice"
