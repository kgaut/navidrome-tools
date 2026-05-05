# Changelog

Toutes les évolutions notables de ce projet sont consignées dans ce fichier.

Le format suit [Keep a Changelog 1.1.0](https://keepachangelog.com/fr/1.1.0/)
et le projet adhère à [Semantic Versioning 2.0](https://semver.org/lang/fr/).

## [Unreleased]

### Added
- **Suffixe + version dans le `<title>` des pages** : chaque onglet
  affiche désormais `Dashboard - Navidrome Tools 0.1.0` (ou
  `… - Navidrome Tools main-abc1234` sur un build de branche). Le
  stamp est exposé via la nouvelle variable Twig globale `app_version`,
  alimentée par `APP_VERSION` (paramètre `app.version`). Le build
  Docker bake la valeur via un `ARG APP_VERSION` que les CI GitHub et
  GitLab passent depuis le tag git (`v0.1.0` → `0.1.0`) ou depuis
  `<branch>-<short_sha>` en push de branche. Défaut `dev` pour les
  exécutions hors Docker.

### Fixed
- **Cache Symfony hors volume persistant** : la fin d'un long
  `app:lastfm:import` plantait sporadiquement avec `Failed opening
  required '/app/var/cache/prod/Container…/getConsole_ErrorListenerService.php'`
  au moment du `ConsoleTerminateEvent`. Cause : le cache compilé du
  conteneur DI vivait dans `/app/var/cache/prod`, sous le volume
  persistant `/app/var` partagé entre instances ; chaque entrypoint
  qui démarrait (web qui redémarre, container `cli` one-shot lancé
  via `docker compose run`) faisait un `rm -rf var/cache/prod` avant
  son `cache:warmup`, supprimant les fichiers de service que la CLI
  en cours allait charger paresseusement à la sortie. Fix : la
  variable `APP_CACHE_DIR` (positionnée à `/app/.symfony-cache` dans
  l'image) sort le cache du volume — chaque conteneur a désormais
  son propre cache dans son layer image, plus de course possible.
  `App\Kernel::getCacheDir()` honore `APP_CACHE_DIR` ; les setups
  hors Docker (Lando, dev local, tests) gardent le défaut Symfony
  (`var/cache/<env>`).

### Added
- **Crash-safety des écritures Navidrome** (#135) : les imports
  Last.fm (`app:lastfm:process`, `app:lastfm:rematch`) wrappent
  désormais chaque batch de 100 INSERT dans une transaction explicite
  `BEGIN IMMEDIATE` / `COMMIT` côté Navidrome. Un crash mid-batch
  (kill -9, exception PHP, OOM) déclenche un ROLLBACK SQLite, et les
  audits + `DELETE FROM lastfm_import_buffer` sont reportés en
  post-commit Navidrome — donc soit tout passe, soit rien (par
  batch). La reprise est idempotente via `scrobbleExistsNear` +
  cross-check intra-batch (`pendingBatchHasNearScrobble`) pour les
  doublons de scrobble entre rows en vol.
- **Restore automatique sur corruption détectée**
  (`NavidromeContainerManager::runWithNavidromeStopped`) : un
  `PRAGMA quick_check` tourne désormais aussi *après* l'action. S'il
  fail, le tool restaure le snapshot pré-action (`<dbPath>.backup-…`
  copié juste avant) et lève une exception explicite (« DB corrompue
  après l'action — restaurée automatiquement depuis … »). Si la
  restore échoue elle aussi, Navidrome n'est PAS redémarré et l'erreur
  pointe vers `app:navidrome:db:restore` pour récupération manuelle.
- **PRAGMA durabilité sur la connexion write Navidrome** :
  `busy_timeout=30000` (retry-on-lock pendant 30s si un autre
  processus tient la DB) et `synchronous=FULL` (fsync complet à
  chaque COMMIT) appliqués au boot. Plus un `wal_checkpoint(TRUNCATE)`
  forcé en fin de run pour s'assurer que le WAL est mergé avant que
  Navidrome ne rouvre la DB.
- **`app:navidrome:db:check [--integrity]`** : check ad-hoc de
  l'intégrité de la DB Navidrome. Quick-check par défaut (rapide),
  `--integrity` pour le full integrity_check (vérifie aussi la
  cohérence row/index). Read-only, n'arrête pas Navidrome.
- **`app:navidrome:db:restore [--timestamp=YYYYMMDDHHMMSS] [--list]`** :
  restauration manuelle d'un snapshot. `--list` affiche les backups
  disponibles. Sans `--timestamp`, prend le plus récent. Stoppe
  Navidrome avant restore, le redémarre après.
- **`NavidromeDbBackup::restore()` + `latestBackup()`** : nouvelles
  méthodes publiques (la classe ne savait jusqu'ici que `backup()` +
  `quickCheck()`). `restore()` vérifie le snapshot *avant* d'écraser
  la DB live, puis re-vérifie après — refuse un restore zombie.
- **Réglages : bouton « Vider la base tools »** dans une zone
  dangereuse de `/settings`. Vide en une opération les tables
  d'import / audit / cache / snapshots / historiques (buffer Last.fm,
  `lastfm_import_track`, `lastfm_match_cache`, `lastfm_history`,
  `navidrome_history`, `stats_snapshot`, `top_snapshot`,
  `run_history`) tout en **conservant** les réglages, les
  définitions de playlists et les deux tables d'alias
  (`lastfm_alias` track-level + `lastfm_artist_alias` artist-level).
  Confirmation par saisie de « WIPE » + dialogue navigateur, CSRF
  dédié `settings_wipe_database`. Implémenté par
  `App\Service\ToolsDatabaseWiper` (DBAL pur, ordre FK-safe).

### Removed
- **BREAKING — Worker Symfony Messenger** : la mécanique async qui
  permettait de lancer les 4 long-runners Last.fm (`fetch`, `process`,
  `rematch`, `sync-loved`) depuis l'UI est entièrement supprimée. Plus
  de service `navidrome-tools-worker` (et plus de `workerserver` côté
  Lando), plus de mode `APP_MODE=worker` dans l'entrypoint, plus de
  table `messenger_messages`, plus de colonne
  `run_history.progress`, plus de polling JSON
  (`/history/{id}/progress.json`). Les dépendances `symfony/messenger`
  et `symfony/doctrine-messenger` sont retirées. Les pages
  `/lastfm/import` et `/lastfm/love-sync` deviennent des pages
  d'aide CLI : compteurs (buffer, unmatched, statut auth Last.fm) +
  blocs `<pre>` listant les commandes à copier-coller (`bin/console`,
  `docker compose exec`, `lando symfony`). La route POST
  `/lastfm/process`, le contrôleur `RematchController` (POST
  `/lastfm/rematch`) et le bouton « rematch » sur `/history/{id}`
  sont supprimés. Migration `Version20260504212838` qui drop la
  colonne `run_history.progress` et la table `messenger_messages`.
  Pour conserver les jobs Last.fm, planifier
  `app:lastfm:{import,process,rematch,sync-loved}` (avec
  `--auto-stop` quand pertinent) depuis le crontab unix de l'hôte —
  exemples dans `docker-compose.example.yml`.

### Fixed
- **`app:lastfm:import` — retry sur erreurs HTTP transitoires de
  l'API Last.fm** : `LastFmClient::call()` intercepte désormais les
  500/5xx, 429 et erreurs réseau (`TransportExceptionInterface`) et
  réessaie jusqu'à 3 fois en attendant `LASTFM_PAGE_DELAY_SECONDS`
  entre chaque tentative. Évite qu'un long fetch (`Last.fm API call
  failed (method=user.getRecentTracks page=215): HTTP/2 500`) ne tue
  toute la run alors que la prochaine page passerait sans souci. Les
  erreurs applicatives (clé d'API invalide, etc., signalées via le
  champ `error` du JSON) ne sont pas retentées — elles surfacent
  immédiatement. Le message d'erreur final inclut le compteur
  d'attempts pour diagnostiquer.
- **`app:lastfm:process` / `app:lastfm:rematch` — fuite mémoire sur
  les gros volumes** : `LastFmBufferProcessor` et `LastFmRematchService`
  flushaient toutes les 100 itérations mais ne vidaient jamais
  l'identity map de Doctrine. Sur un buffer Last.fm volumineux (≥ ~10k
  scrobbles), les entités hydratées par `toIterable()`
  (`LastFmBufferedScrobble` / `LastFmImportTrack`) plus celles
  persistées par le matcher (`LastFmMatchCacheEntry`) finissaient par
  saturer les 128 Mo de PHP — `Allowed memory size of 134217728 bytes
  exhausted in UnitOfWork.php`. Les deux services détachent maintenant
  ces entités juste après chaque flush via un nouveau helper
  `flushAndDetach()`, et le repo cache expose `detachPending()` pour
  vider sa map en mémoire interne en synchro. La `RunHistory`
  attachée à l'audit reste managée pour ne pas casser le callback de
  progression console.

### Changed
- **Favicon** : adapte automatiquement sa couleur au thème système
  (`prefers-color-scheme`). La note reste sombre (`#0f172a`) en mode
  clair et passe en blanc cassé (`#f8fafc`) en mode dark, pour rester
  lisible sur les onglets sombres de Firefox/Chrome.

### Added
- **Page `/stats/tops` — tops fenêtre libre** : nouvelle page dédiée
  qui affiche, sur une fenêtre `[from, to]` arbitraire (sélecteur
  date-picker, filtre optionnel par client Subsonic), le top
  **50 artistes / 100 albums / 500 morceaux** depuis les scrobbles
  Navidrome. Les snapshots sont mis en cache dans la nouvelle table
  `top_snapshot` (clé unique `(window_from, window_to, client)`,
  bornes en epoch arrondies au jour côté contrôleur pour réutiliser
  les calculs entre clics). Bouton **« + Créer playlist Navidrome »**
  sur le top morceaux : crée la playlist via l'API Subsonic à partir
  des N premiers IDs (1 ≤ N ≤ 500, nom personnalisable). Listing des
  10 dernières fenêtres calculées en bas de page pour rappel rapide.
  Nouvelle commande `app:stats:tops:compute --from=… --to=…
  [--client=…]` (wrappée par `RunHistoryRecorder`, type `stats-tops`)
  pour planifier le calcul depuis le crontab. Lien ajouté dans
  `_navbar.html.twig` (sous-menu Statistiques → « Tops fenêtre
  libre »).
- **Lancement des jobs Last.fm depuis l'UI sans timeout HTTP** : les
  4 long-runners (`fetch`, `process`, `rematch`, `sync-loved`) sont
  maintenant exécutés via Symfony Messenger (transport Doctrine,
  table `messenger_messages` auto-créée). Le controller crée une row
  `run_history` en `queued` puis dispatche un message ; un nouveau
  service `navidrome-tools-worker` (`APP_MODE=worker`) consomme la
  file via `messenger:consume async --limit=1` (sérialisation des
  écritures Navidrome). La page `/history/{id}` affiche une **barre
  de progression** rafraîchie via polling JSON
  (`GET /history/{id}/progress.json`, toutes les 2s en vanilla JS,
  recharge la page sur fin de job). Statuts ajoutés : `queued`,
  `running` ; détection automatique des jobs `stale` (> 10 min sans
  mise à jour de progression). Pré-flight `--auto-stop` Navidrome
  déplacé dans le handler.

### Changed
- **BREAKING — PHP 8.4 minimum** : le support de PHP 8.3 est retiré
  (matrice CI ramenée à 8.4 uniquement, `composer.json` pin
  `>=8.4` + `config.platform.php=8.4.0`, image Docker passe sur
  `dunglas/frankenphp:1-php8.4-alpine`, `.lando.yml.dist` sur
  `php:8.4`). Doctrine ORM bascule sur `enable_native_lazy_objects:
  true` (option PHP 8.4 qui remplace les ghost objects basés
  `symfony/var-exporter`). Les déploiements existants reçoivent
  automatiquement la nouvelle image lors d'un `docker compose pull`
  — aucune action requise. Pour le dev local, `lando rebuild -y`
  pour récupérer le nouveau service appserver.
- **BREAKING — Suppression du cron interne (supercronic)** : le tool
  ne planifie plus rien tout seul. La commande `app:cron:dump` et la
  commande `app:playlist:run-all` sont supprimées, le service Docker
  `navidrome-tools-cron` disparaît du `docker-compose.example.yml`,
  le mode `APP_MODE=cron` du Dockerfile / entrypoint disparaît, et
  les variables d'environnement `STATS_REFRESH_SCHEDULE`,
  `LASTFM_LOVE_SYNC_SCHEDULE`, `LASTFM_REMATCH_SCHEDULE`,
  `LASTFM_FETCH_SCHEDULE`, `LASTFM_PROCESS_SCHEDULE` ne sont plus
  lues. Le champ `schedule` de `playlist_definition` est retiré
  (migration `Version20260504100000` qui drop la colonne) ainsi que
  son tri sur le dashboard. Les jobs récurrents
  (`app:playlist:run`, `app:stats:compute`, `app:lastfm:import`,
  `app:lastfm:process`, `app:lastfm:rematch`, `app:history:purge`…)
  doivent être planifiés depuis le **crontab unix de l'hôte** via
  `docker compose exec -T navidrome-tools-web php bin/console …`.
  La dépendance `dragonmantank/cron-expression` est retirée. Section
  « Lancement des jobs récurrents » du README pour des exemples
  prêts à coller.

- **BREAKING — Last.fm import découplé en deux étapes** :
  `app:lastfm:import` ne fait plus que **récupérer** les scrobbles
  Last.fm dans une nouvelle table `lastfm_import_buffer` (pas de
  matching, pas d'écriture Navidrome — peut tourner Navidrome up).
  Une nouvelle commande `app:lastfm:process` traite ensuite ce buffer :
  matching cascade, insertion dans `scrobbles` Navidrome, audit dans
  `lastfm_import_track`, suppression de la row du buffer (Navidrome
  doit être arrêté). La page `/lastfm/import` propose les deux actions
  côte à côte ; le dashboard affiche le compteur du buffer. Le service
  `LastFmImporter` est remplacé par `LastFmFetcher` +
  `LastFmBufferProcessor`. Les options `--tolerance`,
  `--show-unmatched`, `--force`, `--auto-stop` disparaissent de
  `app:lastfm:import` et migrent (sauf `--show-unmatched`) sur
  `app:lastfm:process`. Nouvelles env vars `LASTFM_FETCH_SCHEDULE` et
  `LASTFM_PROCESS_SCHEDULE` (vides par défaut). `app:lastfm:rematch`
  inchangée — continue de retraiter les `lastfm_import_track`
  unmatched cumulés. Migration automatique au boot
  (`Version20260504000000`).

- **Réorganisation du menu de navigation** : le top-level passe de
  10 entrées à 5 (Dashboard, Playlists ▾, Statistiques ▾, Last.fm ▾,
  Admin ▾). « Nouvelle playlist » rejoint le dropdown Playlists, les
  dropdowns Stats et Last.fm sont sous-groupés visuellement (Vue &
  analyse / Découverte / Historique des écoutes / Audit métadonnées
  pour Stats ; Import / Unmatched / Aliases pour Last.fm). « Discover »
  migre dans Stats > Découverte ; « Tagging » dans Stats > Audit
  métadonnées (co-localisé avec « Métadonnées incomplètes » qui
  audite la même chose). « Historique des runs » et « Réglages » sont
  regroupés sous un nouveau dropdown « Admin ». Le menu est désormais
  défini dans un partial unique `templates/_navbar.html.twig` (source
  de vérité partagée desktop + mobile) — corrige au passage
  l'asymétrie où 2 entrées Stats (« Artistes oubliés », « Métadonnées
  incomplètes ») n'étaient accessibles que sur desktop. Closes #115.

### Added
- **Plage de dates dans la progression de `app:lastfm:import`** :
  chaque ligne de progression (toutes les 50 scrobbles) affiche
  désormais la fenêtre `played_at` du batch en cours
  (`batch=YYYY-MM-DD HH:MM → YYYY-MM-DD HH:MM`), pour suivre où
  l'import en est dans l'historique. La signature du callback
  `LastFmFetcher::fetch(progress:)` reçoit deux nouveaux paramètres
  `?\DateTimeImmutable` (premier / dernier `playedAt` du batch).

- **Compteurs Last.fm sur le dashboard** : deux nouvelles cards
  santé affichent le nombre de scrobbles en attente dans le buffer
  Last.fm (lien direct vers `/lastfm/import` pour les traiter) et
  le nombre cumulé de scrobbles non matchés (lien vers
  `/lastfm/unmatched`). Les cards passent en gris quand le compteur
  vaut 0.

- **Ajout de morceau dans une playlist depuis l'UI** : sur
  `/playlists/{id}`, nouveau bloc « Ajouter un morceau » sous la table
  des morceaux. Tape une requête (≥ 2 chars), Subsonic répond les
  matchs (`search3.view` wrappé dans `SubsonicClient::search3()`),
  cliquer « + Ajouter » l'ajoute via `updatePlaylist(songIdToAdd: …)`.
  La requête est conservée dans l'URL après l'ajout pour permettre
  d'enchaîner les ajouts sur le même résultat. Les morceaux déjà
  présents sont filtrés des résultats. Closes #78 (partiellement —
  reorder dans #117).
- **Endpoint JSON `/api/status` + widget Homepage (gethomepage)** :
  nouveau controller `App\Controller\Api\StatusController` qui expose
  les métriques clés du tool en JSON. Sert deux usages avec un seul
  endpoint :
  - **Healthcheck Docker** sans auth : `GET /api/status` retourne
    `{status, navidrome_db}` avec code HTTP 200 (ok) ou 503 (degraded)
    selon que la DB Navidrome est joignable ou non. Utilisable
    directement dans un `HEALTHCHECK` compose.
  - **Widget [Homepage](https://gethomepage.dev/widgets/services/customapi/)**
    avec auth par bearer token (env `HOMEPAGE_API_TOKEN`, transmis via
    `?token=…` ou header `Authorization: Bearer …`) : retourne un
    payload enrichi avec `scrobbles_total`, `unmatched_total`,
    `missing_mbid`, `navidrome_container` et le dernier `RunHistory`
    (type/status/started_at/duration_ms). Token vide = mode enrichi
    désactivé (404 sur les requêtes tokenisées) ; token erroné = 401.
    Comparaison via `hash_equals` (timing-safe). Section dédiée dans
    le README avec snippet `services.yaml` Homepage prêt à coller.
    Ajout d'une exception `PUBLIC_ACCESS` sur `^/api/` dans
    `config/packages/security.yaml`. Closes #106.
- **Top artistes / albums unmatched** : deux nouvelles pages
  `/lastfm/unmatched/artists` et `/lastfm/unmatched/albums` qui
  agrègent les scrobbles non matchés (toutes runs confondues) par
  artiste seul ou par couple `(artiste, album)`, triés par nombre de
  scrobbles décroissant et accompagnés du nombre de titres distincts
  + dernier joué. Bouton « + Lidarr » par ligne (Lidarr ne supporte
  que l'ajout d'artistes : sur la vue albums le bouton ajoute donc
  l'artiste de l'album, le téléchargement de l'album dépend de la
  stratégie de monitoring Lidarr). Statut Lidarr (✓ déjà / ✗ absent)
  affiché par ligne. Une barre d'onglets « Par titre / Par artiste /
  Par album » relie les 3 vues. Liens ajoutés au menu Last.fm
  (desktop + mobile).

### Fixed
- **OAuth Last.fm : la redirection finale n'aboutissait jamais** : la
  page `/lastfm/connect` pré-appelait `auth.getToken` puis passait le
  token obtenu **et** `cb` à `https://www.last.fm/api/auth/`. Or c'est
  un mélange des deux flows incompatibles documentés par Last.fm —
  desktop (token + pas de callback, l'utilisateur copie/colle) vs web
  (pas de token côté URL, Last.fm en génère un et le pousse via `cb`).
  En présence d'un `token` explicite, Last.fm bascule sur le flow
  desktop et n'effectue **pas** la redirection vers `cb` : l'utilisateur
  voit la page « access granted » mais reste bloqué chez Last.fm, et la
  session reste marquée non active dans les Réglages. `connect` ne
  pré-appelle plus `auth.getToken` ; `LastFmAuthService::buildAuthorizeUrl`
  ne prend plus que `$callbackUrl`. Le token arrive bien dans le
  callback et est échangé contre la session via `auth.getSession` comme
  documenté.

- **`--auto-stop` corrompait la DB SQLite Navidrome après un import lourd** :
  `DockerCli::stop()` envoyait un `docker stop -t 10` (timeout codé en
  dur 10s, hérité du défaut Docker). Pas assez pour que Navidrome
  termine son checkpoint WAL sur une grosse librairie après une rafale
  d'écritures (~13k scrobbles insérés en un run) — Docker basculait sur
  SIGKILL en plein flush, laissant un `.db-wal` mi-écrit. Le pipeline
  enchaînait ensuite directement avec `$action()` (l'`import`) sans
  poller `docker inspect` pour confirmer l'arrêt, ouvrait la DB en
  écriture et la corrompait.

  Défense en profondeur dans `NavidromeContainerManager::runWithNavidromeStopped()` :
  1. timeout `docker stop` configurable via `NAVIDROME_STOP_TIMEOUT_SECONDS`
     (défaut 60s, contre 10s avant) ;
  2. polling de `docker inspect` jusqu'à `Running:false` (ceiling
     `NAVIDROME_STOP_WAIT_CEILING_SECONDS`, défaut 30s) — on n'écrira
     jamais sur la DB tant que `inspect` voit Navidrome vivant ;
  3. snapshot de la DB SQLite (+ siblings `-wal` / `-shm`) vers
     `<dbPath>.backup-<unix_ts>` avant l'action — rollback trivial
     en `cp`. Rétention configurable via `NAVIDROME_DB_BACKUP_RETENTION`
     (défaut 3) ;
  4. `PRAGMA quick_check` avant l'action — si la DB est déjà brisée
     (résidu d'un crash antérieur), on abandonne sans aggraver et
     l'utilisateur reçoit un message explicite. Implémenté par le
     nouveau service `App\Navidrome\NavidromeDbBackup`. Closes #118.
- **`Last.fm API error 6: Track not found` interrompait l'import** : à
  l'étape 7 de la cascade de matching, `ScrobbleMatcher` appelle
  `LastFmClient::trackGetInfo()` pour les scrobbles non matchés
  localement. Quand Last.fm ne connaît pas le track (cas attendu —
  morceau absent de leur catalogue), l'API renvoyait `error: 6` et
  `LastFmClient::call()` le propageait en `RuntimeException`, qui
  remontait jusqu'à crasher le run (`app:lastfm:import` /
  `app:lastfm:rematch`) au premier scrobble inconnu de Last.fm.
  Nouvelle exception typée `App\LastFm\LastFmApiException` qui porte
  `errorCode` séparément ; `lookup()` (track.getInfo /
  track.getCorrection) intercepte spécifiquement le code 6 et retourne
  `LastFmTrackInfo::empty()` — la cascade continue normalement vers
  l'éventuel fuzzy puis `unmatched`. Les autres codes (rate limit 29,
  invalid key 10, service down 11/16, etc.) continuent de remonter
  pour ne pas masquer une vraie panne. Closes #113.
- **`UNIQUE constraint failed: lastfm_match_cache.source_artist_norm,
  lastfm_match_cache.source_title_norm` pendant `app:lastfm:import`** :
  l'import ne flushe pas entre deux scrobbles, donc lorsque la même
  couple `(artiste, titre)` revenait plusieurs fois (cas typique : un
  morceau écouté plusieurs fois dans l'historique) ou que deux entrées
  source distinctes se réduisaient à la même forme normalisée,
  `LastFmMatchCacheRepository::upsert()` `persist()`-ait deux entités
  différentes pour la même couple normalisée — l'index unique
  `uniq_lastfm_match_cache_source_norm` les attrapait au flush final
  et l'import s'arrêtait en erreur. Le repo maintient maintenant un
  index en mémoire des entités persistées dans la même requête, qui
  est consulté avant `findOneBy()` dans `findByCouple()`. Index purgé
  en cohérence par `purgeByCouple` / `purgeByArtist` / `purgeAll`.
- **Heures Last.fm history affichées dans `APP_TIMEZONE`** : la page
  `/stats/lastfm-history` affichait les heures de scrobble avec le
  décalage UTC (ex. `10:00` au lieu de `12:00` à Paris en été). Cause :
  Doctrine `datetime_immutable` sérialisait l'heure dans la timezone
  de l'objet (UTC) puis la relisait en l'étiquetant avec la timezone
  PHP par défaut (`Europe/Paris`), ce qui décalait silencieusement
  l'instant ; Twig `|date` ne corrigeait plus rien. Nouveau type
  Doctrine `utc_datetime_immutable` (`App\Doctrine\UtcDateTimeImmutableType`)
  qui force la sérialisation en UTC à l'écriture et tague la valeur
  UTC à la relecture, indépendamment de `APP_TIMEZONE`. Appliqué à
  `LastFmHistoryEntry::$playedAt` / `$fetchedAt` et
  `LastFmImportTrack::$playedAt`. Aucune migration de données : les
  rows existantes en base étaient déjà au bon wall-clock UTC pour
  `played_at` ; `fetched_at` se réaligne au prochain « Rafraîchir ».
  Closes #102.

### Added
- **Menu burger mobile** : la navigation principale est désormais
  utilisable sur petit écran. En `< md` (768px), un bouton hamburger
  remplace la barre horizontale et déplie un menu vertical en
  dessous du header. Les sous-menus (Statistiques, Last.fm) sont
  rendus en `<details>`/`<summary>` (tap-friendly, pas de hover).
  Le menu desktop reste inchangé. ~15 lignes de JS vanilla pour le
  toggle.
- **Pilotage du conteneur Navidrome depuis le dashboard** : nouvelle
  variable `NAVIDROME_CONTAINER_NAME` (vide = feature désactivée). Quand
  renseignée, le dashboard affiche une card « Conteneur Navidrome » avec
  l'état UP/DOWN et des boutons Start/Stop POST CSRF
  (`/navidrome/container/start|stop`). En parallèle, les commandes qui
  écrivent dans la DB Navidrome (`app:lastfm:import`,
  `app:lastfm:rematch`, leurs HTTP counterparts) refusent désormais de
  tourner si le conteneur est détecté UP — flag CLI `--force` pour
  outrepasser, flash error + redirect côté UI. Si le socket Docker n'est
  pas joignable (mount manquant) le statut est `unknown` et les
  écritures sont bloquées par défaut. Implémenté via `docker` CLI (le
  paquet alpine `docker-cli` est installé dans l'image, le socket
  `/var/run/docker.sock` à mounter manuellement — bloc commenté dans
  `docker-compose.example.yml`). Page `/lastfm/import` : le bandeau
  rouge devient un bandeau vert « écritures sûres » quand Navidrome est
  arrêté, et embarque un bouton « ⏸ Arrêter Navidrome » quand il
  tourne.
- **Flag `--auto-stop`** sur `app:lastfm:import` et `app:lastfm:rematch` :
  pilote tout le cycle automatiquement (stop Navidrome → import → restart
  Navidrome, **toujours**, même en cas d'erreur de l'import via
  try/finally). Active sur le cron `app:lastfm:rematch` généré par
  `app:cron:dump` quand `NAVIDROME_CONTAINER_NAME` est renseigné — le job
  tourne désormais entièrement non-attendu sans verrou WAL. No-op si la
  feature est désactivée ou si Navidrome est déjà arrêté. Si le socket
  Docker est `unknown`, refuse l'orchestration (impossible de garantir
  un état cohérent). En cas de double échec (import KO + restart KO), la
  `NavidromeContainerException` finale chaîne l'exception d'origine en
  `previous` pour tracer les deux problèmes.
- **Générateur de playlist « anniversaire »** (key `anniversary`) :
  agrège les top morceaux écoutés à la même date il y a N années
  (souvenirs façon Spotify). Paramètres : `years_offsets` (liste
  CSV, défaut « 1,2,5,10 ») et `window_days` (largeur de la fenêtre
  en ± jours, défaut 3). Si un morceau a été écouté à la même date
  il y a 2 ans ET il y a 5 ans, il est compté deux fois et remonte
  en tête. Nouvelle méthode
  `NavidromeRepository::topTracksInWindows()` qui prend une liste
  de fenêtres et UNION-aggrège côté SQL. Closes #90.
- **Dark theme par défaut** : passe en revue de
  `templates/base.html.twig` qui pose un thème sombre permanent
  via une overlay CSS qui re-cible les utilitaires Tailwind les
  plus courants (`bg-white`, `bg-slate-50/100/200`,
  `text-slate-800/700/600/500`, bords, flash messages, inputs
  natifs, code). Une seule modif de fichier — pas de réécriture
  template par template, pas de `dark:` prefix à propager. Closes #87.
- **Page « Discover » `/discover/artists`** : suggestions d'artistes
  via `LastFmClient::artistGetSimilar` (wrap `artist.getSimilar`).
  Prend tes top 20 artistes des 90 derniers jours, demande à Last.fm
  les 10 plus similaires de chacun, dédoublonne par nom normalisé en
  gardant le meilleur score de match, filtre les artistes déjà dans
  `media_file` (via la nouvelle méthode
  `NavidromeRepository::getKnownArtistsNormalized()` qui exploite
  `np_normalize`). Croise avec
  `LidarrClient::indexExistingArtists()` pour afficher « ✓ déjà dans
  Lidarr » ou un bouton « + Lidarr » par carte. Cache 24h dans
  `stats_snapshot` (key `discover-artists`) avec rafraîchissement
  manuel via POST CSRF. Désactivé silencieusement si `LASTFM_API_KEY`
  est vide. Closes #92.
- **Page « métadonnées incomplètes »** sur `/stats/incomplete-metadata` :
  liste les albums dont la colonne Navidrome `mbz_album_id` est vide
  ou nulle, regroupés par artiste (album_artist) et triés par nombre
  d'écoutes. Pour chaque ligne : nombre de pistes, nombre d'écoutes
  total, lien vers Navidrome (recherche album) et MusicBrainz
  (recherche release). Curate les albums prioritaires à retagger
  dans Picard / beets (les plus écoutés en premier). Détection auto
  de la colonne via `mediaFileColumns()` — fonctionnalité désactivée
  silencieusement si la colonne n'existe pas. Closes #25.
- **Page « artistes oubliés »** sur `/stats/forgotten-artists` :
  liste les artistes avec un historique de plays consistant
  (`min_plays`, défaut 50) qui n'ont rien tourné depuis longtemps
  (`idle_months`, défaut 12). Tri par `plays × idle_seconds` desc
  (les gros favoris dormants montent en haut). Liens directs vers
  Navidrome (recherche artiste) et Last.fm. Pendant à l'échelle
  artiste du générateur `songs-you-used-to-love`. Closes #91.
- **Split des stats par client Subsonic** sur `/stats` : nouveau select
  « Tous / DSub / Symfonium / web… » à côté du select période, alimenté
  par `SELECT DISTINCT client FROM scrobbles`. Filtre le total
  d'écoutes, les morceaux distincts, le top 10 artistes et le top 50
  morceaux. Détection auto via `NavidromeRepository::hasScrobbleClient()`
  (PRAGMA `scrobbles`) — si la colonne est absente côté Navidrome
  (installation très ancienne ou stripped-down), le select n'apparaît
  pas. Le cache `stats_snapshot` clé dans (period, client) avec une
  fonction `StatsService::cacheKey($period, $client)` qui préserve la
  clé legacy `$period` quand le client est null. `computeAll()`
  recalcule automatiquement chaque combo (period × client). Closes #97.
- **Courbe de diversité d'écoute** sur `/stats/charts` : nouveau 4e
  Chart.js qui plotte le ratio artistes uniques / écoutes mois par
  mois (en pourcentage). Indicateur d'exploration vs. rabâchage.
  Méthode `NavidromeRepository::getDiversityByMonth($monthsBack)` qui
  retourne `[{month, plays, uniques}]` avec remplissage des mois sans
  scrobbles à zéro. Closes #93.
- **`app:lastfm:rematch --random`** : nouveau flag qui mélange l'ordre
  des unmatched avant d'appliquer `--limit`. Utile pour échantillonner
  un sous-ensemble représentatif quand on debugge une nouvelle
  heuristique de matching sur un gros backlog (sans le flag, le tri
  par défaut `id ASC` retraite toujours les mêmes morceaux en tête de
  table).
- **Cache de résolution Last.fm match (positif + négatif)** : nouvelle
  table `lastfm_match_cache` (`source_artist_norm`, `source_title_norm`
  UNIQUE → `target_media_file_id` nullable + `strategy`
  + `resolved_at`) qui mémorise le verdict de la cascade entre deux
  imports. `App\LastFm\ScrobbleMatcher` consulte le cache **après**
  les aliases (track + artiste) et **avant** la cascade : hit positif
  → renvoyé tel quel ; hit négatif non-stale → unmatched, on saute la
  cascade et l'API Last.fm. Les négatifs expirent au bout de
  `LASTFM_MATCH_CACHE_TTL_DAYS` jours (défaut 30, 0 = jamais) — purge
  automatique au démarrage de chaque `app:lastfm:import` /
  `app:lastfm:rematch`. Les positifs sont éternels et invalidés par
  les mutations d'alias (création/édition/suppression d'un track-alias
  → `purgeByCouple` ; création/édition d'un artist-alias →
  `purgeByArtist`). `MatchResult` expose 3 compteurs
  (`cacheHitsPositive` / `cacheHitsNegative` / `cacheMisses`) propagés
  dans `RunHistory.metrics`. CLI `bin/console app:lastfm:cache:clear`
  (option `--negative-only`) pour vider à la main. Closes #20.
- **Récupération MBID via Last.fm `track.getInfo`** : nouvelle étape
  dans la cascade de matching (`ScrobbleMatcher::runCascade`), placée
  après le couple 4 paliers et avant le fuzzy. Pour les scrobbles dont
  les heuristiques locales ont échoué, on appelle
  `track.getInfo?artist=…&track=…&autocorrect=1` côté Last.fm pour
  récupérer (a) le MBID officiel quand il manque dans le scrobble,
  (b) une graphie corrigée du couple `(artist, title)`. Si le MBID
  retourné matche dans Navidrome → match. Sinon, on retente la
  cascade DB locale (MBID/triplet/couple) sur la version corrigée.
  Le résultat est mémorisé dans le cache (#20) sous strategy
  `lastfm-correction` ; les négatifs sont également cachés pour
  éviter de re-taper l'API au prochain run. `LastFmClient` gagne
  `trackGetInfo()` et `trackGetCorrection()` qui retournent un
  `LastFmTrackInfo` immuable. Le helper `correctionOrNull()` collapse
  les corrections « identiques au trim+lower près » en `null` —
  Last.fm renvoie le terme corrigé même quand l'input était déjà
  canonique. Réutilise `LASTFM_API_KEY` existant (pas de nouvelle
  variable). Closes #17.
- **Gestion des playlists Navidrome** (epic #71) : nouvelle section
  `/playlists` qui liste les playlists existantes côté Navidrome avec
  leurs métadonnées (nombre de morceaux, durée, dates création/
  modification, owner, public/privé) — `SubsonicClient::getPlaylists()`
  enrichi avec ces champs. Page détail `/playlists/{id}` affiche le
  contenu (artist/album/durée/play count/statut starred), avec : bouton
  rename, suppression (cleanup automatique du `lastSubsonicPlaylistId`
  des `PlaylistDefinition` rattachées), duplication, star/unstar par
  morceau et bulk star/unstar (réutilise `SubsonicClient::starTracks`/
  `unstarTracks` existants), retrait d'un morceau, détection des
  morceaux « morts » (présents dans la playlist mais absents de
  `media_file`) avec bouton purge, statistiques (durée totale, top 10
  artistes, top 10 albums, distribution par année, % jamais joués),
  bulk delete depuis la liste, export M3U téléchargeable. Le
  `M3uExporter` est aussi branché sur la prévisualisation des
  `PlaylistDefinition` (closes #8). Toutes les écritures passent par
  l'API Subsonic (`updatePlaylist.view`, `deletePlaylist.view`) — la
  DB Navidrome reste mountée `:ro` en prod. Nouvelles méthodes
  `SubsonicClient::getPlaylist()` et `updatePlaylist()`. Closes #72,
  #73, #74, #75, #76, #77, #78, #79, #80, #81, #82, #83.
- **Plugins custom en déploiement Docker** : nouveau namespace
  `App\Plugin\` mappé sur `plugins/`, bind-mountable sur `/app/plugins`
  pour ajouter ses propres générateurs de playlists sans rebuilder
  l'image. L'autoload Composer et le cache Symfony sont régénérés à
  chaque démarrage du conteneur (`docker/entrypoint.sh`). Le flag
  `--classmap-authoritative` est retiré du `Dockerfile` pour autoriser
  le fallback PSR-4 actif au runtime ; le classmap optimisé reste en
  place pour les vendors. Documentation complète dans
  `docs/PLUGINS.md` (section « Plugins custom en déploiement Docker »)
  avec exemple de classe et bind-mount à dupliquer sur les services
  web ET cron. Closes #69.
- **Matching Last.fm — featuring asymétrique** : nouveau palier
  `lookupArtistPrefixFeaturingTitle()` dans la cascade
  `findMediaFileByArtistTitle()`. Catche le cas où Last.fm met le
  featuring dans le titre (ex. `Jurassic 5 / Join The Dots (Ft Roots
  Manuva`) tandis que Navidrome le met dans l'artiste (ex. `Jurassic 5
  feat. Roots Manuva / Join the Dots`). Active uniquement quand le
  titre original contient un marker explicite `(feat./ft./featuring/
  with X)` (helper `titleHasFeaturingMarker`) — gardé strict sur le
  titre nettoyé pour limiter les faux-positifs. Marker LIKE côté
  artiste : `:a feat %`, `:a ft %`, `:a featuring %`, `:a with %`.
  Mesuré sur le dataset local : 23 unmatched distincts avec marker,
  6 récupérables (Orelsan, Cypress Hill, Tiken Jah Fakoly, High Tone…).
  Closes #67.
- **Alias d'artistes (synonymes)** : nouvelle table
  `lastfm_artist_alias` (id, source_artist, source_artist_norm UNIQUE,
  target_artist, created_at) qui mappe un nom source Last.fm → nom
  canonique côté Navidrome — utile pour les renommages
  (« La Ruda Salska » → « La Ruda »), variantes de romanisation,
  conventions « The X » / « X, The ». Consulté par
  `App\LastFm\ScrobbleMatcher` **après** l'alias track-level
  (`lastfm_alias`) mais **avant** la cascade : réécrit l'artiste du
  `LastFmScrobble` puis laisse les heuristiques tourner. Un seul
  alias couvre tous les morceaux d'un artiste renommé. CRUD complet
  sur `/lastfm/artist-aliases` (menu Last.fm → Alias artistes) avec
  recherche paginée. Bouton « 🎭 Aliaser artiste » sur
  `/lastfm/unmatched`. Combo avec le rematch (#21) pour récupérer
  rétrospectivement tous les scrobbles concernés. Comparaison via
  `NavidromeRepository::normalize()` (case/accents/ponctuation
  insensitive). Closes #65.
- Page `/tagging/missing-mbid` : audit des morceaux Navidrome dont
  `mbz_track_id` ET `mbz_recording_id` sont vides. Filtres
  artiste/album, pagination, export CSV (id, path, artist,
  album_artist, album, title, year) à piper dans un tagger externe
  type `beet import -A` ou MusicBrainz Picard. Bouton
  « Rescan Navidrome » qui appelle `startScan` via Subsonic
  (`POST /tagging/missing-mbid/rescan`) pour propager les nouveaux
  MBIDs sans attendre le scan planifié. Architecture délibérément
  read-only : navidrome-tools ne touche jamais aux fichiers audio.
  Card santé sur le dashboard + entrée « Tagging » dans la nav. Run
  history type `navidrome-rescan`.
- Queue beets : nouvelle env var `BEETS_QUEUE_PATH` (vide par
  défaut). Quand configurée, un bouton « 📋 Pousser dans la queue
  beets » apparaît sur `/tagging/missing-mbid` et appendit les
  chemins filtrés (jusqu'à 5 000) dans un fichier protégé par
  `flock` que tu fais consommer par un cron beets côté hôte
  (`beet import -A`). Bandeau d'info indique la taille courante
  de la queue. Run history type `beets-queue-push`. Le dossier de
  musique reste read-only pour navidrome-tools — seul le fichier
  de queue est en RW. Doc README + cron type fourni.
- `NavidromeRepository::findMediaFilesWithoutMbid()` /
  `countMediaFilesWithoutMbid()` : version-agnostiques, probe
  `mbz_track_id` et `mbz_recording_id` selon les colonnes présentes.
- `SubsonicClient::startScan(bool $fullScan = false)` qui hit
  `/rest/startScan.view` (full-scan optionnel via `?fullScan=true`).

### Changed
- Doc : recommandation explicite d'activer
  `LASTFM_FUZZY_MAX_DISTANCE=2` pour les imports one-shot Last.fm
  (`README.md` section Stratégie, `.env.dist`, `CLAUDE.md` §6).
  Le fuzzy reste désactivé par défaut (coût CPU sur gros catalogues)
  mais rattrape les typos type `Du riiechst so gut` →
  `Du riechst so gut` avec très peu de faux-positifs. Closes #52.

### Added
- Matching Last.fm : nouveau strip **track-number prefix**
  (`stripTrackNumberPrefix`) qui retire les préfixes type `01 - `,
  `02_`, `12-`, `100. ` du titre — vestige de tags MP3 anciens. Exige
  un séparateur (`_`, `-`, `.`, espace) ET un caractère non-blanc
  derrière, donc `1979`, `5/4`, `99 Luftballons` restent intacts.
  Mesuré sur le dataset local : +54 unmatched distincts récupérés.
  Closes #49.
- Matching Last.fm : nouveau strip **paren tronquée** Last.fm
  (`stripTruncatedParen`) qui retire un bloc de parenthèse OUVERTE en
  fin de titre quand son contenu commence par un marker connu (Last.fm
  tronque les titres ~64 chars). Garde-fou : abstient si une autre
  parenthèse fermée est présente. Mesuré : +4 unmatched distincts.
  Closes #50.
- Matching Last.fm : nouveau palier last-resort **strip lead-artist**
  (`stripLeadArtist`) qui retire les co-artistes séparés par `,`,
  ` - `, ` & `, ` and `, ` et ` (ex. `Médine & Rounhaa` → `Médine`,
  `Queen & David Bowie` → `Queen`). Conservatif : lookup strict
  uniquement (pas combiné avec strip-version-markers ni strip-feat) ET
  exige `album_artist = artist stripped` côté Navidrome (seuil de
  confiance haut pour limiter les faux-positifs sur les vrais
  duos/featurings non reconnus comme tels). Mesuré : +11 unmatched
  distincts. Closes #51.
- Page **`/lastfm/unmatched`** (menu Last.fm → Unmatched) : audit
  cumulé de tous les scrobbles non matchés sur l'ensemble des imports,
  agrégés par `(artist, title, album)` avec compteur et dernier
  `played_at`. Filtres GET `artist` / `title` / `album` (substring
  case-insensitive), pagination 50 par page. Actions par ligne :
  « ✏️ Mapper » (alias manuel) et « + Lidarr » (qui redirige sur la
  page après ajout, via le nouveau hidden field `_redirect_unmatched`
  géré par `LidarrController`). Statut Lidarr ✓/✗/— affiché par ligne
  en réutilisant `LidarrClient::indexExistingArtists()`. Implémentée
  par `LastFmImportTrackRepository::findUnmatchedAggregated()` +
  helper statique testable `queryUnmatchedAggregated()`. Lien depuis
  la carte « Re-tenter le matching cumulé » sur `/lastfm/import`.
  Closes #56.
- Bloc **« Derniers runs »** sur le dashboard (`/`) : tableau des 10
  derniers `RunHistory` (tous types confondus), affiché juste après les
  cards de santé. Reprend les colonnes et badges de `/history`
  (type, label, statut emerald/rose/amber, démarré, durée, métriques)
  + lien « Détails » par ligne et lien « Voir tout l'historique → »
  vers `/history`. Donne un coup d'œil immédiat sur l'activité récente
  du tool (imports Last.fm, rematches, recalculs de stats, runs de
  playlists, sync love) sans avoir à quitter l'accueil. Closes #58.
- Commande **`app:lastfm:rematch`** (+ UI sur `/history/{id}` et
  `/lastfm/import`, + cron via `LASTFM_REMATCH_SCHEDULE`) qui ré-applique
  la cascade de matching courante sur les rows `lastfm_import_track`
  en status `unmatched` et insère les scrobbles trouvés dans Navidrome.
  Utile après ajout de morceaux dans la lib ou déploiement d'une
  nouvelle heuristique : permet de récupérer les unmatched stales sans
  retélécharger l'historique Last.fm. Idempotent (garde-fou
  `scrobbleExistsNear`). Un run rematch est tracé dans `/history` avec
  le nouveau type `lastfm-rematch`. Sur le dataset local : 134/200
  unmatched récupérés au premier essai. Closes #21.
- La cascade de matching est désormais factorisée dans
  `App\LastFm\ScrobbleMatcher` (utilisée à la fois par `LastFmImporter`
  et `LastFmRematchService`). Pas de changement comportemental.
- Encart **« Synthèse »** sur la page `/history/{id}` d'un run
  `lastfm-import` : nombre absolu de scrobbles récupérés depuis Last.fm
  + valeur absolue ET pourcentage rapporté à `fetched` pour chaque
  bucket (insérés, doublons, non matchés, ignorés, matchés =
  insérés+doublons), barre empilée 4-couleurs en lecture rapide.
  Calcul délégué à `App\Service\LastFmImportSummary::fromRun()`
  (résiste aux runs sans `fetched` ou avec métriques manquantes).
  Closes #47.
- Variable d'environnement `APP_TIMEZONE` (défaut `UTC`). Appliquée
  au boot du `Kernel` (PHP `date_default_timezone_set`) ET à Twig
  (filtre `|date` via `twig.date.timezone`). Les timestamps restent
  stockés en UTC ; la conversion ne se fait qu'à l'affichage. Une
  valeur invalide retombe silencieusement sur UTC. Exemples :
  `Europe/Paris`, `America/New_York`, `Asia/Tokyo`.
- Photos d'artistes dans la **légende du chart « top 5 artistes
  timeline »** sur `/stats/charts`. La légende native Chart.js est
  désactivée et remplacée par une `<ul>` HTML qui affiche pour chaque
  artiste : pastille couleur (cohérente avec la ligne du chart),
  miniature 28×28 (fallback initiales si `artist_id` manquant ou
  cover non disponible côté Navidrome), nom, total scrobbles. La
  palette 5-couleurs est centralisée dans
  `StatsChartsController::TOP_ARTISTS_PALETTE` et passée au template
  pour synchronisation JS/Twig. `getTopArtistsTimeline()` expose
  désormais `artist_id` (via `MAX(mf.artist_id)`). Closes #32.
- Infra **miniatures album/artiste** : proxy + cache disque local des
  covers servies par l'API Subsonic `getCoverArt`. Nouveau endpoint
  `/cover/{type}/{id}.jpg?size=128` (`type ∈ album|artist|song`),
  cache miss → appel Subsonic + persist dans
  `COVERS_CACHE_PATH/<type>/<id>-<size>.jpg`, cache hit →
  `BinaryFileResponse` avec `Cache-Control: public, max-age=86400`.
  Erreur Subsonic = `404` (le template tombera sur le fallback
  initiales). `size` clampé à `[1, 1024]` (CVE DoS Navidrome).
  Helper Twig `cover_url(type, id, size)` + macro
  `cover_with_fallback` (`templates/_macros/cover.html.twig`) qui
  affiche soit `<img>` soit un `<div>` initiales coloré (couleur
  hash-stable du nom). Volume Docker dédié `navidrome-tools-covers`.
  Nouvelle env var `COVERS_CACHE_PATH` (défaut
  `var/covers`). Closes #27.
- Sync **bidirectionnelle Last.fm loved ↔ Navidrome starred**
  (adds-only, idempotent). Le morceau ❤ sur Last.fm devient ★ dans
  Navidrome (et inversement). Aucun morceau n'est jamais déstarré ni
  délové automatiquement (suppressions hors v1).
  - Handshake OAuth-like sur `/lastfm/connect` → `/lastfm/connect/callback`,
    persiste la session key dans la table `setting`. Page `/settings`
    affiche un badge ✓/✗ + bouton « Déconnecter ».
  - Page `/lastfm/love-sync` : statut session, sélecteur de
    direction (`both` / `lf-to-nd` / `nd-to-lf`), toggle dry-run,
    bouton « Synchroniser maintenant », rapport (compteurs +
    listing des loved non matchés avec lien vers `/lastfm/aliases/new`).
  - CLI `app:lastfm:sync-loved` (`--direction=…`, `--dry-run`),
    wrapped par `RunHistoryRecorder` (nouveau type
    `lastfm-love-sync` visible sur `/history`).
  - `SubsonicClient::getStarred()` / `starTracks()` / `unstarTracks()`
    (méthodes Subsonic).
  - Nouvelles env vars `LASTFM_API_SECRET` (requis pour signer
    `auth.getSession` / `track.love`) et `LASTFM_LOVE_SYNC_SCHEDULE`
    (cron expression, vide = pas de cron). Closes #23.
- Matching Last.fm : table d'**alias manuels** Last.fm → media_file
  Navidrome (`lastfm_alias`). Consultée en priorité absolue avant
  toutes les heuristiques (MBID, triplet, couple, fuzzy). Une cible
  vide signifie « ignorer ce scrobble silencieusement » (compté en
  `skipped` plutôt qu'en `unmatched`, utile pour les podcasts ou le
  bruit). Page CRUD `/lastfm/aliases` (liste paginée + recherche +
  formulaire). Bouton « ✏️ Mapper » à côté de chaque scrobble non
  matché sur `/history/{id}` qui pré-remplit le formulaire.
  Lookup case/accent/ponctuation-insensitive via la même
  normalisation que `findMediaFileByArtistTitle()`. Closes #18.
- Matching Last.fm : fallback **fuzzy Levenshtein** sur (artist,
  title) en dernier recours, après les paliers MBID / triplet /
  couple. Pré-filtre les candidats sur le préfixe 3 chars (artist
  ou title) pour éviter de scanner toute la lib. Opt-in via la
  nouvelle env var `LASTFM_FUZZY_MAX_DISTANCE` (défaut `0` =
  désactivé, `3` = seuil raisonnable). Permet de matcher
  `Hozier / Take Me to Chruch` ↔ `Hozier / Take Me to Church`,
  `Tchaïkovski` ↔ `Tchaikovsky`, etc. Closes #16.
- Matching Last.fm : désambiguation par triplet
  `(artist, title, album)`. Nouvelle méthode
  `NavidromeRepository::findMediaFileByArtistTitleAlbum()` qui
  retourne l'id seulement quand exactement 1 row matche le triplet
  normalisé (sinon `null` → fallback à la suite). `LastFmImporter`
  insère ce lookup entre MBID et couple : MBID → triplet (si album
  non vide) → couple. Permet de matcher correctement les morceaux
  qui existent sur plusieurs albums (single + version album +
  compilation) sans tomber sur le tie-break arbitraire. Closes #15.
- Matching Last.fm : suppression élargie des décorations de titre.
  `stripVersionMarkers()` retire désormais aussi `Live` (avec ou sans
  qualificatif « Live at Reading 1992 »), `Acoustic`, `Acoustic
  Version`, `Instrumental`, `Demo`, `Deluxe`, `Deluxe Edition`,
  `Deluxe Version` quand ils apparaissent entre parenthèses,
  crochets ou après un tiret. Nouveau helper
  `stripFeaturingFromTitle()` qui retire `(feat. X)` / `(ft. X)` /
  `(featuring X)` / `(with X)` (parens ou brackets) du titre, en
  parallèle de `stripFeaturedArtists()` côté artiste. `Remix` reste
  volontairement non-strippé (recordings distincts). Closes #14.
- Matching Last.fm : normalisation de la ponctuation et des caractères
  spéciaux. Tout ce qui n'est ni lettre, ni chiffre, ni espace est
  désormais strippé avant le lookup, puis les espaces multiples sont
  collapsés. `AC/DC` matche `ACDC`, `Guns N' Roses` matche
  `Guns N Roses` (apostrophe droite ou typographique), `t.A.T.u.`
  matche `tATu`, etc. Les helpers `stripFeaturedArtists()` /
  `stripVersionMarkers()` reçoivent désormais l'input brut (les
  délimiteurs parens/dashes/dots dont leurs regex dépendent sont
  préservés) et la valeur strippée est re-normalisée avant lookup.
  Closes #13.
- Matching Last.fm : normalisation Unicode (décomposition NFKD +
  strip des combining marks `\p{Mn}+`). `Beyoncé` matche désormais
  `Beyonce`, `Sigur Rós` matche `Sigur Ros`, `Mötörhead` matche
  `Motorhead`, etc. Une UDF SQLite `np_normalize(value)` est
  enregistrée sur la connexion Navidrome pour appliquer la même
  normalisation aux colonnes (`media_file.artist/title/album_artist`,
  `artist.name`). Requiert l'extension `ext-intl` (déjà présente dans
  les images Docker / runners CI). Closes #12.
- Section « Artistes non matchés » sur la page `/history/{id}` d'un run
  `lastfm-import` : top 100 artistes agrégés (scrobbles sommés),
  persistés dans `metrics.unmatched_artists`. Pour chaque artiste,
  badge `✓ déjà dans Lidarr` + lien vers la fiche, ou bouton
  `+ Lidarr` (qui redirige vers la même page de détail après ajout).
  Encarts dédiés si Lidarr non configuré ou injoignable. Closes #10.
- `CHANGELOG.md` au format Keep a Changelog ; documentation du workflow
  de release dans `CLAUDE.md` (§5) et lien depuis le `README.md`.
- `AGENTS.md` (convention transverse pour les assistants IA) avec la
  règle « idée prospective du user → ticket GitHub catégorisé +
  entrée dans `ROADMAP.md` ». Pointeur ajouté dans `CLAUDE.md` §9.
- Mise à jour complète de `CLAUDE.md` pour refléter les pages neuves
  (historiques Last.fm/Navidrome, audit per-track, scrobble count
  dashboard, period-aware preview), les nouvelles entités/services/
  controllers, le pipeline `.gitlab-ci.yml`, le matching à 4 paliers,
  le compteur de tests (76, 203 assertions), et 4 nouveaux pièges
  connus (submission_time INTEGER, EnvUser EquatableInterface, Twig 3
  for...if, lando nginx logs). §8 pointe désormais vers `ROADMAP.md`
  + `CHANGELOG.md` au lieu de dupliquer la liste.
- Favicon SVG (note de musique, slate-900) en `public/favicon.svg`,
  référencée depuis `base.html.twig`.
- Variable d'environnement `LASTFM_USER` : pré-remplit le champ
  « Identifiant Last.fm » du formulaire d'import et sert de fallback
  quand l'argument `lastfm-user` est omis sur la CLI
  (`app:lastfm:import`).
- Variable d'environnement `LASTFM_PAGE_DELAY_SECONDS` (défaut 10) :
  pause configurable entre deux pages successives de l'API Last.fm
  pour éviter le rate-limiting. Passer à 0 pour désactiver.
- Période d'import (`date_min`, `date_max`) ajoutée aux métriques
  persistées des runs Last.fm — visible directement dans la colonne
  Métriques de l'historique et dans le dump JSON de la page détail.
- Compteur de scrobbles affiché dans la card « Table scrobbles » du
  dashboard (`SELECT COUNT(*) FROM scrobbles`, formaté avec séparateur
  de milliers).
- Pipeline GitLab CI (`.gitlab-ci.yml`) miroir des GitHub Actions :
  5 jobs (phpcs, phpstan, tests matrix 8.3+8.4, docker-build,
  docker-publish multi-arch). Pousse vers le registre du projet par
  défaut, override via la variable `REGISTRY_IMAGE`.
- Page **Historique Last.fm** (`/stats/lastfm-history`) dans le menu
  Statistiques : affiche les 100 derniers scrobbles cachés en local
  pour le user Last.fm courant (`LASTFM_USER` ou `?user=` dans
  l'URL). Bouton Rafraîchir qui interroge `user.getRecentTracks` et
  fait un wipe + re-insert atomique en transaction. Cache stocké
  dans la nouvelle table `lastfm_history` (migration
  `Version20260501300000`). Run audité dans `RunHistory` avec la
  référence `history:<user>`.
- Page **Historique Navidrome** (`/stats/navidrome-history`), pendant
  symétrique de la précédente : snapshot des 100 derniers scrobbles
  de la table `scrobbles` Navidrome, joins media_file pour artiste/
  titre/album, lien direct vers la fiche Navidrome de chaque
  morceau. Cache dans `navidrome_history` (migration
  `Version20260501400000`), refresh atomique. Nouveau helper
  `NavidromeRepository::getRecentScrobbles(int $limit)`.
- Audit **par-track** des imports Last.fm : chaque scrobble traité
  par un import (CLI ou UI) est désormais persisté dans la nouvelle
  table `lastfm_import_track` (migration `Version20260501500000`)
  avec son statut (`inserted` / `duplicate` / `unmatched`), l'id du
  media_file matché si applicable, le mbid Last.fm, et un FK CASCADE
  vers `run_history`. La page de détail d'un run (`/history/{id}`)
  affiche pour les `lastfm-import` un tableau filtrable par statut
  + recherche full-text sur artiste/titre. Filtre par défaut sur
  les non matchés s'il y en a, sinon tous (page jamais surprenante­
  ment vide). Limité à 500 lignes par vue avec un message si
  tronqué.

### Changed
- `RunHistoryRecorder::record()` passe maintenant la `RunHistory`
  fraîchement persistée à l'action callback en premier argument —
  permet aux callers d'attacher des entités enfants au run via FK
  pendant l'exécution. Les arrow-fns existantes ignorent
  l'argument supplémentaire (PHP discard les args en trop).
- `LastFmImporter::import()` accepte un nouveau callback optionnel
  `onScrobble(scrobble, status, mediaFileId|null)` appelé une fois
  par scrobble traité, utilisé par les callers qui veulent un audit
  détaillé.
- `NavidromeRepository::findMediaFileByArtistTitle()` ne renvoie
  plus `null` quand la même chanson existe sur plusieurs albums.
  Pick déterministe : préfère la row où `album_artist = artist`
  (album studio canonique vs compilation tierce), tie-break par
  `id` ASC. Conséquence : un import Last.fm matche désormais les
  morceaux présents dans plusieurs versions au lieu de les laisser
  unmatched.
- Fallback featuring sur le matching : quand l'artiste Last.fm
  est `Orelsan feat. Thomas Bangalter` (ou variante `ft.` /
  `featuring` / `(feat. …)`) et que le strict-match échoue, retry
  une fois avec uniquement l'artiste lead (`Orelsan`). Couvre les
  cas où Navidrome ne crédite que l'artiste principal sur la
  piste alors que Last.fm cite tous les featuring.
- Fallback marqueurs de version sur le titre : `Soleil Bleu -
  Radio Edit` et `Soleil Bleu (Radio Edit)` retombent sur
  `Soleil Bleu` quand le strict-match échoue. Couvre Radio /
  Album / Single / Extended Edit/Mix/Version, Mono/Stereo
  Version, Remaster(ed) avec ou sans année. Live / Remix /
  Acoustic / Demo / Instrumental sont volontairement **non**
  strippés (différents enregistrements). Les deux fallbacks
  (featuring artiste + version titre) se cumulent : `Orelsan
  feat. Stromae` / `La pluie - Radio Edit` matche bien
  `Orelsan` / `La pluie`.

### Changed
- Page historique des runs : la colonne Métriques masque maintenant
  les valeurs nulles ou vides plutôt que d'afficher `clé=`.
- Preview d'une playlist : la colonne « Plays » reflète désormais le
  total d'écoutes **sur la période du générateur** (top 30 derniers
  jours → plays sur 30 jours, etc.) au lieu du compteur lifetime.
  Pour les générateurs sans période (`top-all-time`,
  `never-played`, `songs-you-used-to-love`), le comportement reste
  inchangé (lifetime, sous-titre `lifetime` ajouté pour clarté).

### Internal
- Nouvelle méthode `PlaylistGeneratorInterface::getActiveWindow()`
  qui retourne `['from', 'to']` ou `null`. Implémentée dans les 8
  générateurs livrés. `NavidromeRepository::summarize()` accepte
  maintenant `?\DateTimeInterface $from, $to` ; quand fournis et que
  la table `scrobbles` existe, le compte de plays vient de
  `scrobbles` au lieu de `annotation.play_count`.

### Fixed
- Page Wrapped : disparition du warning PHP « non-numeric value
  encountered » qui pétait le rendu du bouton « + Créer une playlist
  Top YYYY » à `wrapped/show.html.twig:57`. Causé par
  `number_format(0)` qui injectait un séparateur de milliers dans la
  string d'année avant la soustraction.
- Page `/stats` (période *All-time*) : le total d'écoutes ne
  bougeait plus, même après un refresh, parce que les requêtes
  all-time interrogeaient `annotation.play_count` (qui n'est jamais
  mis à jour par l'import Last.fm — Navidrome ne propage pas) au lieu
  de `scrobbles`. Les 4 méthodes du repo (`getTotalPlays`,
  `getDistinctTracksPlayed`, `getTopArtists`,
  `getTopTracksWithDetails`) utilisent maintenant `scrobbles` aussi
  pour all-time quand la table existe ; fallback `annotation` inchangé
  quand `scrobbles` est absente.

<!--
Sections disponibles pour les futures entrées :
### Added       — nouvelles fonctionnalités
### Changed     — modifications d'une fonctionnalité existante
### Deprecated  — fonctionnalités bientôt retirées
### Removed     — fonctionnalités retirées
### Fixed       — corrections de bugs
### Security    — failles corrigées
-->

<!--
Template pour une release (à coller au-dessus des releases existantes,
en-dessous du bloc [Unreleased] qui doit toujours rester en tête) :

## [X.Y.Z] - YYYY-MM-DD

### Added
- ...

### Fixed
- ...
-->
