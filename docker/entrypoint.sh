#!/bin/sh
set -eu

cd /app

mkdir -p var plugins

# Refresh autoload + DI cache so plugins dropped in /app/plugins are picked up.
# The cache lives in $APP_CACHE_DIR (default /app/.symfony-cache, set as ENV
# in the image), which is in the container's image layer — NOT in the
# /app/var named volume. Two container instances must not share the cache:
# one entrypoint's `rm -rf` would nuke files an in-flight CLI in the other
# container is lazy-loading, surfacing as `Failed opening required
# getConsole_ErrorListenerService.php` at the next ConsoleTerminateEvent.
CACHE_DIR="${APP_CACHE_DIR:-/app/var/cache}"
composer dump-autoload --no-dev --optimize --working-dir=/app --no-interaction --quiet
rm -rf "${CACHE_DIR}/${APP_ENV:-prod}"
php bin/console cache:warmup --env="${APP_ENV:-prod}"

# Apply Doctrine migrations on every boot (idempotent).
php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration --env="${APP_ENV:-prod}" || {
    echo "Migration failed" >&2
    exit 1
}

# First boot: insert example (disabled) playlist definitions.
php bin/console app:fixtures:seed --env="${APP_ENV:-prod}" || true

MODE="${APP_MODE:-web}"

case "$MODE" in
    web)
        exec frankenphp run --config /etc/caddy/Caddyfile
        ;;

    cli)
        # Run a one-shot Symfony command then exit. Args after `cli` are forwarded.
        shift || true
        exec php bin/console "$@"
        ;;

    *)
        echo "Unknown APP_MODE='$MODE' (expected: web | cli)" >&2
        exit 1
        ;;
esac
