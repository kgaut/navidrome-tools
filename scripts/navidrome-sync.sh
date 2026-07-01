#!/usr/bin/env bash
# -----------------------------------------------------------------------------
# navidrome-sync.sh — sync Last.fm → Navidrome (scrobbles + loves).
#
# Le rematch a ses propres scripts (navidrome-rematch.sh /
# navidrome-rematch-full.sh) — il n'est PAS inclus ici.
#
# Flow : stop conteneur → backup → sync scrobbles + loves → quick_check →
#        start → purge.
#
# Codes de sortie : 0 OK · 1 config · 2 stop KO · 3 commande KO (DB intègre →
#                   travail conservé ; sinon rollback) · 4 quick_check KO
#                   (rollback) · 5 start KO après sync.
# -----------------------------------------------------------------------------

set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=navidrome-lib.sh
source "${SCRIPT_DIR}/navidrome-lib.sh"
NOTIFY_TITLE="navidrome-sync"

nd_preflight
nd_register_trap

nd_stop
take_backup

run_step() {
    local label="$1"
    shift
    log "Sync ${label}…"
    "$@" || on_command_failure "Échec de la commande ${label}."
}

run_step "scrobbles → Navidrome" "${PHP_BIN[@]}" bin/console app:scrobbles:sync-navidrome --no-interaction
run_step "loves Last.fm → Navidrome" "${PHP_BIN[@]}" bin/console app:loves:lastfm-to-navidrome --no-interaction

if ! quick_check_ok "$NAVIDROME_DB_PATH"; then
    msg="quick_check KO après sync."
    log "$msg"; rollback_db || true; nd_start_if_stopped || true
    notify_failure "$msg DB restaurée depuis le backup sain le plus récent."
    exit 4
fi
log "quick_check OK."

if ! nd_start_if_stopped; then
    msg="Sync OK mais impossible de redémarrer ${COMPOSE_SERVICE}."
    log "$msg"; notify_failure "$msg"; exit 5
fi

purge_old_backups
log "Sync terminée avec succès."
exit 0
