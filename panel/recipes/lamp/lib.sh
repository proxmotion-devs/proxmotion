#!/bin/bash
# Shared by the LAMP scripts.
# The paths are used by the scripts that source this file.
# shellcheck disable=SC2034

STACK_ROOT=/var/www/html
STACK_SITE=/etc/apache2/sites-available/proxmotion-lamp.conf
# The database name, user and password, kept so that running the app again keeps them.
STACK_CREDENTIALS=/etc/proxmotion/lamp-database

# stack_password: a random password of 24 letters and digits.
stack_password() {
    head -c 48 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | cut -c 1-24
}

# stack_database NAME: create the database NAME with a user of the same name
# (the password kept from an earlier run), and print the password.
stack_database() {
    local name=$1 password=""

    if [ -f "$STACK_CREDENTIALS" ] && [ "$(sed -n 1p "$STACK_CREDENTIALS")" = "$name" ]; then
        password=$(sed -n 2p "$STACK_CREDENTIALS")
    fi
    [ -n "$password" ] || password=$(stack_password)

    mariadb <<SQL
CREATE DATABASE IF NOT EXISTS \`${name}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${name}'@'localhost';
ALTER USER '${name}'@'localhost' IDENTIFIED BY '${password}';
GRANT ALL PRIVILEGES ON \`${name}\`.* TO '${name}'@'localhost';
FLUSH PRIVILEGES;
SQL

    install -d -m 0700 "$(dirname "$STACK_CREDENTIALS")"
    (umask 077 && printf '%s\n%s\n' "$name" "$password" >"$STACK_CREDENTIALS")
    printf '%s' "$password"
}

# stack_host DOMAIN: the domain, or the server's address for URLs.
stack_host() {
    local host=$1
    if [ -z "$host" ]; then
        host=$(pm_public_address)
        case "$host" in
            *:*) host="[$host]" ;;
        esac
    fi
    printf '%s' "$host"
}

# stack_placeholder: a first page in an empty document root.
stack_placeholder() {
    install -d -m 0755 "$STACK_ROOT"
    if [ -z "$(find "$STACK_ROOT" -mindepth 1 -maxdepth 1 ! -name index.html -print -quit)" ]; then
        rm -f "$STACK_ROOT/index.html"
        cat >"$STACK_ROOT/index.php" <<'PHP'
<?php
// Written by the Proxmotion app. Replace it with your site.
echo '<h1>It works</h1><p>PHP ' . PHP_VERSION . ' is running. Upload your site to this directory.</p>';
PHP
    fi
    chown -R www-data:www-data "$STACK_ROOT"
}
