FROM dunglas/frankenphp:1-php8.4-alpine

LABEL org.opencontainers.image.title="Navidrome Tools" \
      org.opencontainers.image.description="Self-hosted Symfony app: playlist generator, listening stats, Last.fm import, Lidarr integration, run history." \
      org.opencontainers.image.source="https://github.com/kgaut/navidrome-playlist-generator" \
      org.opencontainers.image.licenses="MIT"

RUN apk add --no-cache git unzip icu-data-full docker-cli \
 && install-php-extensions @composer pdo_sqlite intl opcache

WORKDIR /app

COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-scripts --prefer-dist --no-interaction --no-progress --optimize-autoloader

COPY . .

RUN composer dump-autoload --optimize --no-dev \
 && mkdir -p var plugins /app/.symfony-cache \
 && chown -R www-data:www-data var plugins /app/.symfony-cache \
 && APP_ENV=prod APP_DEBUG=0 APP_CACHE_DIR=/app/.symfony-cache php bin/console cache:warmup --env=prod || true

COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

# APP_CACHE_DIR points the compiled-container cache at the image layer
# (per-container, ephemeral) instead of /app/var (persistent volume,
# shared across container instances). Sharing the cache caused
# `rm -rf var/cache/prod` from one entrypoint to nuke files an
# in-flight CLI in another container was about to require lazily —
# typical symptom: `Failed opening required getConsole_ErrorListenerService.php`
# at ConsoleTerminateEvent after a long fetch. See CLAUDE.md piège #13.
ENV APP_ENV=prod \
    APP_DEBUG=0 \
    APP_MODE=web \
    APP_CACHE_DIR=/app/.symfony-cache \
    SERVER_NAME=":8080"

EXPOSE 8080
VOLUME ["/app/var"]

ENTRYPOINT ["/entrypoint.sh"]
