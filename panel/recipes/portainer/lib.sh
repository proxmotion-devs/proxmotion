#!/bin/bash
# Shared by the Portainer scripts.
# The names are used by the scripts that source this file.
# shellcheck disable=SC2034

PORTAINER_CONTAINER=portainer
PORTAINER_VOLUME=portainer_data
PORTAINER_IMAGE=portainer/portainer-ce:lts

# portainer_password: a random password of 20 letters and digits (Portainer wants at least 12).
portainer_password() {
    head -c 48 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | cut -c 1-20
}

# docker_install: Docker Engine from Docker's apt repository
# (https://docs.docker.com/engine/install/), unless Docker is already there.
docker_install() {
    if command -v docker >/dev/null 2>&1 && docker info >/dev/null 2>&1; then
        return 0
    fi

    # shellcheck source=/dev/null
    . /etc/os-release
    pm_apt_update
    pm_apt_install ca-certificates curl
    install -m 0755 -d /etc/apt/keyrings
    curl -fsSL "https://download.docker.com/linux/${ID}/gpg" -o /etc/apt/keyrings/docker.asc
    chmod a+r /etc/apt/keyrings/docker.asc
    echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/${ID} ${VERSION_CODENAME} stable" >/etc/apt/sources.list.d/docker.list
    pm_apt_update
    pm_apt_install docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
    systemctl enable --now docker
    docker info >/dev/null || pm_fail "Docker was installed but does not answer."
}

# portainer_exists: whether the Portainer container exists (running or not).
portainer_exists() {
    docker container inspect "$PORTAINER_CONTAINER" >/dev/null 2>&1
}

# portainer_reset_password: a new password for the first administrator with
# Portainer's helper (https://docs.portainer.io/advanced/reset-admin), which
# needs the container stopped. Prints the password.
portainer_reset_password() {
    local output password
    docker stop "$PORTAINER_CONTAINER" >/dev/null
    output=$(docker run --rm -v "$PORTAINER_VOLUME:/data" portainer/helper-reset-password 2>&1) || {
        docker start "$PORTAINER_CONTAINER" >/dev/null || true
        pm_fail "Portainer's password reset helper failed: ${output##*$'\n'}"
    }
    docker start "$PORTAINER_CONTAINER" >/dev/null
    # The helper ends with "... login: <password>".
    password=$(printf '%s\n' "$output" | sed -n 's/.*login: *//p' | tail -n 1)
    [ -n "$password" ] || pm_fail "Portainer's password reset helper printed no password."
    printf '%s' "$password"
}

# portainer_url PORT: the HTTPS address of Portainer.
portainer_url() {
    local host
    host=$(pm_public_address)
    case "$host" in
        *:*) host="[$host]" ;;
    esac
    printf 'https://%s:%s/' "$host" "$1"
}
