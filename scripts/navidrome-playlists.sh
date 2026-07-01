#!/usr/bin/env bash
# -----------------------------------------------------------------------------
# navidrome-playlists.sh — (RE)GÉNÈRE les playlists.
#
# Recalcule et pousse toutes les playlists gérées via l'API Subsonic
# (app:playlists:generate --all). Le conteneur Navidrome doit être DÉMARRÉ
# (écriture via l'API HTTP, pas d'accès direct à la DB) → n'arrête rien et ne
# backup rien.
#
# À cronner APRÈS le dernier rematch de la nuit (avec `flock -w` sur le même
# verrou) pour que les playlists reflètent les écoutes fraîchement insérées.
#
# Codes de sortie : 0 OK · 1 config · 3 commande KO.
# -----------------------------------------------------------------------------

set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=navidrome-lib.sh
source "${SCRIPT_DIR}/navidrome-lib.sh"
NOTIFY_TITLE="navidrome-playlists"

if ! command -v docker >/dev/null 2>&1; then
    log "docker absent du PATH."; exit 1
fi
if [[ ! -f "$COMPOSE_FILE" ]]; then
    log "COMPOSE_FILE introuvable : $COMPOSE_FILE"; exit 1
fi
cd "$PROJECT_DIR"

log "Génération des playlists…"
if ! "${PHP_BIN[@]}" bin/console app:playlists:generate --all --no-interaction; then
    msg="Échec de la génération des playlists (conteneur Navidrome démarré ?)."
    log "$msg"; notify_failure "$msg"; exit 3
fi

log "Playlists générées."
exit 0
