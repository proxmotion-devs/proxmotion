#!/bin/bash
# nginx, PHP-FPM and MariaDB from the distribution's packages, a database for
# the site and an nginx site for /var/www/html. Running it again keeps the
# files, the database and its password.
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

domain=$(pm_input domain)
letsencrypt=$(pm_input letsencrypt)
email=$(pm_input email)
database=$(pm_input database)
database=${database:-site}
database=${database//-/_}

if [ "$letsencrypt" = true ] && [ -z "$domain" ]; then
    pm_fail "A Let's Encrypt certificate needs a domain."
fi

if systemctl is-active --quiet apache2 2>/dev/null; then
    pm_fail "Apache is already running on this server; LEMP needs ports 80 and 443 for nginx."
fi
if [ -e /etc/nginx/sites-enabled/proxmotion-wordpress ]; then
    pm_fail "The WordPress app already serves this server's web ports."
fi

pm_step 5 "Installing nginx, PHP and MariaDB"
pm_apt_update
pm_apt_install ca-certificates curl nginx mariadb-server \
    php-fpm php-cli php-mysql php-curl php-gd php-intl php-mbstring php-xml php-zip
systemctl enable --now mariadb nginx
php_version=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
systemctl enable --now "php${php_version}-fpm"

pm_step 50 "Creating the database"
database_password=$(stack_database "$database")

pm_step 65 "Configuring nginx"
stack_placeholder
cat >"$STACK_SITE" <<NGINX
# Written by the Proxmotion LEMP app.
server {
    listen 80 default_server;
    listen [::]:80 default_server;
    server_name ${domain:-_};
    root ${STACK_ROOT};
    index index.php index.html;
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
ln -sf "$STACK_SITE" /etc/nginx/sites-enabled/proxmotion-lemp
rm -f /etc/nginx/sites-enabled/default
nginx -t || pm_fail "The nginx configuration is invalid."
systemctl reload nginx

site_url="http://$(stack_host "$domain")"
notice="Upload your site to ${STACK_ROOT}; requests for missing files go to index.php."

if [ "$letsencrypt" = true ]; then
    pm_step 80 "Requesting a Let's Encrypt certificate"
    pm_apt_install certbot python3-certbot-nginx
    contact=(--register-unsafely-without-email)
    [ -z "$email" ] || contact=(--email "$email")
    if certbot --nginx --non-interactive --agree-tos "${contact[@]}" --redirect --keep-until-expiring -d "$domain"; then
        site_url="https://$domain"
    else
        notice="No Let's Encrypt certificate: check that $domain points to this server, then run the app again."
    fi
fi

pm_output site_url "$site_url/"
pm_output document_root "$STACK_ROOT"
pm_output database "$database"
pm_output database_password "$database_password"
pm_output notice "$notice"
