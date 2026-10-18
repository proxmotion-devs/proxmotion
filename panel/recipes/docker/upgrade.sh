#!/bin/bash
# Upgrades Docker Engine from an earlier version of this recipe to the
# current version in Docker's apt repository. Images, containers and volumes
# are kept; running containers restart with the daemon.
set -euo pipefail
# shellcheck source=/dev/null
. "$PROXMOTION_APP_DIR/proxmotion.sh"

[ -s /etc/apt/sources.list.d/docker.list ] || pm_fail "Docker's package repository is missing; reinstall the app."

pm_step 20 "Upgrading Docker Engine"
pm_apt_update
pm_apt_upgrade docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin

pm_step 85 "Restarting Docker"
systemctl restart docker
docker info >/dev/null || pm_fail "Docker was upgraded but does not answer."

pm_output docker_version "$(docker version --format '{{.Server.Version}}')"
