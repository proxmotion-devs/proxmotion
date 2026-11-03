#!/usr/bin/env bash
# Proxmotion's native installer for Debian 12/13 and Ubuntu 22.04/24.04 (docs/adr/0055,
# docs/guides/native-install.md). It installs nginx, PHP 8.4 (php-fpm), MariaDB, Redis and
# Node.js 22, the panel under /opt/proxmotion and its systemd units. Run it again with a
# newer version to upgrade; a run with the installed version repairs the configuration.
#
#   sudo bash install.sh --domain panel.example.com --letsencrypt --email you@example.com
#
# Never log its output to a file: the first administrator's generated password is shown
# once (docs/adr/0052 §3).
set -euo pipefail

readonly REPOSITORY="proxmotion-devs/proxmotion"
readonly PREFIX="/opt/proxmotion"
readonly PHP_VERSION="8.4"
readonly NODE_MAJOR="22"
readonly APP_USER="proxmotion"
readonly KEEP_RELEASES=3

domain=""
email=""
letsencrypt=0
version=""
archive=""
admin_name=""
admin_email=""

usage() {
    cat <<'USAGE'
Usage: install.sh [options]

  --domain NAME        The panel's host name (empty: any name, HTTP only)
  --letsencrypt        Get a Let's Encrypt certificate for --domain and serve HTTPS
                       (accepts the Let's Encrypt Subscriber Agreement)
  --email ADDRESS      Contact address for Let's Encrypt
  --version X.Y.Z      The release to install (default: the latest release)
  --archive FILE       Install from a downloaded proxmotion-X.Y.Z.tar.gz instead
  --admin-name NAME    First administrator, without questions (first install only)
  --admin-email EMAIL  Its email address; a password is generated and shown once
  -h, --help           Show this help

Options are remembered in /opt/proxmotion/shared/installer.conf for later runs.
USAGE
}

log() { printf '\n==> %s\n' "$*"; }
die() { printf '\nError: %s\n' "$*" >&2; exit 1; }

as_app() { runuser -u "$APP_USER" -- "$@"; }
artisan() { local release="$1"; shift; as_app "/usr/bin/php$PHP_VERSION" "$release/panel/artisan" "$@"; }

parse_options() {
    while [ "$#" -gt 0 ]; do
        case "$1" in
            --domain) domain="${2:-}"; shift 2 ;;
            --email) email="${2:-}"; shift 2 ;;
            --letsencrypt) letsencrypt=1; shift ;;
            --version) version="${2:-}"; shift 2 ;;
            --archive) archive="${2:-}"; shift 2 ;;
            --admin-name) admin_name="${2:-}"; shift 2 ;;
            --admin-email) admin_email="${2:-}"; shift 2 ;;
            -h|--help) usage; exit 0 ;;
            *) usage >&2; die "Unknown option: $1" ;;
        esac
    done
}

# Earlier runs' choices, unless given again.
load_saved_options() {
    local file="$PREFIX/shared/installer.conf" key value

    [ -f "$file" ] || return 0

    while IFS='=' read -r key value; do
        case "$key" in
            domain) [ -n "$domain" ] || domain="$value" ;;
            email) [ -n "$email" ] || email="$value" ;;
            letsencrypt) [ "$letsencrypt" = 1 ] || letsencrypt="$value" ;;
        esac
    done < "$file"
}

check_options() {
    if [ -n "$domain" ] && ! [[ "$domain" =~ ^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$ ]]; then
        die "--domain must be a lowercase host name such as panel.example.com."
    fi

    if [ "$letsencrypt" = 1 ]; then
        [ -n "$domain" ] || die "--letsencrypt needs --domain."
        [[ "$email" =~ ^[^@[:space:]]+@[^@[:space:]]+\.[^@[:space:]]+$ ]] || die "--letsencrypt needs --email with a valid address."
    fi

    if [ -n "$version" ] && ! [[ "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.-]+)?$ ]]; then
        die "--version must look like 1.2.3."
    fi

    if [ -n "$archive" ] && [ ! -f "$archive" ]; then
        die "The archive $archive does not exist."
    fi
}

check_system() {
    [ "$(id -u)" -eq 0 ] || die "Run the installer as root (sudo bash install.sh …)."
    [ -r /etc/os-release ] || die "Unknown operating system: /etc/os-release is missing."

    # shellcheck source=/dev/null
    . /etc/os-release

    case "${ID:-}:${VERSION_ID:-}" in
        debian:12|debian:13|ubuntu:22.04|ubuntu:24.04) ;;
        *) die "Supported are Debian 12 and 13 and Ubuntu 22.04 and 24.04, not ${PRETTY_NAME:-this system}." ;;
    esac

    os_id="$ID"
    os_codename="${VERSION_CODENAME:-}"
    [ -n "$os_codename" ] || die "/etc/os-release has no VERSION_CODENAME."

    case "$(dpkg --print-architecture)" in
        amd64|arm64) ;;
        *) die "Supported are amd64 and arm64." ;;
    esac
}

add_repository() {
    local name="$1" key_url="$2" line="$3"
    local keyring="/etc/apt/keyrings/$name.gpg" list="/etc/apt/sources.list.d/$name.list"

    if [ ! -f "$keyring" ]; then
        curl -fsSL "$key_url" | gpg --dearmor --yes -o "$keyring"
        chmod 0644 "$keyring"
    fi

    printf 'deb [signed-by=%s] %s\n' "$keyring" "$line" > "$list"
}

install_packages() {
    log "Installing packages"
    export DEBIAN_FRONTEND=noninteractive

    apt-get update -q
    apt-get install -y -q --no-install-recommends ca-certificates curl gnupg
    install -d -m 0755 /etc/apt/keyrings

    # PHP 8.4: Ondřej Surý's packages, the same builds on Debian and Ubuntu.
    if [ "$os_id" = debian ]; then
        add_repository sury-php https://packages.sury.org/php/apt.gpg "https://packages.sury.org/php/ $os_codename main"
    elif [ ! -f "/etc/apt/sources.list.d/ondrej-ubuntu-php-$os_codename.list" ] && [ ! -f "/etc/apt/sources.list.d/ondrej-ubuntu-php-$os_codename.sources" ]; then
        apt-get install -y -q --no-install-recommends software-properties-common
        add-apt-repository -y ppa:ondrej/php
    fi

    # Ubuntu 22.04 has MariaDB 10.6; the panel needs 10.11 or newer (MariaDB's 11.4 LTS).
    if [ "$os_id:$os_codename" = ubuntu:jammy ]; then
        add_repository mariadb https://supplychain.mariadb.com/mariadb-keyring-2019.gpg "https://dlm.mariadb.com/repo/mariadb-server/11.4/repo/ubuntu jammy main"
    fi

    # Node.js for the console proxy; the distributions' own are older than it needs.
    add_repository nodesource https://deb.nodesource.com/gpgkey/nodesource-repo.gpg.key "https://deb.nodesource.com/node_$NODE_MAJOR.x nodistro main"

    local packages=(
        nginx mariadb-server redis-server nodejs
        "php$PHP_VERSION-fpm" "php$PHP_VERSION-cli" "php$PHP_VERSION-mysql" "php$PHP_VERSION-redis"
        "php$PHP_VERSION-intl" "php$PHP_VERSION-zip" "php$PHP_VERSION-bcmath" "php$PHP_VERSION-mbstring"
        "php$PHP_VERSION-xml" "php$PHP_VERSION-curl"
    )
    [ "$letsencrypt" = 1 ] && packages+=(certbot)

    apt-get update -q
    apt-get install -y -q --no-install-recommends "${packages[@]}"
    systemctl enable --now mariadb redis-server nginx "php$PHP_VERSION-fpm"
}

create_user() {
    if ! id "$APP_USER" > /dev/null 2>&1; then
        log "Creating the system user $APP_USER"
        useradd --system --home-dir "$PREFIX" --no-create-home --shell /usr/sbin/nologin "$APP_USER"
    fi

    install -d -m 0755 "$PREFIX" "$PREFIX/releases"
    install -d -m 0750 -o root -g "$APP_USER" "$PREFIX/shared"
}

# The release to install: --archive, --version, or the latest one on GitHub.
fetch_release() {
    work="$(mktemp -d)"
    trap 'rm -rf "$work"' EXIT

    if [ -z "$archive" ]; then
        if [ -z "$version" ]; then
            version="$(curl -fsSL "https://api.github.com/repos/$REPOSITORY/releases/latest" | sed -n 's/.*"tag_name": *"v\([^"]*\)".*/\1/p' | head -n 1)"
            [ -n "$version" ] || die "Could not find the latest release of $REPOSITORY; give --version."
        fi

        local base="https://github.com/$REPOSITORY/releases/download/v$version/proxmotion-$version.tar.gz"
        log "Downloading Proxmotion $version"
        curl -fsSL -o "$work/proxmotion-$version.tar.gz" "$base"
        curl -fsSL -o "$work/proxmotion-$version.tar.gz.sha256" "$base.sha256"
        (cd "$work" && sha256sum -c --quiet "proxmotion-$version.tar.gz.sha256") || die "The download does not match its checksum."
        archive="$work/proxmotion-$version.tar.gz"
    fi

    tar -xzf "$archive" -C "$work"
    local unpacked
    unpacked="$(find "$work" -mindepth 1 -maxdepth 1 -type d -name 'proxmotion-*' | head -n 1)"
    if [ -z "$unpacked" ] || [ ! -f "$unpacked/panel/VERSION" ]; then
        die "$archive is not a Proxmotion release archive."
    fi

    local archived
    archived="$(tr -d '[:space:]' < "$unpacked/panel/VERSION")"
    [ -z "$version" ] || [ "$version" = "$archived" ] || die "The archive holds version $archived, not $version."
    version="$archived"

    release="$PREFIX/releases/$version"

    if [ -d "$release" ]; then
        log "Proxmotion $version is unpacked already"
        return
    fi

    log "Unpacking Proxmotion $version"
    chown -R root:root "$unpacked"
    mv "$unpacked" "$release"
}

# What every release shares: .env and storage/. The panel may write storage/ and its
# release's bootstrap/cache/, nothing else; .env is readable only.
link_shared() {
    local shared="$PREFIX/shared"

    if [ ! -d "$shared/storage" ]; then
        cp -a "$release/panel/storage" "$shared/storage"
    fi
    install -d "$shared/storage/app/private" "$shared/storage/framework/cache/data" \
        "$shared/storage/framework/sessions" "$shared/storage/framework/views" "$shared/storage/logs"
    chown -R "$APP_USER:$APP_USER" "$shared/storage"
    chown -R "$APP_USER:$APP_USER" "$release/panel/bootstrap/cache"

    rm -rf "$release/panel/storage"
    ln -sfn "$shared/storage" "$release/panel/storage"
    ln -sfn "$shared/.env" "$release/panel/.env"
}

random() { head -c "$1" /dev/urandom | od -An -tx1 | tr -d ' \n'; }

# On the first run only: the database, its user and .env. Later runs never touch .env.
write_environment() {
    local env="$PREFIX/shared/.env"

    [ -f "$env" ] && return

    log "Creating the database and $env"
    local db_password url
    db_password="$(random 24)"

    mariadb <<SQL
CREATE DATABASE IF NOT EXISTS proxmotion CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'proxmotion'@'localhost' IDENTIFIED BY '$db_password';
ALTER USER 'proxmotion'@'localhost' IDENTIFIED BY '$db_password';
GRANT ALL PRIVILEGES ON proxmotion.* TO 'proxmotion'@'localhost';
SQL

    if [ "$letsencrypt" = 1 ]; then
        url="https://$domain"
    elif [ -n "$domain" ]; then
        url="http://$domain"
    else
        url="http://$(hostname -I | awk '{ print $1 }')"
    fi

    umask 027
    cat > "$env" <<ENV
# Proxmotion (docs/guides/native-install.md), written by install.sh on the first install and
# never changed by it again. All variables: panel/.env.example of the release.
APP_NAME=Proxmotion
APP_ENV=production
APP_KEY=base64:$(head -c 32 /dev/urandom | base64)
APP_DEBUG=false
APP_URL=$url
APP_LOCALE=en
APP_FALLBACK_LOCALE=en
# install.sh puts the panel into maintenance mode during upgrades through this file driver.
APP_MAINTENANCE_DRIVER=file

LOG_CHANNEL=daily
LOG_LEVEL=info

DB_CONNECTION=mariadb
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=proxmotion
DB_USERNAME=proxmotion
DB_PASSWORD=$db_password

SESSION_DRIVER=redis
SESSION_LIFETIME=120
SESSION_SECURE_COOKIE=$([ "$letsencrypt" = 1 ] && echo true || echo false)
CACHE_STORE=redis
QUEUE_CONNECTION=redis
REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379

# A TLS proxy in front of nginx: its addresses (docs/adr/0054). Empty when nginx faces the internet.
TRUSTED_PROXIES=

BROADCAST_CONNECTION=reverb
REVERB_APP_ID=$(random 4)
REVERB_APP_KEY=$(random 10)
REVERB_APP_SECRET=$(random 20)
REVERB_HOST=127.0.0.1
REVERB_PORT=8080
REVERB_SCHEME=http
REVERB_PUBLIC_HOST=
REVERB_PUBLIC_PORT=
REVERB_PUBLIC_SCHEME=

CONSOLE_PROXY_SECRET=$(random 32)

# Set your SMTP server for password resets and notifications (docs/adr/0044).
MAIL_MAILER=log
MAIL_HOST=
MAIL_PORT=587
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_FROM_ADDRESS=
MAIL_FROM_NAME=Proxmotion

PROXMOTION_REQUIRE_ADMIN_2FA=true
ENV
    umask 022
    chown "root:$APP_USER" "$env"
    chmod 0640 "$env"
}

save_options() {
    printf 'domain=%s\nemail=%s\nletsencrypt=%s\n' "$domain" "$email" "$letsencrypt" > "$PREFIX/shared/installer.conf"
}

render() {
    sed -e "s|@PREFIX@|$PREFIX|g" -e "s|@PHP_VERSION@|$PHP_VERSION|g" \
        -e "s|@DOMAIN@|$domain|g" -e "s|@SERVER_NAME@|${domain:-_}|g" "$1" > "$2"
}

configure_services() {
    local templates="$release/installer"

    log "Configuring php-fpm, nginx and the systemd units"
    render "$templates/php-fpm.conf" "/etc/php/$PHP_VERSION/fpm/pool.d/proxmotion.conf"

    # The console proxy needs only its secret from .env.
    local secret
    secret="$(sed -n 's/^CONSOLE_PROXY_SECRET=//p' "$PREFIX/shared/.env" | tail -n 1)"
    printf 'CONSOLE_PROXY_SECRET=%s\n' "$secret" > "$PREFIX/shared/console-proxy.env"
    chown "root:$APP_USER" "$PREFIX/shared/console-proxy.env"
    chmod 0640 "$PREFIX/shared/console-proxy.env"

    install -d /var/www/letsencrypt
    render "$templates/nginx/panel.conf" /etc/nginx/snippets/proxmotion-panel.conf
    render "$templates/nginx/internal.conf" /etc/nginx/sites-available/proxmotion-internal
    if [ "$letsencrypt" = 1 ] && [ -f "/etc/letsencrypt/live/$domain/fullchain.pem" ]; then
        render "$templates/nginx/site-https.conf" /etc/nginx/sites-available/proxmotion
    else
        render "$templates/nginx/site-http.conf" /etc/nginx/sites-available/proxmotion
    fi
    ln -sfn /etc/nginx/sites-available/proxmotion /etc/nginx/sites-enabled/proxmotion
    ln -sfn /etc/nginx/sites-available/proxmotion-internal /etc/nginx/sites-enabled/proxmotion-internal
    # The panel is the host's default site.
    rm -f /etc/nginx/sites-enabled/default

    local unit
    for unit in "$templates"/systemd/*.service; do
        render "$unit" "/etc/systemd/system/$(basename "$unit")"
    done

    nginx -t -q || die "nginx refused the configuration (nginx -t says why)."
    systemctl daemon-reload
    systemctl reload "php$PHP_VERSION-fpm" nginx
}

obtain_certificate() {
    [ "$letsencrypt" = 1 ] || return 0
    [ -f "/etc/letsencrypt/live/$domain/fullchain.pem" ] && return 0

    log "Getting a Let's Encrypt certificate for $domain"
    certbot certonly --webroot -w /var/www/letsencrypt -d "$domain" -m "$email" \
        --agree-tos --non-interactive --deploy-hook "systemctl reload nginx" \
        || die "certbot could not get a certificate: $domain must point to this server and port 80 be reachable."

    render "$release/installer/nginx/site-https.conf" /etc/nginx/sites-available/proxmotion
    nginx -t -q || die "nginx refused the HTTPS configuration (nginx -t says why)."
    systemctl reload nginx
}

switch_to_release() {
    ln -sfn "$release" "$PREFIX/current.new"
    mv -T "$PREFIX/current.new" "$PREFIX/current"
    # The pool drops its opcache and realpath cache of the previous release.
    systemctl reload "php$PHP_VERSION-fpm"
}

start_units() {
    systemctl enable --quiet proxmotion-worker proxmotion-scheduler proxmotion-reverb proxmotion-console-proxy
    # Started on the current release; a running worker ends after its job (queue:restart).
    systemctl restart proxmotion-scheduler proxmotion-console-proxy
    systemctl start proxmotion-worker proxmotion-reverb
}

first_install() {
    log "Installing Proxmotion $version"
    local options=(--no-interaction)

    if [ -n "$admin_name" ] || [ -n "$admin_email" ]; then
        options+=("--admin-name=$admin_name" "--admin-email=$admin_email")
    elif [ -t 0 ]; then
        options=()
    fi

    artisan "$release" proxmotion:install "${options[@]}" || die "php artisan proxmotion:install failed (see above). Fix the cause and run the installer again."
    switch_to_release
}

# Maintenance mode through the shared storage/ before the switch, so neither release
# serves the other's database; proxmotion:upgrade then keeps it (docs/adr/0052 §4).
upgrade() {
    local previous
    previous="$(readlink -f "$PREFIX/current")"
    local down_file="$PREFIX/shared/storage/framework/down" closed=0

    if [ "$previous" = "$release" ]; then
        log "Running the upgrade steps of Proxmotion $version again"
    else
        log "Upgrading from $(basename "$previous") to Proxmotion $version"
    fi

    if [ ! -f "$down_file" ]; then
        artisan "$release" down --retry=60 || die "The panel could not go into maintenance mode: nothing was changed."
        closed=1
    fi

    switch_to_release

    if ! artisan "$release" proxmotion:upgrade --no-interaction; then
        die "The upgrade failed and the panel stays in maintenance mode. Fix the cause and run the installer again. The previous release is $previous; going back to it after migrations ran may not work."
    fi

    if [ "$closed" = 1 ]; then
        artisan "$release" up
    fi
}

prune_releases() {
    local current old
    current="$(readlink -f "$PREFIX/current")"

    # Newest first by version; the current one always stays.
    find "$PREFIX/releases" -mindepth 1 -maxdepth 1 -type d -printf '%f\n' | sort -rV | tail -n "+$((KEEP_RELEASES + 1))" |
        while read -r old; do
            [ "$PREFIX/releases/$old" = "$current" ] || rm -rf "${PREFIX:?}/releases/$old"
        done
}

main() {
    # The panel's user cannot read most working directories, e.g. root's home.
    cd /
    parse_options "$@"
    load_saved_options
    check_options
    check_system
    install_packages
    create_user
    fetch_release
    link_shared
    write_environment
    save_options
    configure_services

    if [ -L "$PREFIX/current" ]; then
        upgrade
    else
        first_install
    fi

    start_units
    obtain_certificate
    prune_releases

    log "Proxmotion $version is ready at $(sed -n 's/^APP_URL=//p' "$PREFIX/shared/.env" | tail -n 1)"
    cat <<DONE
Settings: $PREFIX/shared/.env. Set MAIL_* there for password resets and notifications, then
run this installer again: it rebuilds the cached configuration and restarts the services.
Logs: $PREFIX/shared/storage/logs and journalctl -u 'proxmotion-*'.
DONE
}

main "$@"
