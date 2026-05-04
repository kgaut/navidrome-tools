# Claude Code — contexte du projet

Ce fichier est lu automatiquement par Claude Code quand on ouvre une
nouvelle session sur ce repo. Il donne le contexte stratégique : design,
conventions, pièges connus, roadmap. Pour la doc utilisateur, voir
`README.md`. Pour les guidelines plugin, voir `docs/PLUGINS.md`.

---

## 1. Produit

**Navidrome Tools** = boîte à outils web self-hosted (Symfony 7) autour
de [Navidrome](https://www.navidrome.org/). Repo GitHub :
`kgaut/navidrome-playlist-generator` (le repo n'a pas été renommé,
seuls le projet et l'image Docker s'appellent désormais
`navidrome-tools`).

Fonctionnalités livrées :

- **Génération de playlists** par règle, basé sur les écoutes Navidrome.
  Système de plugins via `App\Generator\PlaylistGeneratorInterface` —
  ajouter un type = créer un fichier dans `src/Generator/`,
  auto-détecté par `_instanceof` (cf. `config/services.yaml`).
- **8 générateurs livrés** : `top-last-days`, `top-last-month`,
  `top-last-year`, `top-all-time`, `never-played`, `top-month-yago`,
  `top-years-ago`, `songs-you-used-to-love` (high play_count + dernier
  play > N mois).
- **Page `/stats`** : total plays, distinct tracks, top 10 artistes,
  top 50 morceaux, par période (7d/30d/last-month/last-year/all-time),
  cachée dans `stats_snapshot`, refresh manuel + cron.
- **Page `/stats/compare`** : diff côte à côte de deux périodes avec
  badges nouveau/disparu/↑/↓/=. `StatsCompareService` fusionne les
  tops par id (tracks) / nom (artists).
- **Page `/stats/charts`** : trois Chart.js (plays par mois, top 5
  artistes timeline, distribution jour-semaine). Lib chargée via CDN
  jsdelivr dans le block `stylesheets`.
- **Page `/stats/heatmap`** : deux heatmaps HTML/CSS (jour×heure 90j,
  année×jour façon GitHub contribs avec sélecteur d'année).
- **Page `/wrapped/{year}`** : rétrospective annuelle, cachée dans
  `stats_snapshot` (key `wrapped-<year>`). `WrappedService::compute()`
  agrège tout : top 25 artistes, top 50 morceaux, new artists, streak
  jours consécutifs, mois le plus actif, durée totale estimée
  (extrapolation depuis la durée des top tracks).
- **Page `/lastfm/import`** : import du scrobble history Last.fm en
  **deux étapes découplées** depuis 2026-05.
  1. **Fetch** (section 1 du form, `app:lastfm:import`,
     `App\LastFm\LastFmFetcher`) : streame
     `LastFmClient::streamRecentTracks` → `INSERT OR IGNORE INTO
     lastfm_import_buffer` via DBAL pur. Aucune écriture Navidrome,
     peut tourner Navidrome up. Idempotent grâce à la unique
     constraint `(lastfm_user, played_at, artist, title)` ;
     report = `fetched / buffered / already_buffered`. RunHistory
     type `lastfm-fetch`. Cron via `LASTFM_FETCH_SCHEDULE`.
  2. **Process** (section 2 du form + `POST /lastfm/process`,
     `app:lastfm:process`, `App\LastFm\LastFmBufferProcessor`) :
     stream du buffer (par `played_at ASC`) → `ScrobbleMatcher` →
     `scrobbleExistsNear` ±N secondes → `insertScrobble` Navidrome
     → audit `LastFmImportTrack` (FK `RunHistory`) → DELETE row du
     buffer (toujours, y compris `unmatched` — l'audit prend le
     relais pour le rematch). Navidrome doit être arrêté
     (pré-flight CSRF côté UI ; `--force` / `--auto-stop` côté CLI).
     RunHistory type `lastfm-process` ; report `considered /
     inserted / duplicates / unmatched / skipped`. Cron via
     `LASTFM_PROCESS_SCHEDULE` (auto-stop activé automatiquement
     par `app:cron:dump` quand `NAVIDROME_CONTAINER_NAME`).

  Pause configurable (`LASTFM_PAGE_DELAY_SECONDS`, défaut 10s)
  entre 2 pages de l'API Last.fm. User et API key par défaut via
  `LASTFM_USER` / `LASTFM_API_KEY`. Le service legacy
  `LastFmImporter` a été supprimé (constante `TYPE_LASTFM_IMPORT`
  conservée pour relire l'historique des runs antérieurs au split).
- **Page `/history`** : audit de tous les runs cron via
  `RunHistoryRecorder` (status / durée / metrics / message). La page
  détail d'un run `lastfm-process` ou `lastfm-import` (legacy)
  affiche le **listing par-track** (table `lastfm_import_track`,
  FK CASCADE) avec filtre par statut (inserted / duplicate /
  unmatched, défaut « non matchés » s'il y en a) et recherche
  full-text artiste/titre. Les runs `lastfm-fetch` n'ont pas de
  listing par-track (le fetch ne crée pas de `LastFmImportTrack`,
  seulement des rows dans `lastfm_import_buffer`).
- **Alias d'artiste** (`/lastfm/artist-aliases`) : table
  `lastfm_artist_alias` qui mappe un nom source Last.fm → nom
  canonique côté Navidrome (ex. « La Ruda Salska » → « La Ruda »).
  Consulté par `App\LastFm\ScrobbleMatcher` **après** l'alias
  track-level (`lastfm_alias`) mais **avant** la cascade — réécrit
  l'artiste du `LastFmScrobble` puis laisse les heuristiques
  habituelles tourner. Un seul alias couvre tous les morceaux
  d'un artiste renommé. Bouton « 🎭 Aliaser artiste » sur
  `/lastfm/unmatched`. CRUD complet (new/edit/delete + recherche
  paginée). Comparaison via `NavidromeRepository::normalize()`
  (case / accents / ponctuation insensitive). Sub-issue de #11.
- **Page `/lastfm/unmatched`** : audit cumulé de **tous** les
  scrobbles `unmatched` (toutes runs confondues), agrégés par
  `(artist, title, album)` avec compteur de scrobbles + dernier
  `played_at`. Filtres GET `artist` / `title` / `album` (substring
  case-insensitive), pagination 50 par page. Boutons par ligne :
  « ✏️ Mapper » (alias) et « + Lidarr » (avec redirect retour vers la
  page). Statut Lidarr (✓/✗/—) en réutilisant
  `LidarrClient::indexExistingArtists()`. Implémentée par
  `LastFmImportTrackRepository::queryUnmatchedAggregated()` (helper
  statique testable + méthode publique d'instance).
- **Re-match des unmatched** (`app:lastfm:rematch`,
  `POST /lastfm/rematch`) : ré-applique la cascade de matching
  (`App\LastFm\ScrobbleMatcher`, partagée avec
  `LastFmBufferProcessor`) sur les rows `lastfm_import_track` en
  status `unmatched`. Utile quand on ajoute des morceaux à Navidrome
  ou qu'on déploie une nouvelle heuristique : pas besoin de
  re-télécharger l'historique Last.fm. Insère dans `scrobbles`
  Navidrome (avec `scrobbleExistsNear` pour dédup), bascule la row
  en `inserted` / `duplicate` / `skipped`. Wrappé par
  `RunHistoryRecorder` (type `lastfm-rematch`). Bouton sur
  `/history/{id}` (par run) et `/lastfm/import` (cumul global). Cron
  via `LASTFM_REMATCH_SCHEDULE`. Idempotent. Navidrome doit être
  arrêté (mêmes contraintes que `app:lastfm:process`).
- **Page `/stats/lastfm-history`** : 100 derniers scrobbles cachés en
  local pour le user Last.fm, refresh manuel (table `lastfm_history`).
- **Page `/stats/navidrome-history`** : pendant symétrique, snapshot
  des 100 derniers scrobbles de la table `scrobbles` Navidrome avec
  lien direct vers la fiche morceau côté Navidrome (table
  `navidrome_history`).
- **Dashboard** : compteur de scrobbles (`COUNT(*) FROM scrobbles`)
  affiché dans la card santé « Table scrobbles ». Cards
  supplémentaires : **Buffer Last.fm** (count
  `lastfm_import_buffer`, lien vers `/lastfm/import`) et **Scrobbles
  non matchés** (count `lastfm_import_track` status=`unmatched`,
  lien vers `/lastfm/unmatched`). Les deux passent en gris quand
  elles valent 0.
- **Preview de playlist** : la colonne Plays reflète le total
  d'écoutes **sur la fenêtre du générateur** (pas le lifetime), via
  `PlaylistGeneratorInterface::getActiveWindow()` consommé par
  `NavidromeRepository::summarize($ids, $from, $to)`.
- **Intégration Lidarr** : bouton « + Lidarr » par ligne d'unmatched
  pour ajouter l'artiste, plus liens Last.fm / Navidrome (lookup par
  nom normalisé).
- **Cron interne** via supercronic : `app:cron:dump` lit la DB et
  régénère le crontab toutes les 5 min.
- **Création de playlists côté Navidrome** : **toujours via l'API
  Subsonic**, jamais d'écriture directe dans la DB Navidrome (sauf
  `app:lastfm:process` et `app:lastfm:rematch` qui doivent
  absolument tourner Navidrome arrêté ; `app:lastfm:import` n'écrit
  plus dans Navidrome depuis le split fetch/process).
- **Pilotage du conteneur Navidrome** (optionnel, activé si
  `NAVIDROME_CONTAINER_NAME` est renseigné) : card dashboard
  affichant l'état UP/DOWN avec boutons Start/Stop, pré-flight
  automatique sur `app:lastfm:process` / `app:lastfm:rematch` (CLI +
  HTTP) qui bloque si Navidrome tourne (CLI : `--force` pour
  outrepasser ; UI : flash error). Implémenté via `docker` CLI
  (`apk add docker-cli` + mount `/var/run/docker.sock`). Statut
  `unknown` (socket KO) = écritures bloquées par sécurité. Code dans
  `src/Docker/` (`NavidromeContainerConfig`, `DockerCli`,
  `NavidromeContainerManager`, enum `ContainerStatus`,
  `NavidromeContainerException`), POST routes
  `/navidrome/container/{start,stop}` avec CSRF
  `navidrome_container`. Flag CLI `--auto-stop` (sur
  `app:lastfm:process` et `app:lastfm:rematch`) : alternative au
  pré-flight bloquant — orchestre stop Navidrome → action → restart
  via `NavidromeContainerManager::runWithNavidromeStopped()` (try/
  finally, restart même en cas d'erreur de l'action). Activé
  automatiquement par `app:cron:dump` sur les lignes process /
  rematch quand le conteneur est configuré. La commande
  `app:lastfm:import` (fetch only) ne touche plus le conteneur.

---

## 2. Stack

- **PHP 8.3+** (matrice CI : 8.3, 8.4). `composer.json` pinne
  `config.platform.php=8.3.0` — toujours régénérer le lock après un
  `composer require` pour éviter qu'une dep prenne une version
  PHP-8.4-only.
- **Symfony 7** (installé en 7.4.x via Flex), Doctrine ORM 3, DBAL 4.
- **Doctrine** : `enable_lazy_ghost_objects: true` (PAS
  `enable_native_lazy_objects`, qui requiert PHP 8.4).
- **Tailwind via CDN** dans `templates/base.html.twig` — pas de
  toolchain front, pas de `npm`.
- **DB locale** du tool : SQLite via Doctrine ORM
  (`var/data.db`, `DATABASE_URL`).
- **DB Navidrome** : SQLite, montée en `:ro` côté Docker, requêtée via
  `App\Navidrome\NavidromeRepository` (Doctrine DBAL pur, pas d'ORM).
  Détection auto de la table `scrobbles` (Navidrome ≥ 0.55).
- **Image Docker** : `dunglas/frankenphp:1-php8.3-alpine` +
  `supercronic` (multi-arch). Multiplexeur `APP_MODE=web|cron|cli` dans
  `docker/entrypoint.sh`.
- **Auth UI** : un seul user via `APP_AUTH_USER` / `APP_AUTH_PASSWORD`,
  hashé en mémoire au boot par `App\Security\EnvUserProvider`.

---

## 3. Architecture

```
src/
├── Command/          CLI Symfony (app:playlist:run, app:stats:compute,
│                     app:lastfm:import (fetch-only), app:lastfm:process,
│                     app:lastfm:rematch, app:cron:dump, app:history:purge…)
├── Controller/       Dashboard, PlaylistDefinition CRUD, Stats (index/compare/
│                     charts/heatmap/wrapped/lastfm-history/navidrome-history),
│                     LastFmImport, Lidarr, History, Settings, Security
├── Entity/           PlaylistDefinition, Setting, StatsSnapshot, RunHistory,
│                     LastFmHistoryEntry, NavidromeHistoryEntry, LastFmImportTrack,
│                     LastFmBufferedScrobble
├── Form/             PlaylistDefinitionType (form dynamique selon le générateur),
│                     LastFmImportType
├── Generator/        Interface + 8 générateurs + GeneratorRegistry + ParameterDefinition
├── LastFm/           LastFmClient, LastFmFetcher, LastFmBufferProcessor,
│                     ScrobbleMatcher, LastFmScrobble, FetchReport, ProcessReport
├── Lidarr/           LidarrClient, LidarrConfig, LidarrConflictException
├── Navidrome/        NavidromeRepository (toutes les requêtes DBAL Navidrome),
│                     TrackSummary
├── Repository/       Repos Doctrine ORM (PlaylistDefinitionRepository,
│                     RunHistoryRepository, StatsSnapshotRepository,
│                     SettingRepository, LastFmHistoryEntryRepository,
│                     NavidromeHistoryEntryRepository, LastFmImportTrackRepository)
├── Security/         EnvUser, EnvUserProvider
├── Service/          PlaylistRunner, PlaylistNameRenderer, SettingsService,
│                     StatsService, AddArtistToLidarrService, RunHistoryRecorder,
│                     LastFmHistoryService, NavidromeHistoryService, WrappedService,
│                     StatsCompareService
└── Subsonic/         SubsonicClient (createPlaylist/deletePlaylist/getPlaylists/findByName)
```

Décisions structurantes :

- **Tous les jobs longs sont enveloppés** par `RunHistoryRecorder::record()`
  qui persiste un `RunHistory` row (started_at flush avant action,
  status/duration/metrics flush après, rethrow conservé). Le callback
  d'action **reçoit la `RunHistory` fraîchement persistée en premier
  argument** — permet d'attacher des entités enfants (ex.
  `LastFmImportTrack`) via FK pendant l'exécution. Les arrow-fns sans
  paramètre déclaré ignorent l'arg supplémentaire.
- **Les paramètres des générateurs** sont stockés en JSON dans
  `playlist_definition.parameters`. Pas besoin de migration pour
  ajouter un nouveau générateur.
- **Le formulaire** `PlaylistDefinitionType` reconstruit dynamiquement
  les champs de paramètres à partir de `getParameterSchema()` du
  générateur sélectionné, via `FormEvents::PRE_SET_DATA` et
  `PRE_SUBMIT`.
- **Chaque générateur déclare sa fenêtre temporelle** via
  `PlaylistGeneratorInterface::getActiveWindow($parameters)` qui
  retourne `['from', 'to']` ou `null`. Consommé par la preview
  (`summarize($ids, $from, $to)` compte alors les plays depuis
  `scrobbles` au lieu d'`annotation.play_count` lifetime).
- **Lookup MBID Navidrome** : `findMediaFileByMbid` probe `mbz_track_id`
  et `mbz_recording_id` selon la version de Navidrome (cf.
  `mediaFileColumns()`).
- **Matching artist/title à 4 paliers** dans
  `findMediaFileByArtistTitle` :
  1. strict (artist, title) — si plusieurs rows, préfère
     `album_artist = artist` puis tie-break `id ASC` (au lieu de
     l'ancien `null` quand >1 match) ;
  2. fallback featuring : strip `feat.` / `ft.` / `featuring`
     (suffixe ou parens) sur l'artiste ;
  3. fallback marqueur version : strip `- Radio Edit` /
     `(Remastered 2011)` etc. sur le titre — Live/Remix/Acoustic/
     Demo/Instrumental sont volontairement non strippés (différents
     enregistrements) ;
  4. les deux strips combinés.
  5. **Featuring asymétrique** : si le titre original contenait un
     marker `(feat./ft./featuring/with X)` (`titleHasFeaturingMarker`)
     et que les paliers précédents ont échoué, retente avec
     `lookupArtistPrefixFeaturingTitle()` — title strict sur le bare,
     artiste LIKE `:a feat %` / `:a ft %` / etc. Catche le cas où
     Last.fm met le featuring dans le titre et Navidrome dans
     l'artiste.
- **`scrobbles.submission_time` en INTEGER unix epoch** depuis
  Navidrome 0.55. Toutes les requêtes Navidrome bindent
  `getTimestamp()` (PARAM INTEGER) et passent le modifier
  `'unixepoch'` aux fonctions `strftime`/`date`/`datetime`. Cf. §7.
- **Auth UI** : `EnvUser` (custom, **pas** `InMemoryUser` qui est
  `final`) implémente `EquatableInterface` — sinon le firewall
  invalide la session à chaque request (le hash bcrypt est
  régénéré à chaque boot avec un salt aléatoire, le check d'égalité
  voit deux hashs différents et refuse la session). Cf. §7.

---

## 4. Conventions

### Code style

- **PSR-12**, vérifié par `vendor/bin/phpcs` (`phpcs.xml.dist`,
  ligne max 160 — relâchée pour les SQL inline et attributs Doctrine).
- **PHPStan niveau 6** + extensions Symfony/Doctrine/PHPUnit installées
  via `phpstan/extension-installer` (`phpstan.dist.neon`).
- `composer ci` enchaîne `phpcs` + `phpstan` + `phpunit`.
- **Pas de @phpstan-ignore en règle générale** ; corriger la cause.

### Tests

- PHPUnit 11, fichier de bootstrap minimal
  (`tests/bootstrap.php`).
- Pour Navidrome, **toujours** utiliser `tests/Navidrome/NavidromeFixtureFactory`
  qui crée un schéma Navidrome compatible (incluant `media_file.artist_id`,
  `media_file.album_artist`, et `scrobbles.submission_time` en INTEGER
  unix epoch comme la vraie 0.55+). `insertTrack` accepte
  `$album` et `$albumArtist` optionnels (défaut `albumArtist = artist`
  pour mimer un studio). `insertScrobble` accepte un string
  `'Y-m-d H:i:s'` et le convertit en epoch via `strtotime`.
- Stubs HTTP : `Symfony\Component\HttpClient\MockHttpClient`. Voir
  `tests/Lidarr/LidarrClientTest.php` pour un exemple complet.
- Stub Last.fm : `tests/LastFm/FakeLastFmClient` étend `LastFmClient`
  et override `streamRecentTracks` pour yield une liste pré-bakée. Ne
  passe pas par `parent::__construct` (skip le HTTP client + le
  `pageDelaySeconds`).
- Pour mocker l'EntityManager dans les tests de service (ex.
  `LastFmHistoryServiceTest`), reproduire le pattern de
  `RunHistoryRecorderTest::makeFakeEntityManager()`.
- Pour les data-providers PHPUnit 11+, utiliser l'attribut
  `#[DataProvider('methodName')]` (le tag `@dataProvider` doc-comment
  est deprecated).
- 76 tests / 203 assertions au moment de l'écriture, tous verts.

### Commits

- **Conventional Commits** : `feat(scope):`, `fix(scope):`,
  `refactor(scope):`, `chore:`, `test(scope):`, `ci:`, `docs:`.
- Corps du message **explique le pourquoi**, pas le quoi (le diff suffit
  pour le quoi).
- **Footer Claude Code** : ligne `https://claude.ai/code/session_…` sur
  les commits faits par Claude.

### Branches

- `main` est la branche de prod (déclenche la publication GHCR).
- Les développements Claude se font sur `claude/navidrome-playlist-tool-EauI1`
  puis fast-forward sur main.
- L'historique est linéaire (FF only). Pas de squash, pas de rebase
  agressif.

---

## 5. Workflows dev courants

### Lando (recommandé)

```bash
cp .lando.yml.dist .lando.yml         # .lando.yml est gitignored
cp /chemin/vers/navidrome.db var/navidrome.db
lando start
lando migrate
lando seed
# UI : https://navidrome-tools.lndo.site
```

Tooling exposé : `lando symfony`, `lando composer`, `lando test`,
`lando migrate`, `lando seed`, `lando playlist-run`, `lando cron-dump`.

### Sans Lando

```bash
COMPOSER_ALLOW_SUPERUSER=1 composer install
cp .env.dist .env.local && edit
mkdir -p var && cp /chemin/vers/navidrome.db var/navidrome.db
php bin/console doctrine:migrations:migrate -n
php bin/console app:fixtures:seed
symfony serve
```

### CI

`.github/workflows/ci.yml` :

- Sur PR / push n'importe quelle branche : `phpcs`, `phpstan`, `tests`
  (matrice 8.3 + 8.4), `docker-build` (validation seule).
- Sur push `main` ou tag `v*` : en plus, `docker-publish` push vers
  `ghcr.io/kgaut/navidrome-tools` (multi-arch amd64/arm64), tags via
  `docker/metadata-action@v5` :
  - `latest` + `main-<sha7>` sur push main
  - `1.2.3` + `1.2` + `1` + `latest` sur tag `v1.2.3`

Permissions du workflow : `contents: read`, `packages: write`.

`.gitlab-ci.yml` (miroir pour héberger une copie sur une instance
GitLab self-hosted) : reproduit les 5 jobs (phpcs, phpstan, tests
matrix, docker-build, docker-publish multi-arch). Toolchain PHP via
`php:8.3-cli-alpine` + apk add icu-dev sqlite-dev + composer ;
docker buildx + tonistiigi/binfmt pour le multi-arch ; logique de
tags réimplémentée en shell pur. Pousse vers `$CI_REGISTRY_IMAGE`
par défaut, override via la variable `REGISTRY_IMAGE`.

### Release

1. S'assurer que `CHANGELOG.md` a une section `[Unreleased]` non vide qui
   couvre tout ce qui sera dans le tag.
2. Renommer `## [Unreleased]` en `## [X.Y.Z] - YYYY-MM-DD` et insérer un
   nouveau bloc `## [Unreleased]` vide juste au-dessus.
3. Commit `chore(release): vX.Y.Z` puis :

   ```bash
   git checkout main && git pull
   git tag v0.X.0 && git push origin v0.X.0
   ```

Le push du tag déclenche `docker-publish` (cf. `.github/workflows/ci.yml`).

---

## 6. Configuration (env vars)

| Variable                       | Usage                                                       |
|--------------------------------|-------------------------------------------------------------|
| `APP_SECRET`                   | Symfony — `openssl rand -hex 32`                            |
| `APP_ENV`                      | `prod` / `dev` / `test`                                     |
| `APP_MODE`                     | `web` / `cron` / `cli` (entrypoint Docker)                  |
| `APP_AUTH_USER` / `..._PASSWORD` | Login UI                                                  |
| `NAVIDROME_DB_PATH`            | Chemin du fichier SQLite Navidrome (mount `:ro` en prod)    |
| `NAVIDROME_URL`                | Base URL HTTP Navidrome                                     |
| `NAVIDROME_USER` / `..._PASSWORD` | User Subsonic                                            |
| `DATABASE_URL`                 | DSN Doctrine pour la DB locale du tool                      |
| `STATS_REFRESH_SCHEDULE`       | Cron expr (default `0 */6 * * *`)                           |
| `RUN_HISTORY_RETENTION_DAYS`   | default 90                                                  |
| `LIDARR_URL` / `..._API_KEY`   | Vide = intégration désactivée (UI masque le bouton)         |
| `LIDARR_ROOT_FOLDER_PATH`      | Chemin où Lidarr place les artistes                         |
| `LIDARR_QUALITY_PROFILE_ID` / `..._METADATA_PROFILE_ID` | IDs de profils existants            |
| `LIDARR_MONITOR`               | `all` / `future` / `missing` / `existing` / `first` / `latest` / `none` |
| `LASTFM_API_KEY`               | Optionnel, fallback du formulaire et de la CLI              |
| `LASTFM_USER`                  | Optionnel, pré-remplit le champ user / fallback CLI         |
| `LASTFM_PAGE_DELAY_SECONDS`    | Pause entre 2 pages de l'API (default 10, 0 pour désactiver)|
| `LASTFM_FUZZY_MAX_DISTANCE`    | Distance Levenshtein max pour le fallback fuzzy (default 0 = off, **2 recommandé pour les imports one-shot**) |
| `LASTFM_REMATCH_SCHEDULE`      | Cron expr pour `app:lastfm:rematch` (vide = désactivé)      |
| `LASTFM_FETCH_SCHEDULE`        | Cron expr pour `app:lastfm:import` — fetch buffer (vide = désactivé) |
| `LASTFM_PROCESS_SCHEDULE`      | Cron expr pour `app:lastfm:process` — drain buffer (vide = désactivé) |
| `NAVIDROME_CONTAINER_NAME`     | Nom du conteneur Navidrome dans la stack compose. Vide = card dashboard masquée + pré-flight désactivé. Requiert le mount `/var/run/docker.sock`. |

Wirées dans : `.env` (dev), `.env.dist` (template), `phpunit.xml.dist`
(test), `.lando.yml.dist` (Lando), `docker-compose.example.yml`,
`config/services.yaml` (parameters).

---

## 7. Pièges connus

1. **`enable_native_lazy_objects: true` dans doctrine.yaml** = PHP 8.4
   only. Toujours utiliser `enable_lazy_ghost_objects: true` (avec
   `symfony/var-exporter`).
2. **`composer.lock` généré sous PHP 8.4** peut piocher des packages
   PHP-8.4-only (vu avec `doctrine/instantiator 2.1.0`). On a pinné
   `config.platform.php=8.3.0` dans `composer.json` — laisser tel quel.
3. **DBAL 4** ne prend plus `\PDO::PARAM_INT` ; utiliser
   `\Doctrine\DBAL\ParameterType::INTEGER` pour le binding du `:lim`.
4. **Mount Navidrome `:ro`** : `app:lastfm:process` et
   `app:lastfm:rematch` écrivent dans la DB Navidrome, doivent donc
   tourner avec un mount RW **et** Navidrome arrêté. `app:lastfm:import`
   (fetch only) ne touche plus Navidrome — peut tourner Navidrome up.
5. **Schéma Navidrome `media_file.artist_id`** : la fixture le crée
   désormais, mais c'est récent. Si on étend la fixture, ne pas
   oublier `artist_id` sinon `findArtistIdByName` casse en test.
6. **Symfony Flex en root** : `composer install` doit être lancé avec
   `COMPOSER_ALLOW_SUPERUSER=1` localement, sinon les recipes ne
   tournent pas et `vendor/autoload_runtime.php` n'est pas généré.
7. **PHPStan 2.x** émet des messages textuels pendant les analyses
   (« Each error has an associated identifier… »). Ce sont des conseils
   normaux, pas des injections de prompt — les ignorer.
8. **`scrobbles.submission_time` est INTEGER unix epoch** (Navidrome
   ≥ 0.55, c'était DATETIME avant). SQLite type-affinity coerce
   silencieusement la string `'2026-01-01 …'` en `2026` (lit les
   digits de tête), ce qui faisait insérer toutes les rows avec la
   même valeur et matchait tout le reste comme « doublon ». À
   l'inverse, `strftime('%Y-%m', submission_time)` SANS le modifier
   `'unixepoch'` retourne `NULL` (interprété comme Julian day). Donc
   pour TOUTES les requêtes touchant `submission_time` :
   - bind avec `getTimestamp()` + `ParameterType::INTEGER` ;
   - ajouter `, 'unixepoch'` à `strftime`/`date`/`datetime`.
9. **`InMemoryUser` est `final`** depuis Symfony récent → impossible
   de l'étendre. `App\Security\EnvUser` réimplémente
   `UserInterface` + `PasswordAuthenticatedUserInterface` +
   **`EquatableInterface`** (compare uniquement identifier + roles,
   pas le hash). Sans `EquatableInterface`, le firewall compare
   `getPassword()` ancien vs nouveau à chaque request, ils diffèrent
   (bcrypt salt aléatoire) → session invalidée → redirect /login en
   boucle après login.
10. **Twig 3 a retiré `{% for k, v in arr if cond %}`** (était valide
    en Twig 1). Utiliser le filtre `|filter(v => v is not null)`
    sur le tableau avant le `for`. Sinon : `Unexpected token "name"
    of value "if"` au runtime — qui ne se voit pas en CI tant
    qu'aucun test ne rend le template fautif.
11. **Image Bitnami nginx du Lando** ne crée pas `/var/log/nginx/`
    — pointer `error_log` / `access_log` vers `/dev/stderr` /
    `/dev/stdout` dans `.lando/nginx.conf` (sinon nginx crash au
    démarrage avec `[emerg] open() failed`, et Traefik renvoie un
    `404 page not found` text/plain qui ressemble à un "page
    inexistante" mais c'est en fait l'app qui ne tourne pas).
12. **`docker stop -t 10` SIGKILLait Navidrome en plein checkpoint WAL
    SQLite** = `navidrome.db` corrompue après un `--auto-stop` sur une
    grosse librairie (cf. #118). Le manager respecte désormais quatre
    couches avant chaque action : (a) `NAVIDROME_STOP_TIMEOUT_SECONDS`
    (défaut 60s, cf. `DockerCli::stop()`) ; (b) polling de `docker
    inspect` jusqu'à `Running:false` (`waitUntilStopped`) — on n'écrit
    JAMAIS pendant que Navidrome tourne, même si `docker stop` a rendu
    la main avec exit 0 ; (c) snapshot
    `<dbPath>.backup-<unix_ts>` via `App\Navidrome\NavidromeDbBackup`,
    rétention `NAVIDROME_DB_BACKUP_RETENTION` ; (d) `PRAGMA quick_check`
    avant l'action — si la DB est déjà brisée on bloque l'écriture
    pour ne pas aggraver. Si tu réintroduis un appel à `runProcess`
    pour stop/start ailleurs, **passe par `NavidromeContainerManager`**
    sinon tu by-passes les 4 garde-fous.

---

## 8. Roadmap

La roadmap vit dans **[`ROADMAP.md`](ROADMAP.md)** (catégorisée par
domaine + effort S/M/L) avec lien direct vers chaque issue
[GitHub](https://github.com/kgaut/navidrome-playlist-generator/issues),
qui est la source de vérité.

Déjà livré (briques majeures) : Lidarr, historique des runs cron,
stats avancées (`/stats/compare`, `/stats/charts`, `/stats/heatmap`,
`/wrapped/{year}`), historiques Last.fm + Navidrome (snapshots
locaux), audit par-track des imports Last.fm, matching à 4 paliers
(featuring + version markers).

Cf. [`CHANGELOG.md`](CHANGELOG.md) pour le détail chronologique.

---

## 9. Quand tu fais évoluer ce projet

- Toujours `composer ci` vert avant un commit.
- Toujours commit + push sur la feature branch puis fast-forward sur
  main (l'utilisateur veut l'historique linéaire).
- Toujours mettre à jour `README.md` quand on ajoute une feature
  utilisateur ou une variable d'env.
- Toute feature/fix utilisateur ajoute une entrée sous `## [Unreleased]`
  dans `CHANGELOG.md` (Added / Changed / Fixed / etc. selon le cas).
- Quand le user **suggère une idée prospective** (pas un bug à fixer
  immédiatement), suivre le workflow décrit dans
  [`AGENTS.md`](AGENTS.md) : ouvrir un ticket GitHub catégorisé
  + ajouter à `ROADMAP.md`.
- Les nouvelles env vars doivent être déclarées dans **les 5 endroits**
  (`.env`, `.env.dist`, `phpunit.xml.dist`, `.lando.yml.dist`,
  `docker-compose.example.yml`) plus dans `config/services.yaml`.
- Les migrations Doctrine sont jouées au boot du conteneur ; pas besoin
  d'incantation manuelle en prod.
- Tests : un par méthode publique non triviale, fixtures via
  `NavidromeFixtureFactory`.
- Pour ajouter un nouveau générateur de playlist, lire `docs/PLUGINS.md`
  — c'est juste une classe à créer dans `src/Generator/`.
