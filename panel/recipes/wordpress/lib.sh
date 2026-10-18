#!/bin/bash
# Shared by the WordPress scripts.

WP_ROOT=/var/www/wordpress
WP_CLI=/usr/local/bin/wp
WP_NGINX_SITE=/etc/nginx/sites-available/proxmotion-wordpress

# wp ARGS...: WP-CLI as the web server's user in the site's directory, with
# its cache in that user's home.
wp() {
    runuser -u www-data -- env HOME=/var/www WP_CLI_CACHE_DIR=/var/www/.wp-cli/cache php "$WP_CLI" --path="$WP_ROOT" "$@"
}

# wp_password: a random password of 20 letters and digits.
wp_password() {
    head -c 48 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | cut -c 1-20
}

# wp_set_admin_password USER PASSWORD: set the password, creating the
# administrator when the user does not exist.
wp_set_admin_password() {
    if wp user get "$1" --field=ID >/dev/null 2>&1; then
        wp user update "$1" --user_pass="$2" --skip-email >/dev/null
    else
        wp user create "$1" "$(pm_app_input admin_email)" --role=administrator --user_pass="$2" >/dev/null
    fi
}
