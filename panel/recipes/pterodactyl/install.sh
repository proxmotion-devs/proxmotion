#!/bin/bash
# The Pterodactyl Panel 2.0 open beta, installed as its guide describes
# (https://docs.pterodactyl.io/v2/panel/getting-started): PHP-FPM, MariaDB,
# Redis and nginx from the distribution (PHP from Ondřej Surý's repository
# when it has no 8.3 or 8.4), the release tarball checked against its SHA-256,
# Composer, the queue worker and the scheduler. Running it again keeps the
# panel, its database, its key and its users.
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

email=$(pm_input email)
username=$(pm_input username)
username=${username:-admin}
domain=$(pm_input domain)
letsencrypt=$(pm_input letsencrypt)

[ -n "$email" ] || pm_fail "An administrator email is required."
[ "${#username}" -ge 3 ] || pm_fail "Pterodactyl usernames have at least 3 characters."
if [ "$letsencrypt" = true ] && [ -z "$domain" ]; then
    pm_fail "A Let's Encrypt certificate needs a domain."
fi

pm_step 3 "Checking the server"
if systemctl is-active --quiet apache2 2>/dev/null; then
    pm_fail "Apache is running on this server; Pterodactyl needs ports 80 and 443 for nginx."
fi
for site in proxmotion-wordpress proxmotion-lemp; do
    if [ -e "/etc/nginx/sites-enabled/$site" ]; then
        pm_fail "Another app ($site) already serves this server's web ports."
    fi
done
if [ -e "$PTERO_DIR" ] && ! ptero_installed; then
    pm_fail "$PTERO_DIR already exists and was not installed by this app."
fi

pm_step 5 "Installing PHP, MariaDB, Redis and nginx"
pm_apt_update
pm_apt_install ca-certificates curl gnupg tar unzip cron
php_version=$(ptero_php_version)
pm_apt_install "php${php_version}-"{cli,common,fpm,gd,mysql,mbstring,bcmath,xml,curl,zip,intl} \
    mariadb-server redis-server nginx
systemctl enable --now mariadb redis-server cron "php${php_version}-fpm" nginx

pm_step 25 "Installing Composer"
if ! command -v composer >/dev/null 2>&1; then
    workdir=$(mktemp -d)
    curl -fsSL https://getcomposer.org/download/latest-2.x/composer.phar -o "$workdir/composer.phar"
    expected=$(curl -fsSL https://getcomposer.org/download/latest-2.x/composer.phar.sha256 | cut -d ' ' -f 1)
    if [ "$(sha256sum "$workdir/composer.phar" | cut -d ' ' -f 1)" != "$expected" ]; then
        rm -rf "$workdir"
        pm_fail "The Composer download does not match its checksum."
    fi
    install -m 0755 "$workdir/composer.phar" /usr/local/bin/composer
    rm -rf "$workdir"
fi

database_password=$(ptero_state 1)
[ -n "$database_password" ] || database_password=$(ptero_password)
release=$(ptero_state 2)
if [ ! -f "$PTERO_DIR/artisan" ]; then
    pm_step 30 "Downloading Pterodactyl ${PTERO_RELEASE}"
    workdir=$(mktemp -d)
    curl -fsSL "https://github.com/pterodactyl/panel/releases/download/${PTERO_RELEASE}/panel.tar.gz" -o "$workdir/panel.tar.gz"
    if [ "$(sha256sum "$workdir/panel.tar.gz" | cut -d ' ' -f 1)" != "$PTERO_SHA256" ]; then
        rm -rf "$workdir"
        pm_fail "The Pterodactyl download does not match its checksum."
    fi
    # Unpacked next to its place and moved in whole, so a failed unpack leaves nothing behind.
    rm -rf "$PTERO_DIR.download"
    install -d -m 0755 "$PTERO_DIR.download"
    tar -xzf "$workdir/panel.tar.gz" -C "$PTERO_DIR.download"
    rm -rf "$workdir"
    release=$PTERO_RELEASE
    ptero_save_state "$database_password" "$release"
    mv "$PTERO_DIR.download" "$PTERO_DIR"
fi

pm_step 40 "Creating the database"
mariadb <<SQL
CREATE DATABASE IF NOT EXISTS \`${PTERO_DATABASE}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${PTERO_DATABASE_USER}'@'127.0.0.1';
ALTER USER '${PTERO_DATABASE_USER}'@'127.0.0.1' IDENTIFIED BY '${database_password}';
GRANT ALL PRIVILEGES ON \`${PTERO_DATABASE}\`.* TO '${PTERO_DATABASE_USER}'@'127.0.0.1' WITH GRANT OPTION;
FLUSH PRIVILEGES;
SQL

pm_step 45 "Installing the panel's PHP packages"
[ -f "$PTERO_DIR/.env" ] || cp "$PTERO_DIR/.env.example" "$PTERO_DIR/.env"
chmod 0640 "$PTERO_DIR/.env"
(cd "$PTERO_DIR" && COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --no-interaction --no-progress)
chown -R www-data:www-data "$PTERO_DIR"
chmod -R 755 "$PTERO_DIR/storage" "$PTERO_DIR/bootstrap/cache"
# The key encrypts node tokens, API keys and 2FA secrets: never generate a new one.
if ! grep -q '^APP_KEY=base64:' "$PTERO_DIR/.env"; then
    ptero_artisan key:generate --force
fi

pm_step 60 "Configuring nginx"
host=$(ptero_host "$domain")
cat >"$PTERO_SITE" <<NGINX
# Written by the Proxmotion Pterodactyl app.
server {
    listen 80 default_server;
    listen [::]:80 default_server;
    server_name ${domain:-_};
    root ${PTERO_DIR}/public;
    index index.php;
    charset utf-8;

    access_log off;
    error_log /var/log/nginx/pterodactyl.app-error.log error;

    client_max_body_size 100m;
    client_body_timeout 120s;
    sendfile off;

    add_header X-Content-Type-Options nosniff;
    add_header X-Robots-Tag none;
    add_header Content-Security-Policy "frame-ancestors 'self'";
    add_header X-Frame-Options DENY;
    add_header Referrer-Policy same-origin;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location /assets/ {
        location ~ /\. {
            deny all;
        }
        try_files \$uri =404;
    }

    location ~ \.php\$ {
        fastcgi_split_path_info ^(.+\.php)(/.+)\$;
        fastcgi_pass unix:/run/php/php${php_version}-fpm.sock;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param PHP_VALUE "upload_max_filesize = 100M \n post_max_size=100M";
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        fastcgi_param HTTP_PROXY "";
        fastcgi_intercept_errors off;
        fastcgi_buffer_size 16k;
        fastcgi_buffers 4 16k;
        fastcgi_connect_timeout 300;
        fastcgi_send_timeout 300;
        fastcgi_read_timeout 300;
    }

    location ~ /\.ht {
        deny all;
    }
}
NGINX
ln -sf "$PTERO_SITE" /etc/nginx/sites-enabled/proxmotion-pterodactyl
rm -f /etc/nginx/sites-enabled/default
nginx -t || pm_fail "The nginx configuration is invalid."
systemctl reload nginx

panel_url="http://$host"
notice=""
if [ "$letsencrypt" = true ]; then
    pm_step 65 "Requesting a Let's Encrypt certificate"
    pm_apt_install certbot python3-certbot-nginx
    if certbot --nginx --non-interactive --agree-tos --email "$email" --redirect --keep-until-expiring -d "$domain"; then
        panel_url="https://$domain"
    else
        notice="No Let's Encrypt certificate: check that $domain points to this server, then run the app again. "
    fi
fi

pm_step 70 "Configuring the panel"
# Redis for the cache, sessions and the queue, as the guide asks; telemetry off
# until the owner turns it on (PTERODACTYL_TELEMETRY_ENABLED in .env).
ptero_artisan p:environment:setup --author="$email" --url="$panel_url" --timezone=UTC \
    --cache=redis --session=redis --queue=redis \
    --redis-host=127.0.0.1 --redis-pass= --redis-port=6379 \
    --settings-ui=true --telemetry=false
ptero_artisan p:environment:database --host=127.0.0.1 --port=3306 \
    --database="$PTERO_DATABASE" --username="$PTERO_DATABASE_USER" --password="$database_password"

pm_step 75 "Creating the database tables and the default eggs"
ptero_artisan migrate --seed --force

pm_step 85 "Creating the administrator"
password=""
if ptero_user_exists "$username"; then
    notice="${notice}The user $username already existed; its password was kept. Use Reset the administrator password if needed. "
else
    password=$(ptero_password)
    ptero_artisan p:user:make --email="$email" --username="$username" \
        --name-first=Admin --name-last=User --password="$password" --admin=1 \
        || pm_fail "Pterodactyl refused the administrator; is the email address already in use?"
fi

pm_step 90 "Starting the queue worker and the scheduler"
cat >"$PTERO_WORKER" <<UNIT
# Written by the Proxmotion Pterodactyl app.
[Unit]
Description=Pterodactyl Queue Worker
After=redis-server.service

[Service]
User=www-data
Group=www-data
Restart=always
ExecStart=/usr/bin/php ${PTERO_DIR}/artisan queue:work --queue=high,standard,low --sleep=3 --tries=3
StartLimitInterval=180
StartLimitBurst=30
RestartSec=5s

[Install]
WantedBy=multi-user.target
UNIT
systemctl daemon-reload
systemctl enable pteroq.service >/dev/null 2>&1
systemctl restart pteroq.service
printf '# Written by the Proxmotion Pterodactyl app.\n* * * * * www-data php %s/artisan schedule:run >> /dev/null 2>&1\n' "$PTERO_DIR" >"$PTERO_CRON"
chmod 0644 "$PTERO_CRON"

pm_step 95 "Checking the panel"
code=$(curl -s -o /dev/null -w '%{http_code}' -H "Host: ${domain:-localhost}" http://127.0.0.1/ || true)
case "$code" in
    2?? | 3??) ;;
    *) pm_fail "The panel answers with HTTP $code; see $PTERO_DIR/storage/logs." ;;
esac

pm_output panel_url "$panel_url/"
pm_output username "$username"
[ -z "$password" ] || pm_output password "$password"
pm_output notice "${notice}Pterodactyl 2.0 is a beta. Back up APP_KEY from $PTERO_DIR/.env now: without it node tokens and 2FA secrets cannot be read. Set up email with php artisan p:environment:mail, then add nodes and install Wings on them."
