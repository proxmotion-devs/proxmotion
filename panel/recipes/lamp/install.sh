#!/bin/bash
# Apache with mod_php, MariaDB and PHP from the distribution's packages, a
# database for the site and an Apache site for /var/www/html. Running it again
# keeps the files, the database and its password.
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

if systemctl is-active --quiet nginx 2>/dev/null; then
    pm_fail "nginx is already running on this server; LAMP needs ports 80 and 443 for Apache."
fi

pm_step 5 "Installing Apache, PHP and MariaDB"
pm_apt_update
pm_apt_install ca-certificates curl apache2 libapache2-mod-php mariadb-server \
    php-cli php-mysql php-curl php-gd php-intl php-mbstring php-xml php-zip
systemctl enable --now mariadb apache2

pm_step 50 "Creating the database"
database_password=$(stack_database "$database")

pm_step 65 "Configuring Apache"
stack_placeholder
cat >"$STACK_SITE" <<APACHE
# Written by the Proxmotion LAMP app.
<VirtualHost *:80>
    ${domain:+ServerName ${domain}}
    DocumentRoot ${STACK_ROOT}
    DirectoryIndex index.php index.html

    <Directory ${STACK_ROOT}>
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog \${APACHE_LOG_DIR}/proxmotion-lamp-error.log
    CustomLog \${APACHE_LOG_DIR}/proxmotion-lamp-access.log combined
</VirtualHost>
APACHE
a2enmod -q rewrite >/dev/null
a2dissite -q 000-default >/dev/null 2>&1 || true
a2ensite -q proxmotion-lamp >/dev/null
apache2ctl configtest >/dev/null 2>&1 || pm_fail "The Apache configuration is invalid."
systemctl reload apache2

site_url="http://$(stack_host "$domain")"
notice="Upload your site to ${STACK_ROOT}; its .htaccess files are honoured."

if [ "$letsencrypt" = true ]; then
    pm_step 80 "Requesting a Let's Encrypt certificate"
    pm_apt_install certbot python3-certbot-apache
    contact=(--register-unsafely-without-email)
    [ -z "$email" ] || contact=(--email "$email")
    if certbot --apache --non-interactive --agree-tos "${contact[@]}" --redirect --keep-until-expiring -d "$domain"; then
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
