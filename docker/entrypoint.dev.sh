#!/bin/sh
# Entrypoint de DÉVELOPPEMENT (utilisé par docker-compose.dev.yml).
#
# Diffère de docker/entrypoint.sh (prod) sur trois points :
#   - installe les dépendances *dev* (phpunit, phpstan, phpcs…) ;
#   - ne purge PAS le cache prod et ne fait pas de cache:warmup destructif
#     (cf. CLAUDE.md, piège n°1 sur la course web/worker) ;
#   - démarre en APP_ENV=dev / APP_DEBUG=1 (rechargement à chaud du code monté).
set -eu

cd /app

mkdir -p var var/sessions "${APP_CACHE_DIR:-var/cache}"

MODE="${APP_MODE:-web}"
READY_FILE="var/.dev-ready"

case "$MODE" in
    web)
        # Le service web est la source de vérité : il installe vendor/ et migre.
        # Tant qu'il n'a pas fini, le worker patiente (voir plus bas).
        rm -f "$READY_FILE"

        # (Ré)installe les dépendances si vendor/ est absent ou si composer.json
        # est plus récent que l'autoload généré (ex. après un git pull).
        if [ ! -f vendor/autoload_runtime.php ] || [ composer.json -nt vendor/autoload_runtime.php ]; then
            echo "▶ composer install (avec dépendances dev)…"
            composer install --no-interaction --no-progress
        fi

        echo "▶ doctrine:migrations:migrate…"
        php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

        touch "$READY_FILE"

        echo "▶ FrankenPHP → http://localhost:8080 (APP_ENV=dev, APP_DEBUG=1)"
        exec frankenphp run --config /etc/caddy/Caddyfile
        ;;

    worker)
        # Attend que le service web ait fini install + migrations avant de
        # consommer la file (sinon les tables Messenger n'existent pas encore).
        i=0
        until [ -f "$READY_FILE" ]; do
            i=$((i + 1))
            if [ "$i" -gt 150 ]; then
                echo "worker: web pas prêt après 5 min, abandon." >&2
                exit 1
            fi
            echo "worker: en attente du service web (install/migrations)…"
            sleep 2
        done

        echo "▶ messenger:consume async (worker dev)"
        exec php bin/console messenger:consume async --time-limit=3600 --memory-limit=256M -vv
        ;;

    *)
        echo "APP_MODE='$MODE' inconnu (attendu : web | worker)" >&2
        exit 1
        ;;
esac
