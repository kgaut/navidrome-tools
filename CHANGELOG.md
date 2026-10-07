# Changelog

Toutes les évolutions notables de ce projet sont documentées ici.

Le format s'appuie sur [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/)
et le projet suit le [versionnage sémantique](https://semver.org/lang/fr/).

## [Non publié]

<!-- Ajouter ici les changements de la prochaine version, sous Ajouté / Modifié / Corrigé / Supprimé. -->

### Corrigé

- **« Mix de la semaine » ne contenait plus aucune découverte** (issue #255).
  AudioMuse-AI (≥ 3.6) renvoie la réponse de `/api/similar_tracks` en liste à
  la racine du JSON, et non plus sous `similar_songs` : chaque seed revenait
  vide, sans erreur, et la playlist ne contenait que ses seeds. Les deux formes
  sont désormais lues. Si **aucun** seed ne ramène de similaire, la génération
  échoue au lieu d'écraser la playlist par les seuls seeds.
- **AudioMuse : clé envoyée dans le mauvais en-tête** (issue #256). La clé
  part maintenant en `Authorization: Bearer` (l'`API_TOKEN` d'AudioMuse), au
  lieu de `X-API-Key` qu'AudioMuse n'a jamais lu. Un 401/403 fait échouer la
  génération du mix (`AudioMuseAuthException`) au lieu d'être avalé ; un 404
  (morceau non analysé) reste ignoré. `.env.dist` précise la valeur attendue.
- **`app:lastfm:fetch --max-scrobbles=0` ne récupérait plus rien** (issue #253).
  Documentée comme « pas de limite », la valeur `0` était lue comme un plafond
  à zéro : la boucle s'arrêtait avant le premier scrobble, le run finissait en
  `success` avec `fetched=0` et le curseur smart-date ne bougeait pas. Le
  `navidrome-sync.sh` versionné passant cette option, **plus aucun scrobble
  n'était importé** par le cycle nocturne. `0` (ou négatif) = pas de limite ;
  l'option est retirée du script.

## [1.4.0] - 2026-08-23

### Ajouté

- **API JSON `GET /api/stats/daily` (+ `/{day}`)** — séries quotidiennes
  d'écoute agrégées (issue #250), authentifiées par token Bearer
  (`APP_API_TOKEN`). Deux sources nommées (`?source=navidrome|lastfm`), jours
  découpés en heure locale (`APP_TIMEZONE`) et **zéro-remplis**. Par jour :
  morceaux, distincts, artistes, albums, `duration_seconds` +
  `duration_coverage_pct` (durée via `media_file`, rapprochée par `scrobble_sync`
  côté Last.fm), `loved_added` (toujours `annotation.starred_at`) et top artiste.
  Firewall `^/api` stateless + `ApiTokenAuthenticator` (fail-closed si token
  vide), `DailyListeningStatsService`, requêtes par jour sur
  `NavidromeRepository` / `ScrobbleRepository`.

### Modifié

- **`Kernel`** pose désormais aussi `TZ` (C runtime) depuis `APP_TIMEZONE`, pour
  que le bucketing SQLite `'localtime'` de l'API corresponde au fuseau PHP.

## [1.3.0] - 2026-07-17

### Ajouté

- **Playlist « Pépites redécouvertes »** — morceaux rejoués dans le dernier mois
  après ~12 mois de silence, et déjà écoutés avant ce silence (fenêtre de 24
  mois) : de vraies re-découvertes, en aléatoire. Nouvelle
  `PepitesRedecouvertesDefinition` +
  `NavidromeRepository::findRediscoveredGems()` (requête sur l'historique par
  écoute `scrobbles`). Fenêtres configurables via
  `PLAYLIST_PEPITES_REDECOUVERTES_{WINDOW,SILENCE,RECENT}_MONTHS`.

## [1.2.0] - 2026-07-01

### Ajouté

- **Bouton « ↻ Rematcher » par morceau sur `/navidrome/unmatched`** — pour
  déboguer au cas par cas : purge le cache négatif du couple, relance une
  tentative de matching et affiche le résultat (stratégie + cible sur succès,
  ou raison du diagnostic sur échec). Sur succès, les scrobbles du couple sont
  remis en `pending` pour insertion au prochain sync. Lecture seule → n'arrête
  pas Navidrome. Nouvelle route `app_navidrome_unmatched_retry` +
  `ScrobbleSyncRepository::resetCoupleToPending()`.

## [1.1.0] - 2026-07-01

### Ajouté

- **`scripts/navidrome-stats.sh`** — wrapper cron dédié au recalcul des stats
  (`app:stats:compute`, `app:lastfm:stats:compute`, `app:navidrome:stats:compute`).
  Lecture seule, n'arrête pas Navidrome et ne fait pas de backup → cronnable
  fréquemment, y compris en journée. Best-effort (un échec n'interrompt pas les
  autres).

## [1.0.0] - 2026-07-01

Première version taguée de la réécriture v2 (Symfony 7 / PHP 8.4 / FrankenPHP).
L'ancienne POC reste accessible via le tag `poc-v0`.

### Ajouté

- **Import Last.fm** : commande `app:lastfm:fetch` (+ déclenchement UI), table
  `scrobbles` comme source de vérité, suivi de dates intelligent, page
  d'historique des scrobbles avec filtres et statut de matching.
- **Matching & alias** : cascade de matching (`ScrobbleMatcher`), cache de
  matching positif/négatif, génération automatique d'alias
  (`app:aliases:generate`) et suggestions en ligne via MusicBrainz
  (`app:aliases:musicbrainz`).
- **Synchronisation Navidrome** : écriture des écoutes dans `scrobbles`
  (Navidrome ≥ 0.55), arrêt/redémarrage du conteneur, backups automatiques,
  checkpoints intermédiaires pendant les longs runs, résilience aux erreurs
  Last.fm transitoires, commandes `sync-navidrome`, `rematch`,
  `requeue-unmatched`, `wipe-scrobbles`.
- **Synchronisation Strawberry** : import, upload/download, suivi des
  non-matchés et traitement.
- **Loves** : synchronisation des favoris Last.fm ↔ Navidrome (dans les deux
  sens).
- **Playlists** : génération « plugin » via l'API Subsonic (create/replace),
  description en commentaire, activation par playlist, et un large jeu de
  définitions (Hit parade, Retour en arrière, Mix de la semaine, Kickstart,
  Happy birthday, Vieilles/Très vieilles pépites, coups de cœur, découvertes
  récentes, fidèles compagnons, tops mois/année/all-time…).
- **Recommandations** : moteur d'artistes Last.fm avec snapshot asynchrone,
  source ListenBrainz, page de revue et ajout à Lidarr en 1 clic.
- **Stats & dashboard** : commande `app:stats`, pages de stats Last.fm et
  Navidrome, disparité Last.fm ↔ Navidrome, écran « Stats non-matchés »,
  courbe de couverture, streaks d'écoute, heatmap d'activité 12 mois,
  historique quotidien de la bibliothèque, tops artistes/albums/morceaux avec
  filtres de date.
- **Diagnostic** : explication « pourquoi non-matché » sur `/navidrome/unmatched`,
  liens Lidarr / MusicBrainz.
- **Interface** : refonte « Console » (thème sombre, sidebar, design system),
  nouvelle charte graphique, navigation responsive, page d'aide `/help`.
- **Outillage / crontab** : wrappers bash versionnés partageant `navidrome-lib.sh`
  (config via `.env`, notif Gotify) — `navidrome-backup.sh`, `navidrome-sync.sh`
  (cycle de maintenance), `navidrome-rematch.sh`, `navidrome-rematch-full.sh`,
  `navidrome-unmatched-requeue.sh`, `navidrome-playlists.sh`.
- **Infrastructure** : worker Symfony Messenger (transport Doctrine/SQLite),
  `RunHistoryRecorder`, `Notifier` (Gotify/Slack/Discord/Pushover),
  `BackupService`, sessions persistantes ; CI (phpcs, PHPStan, PHPUnit, lint
  Twig, build Docker).

[Non publié]: https://github.com/kgaut/navidrome-tools/compare/1.4.0...HEAD
[1.4.0]: https://github.com/kgaut/navidrome-tools/compare/1.3.0...1.4.0
[1.3.0]: https://github.com/kgaut/navidrome-tools/compare/1.2.0...1.3.0
[1.2.0]: https://github.com/kgaut/navidrome-tools/compare/1.1.0...1.2.0
[1.1.0]: https://github.com/kgaut/navidrome-tools/compare/1.0.0...1.1.0
[1.0.0]: https://github.com/kgaut/navidrome-tools/releases/tag/1.0.0
