#!/bin/bash
# Shared by the Coolify scripts.

# coolify_installed: whether Coolify's containers are there.
coolify_installed() {
    [ -f /data/coolify/source/.env ] && command -v docker >/dev/null 2>&1 &&
        docker container inspect coolify >/dev/null 2>&1
}

# coolify_password: a random password Coolify accepts (upper and lower case,
# digits and a symbol, 24 characters).
coolify_password() {
    printf 'Cf%s7#' "$(head -c 48 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | cut -c 1-20)"
}

# coolify_url: the address of Coolify's dashboard (port 8000).
coolify_url() {
    local host
    host=$(pm_public_address)
    case "$host" in
        *:*) host="[$host]" ;;
    esac
    printf 'http://%s:8000/' "$host"
}
