#!/usr/bin/env bash
# -----------------------------------------------------------------------------
# navidrome-sync.sh — CYCLE DE MAINTENANCE complet.
#
# Enchaîne toutes les commandes utiles SAUF le rematch (scripts dédiés) et la
# sync des scrobbles vers Navidrome / Strawberry (couverts par navidrome-rematch*).
#
#   0. stop conteneur + BACKUP (au départ)
#   1. écriture DIRECTE dans Navidrome (conteneur arrêté) : loves Last.fm → Navidrome
#   2. quick_check → rollback si corruption ; redémarrage du conteneur
#   3. reste du cycle (conteneur relancé, best-effort — un échec réseau
#      n'entraîne PAS de rollback) : fetch, loved sync, loves Navidrome → Last.fm,
#      alias, stats, playlists, recommandations, purge historique.
#   4. purge des backups + notif récap si échec(s).
#
# Commente les lignes qui ne te concernent pas (Strawberry, reco, etc.).
#
# Codes de sortie : 0 OK · 1 config · 2 stop KO · 3 échec(s) non bloquant(s)
#                   (DB intègre, conteneur relancé) · 4 corruption (rollback) ·
#                   5 start KO.
# -----------------------------------------------------------------------------

set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=navidrome-lib.sh
source "${SCRIPT_DIR}/navidrome-lib.sh"
NOTIFY_TITLE="navidrome-sync"

FAILURES=()

# Après une écriture Navidrome : si la DB est corrompue → rollback + exit 4.
guard_integrity_or_rollback() {
    if ! quick_check_ok "$NAVIDROME_DB_PATH"; then
        local msg="$1 : quick_check KO — corruption."
        log "$msg"; rollback_db || true; nd_start_if_stopped || true
        notify_failure "$msg DB restaurée depuis le backup sain le plus récent."
        exit 4
    fi
}

# Commande écrivant dans Navidrome (conteneur arrêté). Un échec sans corruption
# est enregistré mais n'interrompt pas le cycle ; une corruption déclenche le
# rollback (via le guard).
run_write() {
    local label="$1"; shift
    log "→ ${label}…"
    "$@" || { log "Échec : ${label}."; FAILURES+=("$label"); }
    guard_integrity_or_rollback "$label"
}

# Commande annexe (conteneur relancé / hors écriture Navidrome). Best-effort :
# un échec est enregistré, le cycle continue (pas de rollback).
run_aux() {
    local label="$1"; shift
    log "→ ${label}…"
    "$@" || { log "Échec (aux) : ${label}."; FAILURES+=("$label"); }
}

nd_preflight
nd_register_trap

# === 0. Stop + backup ========================================================
nd_stop
take_backup

# === 1. Écritures Navidrome (conteneur arrêté) ===============================
run_write "loves Last.fm → Navidrome" "${PHP_BIN[@]}" bin/console app:loves:lastfm-to-navidrome --no-interaction

# === 2. Redémarrage du conteneur ============================================
if ! nd_start_if_stopped; then
    msg="Impossible de redémarrer ${COMPOSE_SERVICE}."
    log "$msg"; notify_failure "$msg"; exit 5
fi

# === 3. Reste du cycle (conteneur relancé, best-effort) ======================
run_aux "fetch Last.fm"              "${PHP_BIN[@]}" bin/console app:lastfm:fetch --max-scrobbles=0 --no-interaction
run_aux "sync loved Last.fm"         "${PHP_BIN[@]}" bin/console app:lastfm:loved:sync --no-interaction
run_aux "loves Navidrome → Last.fm"  "${PHP_BIN[@]}" bin/console app:loves:navidrome-to-lastfm --no-interaction
run_aux "alias resolubles"           "${PHP_BIN[@]}" bin/console app:aliases:generate --no-interaction
run_aux "alias MusicBrainz"          "${PHP_BIN[@]}" bin/console app:aliases:musicbrainz --no-interaction
run_aux "stats Navidrome"            "${PHP_BIN[@]}" bin/console app:navidrome:stats:compute --no-interaction
run_aux "playlists"                  "${PHP_BIN[@]}" bin/console app:playlists:generate --all --no-interaction
run_aux "recommandations"            "${PHP_BIN[@]}" bin/console app:recommendations:compute --no-interaction
run_aux "purge historique"           "${PHP_BIN[@]}" bin/console app:history:purge --no-interaction

# === 4. Purge des backups + récap ===========================================
purge_old_backups

if [[ ${#FAILURES[@]} -gt 0 ]]; then
    log "Cycle terminé avec ${#FAILURES[@]} échec(s) : ${FAILURES[*]}"
    notify_failure "Commandes en échec : ${FAILURES[*]} (DB intègre, conteneur relancé)."
    exit 3
fi

log "Cycle de maintenance terminé avec succès."
exit 0
