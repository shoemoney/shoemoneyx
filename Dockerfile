# shoemoneyx — one image, many roles: see docker/entrypoint.sh for the ROLE table
# (worker, feeder, desk, artisan, web, queue, schedule, reverb). A second build target, `nginx`,
# fronts the `web` role's php-fpm over HTTPS. Multi-arch:
#   docker buildx build --platform linux/arm64,linux/amd64 --target app   -t ghcr.io/shoemoney/shoemoneyx:latest       --push .
#   docker buildx build --platform linux/arm64,linux/amd64 --target nginx -t ghcr.io/shoemoney/shoemoneyx-nginx:latest --push .
FROM php:8.4-fpm-bookworm AS base
RUN echo 'APT::Sandbox::User "root";' > /etc/apt/apt.conf.d/99qemu \
    && apt-get update && apt-get install -y --no-install-recommends git unzip libzip-dev libsodium-dev libicu-dev libgmp-dev \
    && docker-php-ext-install -j1 pdo_mysql bcmath sodium intl zip pcntl opcache gmp \
    && pecl install redis && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*
# opcache on for CLI too: backtests are hot loops over the same classes, and this base now also
# serves php-fpm (the fpm image ships the php CLI binary as well, which is why it can run both).
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

FROM node:22-bookworm-slim AS frontend-build
WORKDIR /app
COPY package.json package-lock.json ./
COPY .fa-pro ./.fa-pro
RUN npm ci
COPY . .
# REVERB_APP_KEY is the pusher-protocol public key (like Pusher's own JS key: identifies the app,
# not a secret) — fixed here so every self-hoster's prebuilt image agrees with the REVERB_APP_KEY
# docker/up.sh writes to .env. Port/scheme are fixed too because nginx always fronts Reverb on the
# one published port, 443; only the hostname differs per self-hoster, and resources/js/echo.js
# already falls back to window.location.hostname when VITE_REVERB_HOST is unset, so we leave it unset.
ENV VITE_REVERB_APP_KEY=shoemoneyx-desk \
    VITE_REVERB_PORT=443 \
    VITE_REVERB_SCHEME=https
RUN npm run build

FROM base AS app
COPY --from=deps /app/vendor ./vendor
COPY . .
RUN composer dump-autoload --optimize --no-dev && php artisan package:discover --ansi || true
COPY --from=frontend-build /app/public/build ./public/build
# node for the feeder role
COPY --from=node:22-bookworm-slim /usr/local/bin/node /usr/local/bin/node
COPY --from=feeder-deps /feeder/node_modules ./node_modules
COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh && mkdir -p storage/logs storage/framework/{cache,sessions,views} bootstrap/cache && chmod -R 777 storage bootstrap/cache
ENV ROLE=worker WORKERS=0 REDIS_CLIENT=phpredis
ENTRYPOINT ["/entrypoint.sh"]

FROM nginx:1.27-alpine AS nginx
RUN apk add --no-cache openssl \
    && mkdir -p /etc/ssl/shoemoneyx \
    && openssl req -x509 -nodes -days 3650 -newkey rsa:2048 \
       -keyout /etc/ssl/shoemoneyx/selfsigned.key -out /etc/ssl/shoemoneyx/selfsigned.crt \
       -subj "/CN=shoemoneyx-desk"
COPY --from=app /app/public /app/public
COPY docker/nginx-shoemoneyx.conf /etc/nginx/conf.d/default.conf
EXPOSE 443
