# Jobs récurrents et historique des runs

Le tool **n'embarque pas** de cron interne (pas de supercronic, pas
de service `navidrome-tools-cron`, plus de variables `*_SCHEDULE`).
Vous pilotez les commandes Symfony depuis votre **crontab unix** (ou
tout autre scheduler : systemd timers, Nomad cron, etc.).

## Exécuter une commande dans le conteneur web

`docker compose exec -T` réutilise le conteneur `navidrome-tools-web`
déjà démarré (toutes les variables d'environnement y sont déjà
chargées, pas besoin d'un `run --rm` qui relancerait l'entrypoint et
rejouerait les migrations à chaque tick) :

```bash
docker compose exec -T navidrome-tools-web php bin/console <command>
```

Sous Lando, l'équivalent est `lando symfony <command>`.

## Exemple de crontab

Adapté au flux Last.fm en deux étapes (fetch quand Navidrome tourne,
process / rematch quand il est stoppé via `--auto-stop`) :

```cron
# Génération d'une playlist (id depuis l'UI ou
# `bin/console doctrine:dbal:run-sql 'SELECT id, name FROM playlist_definition'`).
0 3 * * * docker compose -f /srv/navidrome/docker-compose.yml exec -T navidrome-tools-web \
    php bin/console app:playlist:run 1

# Refresh du cache stats (lecture seule sur Navidrome — sans risque).
0 */6 * * * docker compose -f /srv/navidrome/docker-compose.yml exec -T navidrome-tools-web \
    php bin/console app:stats:compute

# Fetch Last.fm dans le buffer (Navidrome up — aucune écriture sur sa DB).
*/15 * * * * docker compose -f /srv/navidrome/docker-compose.yml exec -T navidrome-tools-web \
    php bin/console app:lastfm:import

# Drain du buffer dans Navidrome (--auto-stop orchestre stop/run/restart).
0 4 * * * docker compose -f /srv/navidrome/docker-compose.yml exec -T navidrome-tools-web \
    php bin/console app:lastfm:process --auto-stop

# Re-match des unmatched cumulés (idem, --auto-stop).
0 5 * * 0 docker compose -f /srv/navidrome/docker-compose.yml exec -T navidrome-tools-web \
    php bin/console app:lastfm:rematch --auto-stop

# Sync loved ↔ starred (quotidien).
30 4 * * * docker compose -f /srv/navidrome/docker-compose.yml exec -T navidrome-tools-web \
    php bin/console app:lastfm:sync-loved

# Purge de l'historique des runs (rétention RUN_HISTORY_RETENTION_DAYS).
45 4 * * * docker compose -f /srv/navidrome/docker-compose.yml exec -T navidrome-tools-web \
    php bin/console app:history:purge
```

Si vous écrivez le crontab sur l'hôte (pas dans un conteneur sidecar),
remplacez `/srv/navidrome/docker-compose.yml` par le chemin réel et
assurez-vous que l'utilisateur cron a accès à Docker.

## Stop / start automatique de Navidrome

`--auto-stop` est dispo sur les commandes qui **écrivent** dans la DB
Navidrome (`app:lastfm:process`, `app:lastfm:rematch`). Quand
`NAVIDROME_CONTAINER_NAME` est renseigné, il orchestre :

1. **Backup** : copie `navidrome.db` + ses siblings `-wal` / `-shm`
   vers `<dbPath>.backup-<unix_ts>` (rétention `NAVIDROME_DB_BACKUP_RETENTION`).
2. **Stop** : `docker stop -t $NAVIDROME_STOP_TIMEOUT_SECONDS` puis
   poll `docker inspect` jusqu'à `Running=false`.
3. **Quick check pré** : `PRAGMA quick_check` sur la DB — refuse de
   continuer si elle est déjà brisée.
4. **Action** : `process` / `rematch`, batchs encadrés par
   `BEGIN IMMEDIATE` / `COMMIT`.
5. **Quick check post** : si fail, restaure le backup
   automatiquement + exception explicite.
6. **Restart** : `docker start` (toujours, même en cas d'erreur — try/
   finally).

Sans `NAVIDROME_CONTAINER_NAME`, arrêtez Navidrome manuellement avant
d'invoquer les commandes (sinon pré-flight bloquant ; passez
`--force` pour outrepasser **à vos risques**).

## Historique des runs

Tous les jobs longs sont audités dans la table locale `run_history`.
La page `/history` (lien dans la nav) liste les exécutions avec :

- type (`playlist`, `stats`, `lastfm-import`, `lastfm-fetch`,
  `lastfm-process`, `lastfm-rematch`, `lastfm-love-sync`,
  `navidrome-rescan`, `beets-queue-push`, …),
- libellé humain,
- statut (✓ success / ✗ error / skipped) avec badge coloré,
- date de démarrage et durée,
- métriques en JSON (`tracks=50`, `inserted=237`, `unmatched=42`),
- bouton « Détails » pour le message complet et le JSON metrics.

Filtres par type/statut + recherche libre + pagination (50/page).

### Détail par-track pour les runs Last.fm

Les runs `lastfm-process` (et `lastfm-import` legacy) affichent **en
plus** le listing par-track depuis la table `lastfm_import_track` :
filtre par statut (`inserted` / `duplicate` / `unmatched`, défaut «
non matchés » s'il y en a) et recherche full-text artiste/titre.

### Dashboard

Le dashboard `/` affiche un bloc « Derniers runs » avec les 10
dernières entrées (tous types confondus) pour repérer en un coup
d'œil les erreurs récentes, avec un lien direct vers la page
complète.

### Purge

La commande `app:history:purge` supprime les entrées plus vieilles
que `RUN_HISTORY_RETENTION_DAYS` (défaut 90). À planifier dans le
crontab (cf. exemple plus haut). Idempotent — exécutions répétées
n'effacent que ce qui est tombé sous le seuil entre deux runs.

## Notifications de fin de run

Chaque run wrappé par `RunHistoryRecorder` peut déclencher une
notification (Gotify / Slack / Discord / Pushover, broadcast supporté
via CSV `NOTIFY_DRIVERS`). Voir [`NOTIFICATIONS.md`](NOTIFICATIONS.md)
pour la configuration, et la page **`/settings`** pour tester
l'envoi depuis l'UI.
