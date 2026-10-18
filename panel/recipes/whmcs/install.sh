#!/bin/bash
# WHMCS on nginx, PHP-FPM with the ionCube Loader and MariaDB, installed with
# WHMCS's command-line installer (bin/installer.php in the release's install
# directory) from the zip at the customer's download address and with their
# licence key. WHMCS 9 needs PHP 8.2 or 8.3 (Debian 12, Ubuntu 24.04).
set -euo pipefail
# shellcheck source=/dev/null
. "$PROXMOTION_APP_DIR/proxmotion.sh"
# shellcheck source=lib.sh
. "$PROXMOTION_APP_DIR/lib.sh"

# shellcheck source=/dev/null
. /etc/os-release

case "${ID:-}:${VERSION_ID:-}" in
    debian:12 | ubuntu:24.04) ;;
    *) pm_fail "WHMCS needs PHP 8.2 or 8.3: this app supports Debian 12 and Ubuntu 24.04, not ${PRETTY_NAME:-this system}." ;;
esac

[ "$(uname -m)" = x86_64 ] || pm_fail "The ionCube Loader that WHMCS needs is only installed on x86_64 servers."

download_url=$(pm_input download_url)
license_key=$(pm_input license_key)
admin_user=$(pm_input admin_user)
admin_user=${admin_user:-whmcsadmin}
domain=$(pm_input domain)
letsencrypt=$(pm_input letsencrypt)
email=$(pm_input email)

[ "$(pm_input accept_eula)" = true ] || pm_fail "The WHMCS End User Licence Agreement has to be accepted."
case "$download_url" in
    https://*) ;;
    *) pm_fail "The WHMCS download address must start with https://." ;;
esac
[ -n "$license_key" ] || pm_fail "A WHMCS licence key is required."
if [ "$letsencrypt" = true ] && [ -z "$domain" ]; then
    pm_fail "A Let's Encrypt certificate needs a domain."
fi

[ ! -f "$WHMCS_DONE" ] || pm_fail "WHMCS is already installed in $WHMCS_ROOT; reinstall the server to install it again."

if [ -z "$domain" ]; then
    for site in /etc/nginx/sites-enabled/proxmotion-*; do
        if [ -e "$site" ] && [ "$site" != /etc/nginx/sites-enabled/proxmotion-whmcs ]; then
            pm_fail "Another app's site answers on this server's address; give WHMCS a domain."
        fi
    done
fi

if systemctl is-active --quiet apache2 2>/dev/null; then
    pm_fail "Apache is already running on this server; WHMCS needs ports 80 and 443 for nginx."
fi

pm_step 5 "Installing nginx, PHP and MariaDB"
pm_apt_update
pm_apt_install ca-certificates curl unzip nginx mariadb-server \
    php-fpm php-cli php-mysql php-curl php-gd php-imap php-intl php-mbstring php-xml php-zip \
    php-bcmath php-gmp php-soap
systemctl enable --now mariadb nginx
php_version=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
systemctl enable --now "php${php_version}-fpm"

pm_step 20 "Installing the ionCube Loader"
if ! php -m | grep -qi '^ionCube Loader$'; then
    download=$(mktemp -d)
    curl -fsSL https://downloads.ioncube.com/loader_downloads/ioncube_loaders_lin_x86-64.tar.gz -o "$download/ioncube.tar.gz"
    tar -xzf "$download/ioncube.tar.gz" -C "$download"
    loader="$download/ioncube/ioncube_loader_lin_${php_version}.so"
    if [ ! -f "$loader" ]; then
        rm -rf "$download"
        pm_fail "The ionCube Loader has no build for PHP ${php_version}."
    fi
    extension_dir=$(php -r 'echo ini_get("extension_dir");')
    install -m 0644 "$loader" "$extension_dir/ioncube_loader_lin_${php_version}.so"
    rm -rf "$download"
    # Loaded before every other Zend extension, as ionCube requires.
    printf '; priority=00\nzend_extension=%s\n' "$extension_dir/ioncube_loader_lin_${php_version}.so" >"/etc/php/${php_version}/mods-available/ioncube.ini"
    phpenmod -v "$php_version" ioncube
    systemctl restart "php${php_version}-fpm"
fi
php -m | grep -qi '^ionCube Loader$' || pm_fail "PHP does not load the ionCube Loader."

pm_step 30 "Downloading WHMCS"
# What a failed attempt left behind is removed: the installer needs an empty database.
rm -rf "$WHMCS_ROOT"
whmcs_drop_database
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
if ! curl -fsSL --proto '=https' --max-filesize 314572800 "$download_url" -o "$work/whmcs.zip"; then
    pm_fail "WHMCS could not be downloaded from the address given; download links from the WHMCS members area expire, so fetch a new one."
fi
unzip -tq "$work/whmcs.zip" >/dev/null 2>&1 || pm_fail "The download is not a zip file: give the address of the WHMCS full release zip."
unzip -q "$work/whmcs.zip" -d "$work/files"
installer=$(find "$work/files" -maxdepth 4 -path '*/install/bin/installer.php' -print -quit)
[ -n "$installer" ] || pm_fail "The zip does not contain WHMCS (install/bin/installer.php is missing)."
release=$(dirname "$(dirname "$(dirname "$installer")")")
[ -f "$release/init.php" ] || pm_fail "The zip does not contain WHMCS (init.php is missing)."
mv "$release" "$WHMCS_ROOT"
rm -f "$WHMCS_ROOT/configuration.php" "$WHMCS_ROOT/configuration.php.new"

pm_step 45 "Creating the database"
db_password=$(whmcs_random 24)
mariadb <<SQL
CREATE DATABASE whmcs CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'whmcs'@'localhost' IDENTIFIED BY '${db_password}';
GRANT ALL PRIVILEGES ON whmcs.* TO 'whmcs'@'localhost';
FLUSH PRIVILEGES;
SQL

pm_step 55 "Installing WHMCS"
admin_password=$(whmcs_random 20)
# The configuration goes in on standard input, so the licence key and passwords never show in a command line.
config=$(jq -cn \
    --arg user "$admin_user" --arg password "$admin_password" --arg license "$license_key" \
    --arg db_password "$db_password" --arg hash "$(whmcs_random 64)" \
    '{admin: {username: $user, password: $password}, configuration: {license: $license, db_host: "localhost", db_username: "whmcs", db_password: $db_password, db_name: "whmcs", cc_encryption_hash: $hash, mysql_charset: "utf8"}}')
if ! (cd "$WHMCS_ROOT/install" && printf '%s\n' "$config" | php -f bin/installer.php -- -i -n -c); then
    pm_fail "The WHMCS installer failed; check the licence key and that the release supports PHP ${php_version}."
fi
[ -f "$WHMCS_ROOT/configuration.php" ] || pm_fail "The WHMCS installer did not write configuration.php."

pm_step 70 "Securing the files"
rm -rf "$WHMCS_ROOT/install"
chown -R root:root "$WHMCS_ROOT"
for writable in attachments downloads templates_c; do
    install -d -o www-data -g www-data -m 0750 "$WHMCS_ROOT/$writable"
    chown -R www-data:www-data "$WHMCS_ROOT/$writable"
done
chown root:www-data "$WHMCS_ROOT/configuration.php"
chmod 0440 "$WHMCS_ROOT/configuration.php"
cat >"$WHMCS_CRON" <<CRON
# Written by the Proxmotion WHMCS app: WHMCS's automation tasks.
*/5 * * * * www-data php -q ${WHMCS_ROOT}/crons/cron.php
CRON
chmod 0644 "$WHMCS_CRON"

pm_step 75 "Configuring nginx"
listen="listen 80; listen [::]:80;"
server_name=$domain
if [ -z "$domain" ]; then
    listen="listen 80 default_server; listen [::]:80 default_server;"
    server_name=_
fi
cat >"$WHMCS_NGINX_SITE" <<NGINX
# Written by the Proxmotion WHMCS app.
server {
    ${listen}
    server_name ${server_name};
    root ${WHMCS_ROOT};
    index index.php;
    client_max_body_size 32m;

    location ~ ^/(attachments|crons|downloads|templates_c|vendor)/ {
        deny all;
    }

    location ~ (\.tpl|/configuration\.php)\$ {
        deny all;
    }

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php\$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php${php_version}-fpm.sock;
    }

    location ~ /\.(?!well-known) {
        deny all;
    }
}
NGINX
ln -sf "$WHMCS_NGINX_SITE" /etc/nginx/sites-enabled/proxmotion-whmcs
[ -n "$domain" ] || rm -f /etc/nginx/sites-enabled/default
nginx -t || pm_fail "The nginx configuration is invalid."
systemctl reload nginx

if [ -n "$domain" ]; then
    host=$domain
else
    host=$(pm_public_address)
    case "$host" in
        *:*) host="[$host]" ;;
    esac
fi
site_url="http://$host"
notice="Set up email, payment gateways and the other settings under System Settings."

if [ "$letsencrypt" = true ]; then
    pm_step 85 "Requesting a Let's Encrypt certificate"
    pm_apt_install certbot python3-certbot-nginx
    account=(--register-unsafely-without-email)
    [ -z "$email" ] || account=(--email "$email")
    if certbot --nginx --non-interactive --agree-tos "${account[@]}" --redirect --keep-until-expiring -d "$domain"; then
        site_url="https://$domain"
    else
        notice="No Let's Encrypt certificate: check that $domain points to this server, then request one with certbot."
    fi
fi

pm_step 92 "Setting the system address"
# The values are a validated domain or the server's address.
mariadb whmcs <<SQL
UPDATE tblconfiguration SET value = '${site_url}/' WHERE setting = 'SystemURL';
INSERT INTO tblconfiguration (setting, value) SELECT 'SystemURL', '${site_url}/' FROM DUAL
    WHERE NOT EXISTS (SELECT 1 FROM tblconfiguration WHERE setting = 'SystemURL');
SQL
touch "$WHMCS_DONE"

pm_output site_url "$site_url/"
pm_output admin_url "$site_url/admin/"
pm_output username "$admin_user"
pm_output password "$admin_password"
pm_output notice "$notice"
