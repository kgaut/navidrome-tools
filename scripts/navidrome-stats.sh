#!/usr/bin/env bash
# -----------------------------------------------------------------------------
# navidrome-stats.sh — (RE)CALCULE et met en cache toutes les statistiques.
#
# Recalcule les trois jeux de stats :
#   - app:stats:compute          (stats locales, table scrobbles outils)
#   - app:lastfm:stats:compute   (stats Last.fm)
#   - app:navidrome:stats:compute (stats lues dans navidrome.db, en lecture seule)
#
# Ne lit que des DB (aucune écriture dans navidrome.db) → n'arrête RIEN et ne
# backup rien. Sûr à cronner fréquemment, y compris en journée pendant l'écoute.
# Best-effort : une commande en échec n'interrompt pas les autres.
#
# Codes de sortie : 0 OK · 1 config · 3 au moins une commande KO.
# -----------------------------------------------------------------------------

set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=navidrome-lib.sh
source "${SCRIPT_DIR}/navidrome-lib.sh"
NOTIFY_TITLE="navidrome-stats"

if ! command -v docker >/dev/null 2>&1; then
    log "docker absent du PATH."; exit 1
fi
if [[ ! -f "$COMPOSE_FILE" ]]; then
    log "COMPOSE_FILE introuvable : $COMPOSE_FILE"; exit 1
fi
cd "$PROJECT_DIR"

FAILURES=()
run_stat() {
    local label="$1"; shift
    log "→ ${label}…"
    "$@" || { log "Échec : ${label}."; FAILURES+=("$label"); }
}

run_stat "stats locales"   "${PHP_BIN[@]}" bin/console app:stats:compute --no-interaction
run_stat "stats Last.fm"   "${PHP_BIN[@]}" bin/console app:lastfm:stats:compute --no-interaction
run_stat "stats Navidrome" "${PHP_BIN[@]}" bin/console app:navidrome:stats:compute --no-interaction

if [[ ${#FAILURES[@]} -gt 0 ]]; then
    msg="Stats : échec(s) sur ${FAILURES[*]}."
    log "$msg"; notify_failure "$msg"; exit 3
fi

log "Stats recalculées."
exit 0
