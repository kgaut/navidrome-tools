# Navidrome Tools v2 — Roadmap

La POC est conservée taguée `poc-v0` sur `main`.
Ce document décrit l'architecture cible et le lotissement pour la v2
développée sur la branche `develop`.

---

## Architecture cible

### Schéma de données

```
scrobbles                          ← source de vérité Last.fm (jamais vidée sauf wipe)
  id, lastfm_user
  artist, title, album, album_artist
  mbid_track, mbid_artist, mbid_album
  played_at (DATETIME UTC), loved (BOOL)
  image_url, streamable
  fetched_at
  UNIQUE (lastfm_user, played_at, artist, title)

scrobble_sync                      ← statut de sync par cible (créé lazily au 1er sync)
  id
  scrobble_id  FK → scrobbles(id) ON DELETE CASCADE
  target       VARCHAR(32)   ← 'navidrome' | 'strawberry'
  status       VARCHAR(16)   ← 'pending' | 'matched' | 'duplicate' | 'unmatched' | 'skipped'
  target_id    VARCHAR(255)  ← media_file_id (Navidrome) ou rowid (Strawberry)
  strategy     VARCHAR(32)   ← 'mbid' | 'triplet' | 'couple' | 'fuzzy' | 'alias' | 'lastfm-correction'
  attempted_at DATETIME
  synced_at    DATETIME
  run_id       FK → run_history(id) ON DELETE SET NULL
  UNIQUE (scrobble_id, target)

run_history       ← audit de chaque run CLI/HTTP (type, status, durée, métriques JSON)
lastfm_alias      ← mapping manuel (artist, title) → media_file_id ou NULL (skip)
lastfm_artist_alias ← réécriture nom artiste avant cascade matching
lastfm_match_cache  ← memoization matching (positif ∞, négatif TTL configurable)
setting           ← KV store (dont lastfm_last_fetch_{user})
messenger_messages← table transport Messenger (auto-créée par doctrine transport)
```

### Worker Symfony Messenger

Transport `doctrine` (SQLite, table `messenger_messages`) — sans Redis ni infrastructure externe.

```yaml
# docker-compose.yml — service séparé, même image
navidrome-tools-worker:
  image: ghcr.io/kgaut/navidrome-tools:latest
  environment:
    APP_MODE: worker   # → entrypoint lance messenger:consume async
  volumes:
    - navidrome-tools-data:/app/var
    - /path/navidrome.db:/data/navidrome.db  # RW pour sync
```

Les triggers HTTP (`POST /scrobbles/fetch`, `/navidrome/sync`, `/strawberry/process`)
dispatchent un message Messenger et redirigent vers `/history/{id}` où un polling simple
(fetch toutes les 2s sur `GET /history/{id}/status.json`) affiche la progression.

---

## Lotissement

### Lot 0 — Git & Branches ✅ FAIT

- Tag `poc-v0` sur `main` (POC conservée)
- Branche `develop` avec skeleton Symfony 7 propre
- CI GitHub Actions configurée sur `develop` + `main`

---

### Lot 1 — Socle technique

**Objectif :** socle fonctionnel, auth, Docker, CI, modèle de base.

**Périmètre :**
- `RunHistoryRecorder` (repris POC, 0 modification)
- `Notifier` + drivers Gotify / Slack / Discord / Pushover (repris POC)
- `BackupService` : `backup(dbPath, label): string` → `var/backups/YYYY-MM-DD_HH-MM-SS_LABEL.sqlite`
  + `pruneOlderThan(days): int`
- `app:backup:purge` command (wrappé RunHistoryRecorder)
- Migrations initiales : `run_history`, `setting`, `lastfm_alias`, `lastfm_artist_alias`, `lastfm_match_cache`
- Base templates (nav, flash messages)
- Dashboard minimal avec cards health

**Nouvelles env vars :**

| Variable | Usage | Défaut |
|---|---|---|
| `BACKUP_RETENTION_DAYS` | Purge des backups plus vieux que N jours | `7` |

**Livrable :** `lando start` → login → dashboard. CI verte. Backup service testé.

---

### Lot 2 — Import Last.fm → table locale `scrobbles`

**Objectif :** fetch Last.fm, stocker dans `scrobbles` (source de vérité), smart date tracking.

**Périmètre :**
- Migration : table `scrobbles`
- `Scrobble` entity + `ScrobbleRepository`
  - `countByUser(user): int`
  - `getLastPlayedAt(user): ?\DateTimeImmutable`
- `LastFmClient` (repris POC)
- `LastFmFetcher` (adapté POC) → écrit directement dans `scrobbles`, plus de buffer
  - `FetchReport` : fetched / inserted / duplicates
- `app:lastfm:fetch [user] [--api-key=] [--date-min=] [--date-max=] [--max-scrobbles=] [--dry-run]`
  - Sans `--date-min` : lit `setting.lastfm_last_fetch_{user}` → fetch depuis cette date − 1h
  - Après fetch réussi : met à jour le setting (seulement si pas de `--date-min` explicite)
  - Wrappé RunHistoryRecorder (type `lastfm-fetch`)
- `FetchLastFmMessage` (Messenger DTO) + handler
- Contrôleur + page `/lastfm/import` :
  - Compteur scrobbles locaux, date du dernier fetch, état Last.fm auth
  - Formulaire user / date-min / date-max / api-key
  - Bouton → POST → dispatch Messenger → redirect `/history/{run_id}`

**Livrable :** fetch CLI + HTTP. Smart date tracking opérationnel. Dedup testé.

---

### Lot 3 — Sync vers Navidrome

**Objectif :** matcher les scrobbles locaux vers Navidrome, avec worker pour les runs longs.

**Périmètre :**
- Migration : table `scrobble_sync`
- `ScrobbleSync` entity + `ScrobbleSyncRepository`
  - `prepareForTarget(target): int` → crée les rows `pending` manquantes
  - `streamPending(target, limit): iterable`
  - `countByTargetStatus(target, status): int`
- `ScrobbleMatcher` (repris POC, 0 modification)
- `NavidromeRepository` (repris POC)
- `NavidromeContainerManager` + `NavidromeDbBackup` (repris POC)
- `NavidromeSyncService` :
  - `process(limit, dryRun, toleranceSeconds, RunHistory): NavidromeSyncReport`
  - Préparation des rows `pending` + batch matching + backup avant 1ère écriture
- `app:scrobbles:sync-navidrome [--limit=] [--tolerance=] [--dry-run] [--force] [--auto-stop]`
  - Wrappé RunHistoryRecorder (type `navidrome-sync`)
  - Même pre-flight container que POC
- `SyncNavidromeMessage` + handler (via worker, stop/start Navidrome inclus)
- `POST /navidrome/sync` → dispatch → redirect `/history/{id}`
- Page `/history/{id}` avec polling status

**Livrable :** sync Navidrome CLI + HTTP avec worker. Backup auto avant écriture.

---

### Lot 4 — Sync vers Strawberry

**Objectif :** sync playcount/lastplayed dans Strawberry (upload/download ou volume monté).

**Périmètre :**
- `StrawberryRepository` (repris POC)
- `StrawberryUploadService` (repris POC)
- `StrawberrySyncService` (adapté POC `StrawberryBufferProcessor`) :
  - Lit `scrobble_sync(target=strawberry, status=pending)`
  - Groupe par `rowid` → batch `UPDATE songs SET playcount += N, lastplayed = MAX(…)`
  - `StrawberrySyncReport` : considered / matched / unmatched
- `app:scrobbles:sync-strawberry [--db-path=] [--limit=] [--retry-unmatched] [--dry-run]`
  - Wrappé RunHistoryRecorder (type `strawberry-sync`)
- `SyncStrawberryMessage` + handler
- `StrawberryController` : upload / download / delete / process (POST → dispatch)

**Livrable :** sync Strawberry CLI + upload/download HTTP.

---

### Lot 5 — Rematch

**Objectif :** retenter les scrobbles unmatched après ajout de morceaux ou création d'alias.

**Périmètre :**
- `app:scrobbles:rematch --target=navidrome|strawberry [--limit=] [--dry-run]`
  - Reset `scrobble_sync.status = pending` pour les rows `unmatched` de la cible
  - Relance `NavidromeSyncService` ou `StrawberrySyncService`
  - Wrappé RunHistoryRecorder (type `{target}-rematch`)
- `RematchMessage` + handler
- Page `/unmatched/navidrome` : liste paginée (artist, title, album, nb scrobbles, dernier joué) avec filtres
- Page `/unmatched/strawberry` : idem
- Lien depuis dashboard (compteurs par cible)

**Livrable :** pages unmatched + rematch fonctionnel pour les deux cibles.

---

### Lot 6 — Stats, historique, dashboard

**Objectif :** visibilité opérationnelle complète.

**Périmètre :**
- `/history` : liste paginée des runs (filtres type/status/q)
- `/history/{id}` : détail + métriques + polling `GET /history/{id}/status.json`
- Dashboard :
  - Card scrobbles locaux (total, dernier fetch)
  - Card Navidrome (pending, unmatched, état container)
  - Card Strawberry (pending, unmatched, fichier uploadé si actif)
  - Derniers runs
- Stats listening (période, tops artistes/tracks) — repris POC
- `app:stats:compute`, `app:history:purge` — repris POC

**Livrable :** dashboard + historique complets.

---

### Lot 7 — Playlists *(report explicite)*

Feature déférée par l'utilisateur. Portage des generators POC en Lot 7
une fois les lots 1–6 stables. Voir `poc-v0` pour l'implémentation existante.

---

## Fichiers réutilisés de la POC (`poc-v0`)

| Fichier | Modifications attendues |
|---|---|
| `src/LastFm/ScrobbleMatcher.php` | 0 — NE PAS réécrire |
| `src/LastFm/LastFmClient.php` | 0 |
| `src/LastFm/MatchResult.php` | 0 |
| `src/LastFm/LastFmScrobble.php` | 0 |
| `src/Navidrome/NavidromeRepository.php` | 0 |
| `src/Navidrome/NavidromeContainerManager.php` | 0 |
| `src/Navidrome/NavidromeDbBackup.php` | déléguer à `BackupService` |
| `src/Strawberry/StrawberryRepository.php` | 0 |
| `src/Strawberry/StrawberryUploadService.php` | 0 |
| `src/Service/RunHistoryRecorder.php` | 0 |
| `src/Notifier/` | 0 |
| `tests/Navidrome/NavidromeFixtureFactory.php` | 0 |

---

## Variables d'environnement

Toutes les variables de la POC sont conservées. Ajouts :

| Variable | Usage | Défaut |
|---|---|---|
| `BACKUP_RETENTION_DAYS` | Purge auto des backups plus vieux que N jours | `7` |
| `MESSENGER_TRANSPORT_DSN` | Transport Messenger (défaut : doctrine SQLite) | `doctrine://default?auto_setup=0` |

---

## Questions ouvertes

1. **Transport Messenger** : `doctrine` (SQLite, zéro infra, défaut) ou `redis` (override via `MESSENGER_TRANSPORT_DSN`) ?
   → Défaut `doctrine`, override possible.

2. **Progress polling** : `GET /history/{id}/status.json` toutes les 2s (simple fetch) ou Server-Sent Events ?
   → Simple fetch recommandé (SSE = overhead marginal en self-hosted).

3. **Rematch stratégie** : reset `pending` + relancer le service, ou re-appliquer la cascade directement ?
   → Reset `pending` + relancer (réutilise les services existants, pas de duplication).
