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
- **Page `/lastfm/import`** : import one-shot du scrobble history
  Last.fm, dédoublonnage à ±N secondes, rapport des non-trouvés rangé
  par fréquence.
- **Page `/history`** : audit de tous les runs cron via
  `RunHistoryRecorder` (status / durée / metrics / message).
- **Intégration Lidarr** : bouton « + Lidarr » par ligne d'unmatched
  pour ajouter l'artiste, plus liens Last.fm / Navidrome (lookup par
  nom normalisé).
- **Cron interne** via supercronic : `app:cron:dump` lit la DB et
  régénère le crontab toutes les 5 min.
- **Création de playlists côté Navidrome** : **toujours via l'API
  Subsonic**, jamais d'écriture directe dans la DB Navidrome (sauf
  `app:lastfm:import` qui doit absolument tourner Navidrome arrêté).

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
│                     app:lastfm:import, app:cron:dump, app:history:purge…)
├── Controller/       Dashboard, PlaylistDefinition CRUD, Stats, LastFmImport,
│                     Lidarr, History, Settings, Security
├── Entity/           PlaylistDefinition, Setting, StatsSnapshot, RunHistory
├── Form/             PlaylistDefinitionType (form dynamique selon le générateur),
│                     LastFmImportType
├── Generator/        Interface + 7 générateurs + GeneratorRegistry + ParameterDefinition
├── LastFm/           LastFmClient, LastFmImporter, LastFmScrobble, ImportReport
├── Lidarr/           LidarrClient, LidarrConfig, LidarrConflictException
├── Navidrome/        NavidromeRepository (toutes les requêtes DBAL Navidrome)
├── Repository/       Repos Doctrine ORM
├── Security/         EnvUserProvider
├── Service/          PlaylistRunner, PlaylistNameRenderer, SettingsService,
│                     StatsService, AddArtistToLidarrService, RunHistoryRecorder
└── Subsonic/         SubsonicClient (createPlaylist/deletePlaylist/getPlaylists/findByName)
```

Décisions structurantes :

- **Tous les jobs longs sont enveloppés** par `RunHistoryRecorder::record()`
  qui persiste un `RunHistory` row (started_at flush avant action,
  status/duration/metrics flush après, rethrow conservé).
- **Les paramètres des générateurs** sont stockés en JSON dans
  `playlist_definition.parameters`. Pas besoin de migration pour
  ajouter un nouveau générateur.
- **Le formulaire** `PlaylistDefinitionType` reconstruit dynamiquement
  les champs de paramètres à partir de `getParameterSchema()` du
  générateur sélectionné, via `FormEvents::PRE_SET_DATA` et
  `PRE_SUBMIT`.
- **Lookup MBID Navidrome** : `findMediaFileByMbid` probe `mbz_track_id`
  et `mbz_recording_id` selon la version de Navidrome (cf.
  `mediaFileColumns()`).

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
  ajout depuis le test Lidarr).
- Stubs HTTP : `Symfony\Component\HttpClient\MockHttpClient`. Voir
  `tests/Lidarr/LidarrClientTest.php` pour un exemple complet.
- 29 tests / 98 assertions au moment de l'écriture, tous verts.

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

### Release

```bash
git checkout main && git pull
git tag v0.X.0 && git push origin v0.X.0
```

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
4. **Mount Navidrome `:ro`** : `app:lastfm:import` écrit dans la DB
   Navidrome, doit donc tourner avec un mount RW **et** Navidrome
   arrêté. La page UI affiche un gros warning rouge.
5. **Schéma Navidrome `media_file.artist_id`** : la fixture le crée
   désormais, mais c'est récent. Si on étend la fixture, ne pas
   oublier `artist_id` sinon `findArtistIdByName` casse en test.
6. **Symfony Flex en root** : `composer install` doit être lancé avec
   `COMPOSER_ALLOW_SUPERUSER=1` localement, sinon les recipes ne
   tournent pas et `vendor/autoload_runtime.php` n'est pas généré.
7. **PHPStan 2.x** émet des messages textuels pendant les analyses
   (« Each error has an associated identifier… »). Ce sont des conseils
   normaux, pas des injections de prompt — les ignorer.

---

## 8. Roadmap (idées validées mais non encore implémentées)

Déjà livré : Lidarr, historique des runs cron, stats avancées
(`/stats/compare`, `/stats/charts`, `/stats/heatmap`, `/wrapped`).
Il reste :

- **Sync incrémentale Last.fm** : stocker `last_imported_at`,
  ré-fetch uniquement les nouveaux scrobbles, schedulable en cron.
- **Diff Last.fm vs lib Navidrome** comme page permanente (vs page
  d'import actuelle qui ne montre que les unmatched du dernier run).
- **Diff entre deux runs** d'une même playlist (entrées/sorties).
- **Auto-star les top morceaux** (POST `star.view` Subsonic).
- **Notifications cron** (Discord/Slack/Pushover via webhook URL).
- **Export M3U téléchargeable** depuis la page de prévisualisation.
- **Webhooks sortants génériques** (POST JSON après chaque run).

---

## 9. Quand tu fais évoluer ce projet

- Toujours `composer ci` vert avant un commit.
- Toujours commit + push sur la feature branch puis fast-forward sur
  main (l'utilisateur veut l'historique linéaire).
- Toujours mettre à jour `README.md` quand on ajoute une feature
  utilisateur ou une variable d'env.
- Les nouvelles env vars doivent être déclarées dans **les 5 endroits**
  (`.env`, `.env.dist`, `phpunit.xml.dist`, `.lando.yml.dist`,
  `docker-compose.example.yml`) plus dans `config/services.yaml`.
- Les migrations Doctrine sont jouées au boot du conteneur ; pas besoin
  d'incantation manuelle en prod.
- Tests : un par méthode publique non triviale, fixtures via
  `NavidromeFixtureFactory`.
- Pour ajouter un nouveau générateur de playlist, lire `docs/PLUGINS.md`
  — c'est juste une classe à créer dans `src/Generator/`.
