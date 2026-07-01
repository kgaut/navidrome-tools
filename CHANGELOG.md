# Changelog

Toutes les évolutions notables de ce projet sont documentées ici.

Le format s'appuie sur [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/)
et le projet suit le [versionnage sémantique](https://semver.org/lang/fr/).

## [Non publié]

<!-- Ajouter ici les changements de la prochaine version, sous Ajouté / Modifié / Corrigé / Supprimé. -->

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

[Non publié]: https://github.com/kgaut/navidrome-tools/compare/1.0.0...HEAD
[1.0.0]: https://github.com/kgaut/navidrome-tools/releases/tag/1.0.0
