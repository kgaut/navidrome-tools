#!/usr/bin/env bash
# -----------------------------------------------------------------------------
# navidrome-lib.sh — config + helpers partagés par les wrappers crontab
# (navidrome-backup.sh, navidrome-sync.sh, navidrome-rematch.sh,
#  navidrome-rematch-full.sh). Ce fichier ne FAIT rien seul : il est `source`-é.
#
# La notif (Gotify) est lue depuis le `.env` du projet (un niveau au-dessus de
# scripts/) — pas de secret stocké ici. Les chemins CÔTÉ HÔTE restent en config
# ci-dessous : ils diffèrent des chemins conteneur du .env.
# -----------------------------------------------------------------------------

PROJECT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
ENV_FILE="${ENV_FILE:-${PROJECT_DIR}/.env}"

# Lit une clé simple `KEY=value` du .env (dernière occurrence ; guillemets et
# CR retirés). Renvoie vide si absente. On ne `source` PAS le .env (syntaxe
# Symfony : interpolations, etc.).
env_get() {
    local key="$1" line val
    [[ -f "$ENV_FILE" ]] || return 0
    line="$(grep -E "^[[:space:]]*${key}=" "$ENV_FILE" 2>/dev/null | grep -vE '^[[:space:]]*#' | tail -n1)"
    [[ -z "$line" ]] && return 0
    val="${line#*=}"
    val="${val%$'\r'}"
    if [[ "$val" == \"*\" ]]; then val="${val#\"}"; val="${val%\"}"
    elif [[ "$val" == \'*\' ]]; then val="${val#\'}"; val="${val%\'}"; fi
    printf '%s' "$val"
}

# === Config HÔTE (adapter si besoin ; surchargeable par variable d'env) ======
# Chemin SQLite Navidrome côté hôte (le wrapper fait cp / quick_check dessus).
: "${NAVIDROME_DB_PATH:=${PROJECT_DIR}/data/navidrome/navidrome.db}"
# Où stocker les backups du wrapper (créé si absent).
: "${BACKUP_DIR:=${PROJECT_DIR}/backups}"
# Rétention en jours (find -mtime) pour la purge des backups du wrapper.
: "${BACKUP_RETENTION_DAYS:=7}"
# Docker compose : fichier + service Navidrome.
: "${COMPOSE_FILE:=${PROJECT_DIR}/compose.yml}"
: "${COMPOSE_SERVICE:=navidrome}"
# Commande pour lancer bin/console — tableau (exécution dans le conteneur outils).
if [[ -z "${PHP_BIN+x}" ]]; then PHP_BIN=(docker compose -f "$COMPOSE_FILE" exec -T navidrome-tools-web); fi

# === Notif : lue depuis le .env (aucun secret ici) ===========================
: "${GOTIFY_URL:=$(env_get NOTIFY_GOTIFY_URL)}"
: "${GOTIFY_TOKEN:=$(env_get NOTIFY_GOTIFY_TOKEN)}"
GOTIFY_PRIORITY="${GOTIFY_PRIORITY:-$(env_get NOTIFY_GOTIFY_PRIORITY)}"; : "${GOTIFY_PRIORITY:=8}"

# Préfixe affiché dans les logs / notifs (chaque entrée le surcharge).
: "${NOTIFY_TITLE:=navidrome}"

# === État partagé ============================================================
STOPPED=0
BACKUP_PATH=""

# === Helpers =================================================================

log() {
    printf '[%s] %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*" >&2
}

notify_failure() {
    local message="$1"
    if [[ -z "$GOTIFY_URL" || -z "$GOTIFY_TOKEN" ]]; then
        return 0
    fi
    curl --silent --show-error --max-time 10 \
        --data "title=${NOTIFY_TITLE}" \
        --data "message=${message}" \
        --data "priority=${GOTIFY_PRIORITY}" \
        "${GOTIFY_URL%/}/message?token=${GOTIFY_TOKEN}" \
        >/dev/null 2>&1 || true
}

nd_preflight() {
    if [[ ! -f "$NAVIDROME_DB_PATH" ]]; then
        log "NAVIDROME_DB_PATH introuvable : $NAVIDROME_DB_PATH"; exit 1
    fi
    if [[ ! -f "$COMPOSE_FILE" ]]; then
        log "COMPOSE_FILE introuvable : $COMPOSE_FILE"; exit 1
    fi
    if ! command -v sqlite3 >/dev/null 2>&1; then
        log "sqlite3 absent du PATH — requis pour le quick_check."; exit 1
    fi
    if ! command -v docker >/dev/null 2>&1; then
        log "docker absent du PATH."; exit 1
    fi
    mkdir -p "$BACKUP_DIR"
    cd "$PROJECT_DIR"
}

nd_stop() {
    log "Stop ${COMPOSE_SERVICE}…"
    if ! docker compose -f "$COMPOSE_FILE" stop "$COMPOSE_SERVICE" >/dev/null; then
        local msg="Échec de l'arrêt du conteneur ${COMPOSE_SERVICE}."
        log "$msg"; notify_failure "$msg"; exit 2
    fi
    STOPPED=1
}

nd_start_if_stopped() {
    if [[ "$STOPPED" -eq 1 ]]; then
        log "Redémarrage du conteneur ${COMPOSE_SERVICE}…"
        if docker compose -f "$COMPOSE_FILE" start "$COMPOSE_SERVICE" >/dev/null; then
            STOPPED=0
        else
            log "Impossible de redémarrer ${COMPOSE_SERVICE}."; return 1
        fi
    fi
}

# Filet de sécurité : relancer le conteneur si le script meurt après le stop.
nd_register_trap() {
    trap '
        code=$?
        if [[ "$STOPPED" -eq 1 ]]; then
            log "Sortie inattendue (code=$code) avec conteneur arrêté — tentative de redémarrage."
            docker compose -f "$COMPOSE_FILE" start "$COMPOSE_SERVICE" >/dev/null 2>&1 || true
        fi
        exit "$code"
    ' EXIT
}

quick_check_ok() {
    [[ "$(sqlite3 "$1" 'PRAGMA quick_check;' 2>/dev/null)" == "ok" ]]
}

# Backup horodaté de la DB (+ WAL/SHM si présents) dans BACKUP_DIR. Renseigne
# BACKUP_PATH.
take_backup() {
    local ts
    ts="$(date +%Y%m%d_%H%M%S)"
    BACKUP_PATH="${BACKUP_DIR}/navidrome-${ts}.db"
    log "Backup → $BACKUP_PATH"
    cp -p "$NAVIDROME_DB_PATH" "$BACKUP_PATH"
    cp -p "${NAVIDROME_DB_PATH}-wal" "${BACKUP_PATH}-wal" 2>/dev/null || true
    cp -p "${NAVIDROME_DB_PATH}-shm" "${BACKUP_PATH}-shm" 2>/dev/null || true
}

# Backup SAIN le plus récent, parmi les backups du wrapper ($BACKUP_DIR) ET les
# checkpoints intermédiaires écrits par l'app à côté de la DB
# (${NAVIDROME_DB_PATH}.backup-*). Trié par date décroissante ; renvoie le
# premier qui passe quick_check.
latest_sound_backup() {
    local files=() f sorted
    shopt -s nullglob
    for f in "${BACKUP_DIR}"/navidrome-*.db "${NAVIDROME_DB_PATH}".backup-*; do
        [[ "$f" == *-wal || "$f" == *-shm ]] && continue
        [[ -f "$f" ]] && files+=("$f")
    done
    shopt -u nullglob
    [[ ${#files[@]} -eq 0 ]] && return 1

    sorted="$(stat --format='%Y	%n' "${files[@]}" 2>/dev/null | sort -rn | cut -f2-)"
    while IFS= read -r f; do
        [[ -z "$f" || ! -f "$f" ]] && continue
        if quick_check_ok "$f"; then
            printf '%s\n' "$f"; return 0
        fi
    done <<< "$sorted"
    return 1
}

rollback_db() {
    local src
    src="$(latest_sound_backup || true)"
    if [[ -z "$src" || ! -f "$src" ]]; then
        log "Aucun backup sain à restaurer."; return 1
    fi
    log "Rollback DB depuis $src…"
    cp -p "$src" "$NAVIDROME_DB_PATH"
    if [[ -f "${src}-wal" ]]; then cp -p "${src}-wal" "${NAVIDROME_DB_PATH}-wal"; else rm -f "${NAVIDROME_DB_PATH}-wal"; fi
    if [[ -f "${src}-shm" ]]; then cp -p "${src}-shm" "${NAVIDROME_DB_PATH}-shm"; else rm -f "${NAVIDROME_DB_PATH}-shm"; fi
}

# Politique sur échec d'une commande de write : les écritures sont committées
# par lots et atomiques → un échec laisse une DB cohérente. On conserve le
# travail si la DB est intègre ; rollback seulement si corrompue.
on_command_failure() {
    local msg="$1"
    log "$msg"
    if quick_check_ok "$NAVIDROME_DB_PATH"; then
        log "DB intègre — travail partiel conservé (les restants repasseront au prochain run)."
        nd_start_if_stopped || true
        notify_failure "$msg Travail partiel conservé (DB intègre)."
        exit 3
    fi
    log "DB corrompue — rollback vers le backup sain le plus récent."
    rollback_db || true
    nd_start_if_stopped || true
    notify_failure "$msg DB corrompue → restaurée depuis un backup sain."
    exit 3
}

purge_old_backups() {
    log "Purge des backups > ${BACKUP_RETENTION_DAYS} j dans $BACKUP_DIR…"
    find "$BACKUP_DIR" \
        -maxdepth 1 -type f \
        \( -name 'navidrome-*.db' -o -name 'navidrome-*.db-wal' -o -name 'navidrome-*.db-shm' \) \
        -mtime +"${BACKUP_RETENTION_DAYS}" \
        -delete
}
