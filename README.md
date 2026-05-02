# Navidrome Tools

[![CI](https://github.com/kgaut/navidrome-playlist-generator/actions/workflows/ci.yml/badge.svg)](https://github.com/kgaut/navidrome-playlist-generator/actions/workflows/ci.yml)

Boîte à outils web self-hosted (Symfony 7) autour de
[Navidrome](https://www.navidrome.org/) : générateur de playlists basé
sur les écoutes, statistiques détaillées, import one-shot des scrobbles
Last.fm, intégration Lidarr et historique des runs cron.

## Image Docker publiée

L'image officielle est publiée automatiquement sur GitHub Container
Registry (`ghcr.io/kgaut/navidrome-tools`) par la CI :

| Évènement                | Tags publiés                                              |
|--------------------------|-----------------------------------------------------------|
| Push sur `main`          | `latest`, `main-<sha7>`                                   |
| Push d'un tag `v1.2.3`   | `1.2.3`, `1.2`, `1`, `latest`                             |
| Push sur autre branche   | `<nom-de-branche>` (utile pour tester un PR mergé)        |
| Pull request             | aucun push, build de validation seulement                 |

Image multi-arch : `linux/amd64` + `linux/arm64`.

```bash
docker pull ghcr.io/kgaut/navidrome-tools:latest
```

> Pour héberger un miroir sur GitLab, le repo livre aussi un
> [`.gitlab-ci.yml`](.gitlab-ci.yml) avec les mêmes 5 jobs (phpcs,
> phpstan, tests matrix 8.3+8.4, docker build + publish multi-arch).
> Par défaut il pousse vers le registre du projet
> (`$CI_REGISTRY_IMAGE`) ; surchargez la variable `REGISTRY_IMAGE`
> dans Settings → CI/CD pour viser une autre cible.

Fonctionnalités principales :

- **Système de plugins** : chaque type de playlist (« top des X derniers jours »,
  « morceaux jamais écoutés », « top du mois de mai il y a X années »…) est
  une simple classe PHP. En ajouter un nouveau = créer un fichier dans
  `src/Generator/`. Voir [`docs/PLUGINS.md`](docs/PLUGINS.md).
- **Configuration en base** (SQLite locale, Doctrine ORM) : nom, paramètres,
  planning cron, limite, activation par playlist. Géré via l'UI.
- **Génération automatique** par cron interne (supercronic) qui se rafraîchit
  toutes les 5 minutes pour refléter les changements faits dans l'UI.
- **Auth UI** : un seul couple login/mot de passe défini dans `.env`, pas de
  base utilisateurs.
- **Détection automatique** de la table `scrobbles` (Navidrome ≥ 0.55, fin
  2025) pour des stats exactes ; fallback sur `annotation.play_date` sinon.
- **Image Docker** unique qui sert au choix l'UI web ou le démon cron via
  la variable `APP_MODE`.

## Quickstart Docker (production / self-host)

```bash
# 1. Récupérer le compose et l'éditer
curl -O https://raw.githubusercontent.com/kgaut/navidrome-playlist-generator/main/docker-compose.example.yml
cp docker-compose.example.yml docker-compose.yml

# 2. Créer un .env avec vos secrets
cat > .env <<EOF
APP_SECRET=$(openssl rand -hex 32)
NAVIDROME_URL=http://navidrome:4533
NAVIDROME_USER=admin
NAVIDROME_PASSWORD=...                  # mot de passe Navidrome
APP_AUTH_USER=admin
APP_AUTH_PASSWORD=...                   # mot de passe pour l'UI
NAVIDROME_DATA_DIR=/srv/navidrome/data  # dossier qui contient navidrome.db
EOF

# 3. Lancer les deux services (web + cron)
docker compose up -d

# 4. Ouvrir l'UI
open http://localhost:8080
```

Au premier lancement, 4 définitions de playlist d'exemple sont créées
**désactivées** : Top 7j, Top 30j, Top mois passé, Top année passée. Vous
pouvez les éditer puis les activer.

### Variables d'environnement

| Variable             | Obligatoire | Description                                                              |
|----------------------|-------------|--------------------------------------------------------------------------|
| `APP_SECRET`         | oui         | Secret Symfony (32 caractères hex). `openssl rand -hex 32`.              |
| `APP_ENV`            | non (`prod`)| `prod` ou `dev`.                                                         |
| `APP_MODE`           | non (`web`) | `web` (FrankenPHP) ou `cron` (supercronic).                              |
| `NAVIDROME_DB_PATH`  | oui         | Chemin du fichier SQLite Navidrome dans le conteneur. Bind-mounter `:ro`.|
| `NAVIDROME_URL`      | oui         | URL HTTP(S) de Navidrome (sans slash final).                             |
| `NAVIDROME_USER`     | oui         | Utilisateur Navidrome dont on lit les écoutes et qui possède les playlists. |
| `NAVIDROME_PASSWORD` | oui         | Mot de passe de cet utilisateur.                                         |
| `APP_AUTH_USER`      | oui         | Identifiant pour se connecter à l'UI du tool.                            |
| `APP_AUTH_PASSWORD`  | oui         | Mot de passe pour se connecter à l'UI du tool.                           |
| `DATABASE_URL`       | non         | DSN Doctrine pour la DB locale du tool. Défaut : SQLite dans `var/data.db`. |
| `CRON_REGEN_INTERVAL`| non (`300`) | Intervalle en secondes entre 2 régénérations du crontab (mode cron).     |

### Mise à jour

```bash
docker compose pull
docker compose up -d
```

Les migrations Doctrine sont jouées automatiquement à chaque démarrage
(idempotent). Le volume `playlist-data` préserve la configuration entre
redémarrages.

### Cron externe avec arrêt de Navidrome (optionnel)

Le service `navidrome-tools-cron` livré dans le compose lance déjà
supercronic et exécute les jobs en parallèle de Navidrome. Si vous
préférez piloter les jobs depuis le **crontab de l'hôte** (par exemple
parce qu'au moins un job — typiquement `app:lastfm:import` — a besoin
que Navidrome soit arrêté pour écrire dans sa SQLite sans risque de
lock), voici un script complet à appeler depuis le crontab de la
machine.

> Pré-requis : Navidrome **et** les services `navidrome-tools-*` doivent
> être déclarés dans le **même** `docker-compose.yml` (« même stack »).
> Si vous adoptez ce script, **désactivez** le service
> `navidrome-tools-cron` (sinon ses jobs supercronic et ceux du host
> cron se marcheront dessus).

`/usr/local/bin/navidrome-tools-cron.sh` :

```bash
#!/usr/bin/env bash
#
# Stoppe Navidrome, lance les commandes cron de navidrome-tools,
# redémarre Navidrome — quoi qu'il arrive.
#
# Exemple de ligne crontab (root) :
#     0 4 * * * /usr/local/bin/navidrome-tools-cron.sh >> /var/log/navidrome-tools-cron.log 2>&1

set -euo pipefail

# --- Configuration ---
STACK_DIR="/srv/navidrome"            # dossier contenant docker-compose.yml
NAVIDROME_SERVICE="navidrome"         # nom du service Navidrome dans la stack
TOOLS_SERVICE="navidrome-tools-web"   # service tool sur lequel taper les commandes
COMPOSE=(docker compose)              # mettre (docker-compose) pour l'ancien CLI

cd "$STACK_DIR"

log() { echo "[$(date -Is)] $*"; }

# Toujours relancer Navidrome, même si une commande échoue ou que le
# script est interrompu.
restart_navidrome() {
    log "Redémarrage de ${NAVIDROME_SERVICE}"
    "${COMPOSE[@]}" up -d "$NAVIDROME_SERVICE"
}
trap restart_navidrome EXIT

log "Arrêt de ${NAVIDROME_SERVICE}"
"${COMPOSE[@]}" stop "$NAVIDROME_SERVICE"

log "Génération des playlists dues"
"${COMPOSE[@]}" exec -T "$TOOLS_SERVICE" php bin/console app:playlist:run-all

log "Recalcul du cache statistiques"
"${COMPOSE[@]}" exec -T "$TOOLS_SERVICE" php bin/console app:stats:compute

log "Purge de l'historique des runs"
"${COMPOSE[@]}" exec -T "$TOOLS_SERVICE" php bin/console app:history:purge

log "Terminé"
```

Rendez-le exécutable une fois copié :

```bash
sudo chmod +x /usr/local/bin/navidrome-tools-cron.sh
```

Notes :

- `trap … EXIT` garantit que Navidrome est relancé même si une commande
  Symfony plante ou si le script reçoit un `SIGTERM`.
- `docker compose exec -T` réutilise le conteneur `navidrome-tools-web`
  déjà démarré (toutes les variables d'environnement requises y sont
  déjà). Pas besoin d'un `run --rm` qui relancerait l'entrypoint et
  rejouerait les migrations à chaque tick.
- Adaptez la liste de commandes selon vos besoins — vous pouvez par
  exemple ajouter un `app:lastfm:import <user> --api-key=…` une fois
  par jour, qui profitera de l'arrêt de Navidrome pour écrire en toute
  sécurité dans `navidrome.db`.

## Développement local avec Lando (recommandé)

[Lando](https://lando.dev/) fournit l'environnement Symfony complet
(PHP 8.3 + nginx + Composer 2) sans installer quoi que ce soit sur la
machine hôte.

```bash
git clone https://github.com/kgaut/navidrome-playlist-generator
cd navidrome-playlist-generator

# Copier la base SQLite Navidrome (ou créer un symlink)
cp /chemin/vers/navidrome.db var/navidrome.db

# Le repo livre .lando.yml.dist : copiez-le en .lando.yml et adaptez-le
# (mots de passe, URL Navidrome, etc.) — .lando.yml est gitignored.
cp .lando.yml.dist .lando.yml

lando start          # premier lancement : pull des images + composer install
lando migrate        # crée la DB locale du tool
lando seed           # insère les 4 définitions d'exemple

# UI accessible sur :
#   https://navidrome-tools.lndo.site
```

Commandes utiles :

| Commande Lando             | Effet                                                          |
|----------------------------|----------------------------------------------------------------|
| `lando symfony cache:clear`| Vider le cache Symfony.                                        |
| `lando composer require X` | Installer un package.                                          |
| `lando test`               | Lancer PHPUnit.                                                |
| `lando migrate`            | Jouer les migrations Doctrine.                                 |
| `lando seed`               | Réinsérer les fixtures (idempotent).                           |
| `lando playlist-run "Top 30 derniers jours" --dry-run` | Tester une définition. |
| `lando cron-dump`          | Voir le crontab généré pour supercronic.                       |

Le service `cron` lance supercronic en local, identique au mode prod.

Pour activer Xdebug : éditer votre copie locale `.lando.yml` (`xdebug: debug`) puis
`lando rebuild -y`.

## Développement local sans Lando

Pré-requis : PHP 8.2+, ext-pdo_sqlite, Composer 2, [Symfony CLI](https://symfony.com/download).

```bash
composer install
cp .env.dist .env.local              # ajuster les valeurs
mkdir -p var
cp /chemin/vers/navidrome.db var/navidrome.db
php bin/console doctrine:migrations:migrate -n
php bin/console app:fixtures:seed
symfony serve                        # https://127.0.0.1:8000
```

## Statistiques d'écoute

Cinq pages stats accessibles via le menu déroulant **Statistiques** :

| Route                | Contenu                                                                      |
|----------------------|------------------------------------------------------------------------------|
| `/stats`             | Vue d'ensemble par période (7d/30d/last-month/last-year/all-time), cachée    |
|                      | dans `stats_snapshot`, refresh manuel + cron `STATS_REFRESH_SCHEDULE`.       |
| `/stats/compare`     | Comparaison côte à côte de deux périodes : top artistes / morceaux fusionnés |
|                      | avec deltas et badges (nouveau / disparu / ↑N / ↓N / =).                     |
| `/stats/charts`      | Trois graphiques Chart.js : écoutes par mois, top 5 artistes au fil du       |
|                      | temps, distribution par jour de la semaine.                                  |
| `/stats/heatmap`     | Deux heatmaps HTML/CSS pures : jour×heure (90 derniers jours) et             |
|                      | année×jour façon GitHub contribs (avec sélecteur d'année).                   |
| `/wrapped/{year}`    | Rétrospective annuelle façon Spotify Wrapped : total plays / heures écoutées |
|                      | / morceaux distincts, top 25 artistes, top 50 morceaux, nouvelles            |
|                      | découvertes, mois le plus actif, plus longue série d'écoutes consécutives.   |
|                      | Cachée dans `stats_snapshot` (key `wrapped-<year>`).                         |

Toutes les pages requièrent la table `scrobbles` Navidrome (≥ 0.55) et
affichent un bandeau si elle n'est pas trouvée.

## Import one-shot des scrobbles Last.fm

Deux moyens : **page web** `/lastfm/import` (pratique pour quelques
milliers de scrobbles) ou **commande CLI** (recommandée au-delà).

Dans les deux cas, l'outil récupère l'historique Last.fm d'un utilisateur
et l'insère dans la table `scrobbles` de Navidrome en évitant les
doublons.

> ⚠️ **ARRÊTEZ Navidrome avant tout import qui écrit** dans la base
> SQLite : risque de lock SQLite, de corruption WAL et de stats
> incohérentes. La page web affiche ce warning en gros et propose un
> mode **Dry-run** (coché par défaut) pour vérifier le rapport sans
> écrire. Pensez à sauvegarder votre fichier `navidrome.db` avant un
> import en écriture.

### Via l'interface web

Accédez à `/lastfm/import` une fois connecté. Le formulaire propose :

- Identifiant Last.fm + API key. Les deux peuvent venir de
  l'environnement via `LASTFM_USER` (pré-remplit le champ) et
  `LASTFM_API_KEY` (utilisée si le champ est laissé vide).
- Filtres `date_min` / `date_max` optionnels.
- Tolérance dedup (secondes) — un scrobble n'est pas réinséré s'il en
  existe déjà un sur la même piste à ± cette durée.
- Limite de sécurité (max scrobbles, défaut 5 000) pour éviter les
  timeouts HTTP.
- Case **Dry-run** (cochée par défaut).

Le bouton « Lancer l'import » demande une confirmation JS si vous avez
décoché Dry-run. Le rapport s'affiche en bas de page : 4 cards
(récupérés / insérés / doublons / non trouvés) puis un tableau des 100
morceaux non trouvés les plus écoutés sur Last.fm, triés par nombre de
scrobbles décroissant.

### Via la commande CLI

```bash
php bin/console app:lastfm:import [<lastfm-user>] [--api-key=YOUR_KEY] \
    [--date-min=YYYY-MM-DD] [--date-max=YYYY-MM-DD] \
    [--tolerance=60] [--dry-run] [--show-unmatched=50|all|0] \
    [--max-scrobbles=N]
```

L'API key Last.fm s'obtient gratuitement sur
<https://www.last.fm/api/account/create>. Elle peut aussi être passée via
la variable d'environnement `LASTFM_API_KEY`. De même, le username peut
être omis si `LASTFM_USER` est défini dans l'environnement.

### Stratégie

1. **Pagination** : utilise `user.getRecentTracks` de l'API Last.fm,
   200 scrobbles par page, jusqu'au bout de l'historique (filtré par
   `--date-min` / `--date-max` si fournis). Une pause configurable
   (`LASTFM_PAGE_DELAY_SECONDS`, défaut 10s) sépare deux pages
   consécutives pour éviter de surcharger l'API ; passez à 0 pour
   désactiver.
2. **Matching** sur la lib Navidrome (essais successifs jusqu'à
   succès) :
   0. **Alias manuel** : si une entrée existe dans la table
      `lastfm_alias` (page `/lastfm/aliases`) pour le couple
      `(artist, title)` normalisé, elle court-circuite tout. Cible
      vide = scrobble compté en `skipped` (utile pour les podcasts).
   1. **MusicBrainz ID** si Last.fm le fournit (le plus fiable) ;
   2. **Triplet** `(artist, title, album)` normalisé — départage les
      morceaux qui existent sur plusieurs albums (single + version
      album + compilation) ;
   3. **Couple** `(artist, title)` normalisé, avec tie-break
      `album_artist = artist` puis `id ASC` ;
   4. **Fallback fuzzy** Levenshtein artist+title (opt-in via
      `LASTFM_FUZZY_MAX_DISTANCE`, défaut 0 = désactivé, 3 = seuil
      raisonnable). Coûteux : ne s'active que sur les scrobbles qui
      ont échoué aux 3 paliers précédents.

   La normalisation utilisée à toutes les étapes : lowercase + trim
   + décomposition Unicode NFKD + strip des diacritiques (Beyoncé ↔
   Beyonce) + strip de la ponctuation (AC/DC ↔ ACDC) + collapse des
   espaces. Les helpers `stripFeaturedArtists()` /
   `stripFeaturingFromTitle()` / `stripVersionMarkers()` retirent en
   plus les suffixes parasites côté Last.fm (`feat. X`, `(Radio
   Edit)`, `- Remastered 2011`, `(Live at …)`, `(Acoustic)`, etc.).
3. **Déduplication** : un scrobble n'est pas réinséré s'il existe déjà
   dans la table `scrobbles` une ligne avec le même `media_file_id` et
   un `submission_time` à ±`--tolerance` secondes (60 par défaut). Cela
   absorbe les petits décalages d'horloge entre clients de scrobble.
4. **Rapport final** : compteurs `fetched / inserted / duplicates /
   unmatched`, plus un tableau des **morceaux non matchés** agrégés par
   `(artist, title)`, **triés par nombre de scrobbles décroissant**.
   Pratique pour identifier en priorité les morceaux à ajouter dans la
   bibliothèque Navidrome.

### Pré-requis

- **Navidrome ≥ 0.55** (la table `scrobbles` doit exister, sinon la
  commande échoue avec un message explicite).
- **Accès en écriture** sur la base SQLite Navidrome. Si vous lancez la
  commande depuis le conteneur Docker du tool, montez temporairement le
  fichier en read-write — par exemple :
  ```bash
  docker run --rm -it \
      -v /srv/navidrome/data/navidrome.db:/data/navidrome.db \
      -e NAVIDROME_DB_PATH=/data/navidrome.db \
      -e LASTFM_API_KEY=... \
      -e APP_SECRET=... -e APP_AUTH_USER=admin -e APP_AUTH_PASSWORD=... \
      -e NAVIDROME_USER=admin -e NAVIDROME_PASSWORD=... \
      ghcr.io/kgaut/navidrome-tools:latest \
      php bin/console app:lastfm:import myuser
  ```
  (volume **sans** `:ro`). Idéalement, **arrêter Navidrome** pendant
  l'import pour éviter les locks SQLite concurrents.
- Sous Lando : `lando symfony app:lastfm:import myuser --api-key=...`
  fonctionne directement (la DB Navidrome bind-mountée est en RW par
  défaut).

### Exemples

```bash
# Aperçu sans rien écrire :
lando symfony app:lastfm:import myuser --api-key=XXX --dry-run

# Import de toute l'année 2024 :
lando symfony app:lastfm:import myuser --api-key=XXX \
    --date-min=2024-01-01 --date-max=2025-01-01

# Voir tous les morceaux non trouvés (utile pour audit complet) :
lando symfony app:lastfm:import myuser --api-key=XXX --show-unmatched=all
```

## Intégration Lidarr (optionnelle)

Sur la page d'import Last.fm, le tableau des morceaux non trouvés
expose pour chaque ligne :

- **Last.fm ↗** : page artiste publique sur Last.fm.
- **Navidrome ↗** : si l'artiste existe déjà dans Navidrome (lookup par
  nom normalisé), lien direct vers sa fiche dans l'app Navidrome.
- **+ Lidarr** : ajoute l'artiste à Lidarr en un clic. Lidarr déclenchera
  ensuite la recherche/téléchargement et alimentera la lib que Navidrome
  scanne. Bouton masqué si Lidarr n'est pas configuré.

### Configuration

Variables d'environnement (laisser `LIDARR_URL` vide pour désactiver
proprement) :

| Variable                     | Description                                                         |
|------------------------------|---------------------------------------------------------------------|
| `LIDARR_URL`                 | Base URL Lidarr (ex. `http://lidarr:8686`).                         |
| `LIDARR_API_KEY`             | API key (Lidarr → Settings → General).                              |
| `LIDARR_ROOT_FOLDER_PATH`    | Chemin où Lidarr place les artistes (ex. `/music`).                 |
| `LIDARR_QUALITY_PROFILE_ID`  | Id d'un Quality Profile existant.                                   |
| `LIDARR_METADATA_PROFILE_ID` | Id d'un Metadata Profile existant.                                  |
| `LIDARR_MONITOR`             | `all`/`future`/`missing`/`existing`/`first`/`latest`/`none` (défaut `all`). |

Le service :
1. Cherche l'artiste sur MusicBrainz via l'endpoint Lidarr
   `/api/v1/artist/lookup` (l'API key permet d'éviter les rate-limits MB).
2. Prend le premier hit (Lidarr ordonne par pertinence) et POST
   `/api/v1/artist` en demandant `searchForMissingAlbums: true`.
3. Si Lidarr répond que l'artiste existe déjà, l'UI affiche un flash
   info (« déjà présent ») au lieu d'une erreur.

## Historique des runs cron

Tous les jobs longs sont audités dans la table locale `run_history`.
La page `/history` (lien dans la nav) liste les exécutions avec :

- type (`playlist`, `stats`, `lastfm-import`),
- libellé humain,
- statut (✓ success / ✗ error / skipped) avec badge coloré,
- date de démarrage et durée,
- métriques (par exemple `tracks=50`, `inserted=237`, `unmatched=42`),
- bouton « Détails » pour le message complet et le JSON metrics.

Filtres par type/statut + recherche libre + pagination (50/page).

Une commande `app:history:purge` supprime les entrées plus vieilles que
`RUN_HISTORY_RETENTION_DAYS` (défaut 90). Elle est ajoutée
automatiquement au crontab par `app:cron:dump` (1×/jour à 4h30).

## Configuration éditeur

Le repo livre une **config PhpStorm partageable** (PSR-12, inspections
PHPCS et PHPStan câblées sur `phpcs.xml.dist` et `phpstan.dist.neon`,
PHP language level 8.3, framework PHPUnit 11) sous `.idea/` :

- `.idea/codeStyles/` — schéma de code projet
- `.idea/inspectionProfiles/Project_Default.xml` — inspections actives
- `.idea/php.xml` — version PHP, container Symfony, namespace Twig
- `.idea/php-test-framework.xml` — PHPUnit version

Ces fichiers sont whitelistés dans `.gitignore` ; le reste de `.idea/`
(workspace, fichiers per-user) reste ignoré.

Un `.editorconfig` à la racine fournit le minimum universel pour les
autres éditeurs (VS Code, Vim, Sublime…) : indentation 4 espaces (2
pour YAML/JSON/XML/HTML/Twig), LF, UTF-8, trim trailing whitespace.

## Qualité de code et tests

Le projet utilise PHPUnit, PHPStan et PHP_CodeSniffer (PSR-12). Les
trois sont exécutés par la CI GitHub Actions sur chaque push / pull
request, plus un build de l'image Docker en parallèle.

```bash
composer test       # PHPUnit
composer phpstan    # Static analysis (level 6 + extensions Symfony/Doctrine/PHPUnit)
composer phpcs      # PSR-12 coding standard
composer phpcbf     # Auto-fix many PHPCS errors
composer ci         # phpcs + phpstan + tests, séquentiellement
```

Configuration : `phpunit.xml.dist`, `phpstan.dist.neon`, `phpcs.xml.dist`.

Sous Lando :

```bash
lando composer test
lando composer phpstan
lando composer phpcs
lando composer ci
```

## Ajouter un nouveau type de playlist (plugin)

Voir [`docs/PLUGINS.md`](docs/PLUGINS.md). En résumé : créer une classe
qui implémente `App\Generator\PlaylistGeneratorInterface` dans
`src/Generator/`, elle est auto-détectée et apparaît immédiatement dans
le dropdown de l'UI.

## Schéma Navidrome utilisé (lecture seule)

| Table          | Colonnes lues                                                |
|----------------|--------------------------------------------------------------|
| `media_file`   | id, title, album, artist, album_artist, duration, year       |
| `annotation`   | user_id, item_id, item_type, play_count, play_date           |
| `user`         | id, user_name (résolution `NAVIDROME_USER` → user_id Subsonic)|
| `scrobbles`    | media_file_id, user_id, submission_time (Navidrome ≥ 0.55)   |

Si la table `scrobbles` n'existe pas, le tool retombe sur
`annotation.play_date`, qui ne contient que la date du **dernier** play.
Les tops « par fenêtre temporelle » deviennent donc approximatifs ; un
bandeau d'avertissement est affiché dans l'UI dans ce cas.

## Création de playlists

Toutes les playlists sont créées via l'API Subsonic de Navidrome
(`createPlaylist.view`). Le tool **n'écrit jamais directement** dans la
SQLite Navidrome. Avantages : aucun risque de corruption ou de conflit
de lock, fonctionne même si Navidrome tourne en parallèle.

L'option « remplacer la playlist existante » utilise
`getPlaylists.view` + `deletePlaylist.view` pour retirer l'ancienne du
même nom appartenant au même utilisateur, puis recrée la nouvelle.

## Changelog

L'historique des évolutions est tenu dans [`CHANGELOG.md`](CHANGELOG.md)
au format [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/).

## Licence

MIT.
