#!/usr/bin/env bash
# -----------------------------------------------------------------------------
# navidrome-rematch-full.sh — REMATCH COMPLET.
#
# Re-queue TOUS les non-matchés (reset unmatched → pending + purge des négatifs
# du cache) puis relance la cascade sur l'ensemble. Lourd : à cronner rarement
# (ex. après avoir ajouté des morceaux / créé des alias, ou déployé un matcher
# amélioré). Pour éplucher le backlog par lots ensuite, voir navidrome-rematch.sh.
#
# Flow : stop → backup baseline → app:scrobbles:rematch → quick_check → start →
#        purge. Résilient aux erreurs Last.fm transitoires ; checkpoints
#        intermédiaires côté app pendant le run.
#
# Codes de sortie : 0 OK · 1 config · 2 stop KO · 3 commande KO (DB intègre →
#                   conservé ; sinon rollback) · 4 quick_check KO · 5 start KO.
# -----------------------------------------------------------------------------

set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=navidrome-lib.sh
source "${SCRIPT_DIR}/navidrome-lib.sh"
NOTIFY_TITLE="navidrome-rematch-full"

# Cible : navidrome (défaut) ou strawberry.
: "${REMATCH_TARGET:=navidrome}"

nd_preflight
nd_register_trap

nd_stop
take_backup   # baseline ; l'app prend aussi des checkpoints pendant le run

log "Rematch complet ${REMATCH_TARGET} (re-queue de tous les non-matchés)…"
# Pas de --auto-stop (conteneur déjà arrêté). Ajouter --force si l'app ne peut
# pas vérifier l'état du conteneur (socket Docker indisponible côté outils).
if ! "${PHP_BIN[@]}" bin/console app:scrobbles:rematch --target "$REMATCH_TARGET" --no-interaction; then
    on_command_failure "Échec du rematch complet ${REMATCH_TARGET}."
fi

if ! quick_check_ok "$NAVIDROME_DB_PATH"; then
    msg="quick_check KO après rematch complet."
    log "$msg"; rollback_db || true; nd_start_if_stopped || true
    notify_failure "$msg DB restaurée depuis le backup sain le plus récent."
    exit 4
fi
log "quick_check OK."

if ! nd_start_if_stopped; then
    msg="Rematch complet OK mais impossible de redémarrer ${COMPOSE_SERVICE}."
    log "$msg"; notify_failure "$msg"; exit 5
fi

purge_old_backups
log "Rematch complet terminé avec succès."
exit 0
