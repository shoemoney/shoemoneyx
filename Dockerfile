# shoemoneyx — one image, three roles: worker (backtest queue), feeder (websocket), desk (trading loop).
# Multi-arch: docker buildx build --platform linux/arm64,linux/amd64 -t ghcr.io/shoemoney/shoemoneyx:latest --push .
FROM php:8.4-cli-bookworm AS base
RUN echo 'APT::Sandbox::User "root";' > /etc/apt/apt.conf.d/99qemu \
    && apt-get update && apt-get install -y --no-install-recommends git unzip libzip-dev libsodium-dev libicu-dev \
    && docker-php-ext-install -j1 pdo_mysql bcmath sodium intl zip pcntl opcache \
    && pecl install redis && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*
# opcache on for CLI: backtests are hot loops over the same classes
RUN { echo 'opcache.enable_cli=1'; echo 'opcache.jit=tracing'; echo 'opcache.jit_buffer_size=128M'; echo 'memory_limit=1G'; } > /usr/local/etc/php/conf.d/shoemoneyx.ini
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app

FROM base AS deps
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-scripts --no-autoloader --prefer-dist

FROM node:22-bookworm-slim AS feeder-deps
WORKDIR /feeder
COPY package.json package-lock.json ./
# Font Awesome Pro is installed from local tarballs (.fa-pro, gitignored): the feeder stage needs them for npm ci
COPY .fa-pro ./.fa-pro
RUN npm ci --omit=dev --ignore-scripts

FROM base AS app
COPY --from=deps /app/vendor ./vendor
COPY . .
RUN composer dump-autoload --optimize --no-dev && php artisan package:discover --ansi || true
# node for the feeder role
COPY --from=node:22-bookworm-slim /usr/local/bin/node /usr/local/bin/node
COPY --from=feeder-deps /feeder/node_modules ./node_modules
COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh && mkdir -p storage/logs storage/framework/{cache,sessions,views} bootstrap/cache && chmod -R 777 storage bootstrap/cache
ENV ROLE=worker WORKERS=0 REDIS_CLIENT=phpredis
ENTRYPOINT ["/entrypoint.sh"]
