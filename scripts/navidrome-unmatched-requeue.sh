#!/usr/bin/env bash
# -----------------------------------------------------------------------------
# navidrome-unmatched-requeue.sh — RE-QUEUE des non-matchés (sans traitement).
#
# Remet les non-matchés en attente (reset → pending + purge des négatifs du
# cache), SANS lancer la cascade. Le prochain navidrome-rematch.sh (par lots)
# les traitera. N'écrit QUE la DB outils → pas d'arrêt du conteneur Navidrome
# ni de backup.
#
# Usage type : le lancer après avoir ajouté des morceaux / créé des alias, puis
# laisser navidrome-rematch.sh éplucher le backlog run après run.
#
# Codes de sortie : 0 OK · 1 config · 3 commande KO.
# -----------------------------------------------------------------------------

set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=navidrome-lib.sh
source "${SCRIPT_DIR}/navidrome-lib.sh"
NOTIFY_TITLE="navidrome-unmatched-requeue"

# Cible : navidrome (défaut) ou strawberry.
: "${REQUEUE_TARGET:=navidrome}"

if ! command -v docker >/dev/null 2>&1; then
    log "docker absent du PATH."; exit 1
fi
if [[ ! -f "$COMPOSE_FILE" ]]; then
    log "COMPOSE_FILE introuvable : $COMPOSE_FILE"; exit 1
fi
cd "$PROJECT_DIR"

log "Re-queue des non-matchés (${REQUEUE_TARGET})…"
if ! "${PHP_BIN[@]}" bin/console app:scrobbles:requeue-unmatched --target "$REQUEUE_TARGET" --no-interaction; then
    msg="Échec du re-queue des non-matchés (${REQUEUE_TARGET})."
    log "$msg"; notify_failure "$msg"; exit 3
fi

log "Re-queue terminé."
exit 0
