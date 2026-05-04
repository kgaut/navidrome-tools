#!/bin/sh
set -eu

cd /app

mkdir -p var plugins

# Refresh autoload + DI cache so plugins dropped in /app/plugins are picked up.
composer dump-autoload --no-dev --optimize --working-dir=/app --no-interaction --quiet
rm -rf /app/var/cache/prod
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

    worker)
        # Long-lived Messenger consumer for async UI-launched jobs (Last.fm
        # fetch/process/rematch/sync-loved). --limit=1 serialises consumption
        # so jobs that write to Navidrome never run concurrently. The
        # --time-limit recycles the process to keep memory bounded.
        exec php bin/console messenger:consume async \
            --time-limit="${MESSENGER_WORKER_TIME_LIMIT:-3600}" \
            --memory-limit="${MESSENGER_WORKER_MEMORY_LIMIT:-128M}" \
            --limit="${MESSENGER_WORKER_MESSAGE_LIMIT:-1}" \
            -vv
        ;;

    *)
        echo "Unknown APP_MODE='$MODE' (expected: web | cli | worker)" >&2
        exit 1
        ;;
esac
