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
  limite, activation par playlist. Géré via l'UI.
- **Lancement des jobs** depuis le crontab unix de l'hôte (ou tout
  scheduler maison) : `bin/console app:playlist:run <id>`,
  `app:lastfm:import`, `app:lastfm:process`, etc. Pas de cron embarqué
  dans le tool.
- **Auth UI** : un seul couple login/mot de passe défini dans `.env`, pas de
  base utilisateurs.
- **Détection automatique** de la table `scrobbles` (Navidrome ≥ 0.55, fin
  2025) pour des stats exactes ; fallback sur `annotation.play_date` sinon.
- **Image Docker** légère (FrankenPHP) qui sert l'UI web et expose les
  commandes Symfony via `APP_MODE=cli`.

## Quickstart Docker (production / self-host)

> Pour un guide complet (intégration à une stack existante, networks
> Docker partagés, exemple de Caddyfile, troubleshooting), voir
> [`docs/DOCKER.md`](docs/DOCKER.md).

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

# 3. Lancer les services (web + worker pour les jobs Last.fm UI)
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
| `APP_MODE`           | non (`web`) | `web` (FrankenPHP), `cli` (one-shot Symfony command) ou `worker` (consumer Messenger pour les jobs UI asynchrones). |
| `APP_TIMEZONE`       | non (`UTC`) | Fuseau d'affichage (PHP + Twig). Ex. `Europe/Paris`. Stockage reste UTC. |
| `NAVIDROME_DB_PATH`  | oui         | Chemin du fichier SQLite Navidrome dans le conteneur. Bind-mounter `:ro`.|
| `NAVIDROME_URL`      | oui         | URL HTTP(S) de Navidrome (sans slash final).                             |
| `NAVIDROME_USER`     | oui         | Utilisateur Navidrome dont on lit les écoutes et qui possède les playlists. |
| `NAVIDROME_PASSWORD` | oui         | Mot de passe de cet utilisateur.                                         |
| `APP_AUTH_USER`      | oui         | Identifiant pour se connecter à l'UI du tool.                            |
| `APP_AUTH_PASSWORD`  | oui         | Mot de passe pour se connecter à l'UI du tool.                           |
| `DATABASE_URL`       | non         | DSN Doctrine pour la DB locale du tool. Défaut : SQLite dans `var/data.db`. |
| `COVERS_CACHE_PATH`  | non         | Cache disque des miniatures album/artiste. Défaut : `/app/var/covers` (volume Docker dédié dans le compose). |
| `NAVIDROME_CONTAINER_NAME` | non   | Nom du conteneur Navidrome dans la même stack docker-compose. Quand renseigné, le dashboard affiche un statut UP/DOWN avec boutons Start/Stop, et les commandes d'import refusent de tourner si Navidrome est détecté UP (`--force` pour outrepasser). Requiert le mount `/var/run/docker.sock` (cf. `docker-compose.example.yml`). |
| `NAVIDROME_STOP_TIMEOUT_SECONDS` | non (`60`) | Fenêtre de shutdown gracieux passée à `docker stop -t` lorsqu'on arrête Navidrome via `--auto-stop`. Doit confortablement excéder le checkpoint WAL SQLite — le défaut Docker (10s) suffit pour un Navidrome inactif mais peut SIGKILL en plein flush sur une grosse librairie après un import lourd, et corrompre `navidrome.db` (cf. #118). |
| `NAVIDROME_STOP_WAIT_CEILING_SECONDS` | non (`30`) | Après `docker stop`, on poll encore `docker inspect` jusqu'à ce que `Running=false`, plafonné à cette durée. On refuse d'écrire dans la DB tant qu'`inspect` voit Navidrome vivant — ceinture-bretelles contre un SIGTERM handler qui traîne. |
| `NAVIDROME_DB_BACKUP_RETENTION` | non (`3`) | Nombre de snapshots `<navidrome.db>.backup-<unix_ts>` conservés. Avant chaque action `--auto-stop`, le tool copie automatiquement la DB SQLite (et ses siblings `-wal` / `-shm`) — un simple `cp` rétablit l'état précédent. `0` = pas de purge. |
| `HOMEPAGE_API_TOKEN` | non       | Bearer token pour le widget [Homepage](https://gethomepage.dev/widgets/services/customapi/) sur l'endpoint `/api/status`. Vide = mode enrichi désactivé (seul le mode healthcheck no-auth est servi). Voir la section [Widget Homepage](#widget-homepage-gethomepage). |

### Mise à jour

```bash
docker compose pull
docker compose up -d
```

Les migrations Doctrine sont jouées automatiquement à chaque démarrage
(idempotent). Le volume `playlist-data` préserve la configuration entre
redémarrages.

### Worker pour les jobs Last.fm lancés depuis l'UI

Les 4 long-runners Last.fm — fetch, process, rematch et
loved↔starred sync — sont lançables **depuis l'UI sans risque de
timeout HTTP**. Les controllers ne font plus que créer un
`run_history` (status `queued`) et déposer un message dans la file
Doctrine (table `messenger_messages`). La consommation est confiée
à un service **`navidrome-tools-worker`** dédié qui exécute
`messenger:consume async --limit=1` (sérialise les jobs ; `process`
et `rematch` ne tournent jamais en parallèle car ils écrivent dans
Navidrome).

```yaml
# extrait de docker-compose.example.yml
navidrome-tools-worker:
  image: ghcr.io/kgaut/navidrome-tools:latest
  restart: unless-stopped
  environment:
    APP_MODE: worker
    # … mêmes env vars que navidrome-tools-web (DATABASE_URL,
    # NAVIDROME_*, LASTFM_*, NAVIDROME_CONTAINER_NAME)
  volumes:
    - ${NAVIDROME_DATA_DIR}/navidrome.db:/data/navidrome.db   # RW (process/rematch écrivent)
    - navidrome-tools-data:/app/var
    # Si NAVIDROME_CONTAINER_NAME est renseigné, le worker auto-stop
    # Navidrome avant chaque écriture — il lui faut le socket Docker :
    # - /var/run/docker.sock:/var/run/docker.sock
  depends_on: [navidrome-tools-web]
```

Sans ce service, les jobs déposés depuis l'UI restent bloqués en
`queued` indéfiniment. La page `/history/{id}` affiche une **barre
de progression** rafraîchie en quasi temps réel via polling
JSON (`GET /history/{id}/progress.json`) ; au bout de 10 minutes
sans mise à jour de progression, la row est marquée `stale`.

Variables liées :

| Variable                        | Défaut  | Description                                                            |
|---------------------------------|---------|------------------------------------------------------------------------|
| `MESSENGER_WORKER_TIME_LIMIT`   | `3600`  | Recyclage du process worker après cette durée (s) — borne mémoire.     |
| `MESSENGER_WORKER_MEMORY_LIMIT` | `128M`  | Recyclage si la mémoire dépasse ce seuil.                              |
| `MESSENGER_WORKER_MESSAGE_LIMIT`| `1`     | Nombre de messages traités par cycle. **Garder à 1** pour sérialiser. |

### Lancement des jobs récurrents

Le tool n'embarque **plus** de cron interne (pas de supercronic, pas
de service `navidrome-tools-cron`, pas de `app:cron:dump`). Vous
pilotez les commandes Symfony depuis votre crontab unix (ou tout
autre scheduler). Pour exécuter une commande dans le conteneur
existant :

```bash
docker compose exec -T navidrome-tools-web php bin/console <command>
```

Exemples de lignes crontab adaptées au flux Last.fm en deux étapes
(fetch quand Navidrome tourne, process quand il est stoppé) :

```cron
# Génération d'une playlist (l'id vient de l'UI ou de
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

# Purge de l'historique des runs (rétention RUN_HISTORY_RETENTION_DAYS).
30 4 * * * docker compose -f /srv/navidrome/docker-compose.yml exec -T navidrome-tools-web \
    php bin/console app:history:purge
```

`docker compose exec -T` réutilise le conteneur `navidrome-tools-web`
déjà démarré (toutes les variables d'environnement requises y sont
déjà). Pas besoin d'un `run --rm` qui relancerait l'entrypoint et
rejouerait les migrations à chaque tick.

Pour les commandes qui écrivent dans Navidrome (`app:lastfm:process`,
`app:lastfm:rematch`), `--auto-stop` orchestre stop / write / restart
quand `NAVIDROME_CONTAINER_NAME` est configuré. Sans cette variable,
arrêtez Navidrome manuellement avant d'invoquer la commande.

## Développement local avec Lando (recommandé)

[Lando](https://lando.dev/) fournit l'environnement complet sans rien
installer sur l'hôte. La stack expose **trois services** pour mirrorer
la prod 1:1 :

- `appserver` — Symfony 7 (PHP 8.4 + nginx + Composer 2), UI sur
  `https://navidrome-tools.lndo.site`.
- `workerserver` — consumer Messenger long-lived (`APP_MODE=worker`,
  même image que la prod, construite depuis le `Dockerfile`). Sans lui,
  les jobs Last.fm dispatchés depuis l'UI restent bloqués en `queued`.
- `navidrome` — instance Navidrome de test sur
  `https://navidrome.lndo.site`, partage sa base SQLite avec le tool
  via `var/navidrome-data/`.

```bash
git clone https://github.com/kgaut/navidrome-playlist-generator
cd navidrome-playlist-generator

# Préparer le dossier partagé Navidrome ↔ tool (DB + cache).
mkdir -p var/navidrome-data
# (Optionnel) seeder avec votre vraie base, sinon Navidrome en créera
# une vide au premier boot.
cp /chemin/vers/navidrome.db var/navidrome-data/navidrome.db

# Copier le template Lando puis adapter le bind-mount du dossier
# musique (search « /change/me/path/to/music » dans .lando.yml).
# .lando.yml est gitignored.
cp .lando.yml.dist .lando.yml

lando start          # premier lancement : build + composer install
lando migrate        # crée la DB locale du tool
lando seed           # insère les 4 définitions d'exemple
```

URLs disponibles après `lando start` :

- UI tool : <https://navidrome-tools.lndo.site>
- Navidrome : <https://navidrome.lndo.site> (admin créé au premier
  démarrage via l'UI Navidrome)

Commandes utiles :

| Commande Lando             | Effet                                                          |
|----------------------------|----------------------------------------------------------------|
| `lando symfony cache:clear`| Vider le cache Symfony.                                        |
| `lando composer require X` | Installer un package.                                          |
| `lando test`               | Lancer PHPUnit.                                                |
| `lando migrate`            | Jouer les migrations Doctrine.                                 |
| `lando seed`               | Réinsérer les fixtures (idempotent).                           |
| `lando playlist-run "Top 30 derniers jours" --dry-run` | Tester une définition. |
| `lando worker`             | Consommer **un** message en foreground (debug) — le service `workerserver` consomme déjà en boucle, ce raccourci sert à voir la sortie `-vv`. |
| `lando logs -s workerserver -f` | Suivre la sortie du worker en continu. |
| `lando logs -s navidrome -f`    | Suivre les logs du Navidrome embarqué. |

Pour activer Xdebug : éditer votre copie locale `.lando.yml` (`xdebug: debug`) puis
`lando rebuild -y`.

> **Note sur l'écriture dans la base Navidrome** : `app:lastfm:process`
> et `app:lastfm:rematch` doivent être lancés **Navidrome arrêté**
> (`lando stop navidrome` puis relancer après l'import). Le flag
> `--auto-stop` n'est pas câblé sous Lando — c'est un setup dev, pas
> prod.

## Développement local sans Lando

Pré-requis : PHP 8.4+, ext-pdo_sqlite, Composer 2, [Symfony CLI](https://symfony.com/download).

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
| `/stats/tops`        | Tops sur fenêtre `[from, to]` arbitraire : 50 artistes / 100 albums /        |
|                      | 500 morceaux, filtre par client. Cache dans `top_snapshot` (clé              |
|                      | (from, to, client)). Bouton « + Créer playlist Navidrome » sur le top        |
|                      | morceaux. Refresh manuel ou via `app:stats:tops:compute`.                    |
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

## Import des scrobbles Last.fm — workflow en deux étapes

L'import Last.fm est **découplé** en deux phases :

1. **Récupération** (`app:lastfm:import` ou section 1 de
   `/lastfm/import`) — lit l'historique Last.fm via
   `user.getRecentTracks` et stocke chaque scrobble dans la table
   locale `lastfm_import_buffer`. Aucune écriture côté Navidrome :
   **Navidrome peut tourner**.
2. **Traitement** (`app:lastfm:process` ou section 2 de
   `/lastfm/import`) — vide le buffer : matching cascade, insertion
   dans la table `scrobbles` Navidrome, audit dans
   `lastfm_import_track`, suppression de la row du buffer.
   **Navidrome doit être arrêté** (l'écriture concurrente sur la
   SQLite peut corrompre le journal WAL).

Cette séparation permet de fetcher régulièrement (cron léger,
Navidrome up) puis de processer le buffer ponctuellement (manuel
quand vous arrêtez Navidrome ou via cron `--auto-stop`).

### Via l'interface web

`/lastfm/import` propose les deux étapes côte à côte. Le compteur
du buffer (« N scrobbles en attente ») est aussi exposé sur le
**dashboard** (card santé), avec un lien direct.

#### Section 1 — Récupérer depuis Last.fm

- Identifiant Last.fm + API key. Les deux peuvent venir de
  l'environnement via `LASTFM_USER` (pré-remplit le champ) et
  `LASTFM_API_KEY` (utilisée si le champ est laissé vide).
- Filtres `date_min` / `date_max` optionnels.
- Limite de sécurité (max scrobbles, défaut 5 000) pour éviter les
  timeouts HTTP.
- Case **Dry-run** (parcourir l'API Last.fm sans rien écrire dans
  le buffer — utile pour vérifier la connectivité).

Le re-fetch de la même fenêtre est **idempotent** : la contrainte
unique sur `(lastfm_user, played_at, artist, title)` rejette les
doublons et le rapport remonte un compteur `already_buffered`.

#### Section 2 — Traiter le buffer

Affiche le compteur du buffer + l'état du conteneur Navidrome.
Bouton « ▶ Traiter le buffer » disponible une fois Navidrome
arrêté (pré-flight via `NavidromeContainerManager`). Redirige vers
le détail du run `lastfm-process` qui liste l'audit par-track
(inserted / duplicate / unmatched / skipped) avec filtre par statut.

### Via la commande CLI

```bash
# Étape 1 : peut tourner Navidrome up
php bin/console app:lastfm:import [<lastfm-user>] [--api-key=YOUR_KEY] \
    [--date-min=YYYY-MM-DD] [--date-max=YYYY-MM-DD] \
    [--dry-run] [--max-scrobbles=N]

# Étape 2 : Navidrome doit être arrêté
php bin/console app:lastfm:process [--dry-run] [--limit=N] \
    [--tolerance=60] [--force] [--auto-stop]
```

L'API key Last.fm s'obtient gratuitement sur
<https://www.last.fm/api/account/create>. Elle peut aussi être passée via
la variable d'environnement `LASTFM_API_KEY`. De même, le username peut
être omis si `LASTFM_USER` est défini dans l'environnement.

`--auto-stop` (sur `app:lastfm:process` uniquement, comme pour
`app:lastfm:rematch`) orchestre stop → process → restart de
Navidrome via Docker — utile pour les runs cron unattended.

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
   0bis. **Cache de résolution** (`lastfm_match_cache`) : avant de
      relancer la cascade, le matcher consulte une table de
      mémoïsation. Hit positif → réponse immédiate. Hit négatif
      non-stale → unmatched immédiat (on évite l'API Last.fm). Les
      négatifs expirent au bout de `LASTFM_MATCH_CACHE_TTL_DAYS` jours
      (défaut 30, 0 = jamais), purgés au démarrage de chaque import
      / rematch. Les positifs sont éternels et invalidés par les
      mutations d'alias (track ou artiste). CLI
      `bin/console app:lastfm:cache:clear [--negative-only]` pour
      vider à la main.
   1. **MusicBrainz ID** si Last.fm le fournit (le plus fiable) ;
   2. **Triplet** `(artist, title, album)` normalisé — départage les
      morceaux qui existent sur plusieurs albums (single + version
      album + compilation) ;
   3. **Couple** `(artist, title)` normalisé, avec tie-break
      `album_artist = artist` puis `id ASC` ;
   4. **Last.fm `track.getInfo`** : si la cascade locale échoue, on
      interroge Last.fm pour récupérer (a) un MBID officiel quand le
      scrobble n'en avait pas, (b) une graphie corrigée du couple
      `(artist, title)` via `autocorrect=1`. Réutilise `LASTFM_API_KEY`.
      Les résultats — positifs comme négatifs — passent par le cache
      de résolution, donc on n'appelle l'API qu'**une fois** par
      couple distinct.
   5. **Fallback fuzzy** Levenshtein artist+title (opt-in via
      `LASTFM_FUZZY_MAX_DISTANCE`, défaut 0 = désactivé). **Recommandé
      pour les imports one-shot** : passer à `2` rattrape les typos
      type `Du riiechst so gut` → `Du riechst so gut` ou
      `Tchaïkovski` → `Tchaikovsky` avec très peu de faux-positifs.
      Coûteux : O(N) par scrobble unmatched — acceptable pour un
      import manuel, à laisser à `0` sur de très grosses libs.

   La normalisation utilisée à toutes les étapes : lowercase + trim
   + décomposition Unicode NFKD + strip des diacritiques (Beyoncé ↔
   Beyonce) + strip de la ponctuation (AC/DC ↔ ACDC) + collapse des
   espaces. Les helpers `stripFeaturedArtists()` /
   `stripFeaturingFromTitle()` / `stripVersionMarkers()` retirent en
   plus les suffixes parasites côté Last.fm (`feat. X`, `(Radio
   Edit)`, `- Remastered 2011`, `(Live at …)`, `(Acoustic)`, etc.).
3. **Déduplication** : un scrobble n'est pas réinséré s'il existe déjà
   dans la table `scrobbles` une ligne avec le même `media_file_id` et
   un `submission_time` à ±`--tolerance` secondes (60 par défaut, option
   sur `app:lastfm:process`). Cela absorbe les petits décalages
   d'horloge entre clients de scrobble.
4. **Rapport final** : compteurs `considered / inserted / duplicates /
   unmatched / skipped` (sur le run `lastfm-process`). Le détail du
   run liste les morceaux par statut, avec filtre par défaut sur
   `unmatched` quand il y en a — pratique pour identifier en priorité
   les morceaux à ajouter dans la bibliothèque Navidrome (ou à mapper
   via un alias).

### Pré-requis

- **Navidrome ≥ 0.55** (la table `scrobbles` doit exister, sinon
  `app:lastfm:process` échoue avec un message explicite).
- **Accès en écriture** sur la base SQLite Navidrome pour
  `app:lastfm:process` (la phase fetch n'a pas besoin d'écrire). Si
  vous lancez les commandes depuis le conteneur Docker du tool, montez
  le fichier en read-write — par exemple :
  ```bash
  docker run --rm -it \
      -v /srv/navidrome/data/navidrome.db:/data/navidrome.db \
      -e NAVIDROME_DB_PATH=/data/navidrome.db \
      -e LASTFM_API_KEY=... \
      -e APP_SECRET=... -e APP_AUTH_USER=admin -e APP_AUTH_PASSWORD=... \
      -e NAVIDROME_USER=admin -e NAVIDROME_PASSWORD=... \
      ghcr.io/kgaut/navidrome-tools:latest \
      php bin/console app:lastfm:import myuser
  # puis, Navidrome arrêté :
  ghcr.io/kgaut/navidrome-tools:latest \
      php bin/console app:lastfm:process --auto-stop
  ```
- Sous Lando : `lando symfony app:lastfm:import myuser --api-key=...`
  puis `lando symfony app:lastfm:process` fonctionnent directement
  (la DB Navidrome bind-mountée est en RW par défaut).

> **Backup automatique avant chaque écriture.** Quand vous lancez
> `app:lastfm:process --auto-stop` ou `app:lastfm:rematch --auto-stop`,
> le tool snapshote `navidrome.db` (+ siblings `-wal`/`-shm`) en
> `<dbPath>.backup-<unix_ts>` **avant** d'écrire. Rétention configurable
> via `NAVIDROME_DB_BACKUP_RETENTION` (défaut 3 snapshots). Si quoi que
> ce soit tourne mal et que Navidrome refuse de redémarrer, restauration
> en une commande :
>
> ```bash
> # Lister les backups disponibles (du plus ancien au plus récent)
> ls -lh /srv/navidrome/data/navidrome.db.backup-*
>
> # Rollback : remplacer la DB par le dernier backup connu sain
> cp /srv/navidrome/data/navidrome.db.backup-<unix_ts> /srv/navidrome/data/navidrome.db
> docker compose start navidrome
> ```

### Exemples

```bash
# Fetch dry-run (vérifie la connectivité API sans toucher au buffer) :
lando symfony app:lastfm:import myuser --api-key=XXX --dry-run

# Fetch de toute l'année 2024 dans le buffer :
lando symfony app:lastfm:import myuser --api-key=XXX \
    --date-min=2024-01-01 --date-max=2025-01-01

# Vidange du buffer dans Navidrome avec stop/start automatique :
lando symfony app:lastfm:process --auto-stop

# Process dry-run (matching + comptage, ne touche ni Navidrome ni le buffer) :
lando symfony app:lastfm:process --dry-run
```

## Alias d'artistes (synonymes)

Quand un artiste a été **renommé** (« La Ruda Salska » → « La Ruda »),
ou existe sous plusieurs **variantes** (romanisations, conventions
« The X » / « X, The »), Last.fm peut envoyer le nom historique alors
que Navidrome utilise le nom canonique. Plutôt que de créer un alias
manuel par track, la page **`/lastfm/artist-aliases`** (menu Last.fm
→ Alias artistes) gère ces synonymes au niveau artiste : un seul
alias `source → cible` couvre tous les morceaux.

Le matcher (`App\LastFm\ScrobbleMatcher`) consulte la table
**après** l'alias track-level (qui garde la priorité absolue) mais
**avant** la cascade MBID / triplet / couple : il réécrit le nom
d'artiste dans le `LastFmScrobble` puis laisse les heuristiques
habituelles tourner.

Un bouton « 🎭 Aliaser artiste » apparaît sur `/lastfm/unmatched`
pour créer rapidement un alias depuis un scrobble non matché.

Une fois l'alias créé, lancez **« Re-tenter le matching cumulé »**
(cf. ci-dessous) pour ré-essayer rétrospectivement tous les
scrobbles concernés.

## Liste cumulée des unmatched

La page **`/lastfm/unmatched`** (menu Last.fm → Unmatched (titres))
liste tous les scrobbles non matchés sur l'ensemble des imports
passés, agrégés par `(artiste, titre, album)` avec compteur de
scrobbles. Filtres en GET sur `artist`, `title`, `album` (substring
case-insensitive) et pagination 50 par page.

Pour chaque ligne :

- **« ✏️ Mapper »** ouvre le formulaire d'alias manuel pré-rempli avec
  l'artiste et le titre.
- **« 🎭 Aliaser artiste »** ouvre le formulaire d'alias d'artiste.
- **« + Lidarr »** envoie l'artiste à Lidarr (si configuré) et
  redirige sur la page après ajout.
- Le statut Lidarr (✓ déjà / ✗ absent / —) est affiché par ligne en
  cherchant l'artiste dans le catalogue Lidarr existant.

Deux vues complémentaires reliées par une barre d'onglets en haut de
chaque page :

- **`/lastfm/unmatched/artists`** (Top artistes unmatched) — agrège
  par artiste seul. Idéal pour repérer les artistes complètement
  absents de la collection : un seul clic « + Lidarr » couvre tous
  les morceaux manquants de l'artiste. Affiche pour chaque ligne le
  nombre de titres distincts non matchés et le total de scrobbles.
  Filtre GET sur `artist`.
- **`/lastfm/unmatched/albums`** (Top albums unmatched) — agrège par
  couple `(artiste, album)`. Les rows sans album renseigné sont
  écartées. Lidarr ne supporte pas l'ajout d'un album hors contexte
  artiste : le bouton « + Lidarr » ajoute donc l'artiste de l'album,
  le téléchargement de l'album dépend de la stratégie de monitoring
  Lidarr (`LIDARR_MONITOR`). Filtres GET sur `artist` et `album`.

Couplée à la commande `app:lastfm:rematch` (ci-dessous), ces pages
forment le workflow de récupération : identifier ce qui manque
→ créer alias / ajouter à Lidarr → relancer le rematch.

## Re-match des unmatched

Quand on ajoute des morceaux à Navidrome après un import (ou qu'on
déploie une nouvelle heuristique de matching), les scrobbles déjà
marqués `unmatched` dans `lastfm_import_track` peuvent être ré-essayés
**sans retélécharger l'historique Last.fm**. La cascade de matching
courante (alias → MBID → triplet → couple 4-paliers → fuzzy) est
ré-appliquée et les scrobbles trouvés sont insérés dans Navidrome
(idempotent : `scrobbleExistsNear` évite les doublons).

### CLI

```bash
php bin/console app:lastfm:rematch [--dry-run] [--run-id=N] [--limit=N] [--random]
```

`--dry-run` montre le rapport sans écrire. `--run-id=N` limite le
rematch aux unmatched du run #N. `--limit=0` (défaut) = pas de limite.
`--random` mélange l'ordre des unmatched avant d'appliquer `--limit`,
utile pour échantillonner un sous-ensemble représentatif (par défaut
les rows sont parcourues par id croissant, donc avec un `--limit` fixe
on retraiterait toujours les mêmes morceaux en tête de table).

### Web

- Sur `/history/{id}` d'un run `lastfm-process` ou `lastfm-import` :
  bouton « 🔁 Re-tenter le matching de ce run » si le run a au moins
  1 unmatched.
- Sur `/lastfm/import` : carte « Re-tenter le matching cumulé » avec
  le compteur global d'unmatched et un bouton de re-match global.

### Cron

Trois variables pilotent les schedules Last.fm :

- `LASTFM_FETCH_SCHEDULE` — ajoute `app:lastfm:import` au crontab
  (peut tourner Navidrome up).
- `LASTFM_PROCESS_SCHEDULE` — ajoute `app:lastfm:process --auto-stop`
  au crontab quand `NAVIDROME_CONTAINER_NAME` est défini (sinon sans
  auto-stop, à vous de stopper Navidrome).
- `LASTFM_REMATCH_SCHEDULE` — ajoute `app:lastfm:rematch --auto-stop`
  pour retraiter périodiquement les unmatched cumulés.

Toutes vides par défaut. Exemple raisonnable : fetch toutes les
heures, process + rematch chaque dimanche à 05:00.

⚠️ **Navidrome doit être arrêté** pendant le rematch (mêmes contraintes
que `app:lastfm:process` : écriture dans la table `scrobbles`).

## Connexion Last.fm authentifiée (optionnelle)

Certaines actions vers Last.fm — notamment la future synchronisation
loved ↔ starred (issue #23) — exigent une **session authentifiée**, pas
juste l'API key publique utilisée par l'import.

1. Récupérez la **API secret** sur la même page que la API key
   (<https://www.last.fm/api/account/create>) et configurez-la :
   ```env
   LASTFM_API_KEY=votre-api-key
   LASTFM_API_SECRET=votre-api-secret
   ```
2. Connectez-vous à Navidrome Tools puis allez sur `/lastfm/connect` :
   l'app vous redirige vers Last.fm pour consentement, puis vous renvoie
   sur `/settings` avec une session persistée localement (table
   `setting`, clés `lastfm.session_key` / `lastfm.session_user`).
3. La page `/settings` affiche un badge ✓/✗ et un bouton « Déconnecter »
   pour révoquer la session locale (la révocation côté Last.fm se fait
   sur <https://www.last.fm/settings/applications>).

L'**URL de callback** que Last.fm appelle après consentement est
construite automatiquement à partir de l'URL publique de votre instance.
En prod, votre déploiement Docker doit donc être derrière un domaine
résolvable par Last.fm (HTTPS recommandé).

## Sync loved ↔ starred

Une fois la connexion Last.fm faite (cf. ci-dessus), la page
`/lastfm/love-sync` propage les ajouts dans les deux sens entre les
morceaux ❤ Last.fm et les morceaux ★ Navidrome :

- **lf → nd** : un morceau loved sur Last.fm est starré dans Navidrome
  (s'il est résolu via MBID, alias manuel ou couple `(artist, title)`).
- **nd → lf** : un morceau starred dans Navidrome est loved sur Last.fm.
- **adds-only** : la v1 ne déstarre / délove jamais — la propagation
  des suppressions arrivera dans une issue séparée.

La sync est **idempotente** : un re-run immédiat ne fait rien tant que
les deux ensembles sont alignés.

### CLI

```bash
php bin/console app:lastfm:sync-loved [--direction=both|lf-to-nd|nd-to-lf] [--dry-run]
```

### Cron

Définissez `LASTFM_LOVE_SYNC_SCHEDULE` pour ajouter une ligne cron
automatiquement (ex. `0 4 * * *` pour tourner chaque nuit à 04:00). Vide
par défaut.

### Loved sans match

Les morceaux loved sur Last.fm qui n'ont pas de correspondance dans la
lib Navidrome apparaissent dans le rapport avec un bouton « ✏️ Mapper »
qui pré-remplit le formulaire d'alias manuel `/lastfm/aliases/new`.

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

## Tracks sans MBID

La page **`/tagging/missing-mbid`** (menu **Tagging**) liste les
morceaux Navidrome dont les colonnes `mbz_track_id` ET
`mbz_recording_id` sont vides. Sans MBID, le palier le plus fiable de
la cascade de matching Last.fm est inutilisable, et l'outil retombe
sur les paliers (artiste, titre, album) + fuzzy, plus fragiles.

L'architecture est volontairement read-only : navidrome-tools
**n'écrit jamais** dans tes fichiers audio (le volume `/music` peut
rester monté `:ro`). Le workflow est :

1. **Audit** sur la page : filtres artiste/album, pagination, voir
   les chemins absolus.
2. **Export CSV** (bouton « ⬇ Export CSV ») : télécharge la liste
   des chemins (id, path, artist, album, title…) que tu pipes dans
   un tagger sur la machine où ton dossier de musique est en
   lecture/écriture. Exemples :
   - **beets** :
     ```bash
     # extrait la colonne path et nourris beet
     tail -n +2 missing-mbid-2026-05-02.csv \
       | awk -F'"' '{print $4}' \
       | xargs -d '\n' beet import -A --quiet
     ```
   - **MusicBrainz Picard** : ouvre Picard, drag-and-drop le dossier
     de musique, lance « Scan » puis « Save ».
3. **Rescan Navidrome** (bouton « ↻ Rescan Navidrome ») : déclenche
   `startScan` via Subsonic une fois le tagging fini. Les nouveaux
   MBIDs apparaissent dans `media_file.mbz_track_id` sans attendre
   le scan planifié. Logué dans `/history` sous le type
   `navidrome-rescan`.

Une card « Tracks sans MBID » sur le dashboard affiche le compteur
courant et un raccourci vers la page.

### Queue beets (intégration semi-automatique)

Si tu préfères ne pas copier-coller le CSV à chaque fois, configure
`BEETS_QUEUE_PATH` (ex. `/shared/beets-queue.txt`). La page expose
alors un bouton **« 📋 Pousser dans la queue beets »** qui appendit
les chemins filtrés dans ce fichier sous `flock` (sûr en
concurrence). Côté hôte beets, monter le même volume et lancer un
cron qui consomme la queue, par exemple :

```bash
# /etc/cron.d/beets-queue : toutes les 15 min
*/15 * * * * beets   ( flock -x 9 ; \
   [ -s /shared/beets-queue.txt ] || exit 0 ; \
   mv /shared/beets-queue.txt /shared/beets-queue.processing ; \
 ) 9>/shared/beets-queue.lock && \
 beet import -A --quiet $(cat /shared/beets-queue.processing) && \
 rm /shared/beets-queue.processing
```

Avec ce pattern, navidrome-tools ne touche **que** le fichier de
queue (RW), `/music` reste `:ro`. Le push est tracé dans
`/history` (type `beets-queue-push`) et le bandeau de la page
affiche la taille courante de la queue.

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

Le dashboard (`/`) affiche en plus un bloc « Derniers runs » avec les
10 dernières entrées (tous types confondus) pour repérer en un coup
d'œil les erreurs récentes, avec un lien direct vers la page
complète.

Une commande `app:history:purge` supprime les entrées plus vieilles que
`RUN_HISTORY_RETENTION_DAYS` (défaut 90). À planifier dans votre
crontab unix (cf. la section [Lancement des jobs récurrents](#lancement-des-jobs-récurrents)).

## Widget Homepage (gethomepage)

Endpoint JSON `/api/status` consommable par le widget
[Custom API](https://gethomepage.dev/widgets/services/customapi/) de
[Homepage](https://gethomepage.dev/). Sert aussi de healthcheck Docker.

Deux modes d'accès :

| Sans token (no-auth)                                     | Avec token (`HOMEPAGE_API_TOKEN`)                                  |
|----------------------------------------------------------|--------------------------------------------------------------------|
| `GET /api/status`                                        | `GET /api/status?token=…` ou `Authorization: Bearer …`             |
| Payload minimal `{status, navidrome_db}`                 | Payload enrichi : compteurs, dernier run, statut conteneur         |
| Codes HTTP 200 (ok) / 503 (degraded) — Docker friendly   | Code HTTP 200 ; 401 si token erroné, 404 si feature désactivée     |

### Activer le mode enrichi

Générer un token et l'injecter dans l'environnement du conteneur web :

```bash
openssl rand -hex 32                  # copier dans HOMEPAGE_API_TOKEN
```

Puis configurer le widget Homepage (`services.yaml`) :

```yaml
- Navidrome Tools:
    icon: navidrome.png
    href: https://navidrome-tools.example.com
    widget:
      type: customapi
      url: https://navidrome-tools.example.com/api/status?token={{HOMEPAGE_VAR_NAVIDROME_TOOLS_TOKEN}}
      refreshInterval: 60000
      mappings:
        - field: scrobbles_total
          label: Scrobbles
          format: number
        - field: unmatched_total
          label: Unmatched
          format: number
        - field: { last_run: status }
          label: Dernier run
        - field: { last_run: started_at }
          label: À
          format: relativeDate
```

Et déclarer la variable Homepage côté docker-compose :

```yaml
environment:
  HOMEPAGE_VAR_NAVIDROME_TOOLS_TOKEN: ${HOMEPAGE_VAR_NAVIDROME_TOOLS_TOKEN}
```

### Payload enrichi

```json
{
  "status": "ok",
  "navidrome_db": true,
  "scrobbles_total": 142387,
  "unmatched_total": 312,
  "missing_mbid": 47,
  "navidrome_container": "running",
  "last_run": {
    "type": "lastfm-import",
    "reference": "me",
    "label": "Last.fm import (me)",
    "status": "success",
    "started_at": "2026-05-03T08:00:01+00:00",
    "finished_at": "2026-05-03T08:03:05+00:00",
    "duration_ms": 184230
  }
}
```

`navidrome_container` reprend l'enum `ContainerStatus` :
`disabled` / `running` / `stopped` / `notfound` / `unknown`. `last_run`
vaut `null` quand `run_history` est vide.

### Healthcheck Docker

Sans HOMEPAGE_API_TOKEN, l'endpoint reste un healthcheck pratique :

```yaml
healthcheck:
  test: ["CMD", "curl", "-fsS", "http://localhost:8080/api/status"]
  interval: 30s
  timeout: 5s
  retries: 3
```

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

**En déploiement Docker**, il n'est pas nécessaire de rebuilder
l'image : il suffit de bind-mounter un dossier hôte sur `/app/plugins`
(namespace `App\Plugin\`) et d'y déposer ses classes. L'autoload et le
cache Symfony sont régénérés à chaque démarrage du conteneur. Détails et
exemple complet dans [`docs/PLUGINS.md`](docs/PLUGINS.md#plugins-custom-en-déploiement-docker).

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

## Gestion des playlists existantes

Page `/playlists` (lien « Playlists » dans la barre de nav) : liste
toutes les playlists Navidrome avec leur métadonnées (nombre de
morceaux, durée, dates de création/modification, owner, public/privé).
Cases à cocher + bouton « Supprimer la sélection » pour la suppression
en masse. Bouton « M3U » par ligne pour télécharger la playlist au
format M3U Extended (lisible par VLC / mpv / foobar2000).

Page détail `/playlists/{id}` : affiche le contenu de la playlist
(titre, artiste, album, durée, play count, statut starred ★) et permet :

- **Renommer** la playlist (`updatePlaylist.view`)
- **Dupliquer** la playlist sous le nom « X (copie) »
- **Supprimer** la playlist (avec nettoyage automatique du
  `lastSubsonicPlaylistId` des `PlaylistDefinition` rattachées)
- **Star/unstar individuel** d'un morceau (icône ★/☆)
- **Bulk star/unstar** : « ★ Tout starrer » / « ☆ Tout dé-starrer »,
  un seul appel API
- **Retirer un morceau** de la playlist (`updatePlaylist.view` avec
  `songIndexToRemove`)
- **Détecter les morceaux morts** (présents dans la playlist mais
  absents de `media_file`) et les purger en un clic
- **Statistiques** : durée totale, nombre starré, % jamais joués, top
  10 artistes, top 10 albums, distribution par année (mini bar chart)
- **Exporter en M3U**

Toutes les écritures passent par l'API Subsonic — aucune écriture
directe dans la DB Navidrome (qui peut donc rester montée `:ro`).

L'export M3U est aussi disponible sur la prévisualisation des
définitions de playlist (`/playlist/{id}/preview` → bouton « Exporter
M3U »), pour récupérer la liste avant même de la créer côté Navidrome.

## Changelog

L'historique des évolutions est tenu dans [`CHANGELOG.md`](CHANGELOG.md)
au format [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/).

## Licence

MIT.
