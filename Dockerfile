FROM dunglas/frankenphp:1-php8.3-alpine

LABEL org.opencontainers.image.title="Navidrome Tools" \
      org.opencontainers.image.description="Self-hosted Symfony app: playlist generator, listening stats, Last.fm import, Lidarr integration, run history." \
      org.opencontainers.image.source="https://github.com/kgaut/navidrome-playlist-generator" \
      org.opencontainers.image.licenses="MIT"


ARG SUPERCRONIC_VERSION=v0.2.30
ARG SUPERCRONIC_AMD64_SHA1=7da26ce6ab48d75e97f7204554afe7c80779ec77
ARG SUPERCRONIC_ARM64_SHA1=33b06352d1b4c11604a44a3f0aaef47cc6d33e07

RUN apk add --no-cache git unzip icu-data-full \
 && install-php-extensions @composer pdo_sqlite intl opcache \
 && set -eux; \
    arch="$(apk --print-arch)"; \
    case "$arch" in \
        x86_64)  url="https://github.com/aptible/supercronic/releases/download/${SUPERCRONIC_VERSION}/supercronic-linux-amd64";  sha=$SUPERCRONIC_AMD64_SHA1 ;; \
        aarch64) url="https://github.com/aptible/supercronic/releases/download/${SUPERCRONIC_VERSION}/supercronic-linux-arm64";  sha=$SUPERCRONIC_ARM64_SHA1 ;; \
        *) echo "Unsupported arch: $arch" && exit 1 ;; \
    esac; \
    wget -O /usr/local/bin/supercronic "$url" && \
    chmod +x /usr/local/bin/supercronic

WORKDIR /app

COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-scripts --prefer-dist --no-interaction --no-progress --optimize-autoloader

COPY . .

RUN composer dump-autoload --optimize --no-dev \
 && mkdir -p var plugins \
 && chown -R www-data:www-data var plugins \
 && APP_ENV=prod APP_DEBUG=0 php bin/console cache:warmup --env=prod || true

COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

ENV APP_ENV=prod \
    APP_DEBUG=0 \
    APP_MODE=web \
    SERVER_NAME=":8080"

EXPOSE 8080
VOLUME ["/app/var"]

ENTRYPOINT ["/entrypoint.sh"]
