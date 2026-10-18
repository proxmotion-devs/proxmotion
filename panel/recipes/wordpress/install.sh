#!/bin/bash
# WordPress on nginx, PHP-FPM and MariaDB from the distribution's packages,
# installed and configured with WP-CLI (https://wp-cli.org/). Running it again
# keeps the site and its database and only sets a new administrator password.
set -euo pipefail
# shellcheck source=/dev/null
. "$PROXMOTION_APP_DIR/proxmotion.sh"
# shellcheck source=lib.sh
. "$PROXMOTION_APP_DIR/lib.sh"

# shellcheck source=/dev/null
. /etc/os-release

case "${ID:-}" in
    ubuntu | debian) ;;
    *) pm_fail "This operating system is not supported: ${ID:-unknown}." ;;
esac

admin_email=$(pm_input admin_email)
admin_user=$(pm_input admin_user)
admin_user=${admin_user:-admin}
site_title=$(pm_input site_title)
site_title=${site_title:-WordPress}
language=$(pm_input language)
language=${language:-en_US}
domain=$(pm_input domain)
letsencrypt=$(pm_input letsencrypt)

[ -n "$admin_email" ] || pm_fail "An administrator email is required."
if [ "$letsencrypt" = true ] && [ -z "$domain" ]; then
    pm_fail "A Let's Encrypt certificate needs a domain."
fi

if systemctl is-active --quiet apache2 2>/dev/null; then
    pm_fail "Apache is already running on this server; WordPress needs ports 80 and 443 for nginx."
fi

pm_step 5 "Installing nginx, PHP and MariaDB"
pm_apt_update
pm_apt_install ca-certificates curl nginx mariadb-server \
    php-fpm php-cli php-mysql php-curl php-gd php-intl php-mbstring php-xml php-zip php-imagick
systemctl enable --now mariadb nginx
php_version=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
systemctl enable --now "php${php_version}-fpm"

pm_step 35 "Installing WP-CLI"
if [ ! -x "$WP_CLI" ]; then
    download=$(mktemp -d)
    curl -fsSL https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar -o "$download/wp-cli.phar"
    curl -fsSL https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar.sha512 -o "$download/wp-cli.phar.sha512"
    if ! echo "$(tr -d '[:space:]' <"$download/wp-cli.phar.sha512")  $download/wp-cli.phar" | sha512sum -c --quiet -; then
        rm -rf "$download"
        pm_fail "The WP-CLI download did not match its checksum."
    fi
    install -m 0755 "$download/wp-cli.phar" "$WP_CLI"
    rm -rf "$download"
fi

pm_step 45 "Downloading WordPress"
install -d -o www-data -g www-data -m 0755 "$WP_ROOT"
# The web server's user needs a writable home for WP-CLI's cache.
install -d -o www-data -g www-data -m 0700 /var/www/.wp-cli
if [ ! -f "$WP_ROOT/wp-includes/version.php" ]; then
    wp core download --locale="$language"
fi

pm_step 60 "Creating the database"
if [ -f "$WP_ROOT/wp-config.php" ]; then
    db_password=$(wp config get DB_PASSWORD)
else
    db_password=$(wp_password)
fi
mariadb <<SQL
CREATE DATABASE IF NOT EXISTS wordpress CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'wordpress'@'localhost';
ALTER USER 'wordpress'@'localhost' IDENTIFIED BY '${db_password}';
GRANT ALL PRIVILEGES ON wordpress.* TO 'wordpress'@'localhost';
FLUSH PRIVILEGES;
SQL
if [ ! -f "$WP_ROOT/wp-config.php" ]; then
    wp config create --dbname=wordpress --dbuser=wordpress --dbpass="$db_password" --dbhost=localhost --dbcharset=utf8mb4 --locale="$language"
    chmod 0640 "$WP_ROOT/wp-config.php"
fi

pm_step 70 "Configuring nginx"
server_name=${domain:-_}
cat >"$WP_NGINX_SITE" <<NGINX
# Written by the Proxmotion WordPress app.
server {
    listen 80 default_server;
    listen [::]:80 default_server;
    server_name ${server_name};
    root ${WP_ROOT};
    index index.php;
    client_max_body_size 64m;

    location / {
        try_files \$uri \$uri/ /index.php?\$args;
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
ln -sf "$WP_NGINX_SITE" /etc/nginx/sites-enabled/proxmotion-wordpress
rm -f /etc/nginx/sites-enabled/default
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
notice=""

if [ "$letsencrypt" = true ]; then
    pm_step 80 "Requesting a Let's Encrypt certificate"
    pm_apt_install certbot python3-certbot-nginx
    if certbot --nginx --non-interactive --agree-tos --email "$admin_email" --redirect --keep-until-expiring -d "$domain"; then
        site_url="https://$domain"
    else
        notice="No Let's Encrypt certificate: check that $domain points to this server, then run the app again."
    fi
fi

pm_step 85 "Setting up WordPress"
admin_password=$(wp_password)
if wp core is-installed 2>/dev/null; then
    wp option update home "$site_url" >/dev/null
    wp option update siteurl "$site_url" >/dev/null
    wp_set_admin_password "$admin_user" "$admin_password"
else
    wp core install --url="$site_url" --title="$site_title" --admin_user="$admin_user" \
        --admin_password="$admin_password" --admin_email="$admin_email" --skip-email
fi

pm_output site_url "$site_url/"
pm_output admin_url "$site_url/wp-admin/"
pm_output username "$admin_user"
pm_output password "$admin_password"
[ -z "$notice" ] || pm_output notice "$notice"
