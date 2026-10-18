#!/bin/bash
# Docker Engine and Portainer Community Edition as a container
# (https://docs.portainer.io/start/install-ce/server/docker/linux), with the
# administrator created on first start. Running it again keeps Portainer and
# its data and resets the administrator password.
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

port=$(pm_input port)
port=${port:-9443}

pm_step 10 "Installing Docker Engine"
docker_install
pm_apt_update
pm_apt_install curl apache2-utils

if portainer_exists; then
    pm_step 70 "Portainer is already installed"
    docker start "$PORTAINER_CONTAINER" >/dev/null
    password=$(portainer_reset_password)
    notice="Portainer was already installed; its data was kept and the administrator got a new password."
else
    pm_step 50 "Starting Portainer"
    password=$(portainer_password)
    # Portainer takes the first administrator's password as a bcrypt hash.
    hash=$(htpasswd -nbBC 10 '' "$password" | tr -d ':\n')
    docker volume create "$PORTAINER_VOLUME" >/dev/null
    docker run -d --name "$PORTAINER_CONTAINER" --restart=always \
        -p "${port}:9443" \
        -v /var/run/docker.sock:/var/run/docker.sock \
        -v "$PORTAINER_VOLUME:/data" \
        "$PORTAINER_IMAGE" --admin-password "$hash" >/dev/null
    notice="Portainer uses a self-signed certificate: your browser asks you to accept it once."
fi

pm_step 90 "Waiting for Portainer"
for _ in $(seq 1 30); do
    if curl -fsk -o /dev/null "https://127.0.0.1:${port}/api/system/status"; then
        break
    fi
    sleep 2
done
curl -fsk -o /dev/null "https://127.0.0.1:${port}/api/system/status" || pm_fail "Portainer did not start; see docker logs portainer."

pm_output panel_url "$(portainer_url "$port")"
pm_output username admin
pm_output password "$password"
pm_output notice "$notice"
