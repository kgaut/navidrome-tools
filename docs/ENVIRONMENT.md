# Variables d'environnement

Toutes les variables consommées par Navidrome Tools, groupées par
fonctionnalité. Une variable vide vaut « feature désactivée » pour
toutes les intégrations optionnelles (Lidarr, Last.fm, Homepage,
pilotage conteneur, queue beets, …) — le reste de l'app continue de
tourner.

> **Où les éditer ?**
>
> - **Prod Docker** : variables d'environnement du conteneur
>   `navidrome-tools-web` dans votre `docker-compose.yml` (cf.
>   [`docker-compose.example.yml`](../docker-compose.example.yml)).
> - **Dev local hors Lando** : `.env.local` à la racine du projet
>   (copie de [`.env.dist`](../.env.dist), non versionnée).
> - **Dev Lando** : section `environment` de votre `.lando.yml`
>   (copie de [`.lando.yml.dist`](../.lando.yml.dist), non versionnée).
> - **Tests** : `phpunit.xml.dist` (valeurs neutres, ne pas y stocker
>   de credentials réels).

Tableau récapitulatif rapide, suivi des sections détaillées avec
exemples et contexte.

## Récapitulatif par catégorie

| Domaine                            | Variables                                                                                       |
|------------------------------------|-------------------------------------------------------------------------------------------------|
| [Symfony / app](#symfony--app)     | `APP_SECRET`, `APP_ENV`, `APP_MODE`, `APP_TIMEZONE`, `APP_VERSION`, `APP_AUTH_USER`, `APP_AUTH_PASSWORD`, `DATABASE_URL`, `DEFAULT_URI` |
| [Source Navidrome](#source-navidrome) | `NAVIDROME_DB_PATH`, `NAVIDROME_URL`, `NAVIDROME_USER`, `NAVIDROME_PASSWORD`                  |
| [Pilotage conteneur](#pilotage-du-conteneur-navidrome) | `NAVIDROME_CONTAINER_NAME`, `NAVIDROME_STOP_TIMEOUT_SECONDS`, `NAVIDROME_STOP_WAIT_CEILING_SECONDS`, `NAVIDROME_DB_BACKUP_RETENTION` |
| [Last.fm](#lastfm)                 | `LASTFM_API_KEY`, `LASTFM_API_SECRET`, `LASTFM_USER`, `LASTFM_PAGE_DELAY_SECONDS`, `LASTFM_FUZZY_MAX_DISTANCE`, `LASTFM_MATCH_CACHE_TTL_DAYS` |
| [Lidarr](#lidarr)                  | `LIDARR_URL`, `LIDARR_API_KEY`, `LIDARR_ROOT_FOLDER_PATH`, `LIDARR_QUALITY_PROFILE_ID`, `LIDARR_METADATA_PROFILE_ID`, `LIDARR_MONITOR` |
| [Historique des runs](#historique-des-runs) | `RUN_HISTORY_RETENTION_DAYS`                                                            |
| [Cover art](#cover-art)            | `COVERS_CACHE_PATH`                                                                             |
| [Queue beets](#queue-beets)        | `BEETS_QUEUE_PATH`                                                                              |
| [Widget Homepage](#widget-homepage-gethomepagedev) | `HOMEPAGE_API_TOKEN`                                                            |
| [Notifications](#notifications)    | `NOTIFY_DRIVERS`, `NOTIFY_ON`, `NOTIFY_GOTIFY_URL`, `NOTIFY_GOTIFY_TOKEN`, `NOTIFY_GOTIFY_PRIORITY`, `NOTIFY_SLACK_WEBHOOK_URL`, `NOTIFY_DISCORD_WEBHOOK_URL`, `NOTIFY_PUSHOVER_TOKEN`, `NOTIFY_PUSHOVER_USER` |

## Symfony / app

| Variable             | Défaut       | Description                                                                                                   |
|----------------------|--------------|---------------------------------------------------------------------------------------------------------------|
| `APP_SECRET`         | (obligatoire)| Secret Symfony (32 caractères hex). Générer avec `openssl rand -hex 32`.                                      |
| `APP_ENV`            | `prod`       | `prod` / `dev` / `test`. Met l'app en mode debug + erreurs verbeuses en `dev`.                                |
| `APP_MODE`           | `web`        | `web` = serveur FrankenPHP exposé sur le port 8080. `cli` = exécution one-shot d'une commande Symfony.        |
| `APP_TIMEZONE`       | `UTC`        | Fuseau d'affichage appliqué à PHP **et** au filtre Twig `\|date`. Les timestamps restent stockés en UTC.      |
| `APP_VERSION`        | `dev`        | Suffixe affiché dans le `<title>` (ex. `Dashboard - Navidrome Tools 0.1.0`). Bake automatique côté CI.        |
| `APP_AUTH_USER`      | (obligatoire)| Identifiant pour se connecter à l'UI du tool. Un seul utilisateur supporté.                                   |
| `APP_AUTH_PASSWORD`  | (obligatoire)| Mot de passe associé (hashé en mémoire au boot, jamais persisté).                                             |
| `DATABASE_URL`       | (sqlite var/)| DSN Doctrine pour la DB locale du tool. Défaut : SQLite dans `var/data.db`.                                   |
| `DEFAULT_URI`        | `http://localhost` | Base URL utilisée pour générer des URLs hors contexte HTTP (ex. liens dans les notifs, exports M3U).    |

> `APP_VERSION` est normalement bakée au build Docker via
> `ARG APP_VERSION` (la CI passe le tag git sur un push tagué, ou
> `<branch>-<sha7>` sinon). Ne l'override en compose que si vous
> rebuildez localement.

## Source Navidrome

Toutes obligatoires : ce sont les credentials qui permettent au tool
de lire la lib Navidrome (côté SQLite **et** côté API Subsonic).

| Variable             | Défaut             | Description                                                                                            |
|----------------------|--------------------|--------------------------------------------------------------------------------------------------------|
| `NAVIDROME_DB_PATH`  | `/data/navidrome.db` (compose) | Chemin du fichier SQLite Navidrome **dans le conteneur**. À bind-mounter `:ro` en prod sauf si vous utilisez `app:lastfm:process` / `rematch` (qui écrivent dans `scrobbles`). |
| `NAVIDROME_URL`      | `http://navidrome:4533` | URL HTTP(S) de Navidrome (sans slash final). Utilisée pour l'API Subsonic.                       |
| `NAVIDROME_USER`     | `admin`            | Utilisateur Navidrome dont on lit les écoutes (filtrage de `scrobbles.user_id`) et qui possède les playlists créées par le tool. |
| `NAVIDROME_PASSWORD` | (obligatoire)      | Mot de passe Navidrome correspondant.                                                                  |

## Pilotage du conteneur Navidrome

Activé si `NAVIDROME_CONTAINER_NAME` est non vide. Permet : card
dashboard avec boutons Start/Stop, pré-flight automatique sur les
commandes qui écrivent dans Navidrome, et `--auto-stop` pour les
runs cron unattended. Requiert le mount `/var/run/docker.sock` dans
le conteneur `navidrome-tools-web`.

| Variable                                | Défaut | Description                                                                                                |
|-----------------------------------------|--------|------------------------------------------------------------------------------------------------------------|
| `NAVIDROME_CONTAINER_NAME`              | (vide) | Nom (ou ID) du conteneur Navidrome dans la stack compose. Vide = feature désactivée.                       |
| `NAVIDROME_STOP_TIMEOUT_SECONDS`        | `60`   | Fenêtre passée à `docker stop -t` lors d'un `--auto-stop`. Doit excéder le checkpoint WAL SQLite — le défaut Docker (10s) peut SIGKILL en plein flush et corrompre `navidrome.db` (#118). |
| `NAVIDROME_STOP_WAIT_CEILING_SECONDS`   | `30`   | Après `docker stop`, polling de `docker inspect` jusqu'à `Running=false`, plafonné à cette durée. Ceinture-bretelles contre un SIGTERM handler qui traîne. |
| `NAVIDROME_DB_BACKUP_RETENTION`         | `3`    | Nombre de snapshots `<dbPath>.backup-<unix_ts>` conservés. Avant chaque action `--auto-stop`, la DB SQLite (+ siblings `-wal`/`-shm`) est copiée — un `cp` rétablit l'état antérieur. `0` = pas de purge. |

## Last.fm

Tout vide par défaut — l'intégration Last.fm (import des scrobbles,
sync loved↔starred) reste dispo via formulaire, mais l'API key
devra alors être saisie à chaque appel.

| Variable                       | Défaut | Description                                                                                                                                       |
|--------------------------------|--------|---------------------------------------------------------------------------------------------------------------------------------------------------|
| `LASTFM_API_KEY`               | (vide) | API key publique. Création gratuite : <https://www.last.fm/api/account/create>. Fallback pour `/lastfm/import` et `app:lastfm:import` quand non passée explicitement. |
| `LASTFM_API_SECRET`            | (vide) | API secret (même page). **Requis** pour `/lastfm/connect` et la sync loved↔starred (signature `auth.getSession` / `track.love`).                  |
| `LASTFM_USER`                  | (vide) | Username pré-rempli dans le formulaire `/lastfm/import` et utilisé comme fallback CLI.                                                            |
| `LASTFM_PAGE_DELAY_SECONDS`    | `10`   | Pause entre deux pages consécutives de `user.getRecentTracks`. `0` désactive la pause (déconseillé sur de gros historiques).                       |
| `LASTFM_FUZZY_MAX_DISTANCE`    | `0`    | Distance Levenshtein max pour le fallback fuzzy de matching (artiste + titre). `0` = désactivé. **`2` recommandé pour les imports one-shot** (rattrape les typos sans excès de faux-positifs). Coûteux : O(N) par scrobble unmatched. |
| `LASTFM_MATCH_CACHE_TTL_DAYS`  | `30`   | TTL (jours) des entrées **négatives** du cache de résolution `lastfm_match_cache`. Les positives sont éternelles (invalidées par les mutations d'alias). `0` = ne purge jamais (purge manuelle via `app:lastfm:cache:clear`). |

## Lidarr

Vide = bouton « + Lidarr » masqué partout. Voir
[`LIDARR.md`](LIDARR.md) pour le workflow.

| Variable                     | Défaut   | Description                                                                                                 |
|------------------------------|----------|-------------------------------------------------------------------------------------------------------------|
| `LIDARR_URL`                 | (vide)   | Base URL de Lidarr (ex. `http://lidarr:8686`). Vide = intégration off.                                       |
| `LIDARR_API_KEY`             | (vide)   | API key Lidarr (`Settings → General → Security`).                                                           |
| `LIDARR_ROOT_FOLDER_PATH`    | `/music` | Chemin où Lidarr place les nouveaux artistes (doit exister dans Lidarr).                                    |
| `LIDARR_QUALITY_PROFILE_ID`  | `1`      | Id d'un Quality Profile Lidarr existant.                                                                    |
| `LIDARR_METADATA_PROFILE_ID` | `1`      | Id d'un Metadata Profile Lidarr existant.                                                                   |
| `LIDARR_MONITOR`             | `all`    | Stratégie de monitoring d'album : `all`, `future`, `missing`, `existing`, `first`, `latest`, `none`.        |

## Historique des runs

| Variable                       | Défaut | Description                                                                                                                            |
|--------------------------------|--------|----------------------------------------------------------------------------------------------------------------------------------------|
| `RUN_HISTORY_RETENTION_DAYS`   | `90`   | Rétention des lignes de la table `run_history`. La commande `app:history:purge` supprime les rows plus vieilles. Voir [`CRON.md`](CRON.md). |

## Cover art

| Variable             | Défaut         | Description                                                                                                                |
|----------------------|----------------|----------------------------------------------------------------------------------------------------------------------------|
| `COVERS_CACHE_PATH`  | `/app/var/covers` | Cache disque des miniatures album/artiste, alimenté par le proxy `/cover/*`. En prod, monter un volume Docker dédié.    |

## Queue beets

| Variable             | Défaut | Description                                                                                                                |
|----------------------|--------|----------------------------------------------------------------------------------------------------------------------------|
| `BEETS_QUEUE_PATH`   | (vide) | Chemin (côté conteneur) d'un fichier dans lequel l'app appendit les chemins absolus des tracks à tagger via `/tagging/missing-mbid`. Vide = bouton « Pousser dans la queue beets » masqué. Voir [`TAGGING.md`](TAGGING.md#queue-beets-intégration-semi-automatique). |

## Widget Homepage (gethomepage.dev)

| Variable             | Défaut | Description                                                                                                                |
|----------------------|--------|----------------------------------------------------------------------------------------------------------------------------|
| `HOMEPAGE_API_TOKEN` | (vide) | Bearer token consommé par `/api/status` pour le mode **enrichi** (compteurs, dernier run, statut conteneur). Vide = seul le mode healthcheck no-auth est servi. Générer avec `openssl rand -hex 32`. Voir [`HOMEPAGE.md`](HOMEPAGE.md). |

## Notifications

Greffe `App\Notifier\Notifier` sur `RunHistoryRecorder` — pousse une
notification à chaque fin de run cron. Vide partout = feature
désactivée. Voir [`NOTIFICATIONS.md`](NOTIFICATIONS.md) pour le détail
des drivers et la page de test sur `/settings`.

| Variable                       | Défaut  | Description                                                                                                       |
|--------------------------------|---------|-------------------------------------------------------------------------------------------------------------------|
| `NOTIFY_DRIVERS`               | (vide)  | CSV des canaux actifs : `gotify`, `slack`, `discord`, `pushover`. Plusieurs valeurs autorisées (broadcast).        |
| `NOTIFY_ON`                    | `error` | `error` (échecs seulement) ou `all` (succès aussi).                                                               |
| `NOTIFY_GOTIFY_URL`            | (vide)  | URL de base de votre instance Gotify (ex. `https://gotify.example.com`).                                          |
| `NOTIFY_GOTIFY_TOKEN`          | (vide)  | Application token Gotify.                                                                                         |
| `NOTIFY_GOTIFY_PRIORITY`       | `5`     | Priorité par défaut (1..10). Bumpée à au moins 8 sur erreur.                                                      |
| `NOTIFY_SLACK_WEBHOOK_URL`     | (vide)  | Webhook incoming Slack (Apps → Incoming Webhooks).                                                                |
| `NOTIFY_DISCORD_WEBHOOK_URL`   | (vide)  | Webhook channel Discord (Server Settings → Integrations → Webhooks). Content tronqué à 1900 chars (cap Discord 2000). |
| `NOTIFY_PUSHOVER_TOKEN`        | (vide)  | Application token Pushover.                                                                                       |
| `NOTIFY_PUSHOVER_USER`         | (vide)  | User ou group key Pushover.                                                                                       |

## Vérifier la configuration

Une fois le conteneur démarré, l'UI expose plusieurs sondes :

- `/api/status` (no-auth) — code HTTP 200 si la DB Navidrome est
  accessible et la DB locale du tool migrée, sinon 503. Pratique en
  `healthcheck` Docker.
- `/api/status?token=…` (mode enrichi) — payload JSON avec compteurs
  + dernier run + statut conteneur Navidrome. Utile pour valider
  l'auth Homepage et l'observabilité.
- Card « Conteneur Navidrome » sur le dashboard si
  `NAVIDROME_CONTAINER_NAME` est renseigné (UP/DOWN + boutons).

## Ajouter une nouvelle variable

Pour rester cohérent avec le reste du code, toute nouvelle variable
doit être déclarée dans **les cinq endroits** listés en haut, plus
dans `config/services.yaml` (sous `parameters:`) si elle est
consommée par un service via `%env(...)%`. Cf. [`CLAUDE.md`](../CLAUDE.md#9-quand-tu-fais-évoluer-ce-projet)
pour la checklist complète.
