#!/usr/bin/env bash
# -----------------------------------------------------------------------------
# navidrome-rematch.sh — REMATCH PAR LOTS (léger).
#
# NE re-queue PAS les non-matchés : traite juste jusqu'à REMATCH_LIMIT morceaux
# EN ATTENTE (pending) — pour éplucher, run après run, le backlog laissé par un
# navidrome-rematch-full.sh (ou les scrobbles fraîchement fetchés). À cronner
# souvent (c'est court et borné).
#
# Flow : stop → backup → app:scrobbles:sync-navidrome --limit N → quick_check →
#        start → purge.
#
# Codes de sortie : 0 OK · 1 config · 2 stop KO · 3 commande KO (DB intègre →
#                   conservé ; sinon rollback) · 4 quick_check KO · 5 start KO.
# -----------------------------------------------------------------------------

set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=navidrome-lib.sh
source "${SCRIPT_DIR}/navidrome-lib.sh"
NOTIFY_TITLE="navidrome-rematch"

# Nombre max de morceaux en attente traités par run (0 = pas de limite).
: "${REMATCH_LIMIT:=5000}"

nd_preflight
nd_register_trap

nd_stop
take_backup

log "Rematch par lots — jusqu'à ${REMATCH_LIMIT} morceaux en attente…"
if ! "${PHP_BIN[@]}" bin/console app:scrobbles:sync-navidrome --limit "$REMATCH_LIMIT" --no-interaction; then
    on_command_failure "Échec du rematch par lots (limit=${REMATCH_LIMIT})."
fi

if ! quick_check_ok "$NAVIDROME_DB_PATH"; then
    msg="quick_check KO après rematch par lots."
    log "$msg"; rollback_db || true; nd_start_if_stopped || true
    notify_failure "$msg DB restaurée depuis le backup sain le plus récent."
    exit 4
fi
log "quick_check OK."

if ! nd_start_if_stopped; then
    msg="Rematch par lots OK mais impossible de redémarrer ${COMPOSE_SERVICE}."
    log "$msg"; notify_failure "$msg"; exit 5
fi

purge_old_backups
log "Rematch par lots terminé avec succès."
exit 0
