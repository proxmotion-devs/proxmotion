#!/bin/bash
# Shared by the Pterodactyl scripts.
# The names are used by the scripts that source this file.
# shellcheck disable=SC2034

PTERO_DIR=/var/www/pterodactyl
# The release this recipe version installs and the SHA-256 of its panel.tar.gz
# (https://github.com/pterodactyl/panel/releases).
PTERO_RELEASE=v2.0.0-beta.2
PTERO_SHA256=51f997aa742094bb3de8365e196b532b485684ff951af7acb2ca1325440a5b64
PTERO_SITE=/etc/nginx/sites-available/proxmotion-pterodactyl
PTERO_WORKER=/etc/systemd/system/pteroq.service
PTERO_CRON=/etc/cron.d/proxmotion-pterodactyl
# The database password and the release, kept so that running the app again
# keeps the database and never replaces a panel it did not install.
PTERO_STATE=/etc/proxmotion/pterodactyl
PTERO_DATABASE=panel
PTERO_DATABASE_USER=pterodactyl

# ptero_password: a random password of 24 letters and digits.
ptero_password() {
    head -c 48 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | cut -c 1-24
}

# ptero_state LINE: one line of the state file (1 = database password, 2 = release).
ptero_state() {
    [ ! -f "$PTERO_STATE" ] || sed -n "${1}p" "$PTERO_STATE"
}

# ptero_save_state DATABASE_PASSWORD RELEASE
ptero_save_state() {
    install -d -m 0700 "$(dirname "$PTERO_STATE")"
    (umask 077 && printf '%s\n%s\n' "$1" "$2" >"$PTERO_STATE")
}

# ptero_installed: whether this app installed the panel here.
ptero_installed() {
    [ -f "$PTERO_DIR/artisan" ] && [ -n "$(ptero_state 2)" ]
}

# ptero_artisan ARG...: the panel's artisan as www-data, without prompts.
ptero_artisan() {
    (cd "$PTERO_DIR" && runuser -u www-data -- php artisan --no-interaction "$@")
}

# ptero_php_version: the PHP version to use (the panel needs 8.3 or 8.4),
# adding Ondřej Surý's repository when the distribution has neither.
ptero_php_version() {
    local version

    for version in 8.3 8.4; do
        if apt-cache show --no-all-versions "php${version}-fpm" >/dev/null 2>&1; then
            printf '%s' "$version"
            return 0
        fi
    done

    # shellcheck source=/dev/null
    . /etc/os-release
    if [ "$ID" = ubuntu ]; then
        pm_apt_install software-properties-common gpg-agent >&2
        LC_ALL=C.UTF-8 add-apt-repository -y ppa:ondrej/php >&2
    else
        local keyring=/etc/apt/keyrings/sury-php.gpg
        install -m 0755 -d /etc/apt/keyrings
        curl -fsSL https://packages.sury.org/php/apt.gpg -o "$keyring.download"
        if grep -q -- '-----BEGIN PGP' "$keyring.download"; then
            gpg --dearmor --yes -o "$keyring" "$keyring.download"
            rm -f "$keyring.download"
        else
            mv "$keyring.download" "$keyring"
        fi
        chmod a+r "$keyring"
        echo "deb [signed-by=${keyring}] https://packages.sury.org/php/ ${VERSION_CODENAME} main" >/etc/apt/sources.list.d/sury-php.list
    fi
    pm_apt_update >&2

    apt-cache show --no-all-versions php8.3-fpm >/dev/null 2>&1 || pm_fail "PHP 8.3 is not available for this system."
    printf '8.3'
}

# ptero_host DOMAIN: the domain, or the server's address for URLs.
ptero_host() {
    local host=$1
    if [ -z "$host" ]; then
        host=$(pm_public_address)
        case "$host" in
            *:*) host="[$host]" ;;
        esac
    fi
    printf '%s' "$host"
}

# ptero_user_exists USERNAME: whether the panel has a user with this name
# (a slug, so it is safe in the query).
ptero_user_exists() {
    [ "$(mariadb -N -B -e "SELECT COUNT(*) FROM \`${PTERO_DATABASE}\`.users WHERE username = '$1'" 2>/dev/null)" != 0 ]
}

# ptero_set_password USERNAME PASSWORD: a new password for a panel user. The
# values reach PHP through the environment, never through the code.
ptero_set_password() {
    # shellcheck disable=SC2016
    (cd "$PTERO_DIR" && PTERO_USERNAME=$1 PTERO_PASSWORD=$2 runuser -u www-data -- php artisan --no-interaction tinker --execute '
        $user = \Pterodactyl\Models\User::query()->where("username", getenv("PTERO_USERNAME"))->firstOrFail();
        $user->password = getenv("PTERO_PASSWORD");
        $user->save();
    ') >/dev/null
}
