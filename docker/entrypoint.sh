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

    cron)
        # Regenerate the crontab every 5 minutes so the cron picks up
        # definitions added/edited via the web UI.
        REGEN_INTERVAL="${CRON_REGEN_INTERVAL:-300}"
        CRONTAB_PATH=/tmp/crontab

        php bin/console app:cron:dump --env="${APP_ENV:-prod}" > "$CRONTAB_PATH"
        echo "[entrypoint] starting supercronic with crontab:"
        cat "$CRONTAB_PATH"

        # Run supercronic in the background, restart it on crontab change.
        supercronic "$CRONTAB_PATH" &
        SUPER_PID=$!

        trap 'kill -TERM $SUPER_PID 2>/dev/null; exit 0' INT TERM

        while true; do
            sleep "$REGEN_INTERVAL"
            php bin/console app:cron:dump --env="${APP_ENV:-prod}" > "${CRONTAB_PATH}.new"
            if ! cmp -s "$CRONTAB_PATH" "${CRONTAB_PATH}.new"; then
                echo "[entrypoint] crontab changed, restarting supercronic"
                mv "${CRONTAB_PATH}.new" "$CRONTAB_PATH"
                kill -TERM "$SUPER_PID" 2>/dev/null || true
                wait "$SUPER_PID" 2>/dev/null || true
                supercronic "$CRONTAB_PATH" &
                SUPER_PID=$!
            else
                rm -f "${CRONTAB_PATH}.new"
            fi
        done
        ;;

    cli)
        # Run a one-shot Symfony command then exit. Args after `cli` are forwarded.
        shift || true
        exec php bin/console "$@"
        ;;

    *)
        echo "Unknown APP_MODE='$MODE' (expected: web | cron | cli)" >&2
        exit 1
        ;;
esac
