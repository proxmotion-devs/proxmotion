# Development image for the Proxmotion panel (PHP-FPM + Composer + Node).
# Production images will be built separately (Phase 8).
FROM php:8.3-fpm-bookworm

ARG UID=1000
ARG GID=1000

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git unzip curl libicu-dev libzip-dev libpng-dev libonig-dev \
    && docker-php-ext-install -j"$(nproc)" intl zip bcmath pcntl pdo_mysql mbstring gd \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN groupmod -o -g "${GID}" www-data && usermod -o -u "${UID}" www-data

WORKDIR /var/www/panel
USER www-data
