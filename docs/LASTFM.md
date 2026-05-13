# Last.fm — import, matching, rematch, alias, sync loved

Toute l'intégration Last.fm : récupération de l'historique de
scrobbles, matching sur la lib Navidrome, audit par-track, alias
manuel & artiste, re-match a posteriori, et synchronisation
loved ↔ starred.

> Configuration : voir [`ENVIRONMENT.md`](ENVIRONMENT.md#lastfm).
> Schedule cron : voir [`CRON.md`](CRON.md).

## Workflow en deux étapes

L'import Last.fm est **découplé** :

1. **Récupération** (`app:lastfm:import` ou section 1 de
   `/lastfm/import`) — lit l'historique via `user.getRecentTracks` et
   stocke chaque scrobble dans `lastfm_import_buffer`. **Aucune
   écriture côté Navidrome** : Navidrome peut tourner.
2. **Traitement** (`app:lastfm:process` ou section 2 de
   `/lastfm/import`) — draine le buffer : matching cascade,
   insertion dans `scrobbles` côté Navidrome, audit dans
   `lastfm_import_track`, suppression du buffer.
   **Navidrome doit être arrêté** (écriture concurrente sur la SQLite
   = risque de corruption du WAL).

Cette séparation permet de fetcher régulièrement (cron léger,
Navidrome up) puis de processer ponctuellement (manuel ou cron
`--auto-stop`).

## Via l'UI web

`/lastfm/import` présente les deux étapes côte à côte. Le compteur du
buffer (« N scrobbles en attente ») est aussi exposé sur le
**dashboard** (card santé), avec un lien direct.

### Section 1 — Récupérer depuis Last.fm

- Identifiant Last.fm + API key. Tous deux peuvent venir de
  l'environnement (`LASTFM_USER` pré-remplit, `LASTFM_API_KEY` est
  utilisée si le champ est vide).
- Filtres `date_min` / `date_max` optionnels (`YYYY-MM-DD`).
- Limite de sécurité `max_scrobbles` (défaut 5 000) pour éviter les
  timeouts HTTP.
- Case **Dry-run** : parcourt l'API sans rien écrire dans le buffer
  (utile pour valider la connectivité / l'API key).

Le re-fetch d'une fenêtre déjà importée est **idempotent** : la
contrainte unique sur `(lastfm_user, played_at, artist, title)`
rejette les doublons et le rapport remonte un compteur
`already_buffered`.

### Section 2 — Traiter le buffer

Affiche le compteur du buffer + l'état du conteneur Navidrome.
Bouton « ▶ Traiter le buffer » dispo une fois Navidrome arrêté
(pré-flight via `NavidromeContainerManager`). Redirige vers le détail
du run `lastfm-process` qui liste l'audit par-track avec filtre par
statut.

## Via CLI

```bash
# Étape 1 — peut tourner Navidrome up
php bin/console app:lastfm:import [<lastfm-user>] [--api-key=YOUR_KEY] \
    [--date-min=YYYY-MM-DD] [--date-max=YYYY-MM-DD] \
    [--dry-run] [--max-scrobbles=N]

# Étape 2 — Navidrome doit être arrêté (ou --auto-stop)
php bin/console app:lastfm:process [--dry-run] [--limit=N] \
    [--tolerance=60] [--force] [--auto-stop]
```

Username et API key peuvent être omis si `LASTFM_USER` /
`LASTFM_API_KEY` sont dans l'environnement.

`--auto-stop` orchestre stop → process → restart de Navidrome
(cf. [`CRON.md`](CRON.md#stop--start-automatique-de-navidrome)).

## Cascade de matching

Le matcher (`App\LastFm\ScrobbleMatcher`) essaie les paliers ci-dessous
**dans l'ordre**, et s'arrête au premier match :

0. **Alias manuel** (`lastfm_alias`) : si une entrée existe pour le
   couple `(artist, title)` normalisé, elle court-circuite tout. Cible
   vide = scrobble compté en `skipped` (utile pour les podcasts).
0bis. **Cache de résolution** (`lastfm_match_cache`) : hit positif →
   réponse immédiate ; hit négatif non-stale → unmatched immédiat
   (on évite de re-frapper la lib + l'API). Les négatifs expirent au
   bout de `LASTFM_MATCH_CACHE_TTL_DAYS` jours (défaut 30), purgés au
   démarrage de chaque import / rematch. Les positifs sont éternels
   et invalidés par les mutations d'alias.
   CLI : `bin/console app:lastfm:cache:clear [--negative-only]`.
1. **MusicBrainz ID** si Last.fm le fournit (le plus fiable).
2. **Triplet** `(artist, title, album)` normalisé — départage les
   morceaux qui existent sur plusieurs albums (single + version album
   + compilation).
3. **Couple** `(artist, title)` normalisé, avec tie-break
   `album_artist = artist` puis `id ASC`. Inclut un fallback
   « featuring asymétrique » : si le titre Last.fm contient un
   marqueur `(feat. X)` mais que Navidrome stocke le featuring côté
   artiste, retente avec `artist LIKE ':a feat %'` etc.
4. **Last.fm `track.getInfo`** : si la cascade locale échoue,
   interroge Last.fm pour (a) un MBID officiel quand le scrobble n'en
   avait pas, (b) une graphie corrigée du couple via
   `autocorrect=1`. Résultats positifs comme négatifs passent par le
   cache → une seule frappe API par couple distinct.
5. **Fallback fuzzy** Levenshtein artist+title, opt-in via
   `LASTFM_FUZZY_MAX_DISTANCE`. **Recommandé pour les imports
   one-shot** : `2` rattrape les typos type `Du riiechst so gut`
   → `Du riechst so gut` ou `Tchaïkovski` → `Tchaikovsky` avec peu de
   faux-positifs. Coûteux : O(N) par scrobble unmatched, à laisser à
   `0` sur de très grosses libs en mode cron.

**Normalisation utilisée** à toutes les étapes : lowercase + trim +
décomposition Unicode NFKD + strip des diacritiques (Beyoncé ↔
Beyonce) + strip de la ponctuation (AC/DC ↔ ACDC) + collapse des
espaces. Les helpers `stripFeaturedArtists()` /
`stripFeaturingFromTitle()` / `stripVersionMarkers()` retirent en plus
les suffixes parasites côté Last.fm (`feat. X`, `(Radio Edit)`,
`- Remastered 2011`, `(Live at …)`, `(Acoustic)`, etc.).

## Déduplication

Un scrobble n'est pas réinséré s'il existe déjà dans la table
`scrobbles` Navidrome une ligne avec le même `media_file_id` et un
`submission_time` à `±--tolerance` secondes (60 par défaut). Cela
absorbe les petits décalages d'horloge entre clients de scrobble.

## Pré-requis

- **Navidrome ≥ 0.55** (table `scrobbles` requise — sinon
  `app:lastfm:process` échoue avec un message explicite).
- **Accès en écriture** sur la SQLite Navidrome pour
  `app:lastfm:process` et `app:lastfm:rematch` uniquement. La phase
  fetch n'a pas besoin d'écrire.

## Backup automatique avant écriture

Quand vous lancez `app:lastfm:process --auto-stop` ou
`app:lastfm:rematch --auto-stop`, le tool snapshote `navidrome.db`
(+ siblings `-wal` / `-shm`) en `<dbPath>.backup-<unix_ts>` **avant**
d'écrire. Rétention configurable via `NAVIDROME_DB_BACKUP_RETENTION`
(défaut 3). Restauration en une commande si quoi que ce soit tourne
mal :

```bash
# Lister les backups (du plus récent au plus ancien)
ls -lht /srv/navidrome/data/navidrome.db.backup-*

# Rollback
cp /srv/navidrome/data/navidrome.db.backup-<unix_ts> \
   /srv/navidrome/data/navidrome.db
docker compose start navidrome
```

Une commande aide à lister / restaurer :
`php bin/console app:navidrome:db:restore [--list]`.

## Alias manuels (track-level)

Page **`/lastfm/aliases`** (menu Last.fm → Alias morceaux). Pour
chaque alias :

- **Source** : `(artiste, titre)` Last.fm.
- **Cible** : `media_file_id` Navidrome (ou vide pour skip).

Court-circuite la cascade dès le palier 0. Utile pour les morceaux
mal taggés que vous ne pouvez pas (ou ne voulez pas) corriger côté
audio.

## Alias d'artiste

Page **`/lastfm/artist-aliases`** (menu Last.fm → Alias artistes).
Quand un artiste a été **renommé** (« La Ruda Salska » → « La Ruda »),
ou existe sous plusieurs **variantes** (romanisations, conventions
« The X » / « X, The »). Plutôt qu'un alias track par morceau, un seul
alias `source → cible` couvre tout le catalogue.

Le matcher consulte la table **après** l'alias track-level (qui garde
la priorité absolue) mais **avant** la cascade MBID / triplet /
couple : il réécrit le nom d'artiste dans le `LastFmScrobble` puis
laisse les heuristiques habituelles tourner.

Un bouton « 🎭 Aliaser artiste » apparaît sur `/lastfm/unmatched`
pour créer rapidement un alias depuis un scrobble non matché. Lancer
ensuite **« Re-tenter le matching cumulé »** (cf. plus bas) pour
ré-essayer rétrospectivement tous les scrobbles concernés.

## Liste cumulée des unmatched

Page **`/lastfm/unmatched`** (menu Last.fm → Unmatched (titres)) :
agrège tous les scrobbles non matchés de toutes les runs passées par
`(artist, title, album)` avec compteur de scrobbles. Filtres en GET
sur `artist`, `title`, `album` (substring case-insensitive), pagination
50/page.

Par ligne :

- **✏️ Mapper** — ouvre le formulaire d'alias manuel pré-rempli.
- **🎭 Aliaser artiste** — ouvre le formulaire d'alias d'artiste.
- **+ Lidarr** — envoie l'artiste à Lidarr (si configuré).
- Statut Lidarr (✓ déjà / ✗ absent / —) par ligne.

Deux vues complémentaires reliées par une barre d'onglets en haut de
chaque page :

- **`/lastfm/unmatched/artists`** — agrège par artiste seul. Idéal
  pour repérer les artistes complètement absents : un seul clic
  « + Lidarr » couvre tous les morceaux manquants.
- **`/lastfm/unmatched/albums`** — agrège par `(artist, album)`.
  Lidarr ne supporte pas l'ajout d'un album hors contexte artiste :
  le bouton « + Lidarr » ajoute donc l'artiste de l'album ; le
  téléchargement dépend de la stratégie de monitoring Lidarr
  (`LIDARR_MONITOR`).

## Re-match des unmatched

Quand on ajoute des morceaux à Navidrome après un import (ou qu'on
déploie une nouvelle heuristique de matching), les scrobbles déjà
marqués `unmatched` dans `lastfm_import_track` peuvent être ré-essayés
**sans retélécharger** l'historique Last.fm. La cascade courante
(alias → MBID → triplet → couple 4-paliers → fuzzy) est ré-appliquée
et les scrobbles trouvés sont insérés dans Navidrome (idempotent :
`scrobbleExistsNear` évite les doublons).

### CLI

```bash
php bin/console app:lastfm:rematch [--dry-run] [--run-id=N] \
    [--limit=N] [--random] [--force] [--auto-stop]
```

- `--dry-run` : compte sans écrire.
- `--run-id=N` : limite au run #N.
- `--limit=0` (défaut) = pas de limite.
- `--random` : mélange l'ordre avant d'appliquer `--limit` (utile
  pour échantillonner un sous-ensemble — sinon on retraite toujours
  les mêmes morceaux en tête de table).

### Web

- Sur `/history/{id}` d'un run `lastfm-process` (ou `lastfm-import`
  legacy) : bouton « 🔁 Re-tenter le matching de ce run » si le run a
  au moins 1 unmatched.
- Sur `/lastfm/import` : carte « Re-tenter le matching cumulé » avec
  le compteur global d'unmatched et un bouton de re-match global.

⚠️ Navidrome doit être arrêté pendant le rematch (mêmes contraintes
que `app:lastfm:process` : écriture dans la table `scrobbles`).

## Connexion Last.fm authentifiée

Certaines actions Last.fm — notamment la sync loved ↔ starred — exigent
une **session authentifiée**, pas juste l'API key publique utilisée
par l'import.

1. Récupérer la **API secret** sur la même page que la API key et la
   configurer dans l'environnement :
   ```env
   LASTFM_API_KEY=votre-api-key
   LASTFM_API_SECRET=votre-api-secret
   ```
2. Se connecter à Navidrome Tools puis aller sur `/lastfm/connect` :
   l'app redirige vers Last.fm pour consentement, puis renvoie sur
   `/settings` avec une session persistée localement (table `setting`,
   clés `lastfm.session_key` / `lastfm.session_user`).
3. `/settings` affiche un badge ✓/✗ et un bouton « Déconnecter » pour
   révoquer la session locale (la révocation côté Last.fm se fait sur
   <https://www.last.fm/settings/applications>).

L'**URL de callback** est construite automatiquement à partir de
l'URL publique de votre instance. En prod, votre déploiement Docker
doit donc être derrière un domaine résolvable par Last.fm (HTTPS
recommandé).

## Sync loved ↔ starred

Une fois la connexion authentifiée faite, la page `/lastfm/love-sync`
propage les ajouts dans les deux sens entre les morceaux ❤ Last.fm
et les morceaux ★ Navidrome :

- **lf → nd** : un morceau loved sur Last.fm est starré dans Navidrome
  (s'il est résolu via MBID, alias manuel ou couple `(artist, title)`).
- **nd → lf** : un morceau starred dans Navidrome est loved sur Last.fm.
- **adds-only** : la v1 ne déstarre / délove jamais — la propagation
  des suppressions est tracée dans une issue séparée.

Idempotente : un re-run immédiat ne fait rien tant que les deux
ensembles sont alignés.

### CLI

```bash
php bin/console app:lastfm:sync-loved [--direction=both|lf-to-nd|nd-to-lf] [--dry-run]
```

### Loved sans match

Les morceaux loved Last.fm sans correspondance dans la lib Navidrome
apparaissent dans le rapport avec un bouton « ✏️ Mapper » qui
pré-remplit le formulaire d'alias manuel `/lastfm/aliases/new`.
