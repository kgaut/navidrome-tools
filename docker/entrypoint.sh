#!/bin/sh
set -eu

cd /app

mkdir -p var

CACHE_DIR="${APP_CACHE_DIR:-/app/var/cache}"
composer dump-autoload --no-dev --optimize --working-dir=/app --no-interaction --quiet
rm -rf "${CACHE_DIR}/${APP_ENV:-prod}"
php bin/console cache:warmup --env="${APP_ENV:-prod}"

# Apply Doctrine migrations on every boot (idempotent).
php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration --env="${APP_ENV:-prod}" || {
    echo "Migration failed" >&2
    exit 1
}

MODE="${APP_MODE:-web}"

case "$MODE" in
    web)
        exec frankenphp run --config /etc/caddy/Caddyfile
        ;;

    worker)
        exec php bin/console messenger:consume async --time-limit=3600 --memory-limit=256M -vv
        ;;

    cli)
        shift || true
        exec php bin/console "$@"
        ;;

    *)
        echo "Unknown APP_MODE='$MODE' (expected: web | worker | cli)" >&2
        exit 1
        ;;
esac
