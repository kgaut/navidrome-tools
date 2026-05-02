# Changelog

Toutes les évolutions notables de ce projet sont consignées dans ce fichier.

Le format suit [Keep a Changelog 1.1.0](https://keepachangelog.com/fr/1.1.0/)
et le projet adhère à [Semantic Versioning 2.0](https://semver.org/lang/fr/).

## [Unreleased]

### Added
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
