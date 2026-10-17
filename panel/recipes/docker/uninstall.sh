#!/bin/bash
# Removes Docker Engine and its repository. Images, containers and volumes in
# /var/lib/docker and /var/lib/containerd are kept; delete them by hand when
# they are no longer needed.
set -euo pipefail
# shellcheck source=/dev/null
. "$PROXMOTION_APP_DIR/proxmotion.sh"

pm_step 20 "Stopping Docker"
systemctl disable --now docker.service docker.socket containerd.service 2>/dev/null || true

pm_step 50 "Removing the packages"
DEBIAN_FRONTEND=noninteractive apt-get -o DPkg::Lock::Timeout=600 purge -y -q docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
rm -f /etc/apt/sources.list.d/docker.list /etc/apt/keyrings/docker.asc
pm_apt_update
