# Changelog

Toutes les évolutions notables de ce projet sont consignées dans ce fichier.

Le format suit [Keep a Changelog 1.1.0](https://keepachangelog.com/fr/1.1.0/)
et le projet adhère à [Semantic Versioning 2.0](https://semver.org/lang/fr/).

## [Unreleased]

### Added
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

### Fixed
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
