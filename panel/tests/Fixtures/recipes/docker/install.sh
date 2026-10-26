#!/bin/bash
# Docker Engine from Docker's apt repository (https://docs.docker.com/engine/install/).
set -euo pipefail
# shellcheck source=/dev/null
. "$PROXMOTION_APP_DIR/proxmotion.sh"

# shellcheck source=/dev/null
. /etc/os-release

case "${ID:-}" in
    ubuntu | debian) ;;
    *) pm_fail "This operating system is not supported: ${ID:-unknown}." ;;
esac

pm_step 10 "Adding Docker's package repository"
pm_apt_update
pm_apt_install ca-certificates curl
install -m 0755 -d /etc/apt/keyrings
curl -fsSL "https://download.docker.com/linux/${ID}/gpg" -o /etc/apt/keyrings/docker.asc
chmod a+r /etc/apt/keyrings/docker.asc
echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/${ID} ${VERSION_CODENAME} stable" >/etc/apt/sources.list.d/docker.list

pm_step 40 "Installing Docker Engine"
pm_apt_update
pm_apt_install docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin

pm_step 85 "Starting Docker"
systemctl enable --now docker
docker info >/dev/null || pm_fail "Docker was installed but does not answer."

pm_output docker_version "$(docker version --format '{{.Server.Version}}')"
