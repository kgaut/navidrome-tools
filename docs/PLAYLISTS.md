# Playlists — création et gestion

Tout ce qui touche aux playlists Navidrome : génération depuis une
définition, gestion des playlists existantes, schéma Navidrome lu en
arrière-plan.

## Création de playlists

Toutes les playlists sont créées via l'**API Subsonic** de Navidrome
(`createPlaylist.view`). Le tool **n'écrit jamais directement** dans la
SQLite Navidrome pour cette opération. Avantages :

- aucun risque de corruption ou de conflit de lock,
- fonctionne même si Navidrome tourne en parallèle,
- la DB Navidrome peut rester montée `:ro` côté tool.

L'option « remplacer la playlist existante » utilise
`getPlaylists.view` + `deletePlaylist.view` pour retirer l'ancienne du
même nom appartenant au même utilisateur, puis recrée la nouvelle.

## Définitions de playlist

Une **définition** = ligne dans `playlist_definition` (DB locale du
tool) qui combine :

- un **générateur** (= un plugin PHP, cf. [`PLUGINS.md`](PLUGINS.md)),
- des **paramètres** (stockés en JSON, schéma défini par le générateur),
- un **modèle de nom** (`{date}`, `{month}`, `{year}`, `{label}`,
  `{name}`, `{preset}`, `{param:nom}`),
- une **limite** (nombre de morceaux),
- un **flag activé / désactivé**.

8 générateurs livrés out-of-the-box : `top-last-days`,
`top-last-month`, `top-last-year`, `top-all-time`, `never-played`,
`top-month-yago`, `top-years-ago`, `songs-you-used-to-love` (high
`play_count` + dernier play > N mois).

## Preview avant publication

La page `/playlist/{id}/preview` montre la liste résultante avant
toute écriture côté Navidrome :

- compteur de tracks générés,
- colonnes : titre, artiste, album, durée, play count **sur la fenêtre
  du générateur** (pas le lifetime),
- bouton « Exporter M3U » pour récupérer la liste avant même de la
  créer côté Navidrome,
- bouton « Publier dans Navidrome » qui appelle Subsonic
  `createPlaylist`.

La colonne « Plays » reflète bien la fenêtre temporelle déclarée par
le générateur (`PlaylistGeneratorInterface::getActiveWindow()`),
consommée par `NavidromeRepository::summarize($ids, $from, $to)`.

## Gestion des playlists existantes

Page **`/playlists`** (lien « Playlists » dans la nav) : liste toutes
les playlists Navidrome avec leurs métadonnées (nombre de morceaux,
durée, dates création/modification, owner, public/privé). Cases à
cocher + bouton « Supprimer la sélection » pour la suppression en
masse. Bouton « M3U » par ligne pour télécharger la playlist au format
M3U Extended (lisible par VLC / mpv / foobar2000).

Page détail **`/playlists/{id}`** : affiche le contenu (titre,
artiste, album, durée, play count, statut starred ★) et permet :

- **Renommer** la playlist (`updatePlaylist.view`).
- **Dupliquer** la playlist sous le nom `X (copie)`.
- **Supprimer** la playlist (avec nettoyage automatique du
  `lastSubsonicPlaylistId` des `PlaylistDefinition` rattachées).
- **Star / unstar individuel** d'un morceau (icône ★/☆).
- **Bulk star / unstar** : « ★ Tout starrer » / « ☆ Tout dé-starrer »,
  un seul appel API.
- **Retirer un morceau** de la playlist (`updatePlaylist.view` avec
  `songIndexToRemove`).
- **Détecter les morceaux morts** (présents dans la playlist mais
  absents de `media_file`) et les purger en un clic.
- **Statistiques** : durée totale, nombre starré, % jamais joués, top
  10 artistes, top 10 albums, distribution par année (mini bar chart).
- **Exporter en M3U**.

Toutes les écritures passent par l'API Subsonic — aucune écriture
directe dans la DB Navidrome (qui peut donc rester montée `:ro`).

## Schéma Navidrome utilisé (lecture seule)

| Table          | Colonnes lues                                                |
|----------------|--------------------------------------------------------------|
| `media_file`   | id, title, album, artist, album_artist, duration, year       |
| `annotation`   | user_id, item_id, item_type, play_count, play_date           |
| `user`         | id, user_name (résolution `NAVIDROME_USER` → user_id Subsonic) |
| `scrobbles`    | media_file_id, user_id, submission_time (Navidrome ≥ 0.55)   |

Si la table `scrobbles` n'existe pas, le tool retombe sur
`annotation.play_date`, qui ne contient que la date du **dernier**
play. Les tops « par fenêtre temporelle » deviennent donc
approximatifs ; un bandeau d'avertissement est affiché dans l'UI dans
ce cas.

### Pourquoi pas une migration Doctrine côté tool ?

Le tool **ne touche jamais au schéma Navidrome**. La détection de la
table `scrobbles` se fait à l'ouverture de la connexion DBAL via
`mediaFileColumns()` / `hasScrobblesTable()`, sans rien créer. Le
seul cas où le tool écrit dans la DB Navidrome est l'insertion de
lignes dans `scrobbles` lors des imports / rematch Last.fm, et là on
respecte strictement le schéma natif de Navidrome 0.55+.
