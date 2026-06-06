# Navidrome Tools

Boîte à outils pour **importer son historique d'écoute Last.fm dans
[Navidrome](https://www.navidrome.org/)** (et accessoirement
[Strawberry](https://www.strawberrymusicplayer.org/)), synchroniser les
favoris (loved ↔ starred), et produire des statistiques d'écoute.

Le cœur du projet est un **moteur de matching** qui résout chaque scrobble
Last.fm `(artiste, titre, album, MBID)` vers une piste `media_file` de
Navidrome, puis insère les écoutes dans la table `scrobbles` de Navidrome (≥ 0.55)
pour que les compteurs de lecture reflètent l'historique Last.fm.

---

## Sommaire

- [Fonctionnalités](#fonctionnalités)
- [Stack technique](#stack-technique)
- [Architecture](#architecture)
- [Démarrage rapide (dev)](#démarrage-rapide-dev)
- [Déploiement (prod)](#déploiement-prod)
- [Configuration](#configuration)
- [Interface web](#interface-web)
- [Commandes console](#commandes-console)
- [Workflow type](#workflow-type)
- [Le matching en détail](#le-matching-en-détail)
- [Développement](#développement)

---

## Fonctionnalités

- **Récupération Last.fm** : import incrémental de l'historique de scrobbles.
- **Matching cascade** : MBID → triplet (artiste/titre/album) → couple
  (artiste/titre) avec normalisation accents/casse/ponctuation, strip
  `feat.` / version markers, `track.getInfo` (autocorrection), fuzzy
  Levenshtein optionnel.
- **Alias** manuels et **auto-générés** (track + artiste) pour rattraper les
  divergences de nommage que la cascade ne peut pas résoudre seule.
- **Sync Navidrome / Strawberry** : insertion des écoutes matchées, avec
  arrêt/redémarrage automatique du conteneur Navidrome, backup et
  `quick_check` SQLite avant écriture.
- **Favoris** : synchronisation bidirectionnelle loved (Last.fm) ↔ starred
  (Navidrome).
- **Statistiques** : top artistes/titres/albums, heatmap, diversité, streaks,
  charts par jour/semaine/mois.
- **Interface web** + **commandes CLI** (crontab) + **worker** asynchrone
  (Symfony Messenger).
- **Notifications** (Gotify / Slack / Discord / Pushover) sur succès/erreur.

---

## Stack technique

- **PHP 8.4** · **Symfony 7** (Console, Messenger, Doctrine ORM 3 / DBAL 4 /
  Migrations 3)
- **SQLite** : base outils locale (`var/data.db`) + base Navidrome
  (`navidrome.db`, montée en lecture seule en prod)
- **FrankenPHP 1** (image `dunglas/frankenphp:1-php8.4-alpine`, multi-arch)
- `APP_MODE=web|worker|cli` (cf. `docker/entrypoint.sh`)
- Qualité : PSR-12 (160 col), PHPStan niveau 6, PHPUnit — `composer ci`

---

## Architecture

```
src/
├── Command/        → commandes console (CLI / crontab)
├── Controller/     → interface web
├── LastFm/         → client API + cascade de matching (ScrobbleMatcher)
├── Navidrome/      → bridge DB SQLite, sync, backup
├── Strawberry/     → sync playcount, upload/download
├── Docker/         → arrêt/démarrage du conteneur Navidrome
├── Message/ + MessageHandler/  → jobs asynchrones (worker Messenger)
├── Service/        → AliasGenerator, stats, backup, run history…
├── Repository/     → accès Doctrine (scrobbles, sync, alias, cache…)
├── Entity/         → Scrobble, ScrobbleSync, LastFmAlias, … 
├── Notifier/       → drivers Gotify/Slack/Discord/Pushover
└── Security/       → EnvUserProvider (utilisateur unique via env)
```

---

## Démarrage rapide (dev)

Environnement Docker Compose (FrankenPHP + PHP 8.4 + SQLite), code monté en
volume.

```bash
# 1. (si absent) créer le .env de dev — le dépôt en fournit un avec des valeurs de dev
cp .env.dist .env

# 2. lancer web + worker
docker compose -f docker-compose.dev.yml up --build
```

- Application : http://localhost:8080 (login `admin` / `changeme`, cf. `.env`).
- Le premier démarrage installe `vendor/` puis applique les migrations Doctrine.
- Port personnalisé : `WEB_PORT=8081 docker compose -f docker-compose.dev.yml up`.
- UID hôte : `UID=$(id -u) GID=$(id -g) docker compose -f docker-compose.dev.yml up`.

Commandes courantes :

```bash
DC="docker compose -f docker-compose.dev.yml"
$DC exec web composer ci                      # phpcs + phpstan + phpunit
$DC exec web php bin/console <commande>        # console Symfony
$DC logs -f worker                             # suivre les jobs async
$DC down                                       # arrêter (var/data.db conservée dans le volume dev-var)
```

> **Note** : en dev, `/app/var` vit dans le volume Docker `dev-var` (pas dans le
> dépôt). Pour pointer vers une base Navidrome, renseignez `NAVIDROME_DB_PATH`
> dans `.env`. Voir `DEV.md` pour les détails.

---

## Déploiement (prod)

Image prébuildée : `ghcr.io/kgaut/navidrome-tools`. Trois rôles via `APP_MODE` :

| `APP_MODE` | Rôle |
|---|---|
| `web`    | serveur HTTP (FrankenPHP) + applique les migrations au boot |
| `worker` | consomme les jobs Symfony Messenger (sync asynchrones) |
| `cli`    | one-shot pour crontab (`php bin/console …`) |

Points d'attention (prod) :

- Monter `navidrome.db` en **lecture seule** (`:ro`) ; les commandes qui écrivent
  (sync) arrêtent d'abord le conteneur Navidrome (`NAVIDROME_CONTAINER_NAME` +
  accès au socket Docker).
- Ne **pas** partager `APP_CACHE_DIR` entre les conteneurs web et worker.
- `NAVIDROME_USER` doit correspondre au compte Navidrome réel (sinon les
  commandes de sync échouent avec « user not found »).
- `scripts/navidrome-sync.sh.example` : wrapper bash pour orchestrer un cycle
  complet en crontab.

---

## Configuration

Toutes les variables (copier `.env.dist` → `.env` en dev, ou passer en variables
d'environnement Docker en prod) :

### Application

| Variable | Défaut | Description |
|---|---|---|
| `APP_ENV` | `prod` | `prod` / `dev` / `test` |
| `APP_SECRET` | — | secret Symfony (`openssl rand -hex 32`) |
| `APP_TIMEZONE` | `UTC` | fuseau pour l'affichage des dates |
| `APP_MODE` | `web` | `web` / `worker` / `cli` |
| `APP_AUTH_USER` / `APP_AUTH_PASSWORD` | `admin` / — | identifiants de l'UI web |
| `DATABASE_URL` | `sqlite:///…/var/data.db` | base outils locale |
| `MESSENGER_TRANSPORT_DSN` | `doctrine://default?auto_setup=0` | transport des jobs |

### Navidrome

| Variable | Défaut | Description |
|---|---|---|
| `NAVIDROME_DB_PATH` | `/data/navidrome.db` | chemin de la base SQLite Navidrome |
| `NAVIDROME_URL` | `http://navidrome:4533` | URL de l'instance (covers, liens) |
| `NAVIDROME_USER` | `admin` | **compte Navidrome** dont on importe l'historique |
| `NAVIDROME_PASSWORD` | — | mot de passe (API Subsonic) |
| `NAVIDROME_CONTAINER_NAME` | — | nom du conteneur Docker à arrêter avant écriture (vide = check désactivé) |
| `NAVIDROME_STOP_TIMEOUT_SECONDS` | `60` | délai d'arrêt propre (checkpoint WAL) avant SIGKILL |
| `NAVIDROME_STOP_WAIT_CEILING_SECONDS` | `30` | attente max de confirmation d'arrêt |

### Last.fm

| Variable | Défaut | Description |
|---|---|---|
| `LASTFM_API_KEY` / `LASTFM_API_SECRET` | — | clés API (https://www.last.fm/api) |
| `LASTFM_USER` | — | utilisateur Last.fm par défaut |
| `LASTFM_PAGE_DELAY_SECONDS` | `10` | délai entre pages (rate limit) |
| `LASTFM_FUZZY_MAX_DISTANCE` | `0` | distance Levenshtein du matching fuzzy (0 = désactivé) |
| `LASTFM_MATCH_CACHE_TTL_DAYS` | `30` | durée de vie des entrées négatives du cache de matching |

### MusicBrainz

Utilisé par `app:aliases:musicbrainz` (lookup en ligne pour la génération d'alias).

| Variable | Défaut | Description |
|---|---|---|
| `MUSICBRAINZ_USER_AGENT` | — | UA contact-bearing exigé par le ToS MB (ex. `mon-projet/1.0 (mon@mail.tld)`) |
| `MUSICBRAINZ_BASE_URL` | `https://musicbrainz.org/ws/2/` | racine de l'API ; à changer pour viser un mirror ou un mbserver local |

### Strawberry / Backups / Historique / Notifications

| Variable | Défaut | Description |
|---|---|---|
| `STRAWBERRY_DB_PATH` | — | chemin de `strawberry.db` (vide = désactivé) |
| `BACKUP_RETENTION_DAYS` | `7` | rétention des backups |
| `RUN_HISTORY_RETENTION_DAYS` | `90` | rétention de l'historique des runs |
| `NOTIFY_DRIVERS` | — | drivers actifs (`gotify,slack,discord,pushover`) |
| `NOTIFY_ON` | `error` | `error` / `always` / `never` |
| `NOTIFY_GOTIFY_URL` / `…_TOKEN` / `…_PRIORITY` | — | Gotify |
| `NOTIFY_SLACK_WEBHOOK_URL` | — | Slack |
| `NOTIFY_DISCORD_WEBHOOK_URL` | — | Discord |
| `NOTIFY_PUSHOVER_TOKEN` / `…_USER` | — | Pushover |

---

## Interface web

Authentification par utilisateur unique (`APP_AUTH_USER` / `APP_AUTH_PASSWORD`).

| Route | Page |
|---|---|
| `/` | dashboard (état, compteurs, actions rapides) |
| `/stats`, `/lastfm/stats`, `/navidrome/stats` | statistiques (incluant la **disparité** Last.fm ↔ Navidrome sur `/navidrome/stats` : top mois et années avec le plus d'écoutes Last.fm sans équivalent Navidrome, bornés au premier scrobble Navidrome) |
| `/lastfm/import` | import d'historique Last.fm |
| `/navidrome/sync`, `/navidrome/rematch`, `/navidrome/unmatched` | matching/sync Navidrome |
| `/strawberry/*` | sync Strawberry (upload/download de la base) |
| `/lastfm/aliases`, `/lastfm/artist-aliases` | gestion des alias (piste / artiste) |
| `/navidrome/container/{start,stop}` | contrôle du conteneur Navidrome |
| `/history` | historique des runs |

---

## Commandes console

Toutes via `php bin/console <commande>`. Ajouter `--dry-run` (quand dispo) pour
simuler sans écrire, et `-v` pour plus de détail.

### Récupération Last.fm

#### `app:lastfm:fetch [user]`
Importe l'historique de scrobbles Last.fm dans la table locale `scrobbles`
(incrémental). Adapté au crontab.

| Option | Description |
|---|---|
| `user` (argument) | utilisateur Last.fm (défaut : `LASTFM_USER`) |
| `--api-key` | surcharge `LASTFM_API_KEY` |
| `--date-min` / `--date-max` | borne la fenêtre `YYYY-MM-DD` (date-max inclut tout le jour) |
| `--max-scrobbles` | plafond de scrobbles récupérés |
| `--dry-run` | n'écrit rien |

#### `app:lastfm:auth [user]`
Obtient et stocke une **session key** Last.fm (nécessaire pour écrire les
loves : `track.love` / `unlove`).

| Option | Description |
|---|---|
| `user` (argument) | utilisateur Last.fm |
| `--api-key` / `--api-secret` | surcharges des clés API |
| `--password` | mot de passe Last.fm (sinon demandé interactivement) |

### Matching & synchronisation

#### `app:scrobbles:sync-navidrome`
Matche les scrobbles en attente et insère les écoutes résolues dans la table
`scrobbles` de Navidrome. **Écrit dans navidrome.db** (Navidrome doit être
arrêté).

| Option | Description |
|---|---|
| `--dry-run` | matche sans écrire |
| `--limit` | nombre max de lignes traitées (`0` = illimité) |
| `--tolerance` | tolérance (s) pour la déduplication des écoutes (défaut `60`) |
| `--force` | outrepasse le pré-vol conteneur Navidrome |
| `--auto-stop` | arrête puis redémarre automatiquement le conteneur Navidrome |

#### `app:scrobbles:rematch`
Re-tente le matching des scrobbles **non-matchés** (remet `unmatched` → `pending`
puis relance le sync). Utile après ajout de pistes, création d'alias, ou
amélioration des heuristiques.

| Option | Description |
|---|---|
| `--target` / `-t` | `navidrome` (défaut) ou `strawberry` |
| `--dry-run` | reset + match sans écrire |
| `--limit` | nombre max de lignes (`0` = illimité) |
| `--force` | outrepasse le pré-vol conteneur Navidrome |
| `--auto-stop` | arrête/redémarre Navidrome automatiquement |

#### `app:aliases:generate`
**Génère automatiquement** des alias Last.fm → Navidrome à haute confiance pour
les scrobbles non-matchés. Lit Navidrome en lecture seule, n'écrit que les
tables d'alias (`lastfm_alias` / `lastfm_artist_alias`) et purge le cache de
matching concerné. **Idempotente.** Écarte les couples qu'un simple rematch
résoudrait déjà (statut périmé). Lancer `app:scrobbles:rematch` ensuite pour
appliquer.

Stratégies (chaque alias par la première qui aboutit) :

- `artist-mbid` — MBID artiste partagé (`scrobbles.mbid_artist` ↔
  `artist.mbz_artist_id`), nom Navidrome possédé différent → **alias artiste**.
- `album-mbid-exact` — `mbid_album` possédé + titre exact (normalisé) unique
  dans l'album → **alias piste**.
- `album-mbid-fuzzy` — idem + titre rapproché par Levenshtein dans l'album.
- `artist-fuzzy` — artiste possédé par son nom + titre fuzzy unique dans son
  catalogue.

| Option | Description |
|---|---|
| `--dry-run` | aperçu (table d'échantillon + résumé), n'écrit rien |
| `--target` / `-t` | jeu de non-matchés à scanner (`navidrome` par défaut) |
| `--no-artist-mbid` | désactive la stratégie alias artiste (MBID) |
| `--no-album-exact` | désactive album-MBID + titre exact |
| `--no-album-fuzzy` | désactive album-MBID + titre fuzzy |
| `--no-artist-fuzzy` | désactive artiste possédé + titre fuzzy |
| `--album-fuzzy-distance` | distance Levenshtein max (album, défaut `5`) |
| `--artist-fuzzy-distance` | distance Levenshtein max (artiste, défaut `2`) |
| `--limit` | nombre max de couples scannés (`0` = illimité) |

#### `app:aliases:musicbrainz`
Complète `app:aliases:generate` (offline, MBID-only) avec une passe **en ligne sur
MusicBrainz** : récupère, pour chaque artiste non-matché, son nom canonique et
ses aliases MB, et les confronte aux artistes possédés. Rattrape les cas que la
normalisation n'attrape pas (`Beatles, The` ↔ `The Beatles`, `Sigur Ros` ↔
`Sigur Rós`, `MPL` ↔ `Ma Pauvre Lucette`…), y compris quand Last.fm n'a jamais
envoyé d'MBID. Lit Navidrome en lecture seule, n'écrit que `lastfm_artist_alias`
et purge le cache de matching concerné.

Stratégie :

- intersecte les noms canoniques + aliases MB (score ≥ 80) avec les artistes
  possédés (`np_normalize`) ;
- **1 match lib unique → UNIQUE** → alias auto-appliqué ;
- **plusieurs matches → AMBIGUOUS** → skip silencieux, ou prompt avec `-i` ;
- **0 match → NO_MATCH** → simplement consigné dans le report.

Throttle obligatoire (MB exige ≈ 1 req/s par UA) ; UA contact-bearing
**requis** dans `MUSICBRAINZ_USER_AGENT` (cf. section *MusicBrainz* plus haut).
Lancer `app:scrobbles:rematch` ensuite pour appliquer les alias créés.

| Option | Description |
|---|---|
| `--target` / `-t` | `navidrome` (défaut) ou `strawberry` |
| `--dry-run` | aperçu (échantillon + résumé), n'écrit rien |
| `-i` / `--interactive` | prompt pour trancher les candidats ambigus |
| `--limit` | nombre max d'artistes à requêter (`0` = illimité) |
| `--rate-limit-ms` | délai entre requêtes MB (défaut `1100` ; warning sous `1000`) |

#### `app:scrobbles:sync-strawberry`
Synchronise les scrobbles en attente dans la base Strawberry (`playcount` /
`lastplayed`).

| Option | Description |
|---|---|
| `--dry-run` | matche sans écrire |
| `--limit` | nombre max de lignes |
| `--retry-unmatched` | re-tente aussi les `unmatched` |
| `--db-path` | surcharge `STRAWBERRY_DB_PATH` |

### Favoris (loved / starred)

#### `app:lastfm:loved:sync [user]`
Marque rétroactivement comme « loved » les scrobbles locaux dont le titre est
dans la liste des loved Last.fm.

| Option | Description |
|---|---|
| `user` (argument) · `--api-key` · `--dry-run` | utilisateur / clé / simulation |

#### `app:loves:lastfm-to-navidrome [user]`
Propage les titres « loved » Last.fm vers le statut « starred » de Navidrome.

| Option | Description |
|---|---|
| `user` (argument) · `--api-key` · `--auto-stop` · `--dry-run` | — |

#### `app:loves:navidrome-to-lastfm [user]`
Propage les titres « starred » de Navidrome vers les « loved » Last.fm
(nécessite une session key, cf. `app:lastfm:auth`).

| Option | Description |
|---|---|
| `user` (argument) · `--api-key` · `--api-secret` · `--dry-run` | — |

### Statistiques

#### `app:stats:compute`
Calcule et met en cache les statistiques d'écoute depuis la table locale
`scrobbles`.

| Option | Description |
|---|---|
| `--period` | période ciblée |
| `--user` | utilisateur Last.fm (défaut `LASTFM_USER`) |

#### `app:lastfm:stats:compute` · `app:navidrome:stats:compute`
Recalculent le snapshot de stats en cache (Last.fm / Navidrome). Adaptés au
crontab. `app:lastfm:stats:compute` accepte `--user`.

### Maintenance

#### `app:backup:purge`
Supprime les backups plus vieux que `BACKUP_RETENTION_DAYS`. Option `--days`
pour surcharger.

#### `app:history:purge`
Supprime les entrées `run_history` plus vieilles que
`RUN_HISTORY_RETENTION_DAYS`.

---

## Workflow type

```bash
# 1. Récupérer l'historique Last.fm
php bin/console app:lastfm:fetch --max-scrobbles=0

# 2. (optionnel) générer des alias pour les non-matchés résolubles
php bin/console app:aliases:generate --dry-run        # offline, MBID-only
php bin/console app:aliases:generate                  # applique
php bin/console app:aliases:musicbrainz --dry-run     # online, via MusicBrainz
php bin/console app:aliases:musicbrainz               # applique les unique-matches

# 3. Matcher + insérer dans Navidrome (Navidrome arrêté, ou --auto-stop)
php bin/console app:scrobbles:sync-navidrome --auto-stop

# 4. Re-tenter les non-matchés (après ajout de pistes / alias)
php bin/console app:scrobbles:rematch --target navidrome --auto-stop

# 5. Synchroniser les favoris
php bin/console app:loves:lastfm-to-navidrome --auto-stop
```

Ordre recommandé pour les alias : **`app:aliases:generate` →
`app:aliases:musicbrainz` → `app:scrobbles:rematch`** (les deux générateurs
n'écrivent pas dans Navidrome — l'offline d'abord puisqu'il ne coûte rien et
épuise les cas faciles, l'online ensuite pour ce qui reste ; le rematch
applique les alias et insère les écoutes nouvellement résolues).

---

## Le matching en détail

Pour chaque scrobble, `ScrobbleMatcher` applique en cascade (court-circuit au
premier succès) :

1. **Alias piste** manuel (override total) → matched / skipped.
2. **Alias artiste** → réécrit le nom avant la suite.
3. **Cache de matching** (`lastfm_match_cache`) — réponses connues
   (positives gardées, négatives expirent après `LASTFM_MATCH_CACHE_TTL_DAYS`).
4. **MBID** (`mbid_track` ↔ `mbz_recording_id`).
5. **Triplet** `(artiste, titre, album)` quand l'album est renseigné.
6. **Couple** `(artiste, titre)` avec normalisation + strip `feat.` / version.
7. **`track.getInfo`** Last.fm (autocorrection orthographe / MBID canonique).
8. **Fuzzy Levenshtein** (opt-in via `LASTFM_FUZZY_MAX_DISTANCE > 0`).

Normalisation (`np_normalize`) : minuscules, suppression des accents (NFKD) et
de la ponctuation — `Beyoncé` = `Beyonce`, `AC/DC` = `ACDC`, etc.

Les couples insolubles automatiquement se gèrent via les **alias** : UI,
`app:aliases:generate` (offline, MBID-only) ou `app:aliases:musicbrainz`
(online, MB).

---

## Développement

```bash
composer ci          # phpcs + phpstan (niveau 6) + phpunit
composer phpstan
composer phpcs        # PSR-12, 160 colonnes
composer phpcbf       # auto-fix
composer test
```

Conventions :

- **Conventional Commits** : `feat(scope):`, `fix(scope):`, `refactor(scope):`…
- Un test par méthode publique non triviale (fixtures SQLite en mémoire).
- Migrations : une par feature, `VersionYYYYMMDDHHMMSS`.

Voir `DEV.md` (environnement dev) et `CLAUDE.md` (contexte projet, pièges
hérités) pour les détails.
