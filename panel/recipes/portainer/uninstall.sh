#!/bin/bash
# Removes the Portainer container, its image and its data volume. Docker
# Engine and the other containers stay, since they may be in use.
set -euo pipefail
# shellcheck source=/dev/null
. "$PROXMOTION_APP_DIR/proxmotion.sh"
# shellcheck source=lib.sh
. "$PROXMOTION_APP_DIR/lib.sh"

command -v docker >/dev/null 2>&1 || exit 0

pm_step 30 "Removing the Portainer container"
docker rm -f "$PORTAINER_CONTAINER" >/dev/null 2>&1 || true

pm_step 70 "Removing Portainer's data and image"
docker volume rm "$PORTAINER_VOLUME" >/dev/null 2>&1 || true
docker image rm "$PORTAINER_IMAGE" portainer/helper-reset-password >/dev/null 2>&1 || true
