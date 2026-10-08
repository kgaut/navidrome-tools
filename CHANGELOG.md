# Changelog

Toutes les évolutions notables de ce projet sont documentées ici.

Le format s'appuie sur [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/)
et le projet suit le [versionnage sémantique](https://semver.org/lang/fr/).

## [Non publié]

<!-- Ajouter ici les changements de la prochaine version, sous Ajouté / Modifié / Corrigé / Supprimé. -->

### Ajouté

- **Playlists « Kickstart énergique » et « Empreinte sonore »** (issue #257).
  « Kickstart énergique » : la Kickstart (morceaux qui ouvrent le plus souvent
  la journée, dans son ordre) limitée aux morceaux dont l'énergie AudioMuse
  dépasse le `PLAYLIST_KICKSTART_ENERGY_PERCENTILE`-ième percentile de la
  bibliothèque (60) et dont le tempo atteint `PLAYLIST_KICKSTART_MIN_BPM` (110)
  ou qui sont dansants (BPM souvent détecté à moitié) ; la Kickstart d'origine
  ne change pas. « Empreinte sonore » : reprend la playlist qu'AudioMuse
  produisait (`POST /api/sonic_fingerprint/generate`), calculée sur le compte
  Navidrome configuré dans AudioMuse, sans envoi d'identifiants.

## [1.5.2] - 2026-10-08

### Sécurité

- **Dépendances vulnérables mises à jour** (issue #252). Symfony passe de 7.2
  (fin de maintenance) à **7.4 LTS** (7.4.20 : `http-foundation`,
  `security-http`, `cache`, `routing`, `runtime`…), Twig de 3.24.0 à **3.30.0**
  (17 avis, dont des critiques) et `squizlabs/php_codesniffer` (outil de dev) à
  3.13.6. `composer audit` : aucun avis restant. Contrainte Flex
  `extra.symfony.require` et `symfony/process` passées à `7.4.*`.

## [1.5.1] - 2026-10-08

### Corrigé

- **`app:audiomuse:sync` échouait en HTTP 400** (issue #257). Les lots de 500
  ids envoyés à `/api/sync?ids=…` donnaient une URL d'environ 11,5 Ko, alors que
  gunicorn (devant AudioMuse) refuse toute ligne de requête de plus de 4 094
  octets : l'import ne passait jamais, et « Course », « Calmes et peu écoutés »
  et « Énergiques oubliées » restaient vides. Le client découpe désormais les
  ids par taille encodée (≤ 3 000 octets, environ 120 ids par appel).

### Sécurité

- **Jetons Subsonic et clés Last.fm masqués dans les logs** (issue #265). Le
  client HTTP de Symfony journalise chaque URL (niveau `info`, affiché par le
  worker `-vv`) : le couple `t`/`s` de Subsonic, rejouable tant que le mot de
  passe ne change pas, se retrouvait dans les logs du conteneur. Le client HTTP
  journalise désormais via `RedactingLogger`, qui masque `t`, `s`, `p`,
  `api_key`, `api_sig`, `sk`, `token`… (`***`) ; les messages d'erreur des
  clients Subsonic et Last.fm, et ceux enregistrés dans l'historique et les
  notifications, sont masqués de la même façon. ⚠️ Changer le mot de passe
  Navidrome du compte utilisé pour invalider les jetons déjà journalisés.

## [1.5.0] - 2026-10-08

### Ajouté

- **Caractéristiques audio d'AudioMuse-AI importées + 3 playlists « soniques »**
  (issue #257). `app:audiomuse:sync` importe tempo, tonalité, énergie, ambiances
  et genres pondérés dans la table `audio_feature` (migration
  `Version20261008080000`), de façon incrémentale : manifeste `GET /api/sync?fields=index`,
  puis détail (sans embeddings) des seuls morceaux nouveaux ou réanalysés, par
  lots de 500 ; les morceaux disparus sont retirés, mais un manifeste vide
  n'efface jamais la table. Run `audiomuse-sync` dans l'historique ;
  `navidrome-playlists.sh` le lance avant la génération (un échec n'empêche pas
  de générer). Nouvelles playlists : **« Course »** (morceaux écoutés entre
  `PLAYLIST_COURSE_BPM_MIN` et `_MAX` BPM, demi-tempo toléré),
  **« Calmes et peu écoutés »** (ambiance « relaxed », ≤ `PLAYLIST_CALMES_MAX_PLAYS`
  écoutes) et **« Énergiques oubliées »** (les plus énergiques des pépites
  oubliées). `NavidromeRepository::getPlayCountsByMediaFileId()` découpe
  désormais ses requêtes par lots de 500.
- **Playlists décrites en texte** (issue #258) — sur `/playlists`, une phrase
  (« piano calme, pluie ») + un nombre de morceaux (+ un plafond d'écoutes
  facultatif) crée une playlist Navidrome à partir de la recherche texte CLAP
  d'AudioMuse-AI (`POST /api/clap/search`), dans l'ordre de pertinence.
  Enregistrée (table `described_playlist`, migration
  `Version20261007220000`), elle apparaît dans « Playlists générées » (slug
  `decrite-<id>`) et se régénère avec les autres ; « Retirer » arrête sa
  régénération sans supprimer la playlist Navidrome. `PlaylistGenerator`
  accepte des fournisseurs de définitions issues des données
  (`PlaylistDefinitionProviderInterface`, tag
  `app.playlist_definition_provider`). Les erreurs d'AudioMuse remontent avec
  leur `error_message` (ex. CLAP désactivé).
- **Playlist « Du connu vers l'oubli »** (issue #259) — un parcours sonore
  d'AudioMuse-AI (`/api/find_path`) qui glisse d'un morceau du top des 30
  derniers jours vers une pépite oubliée, par des titres soniquement proches,
  dans l'ordre du chemin. Extrémités tirées au hasard à chaque génération ;
  jusqu'à 3 paires tentées si AudioMuse ne trouve pas de chemin, puis échec
  (la playlist existante est conservée). Réglages `PLAYLIST_PARCOURS_LENGTH`
  (25) et `PLAYLIST_PARCOURS_START_DAYS` (30), seuils `PLAYLIST_PEPITES_*`
  pour l'arrivée. Nouvelle `AudioMuseClient::findPath()` ; délai des appels
  AudioMuse porté de 30 à 60 s.

## [1.4.1] - 2026-10-07

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

[Non publié]: https://github.com/kgaut/navidrome-tools/compare/1.5.2...HEAD
[1.5.2]: https://github.com/kgaut/navidrome-tools/compare/1.5.1...1.5.2
[1.5.1]: https://github.com/kgaut/navidrome-tools/compare/1.5.0...1.5.1
[1.5.0]: https://github.com/kgaut/navidrome-tools/compare/1.4.1...1.5.0
[1.4.1]: https://github.com/kgaut/navidrome-tools/compare/1.4.0...1.4.1
[1.4.0]: https://github.com/kgaut/navidrome-tools/compare/1.3.0...1.4.0
[1.3.0]: https://github.com/kgaut/navidrome-tools/compare/1.2.0...1.3.0
[1.2.0]: https://github.com/kgaut/navidrome-tools/compare/1.1.0...1.2.0
[1.1.0]: https://github.com/kgaut/navidrome-tools/compare/1.0.0...1.1.0
[1.0.0]: https://github.com/kgaut/navidrome-tools/releases/tag/1.0.0
