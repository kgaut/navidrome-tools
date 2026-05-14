FROM dunglas/frankenphp:1-php8.4-alpine

LABEL org.opencontainers.image.title="Navidrome Tools" \
      org.opencontainers.image.description="Self-hosted Symfony app: Last.fm scrobble import, Navidrome/Strawberry sync, listening stats." \
      org.opencontainers.image.source="https://github.com/kgaut/navidrome-tools" \
      org.opencontainers.image.licenses="MIT"

RUN apk add --no-cache git unzip icu-data-full docker-cli \
 && install-php-extensions @composer pdo_sqlite intl opcache

WORKDIR /app

COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-scripts --prefer-dist --no-interaction --no-progress --optimize-autoloader

COPY . .

RUN composer dump-autoload --optimize --no-dev \
 && mkdir -p var \
 && chown -R www-data:www-data var \
 && APP_ENV=prod APP_DEBUG=0 APP_CACHE_DIR=/app/.symfony-cache php bin/console cache:warmup --env=prod || true

COPY docker/php.ini /usr/local/etc/php/conf.d/99-navidrome-tools.ini
COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

ARG APP_VERSION=dev

ENV APP_ENV=prod \
    APP_DEBUG=0 \
    APP_MODE=web \
    APP_VERSION=${APP_VERSION} \
    APP_CACHE_DIR=/app/.symfony-cache \
    SERVER_NAME=":8080"

EXPOSE 8080
VOLUME ["/app/var"]

ENTRYPOINT ["/entrypoint.sh"]
