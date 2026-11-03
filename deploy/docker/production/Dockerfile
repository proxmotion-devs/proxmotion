# syntax=docker/dockerfile:1
#
# Production images of the panel (docs/adr/0054). Build from the repository root:
#   docker build -f deploy/docker/production/Dockerfile --target panel -t proxmotion .
#   docker build -f deploy/docker/production/Dockerfile --target nginx -t proxmotion-nginx .
# The console proxy has its own image (deploy/console-proxy/Dockerfile).
#
# Nothing of an installation is built in: settings come from the environment when a
# container starts, so one image fits every installation.

ARG PHP_VERSION=8.4
ARG NODE_VERSION=22

FROM node:${NODE_VERSION}-bookworm-slim AS node

# PHP with the extensions the panel uses (docs/adr/0052 §2). Build packages and the base
# image's compilers are removed again; only the libraries PHP links against stay.
FROM php:${PHP_VERSION}-fpm-bookworm AS php

RUN set -eux; \
    saved="$(apt-mark showmanual)"; \
    apt-get update; \
    apt-get install -y --no-install-recommends libicu-dev libzip-dev; \
    docker-php-ext-install -j"$(nproc)" bcmath intl opcache pcntl pdo_mysql zip; \
    pecl install redis; \
    docker-php-ext-enable redis; \
    apt-mark auto '.*' > /dev/null; \
    [ -z "$saved" ] || apt-mark manual $saved > /dev/null; \
    apt-mark auto $PHPIZE_DEPS > /dev/null; \
    find /usr/local -type f -executable -exec ldd '{}' ';' 2>/dev/null \
        | awk '/=>/ { so = $(NF-1); if (index(so, "/usr/local/") == 1) { next }; gsub("^/(usr/)?", "", so); printf "*%s\n", so }' \
        | sort -u | xargs -r dpkg-query --search | cut -d: -f1 | sort -u | xargs -r apt-mark manual; \
    apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false; \
    rm -rf /var/lib/apt/lists/* /tmp/pear; \
    php -m | grep -qx redis

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY deploy/docker/production/php.ini "$PHP_INI_DIR/conf.d/zz-proxmotion.ini"
COPY deploy/docker/production/php-fpm.conf /usr/local/etc/php-fpm.d/zz-proxmotion.conf

WORKDIR /var/www/panel

# Dependencies without development packages, then the code.
FROM php AS vendor

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY panel/composer.json panel/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist --no-progress

COPY panel/ ./
RUN set -eux; \
    mkdir -p bootstrap/cache storage/app/private storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs; \
    composer dump-autoload --no-dev --optimize --no-interaction

# The frontend. Its packages are installed before the code arrives, so a code change does
# not install them again; Wayfinder runs php artisan while it builds, hence PHP and the code.
FROM php AS assets

COPY --from=node /usr/local/bin/node /usr/local/bin/node
COPY --from=node /usr/local/lib/node_modules /usr/local/lib/node_modules
COPY panel/package.json panel/package-lock.json panel/.npmrc ./
RUN set -eux; \
    ln -s ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm; \
    npm ci --no-audit --no-fund

COPY --from=vendor /var/www/panel ./
RUN npm run build

# php-fpm, queue workers, the scheduler and Reverb: one role per container (entrypoint.sh).
FROM php AS panel

ARG VERSION=dev
LABEL org.opencontainers.image.title="Proxmotion" \
      org.opencontainers.image.description="Open-source VPS management panel for Proxmox VE" \
      org.opencontainers.image.licenses="AGPL-3.0-or-later" \
      org.opencontainers.image.source="https://github.com/proxmotion-devs/proxmotion" \
      org.opencontainers.image.version="${VERSION}"

# Compiled views stay in the container, like its other caches: on the shared storage/
# volume, containers starting together and two versions during an upgrade would write
# and read the same files (opcache does not check them again).
ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    VIEW_COMPILED_PATH=/var/www/panel/bootstrap/cache/views

# The code belongs to root and is read-only for the panel; it writes only storage/
# (a volume) and bootstrap/cache/ (the caches each container builds when it starts).
COPY --from=vendor /var/www/panel /var/www/panel
COPY --from=assets /var/www/panel/public/build /var/www/panel/public/build
COPY deploy/docker/production/entrypoint.sh /usr/local/bin/proxmotion-entrypoint
RUN set -eux; \
    mkdir -p bootstrap/cache/views; \
    chown -R www-data:www-data storage bootstrap/cache; \
    chmod 0755 /usr/local/bin/proxmotion-entrypoint

USER www-data
VOLUME /var/www/panel/storage
EXPOSE 9000 8080

ENTRYPOINT ["proxmotion-entrypoint"]
CMD ["web"]

# nginx with the public files, in front of php-fpm, Reverb and the console proxy.
FROM nginx:1.29-alpine AS nginx

ARG VERSION=dev
LABEL org.opencontainers.image.title="Proxmotion nginx" \
      org.opencontainers.image.licenses="AGPL-3.0-or-later" \
      org.opencontainers.image.source="https://github.com/proxmotion-devs/proxmotion" \
      org.opencontainers.image.version="${VERSION}"

# Where nginx finds the other containers (the services of compose.yml), and who it asks for
# their addresses (Docker's DNS).
ENV PROXMOTION_FPM=web:9000 \
    PROXMOTION_REVERB=reverb:8080 \
    PROXMOTION_CONSOLE_PROXY=console-proxy:8090 \
    PROXMOTION_RESOLVER=127.0.0.11

COPY --from=assets /var/www/panel/public /var/www/panel/public
COPY deploy/docker/production/nginx.conf.template /etc/nginx/templates/default.conf.template

HEALTHCHECK --interval=30s --timeout=5s CMD wget -qO /dev/null http://127.0.0.1/nginx-health || exit 1

# The console proxy with its production dependencies, for the release archive.
FROM node AS console-proxy

WORKDIR /console-proxy
COPY deploy/console-proxy/package.json deploy/console-proxy/package-lock.json ./
RUN npm ci --omit=dev --no-audit --no-fund
COPY deploy/console-proxy/src ./src

# The release archive the native installer installs (docs/adr/0055): the panel as in the
# panel image, the console proxy and the installer's templates. Exported, not run:
#   docker build -f deploy/docker/production/Dockerfile --target release --output dist .
FROM scratch AS release

COPY --from=vendor /var/www/panel /panel
COPY --from=assets /var/www/panel/public/build /panel/public/build
COPY --from=console-proxy /console-proxy /console-proxy
COPY deploy/installer /installer
