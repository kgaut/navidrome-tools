# Intégration Lidarr

Boutons « + Lidarr » contextuels pour pousser un artiste manquant à
[Lidarr](https://lidarr.audio/) en un clic, partout où un scrobble
unmatched est listé.

## Configuration

Voir [`ENVIRONMENT.md`](ENVIRONMENT.md#lidarr) pour la liste complète
des variables. Vide = bouton masqué proprement (intégration off).

Minimum requis :

```env
LIDARR_URL=http://lidarr:8686
LIDARR_API_KEY=...                # Lidarr → Settings → General → Security
LIDARR_ROOT_FOLDER_PATH=/music    # doit exister côté Lidarr
LIDARR_QUALITY_PROFILE_ID=1       # id d'un Quality Profile existant
LIDARR_METADATA_PROFILE_ID=1      # id d'un Metadata Profile existant
LIDARR_MONITOR=all                # all|future|missing|existing|first|latest|none
```

## Où apparaît le bouton

- **`/lastfm/unmatched`** (titres, artistes, albums — les trois vues).
- **`/lastfm/love-sync`** dans la section « Loved sans match ».
- **`/lastfm/import` → détail run** dans le tableau par-track des
  unmatched.

Par ligne, le tool affiche aussi :

- **Last.fm ↗** — lien vers la page artiste publique sur Last.fm.
- **Navidrome ↗** — si l'artiste existe déjà dans Navidrome (lookup
  par nom normalisé), lien direct vers sa fiche dans l'app Navidrome.
- **Statut Lidarr** — ✓ déjà / ✗ absent / — (intégration off),
  pré-calculé pour la page entière via
  `LidarrClient::indexExistingArtists()`.

## Fonctionnement

Au clic sur **+ Lidarr**, le service `AddArtistToLidarrService` :

1. Cherche l'artiste sur MusicBrainz via l'endpoint Lidarr
   `/api/v1/artist/lookup` (l'API key Lidarr permet d'éviter les
   rate-limits MB).
2. Prend le premier hit (Lidarr ordonne par pertinence) et POST
   `/api/v1/artist` en demandant `searchForMissingAlbums: true`.
3. Si Lidarr répond que l'artiste existe déjà
   (`LidarrConflictException`), l'UI affiche un flash info
   « déjà présent » au lieu d'une erreur.

Tous les paramètres sont configurés une fois pour toutes via les env
vars — pas d'UI de configuration côté tool.

## Cas particulier : « ajouter par album »

Lidarr ne supporte pas l'ajout d'un album hors contexte d'un artiste
existant. Quand vous cliquez « + Lidarr » sur une ligne de
`/lastfm/unmatched/albums`, le tool ajoute donc **l'artiste** de
l'album ; le téléchargement effectif de cet album dépend de la
stratégie de monitoring Lidarr (`LIDARR_MONITOR`) :

- `all` — tous les albums de l'artiste seront monitorés (par défaut).
- `missing` — seulement les albums absents du disque.
- `first` / `latest` — premier ou dernier album seulement.
- `none` — l'artiste est ajouté sans rien monitorer (vous activez à la
  main dans Lidarr).
