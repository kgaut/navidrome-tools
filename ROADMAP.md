# Roadmap

État des fonctionnalités prévues pour Navidrome Tools. Le détail
complet de chaque item se trouve dans l'issue GitHub liée. Ce fichier
n'est qu'un index lisible hors-ligne ; la source de vérité reste
[GitHub Issues](https://github.com/kgaut/navidrome-playlist-generator/issues).

## Légende

- **S** : effort < 1 jour
- **M** : effort 1-2 jours
- **L** : effort 3+ jours
- Labels GitHub : `area:lastfm`, `area:playlists`, `area:stats`,
  `area:integrations`, `area:cron`, `area:export`

---

## Icebox

Tout ce qui est planifié mais pas encore en cours. Aucun item n'est
attribué pour l'instant — premier qui prend ouvre une PR.

### Last.fm — matching (meta [#11])

Améliorations granulaires du pipeline de matching scrobble Last.fm →
`media_file` Navidrome. Voir le meta [#11] pour la stratégie globale et
l'ordre d'attaque recommandé.

| #   | Titre                                                              | Effort |
|-----|--------------------------------------------------------------------|--------|
| [#20] | Cache de résolution (positif + négatif)                         | S      |
| [#22] | Profil strict / default / lax configurable                      | S      |
| [#17] | MBID manquant via `track.getInfo` / `track.getCorrection`       | M      |
| [#21] | Re-tenter les unmatched cumulés sans re-télécharger l'historique | M      |
| [#19] | Suggestions automatiques + apprentissage par confirmation       | L      |

### Last.fm — autres

| #   | Titre                                                              | Effort |
|-----|--------------------------------------------------------------------|--------|
| [#3]  | Sync incrémentale Last.fm → Navidrome                           | M      |
| [#4]  | Page permanente : diff Last.fm vs lib Navidrome                 | M      |
| [#47] | Synthèse d'un import Last.fm sur la page détail (totaux + %)    | M      |

### Stats / Curation

| #   | Titre                                                              | Effort |
|-----|--------------------------------------------------------------------|--------|
| [#25] | Page « métadonnées incomplètes » : albums sans MBID groupés par artiste | M      |

### Playlists

| #   | Titre                                                              | Effort |
|-----|--------------------------------------------------------------------|--------|
| [#6]  | Auto-star des top morceaux (Subsonic `star.view`)               | S      |
| [#8]  | Export M3U téléchargeable depuis la prévisualisation            | S      |
| [#5]  | Diff entre deux runs d'une même playlist                        | M      |

#### Gestion des playlists Navidrome (meta [#71])

Vraie page de gestion des playlists côté navidrome-tools : voir/renommer/
supprimer/starrer sans avoir à ouvrir Navidrome. Toutes les écritures
restent côté Subsonic (DB Navidrome `:ro`). Voir le meta [#71] pour le
contexte complet et l'ordre d'attaque (72 → 73 → 74-77 en parallèle).

| #     | Titre                                                              | Effort |
|-------|--------------------------------------------------------------------|--------|
| [#72] | Liste sur `/playlists` (étend `getPlaylists` avec count/durée/dates) | S      |
| [#73] | Page détail `/playlists/{id}` avec tracks et statut starred        | S      |
| [#74] | Renommer une playlist via `updatePlaylist.view`                    | S      |
| [#75] | Supprimer depuis l'UI + nettoyage `lastSubsonicPlaylistId`         | S      |
| [#76] | Star / unstar individuel d'un morceau (réutilise `starTracks`)     | S      |
| [#77] | Bulk star/unstar de tous les morceaux d'une playlist               | S      |
| [#79] | Dupliquer une playlist                                             | S      |
| [#82] | Bulk delete depuis la liste                                        | S      |
| [#83] | Export M3U depuis la page détail (mutualisé avec [#8])             | M      |
| [#84] | Auto-star CLI réutilisant `starTracks` (ferme [#6])                | M      |
| [#78] | Ajouter / retirer / réordonner des morceaux                        | M      |
| [#80] | Statistiques par playlist (durée, top artistes, distribution)      | M      |
| [#81] | Détection des morceaux morts + purge                               | M      |

### Cron / observabilité

| #   | Titre                                                              | Effort |
|-----|--------------------------------------------------------------------|--------|
| [#7]  | Notifications cron (Discord / Slack / Pushover)                 | M      |
| [#9]  | Webhooks sortants génériques (POST JSON après chaque run)       | M      |
| [#58] | Tableau des 10 derniers runs sur le dashboard                   | S      |

### UI — Miniatures album/artiste (meta [#26])

Affichage de covers album et photos d'artiste partout où c'est
pertinent (stats, wrapped, histories, playlist preview). Stratégie
commune : proxy `/cover/*` qui tape l'API Subsonic `getCoverArt` et
cache sur disque dans un volume Docker dédié, déduplication par
`album_id`. Voir le meta [#26] pour la stratégie globale et l'ordre
d'attaque (A est prérequis pour B-F).

| #   | Titre                                                              | Effort |
|-----|--------------------------------------------------------------------|--------|
| [#28] | B. Miniatures sur /stats (index + compare)                      | S      |
| [#29] | C. Miniatures sur /wrapped/{year}                               | S      |
| [#30] | D. Miniatures sur les histories (Last.fm, Navidrome, run detail)| S      |
| [#31] | E. Miniatures sur la preview de playlist                        | S      |

---

## En cours

_Aucun item en cours pour l'instant._

---

## Livré

Pour mémoire, les grandes briques déjà livrées sur `main` (à compléter
quand on tagge des releases) :

- v0.1 — Bootstrap : 7 générateurs, dashboard, CRUD playlist, Subsonic
  client, cron interne via supercronic.
- v0.2 — Stats `/stats` cachées dans `stats_snapshot`.
- v0.3 — Import one-shot Last.fm (CLI + UI + warning) avec dédup ±60s.
- v0.4 — Intégration Lidarr (`+ Lidarr` sur les unmatched).
- v0.5 — Historique des runs cron (`/history`) + `RunHistoryRecorder`.
- v0.6 — Stats avancées : `/stats/compare`, `/stats/charts`,
  `/stats/heatmap`, `/wrapped/{year}`.
- v0.7 — Générateur `songs-you-used-to-love`.
- v0.8 — UX dashboard : filtres + sort + duplicate + lien Subsonic +
  pastille santé live + boutons `+ Nouvelle playlist` contextuels.

---

[#3]: https://github.com/kgaut/navidrome-playlist-generator/issues/3
[#4]: https://github.com/kgaut/navidrome-playlist-generator/issues/4
[#5]: https://github.com/kgaut/navidrome-playlist-generator/issues/5
[#6]: https://github.com/kgaut/navidrome-playlist-generator/issues/6
[#7]: https://github.com/kgaut/navidrome-playlist-generator/issues/7
[#8]: https://github.com/kgaut/navidrome-playlist-generator/issues/8
[#9]: https://github.com/kgaut/navidrome-playlist-generator/issues/9
[#11]: https://github.com/kgaut/navidrome-playlist-generator/issues/11
[#17]: https://github.com/kgaut/navidrome-playlist-generator/issues/17
[#19]: https://github.com/kgaut/navidrome-playlist-generator/issues/19
[#20]: https://github.com/kgaut/navidrome-playlist-generator/issues/20
[#21]: https://github.com/kgaut/navidrome-playlist-generator/issues/21
[#22]: https://github.com/kgaut/navidrome-playlist-generator/issues/22
[#25]: https://github.com/kgaut/navidrome-playlist-generator/issues/25
[#26]: https://github.com/kgaut/navidrome-playlist-generator/issues/26
[#28]: https://github.com/kgaut/navidrome-playlist-generator/issues/28
[#29]: https://github.com/kgaut/navidrome-playlist-generator/issues/29
[#30]: https://github.com/kgaut/navidrome-playlist-generator/issues/30
[#31]: https://github.com/kgaut/navidrome-playlist-generator/issues/31
[#47]: https://github.com/kgaut/navidrome-playlist-generator/issues/47
[#58]: https://github.com/kgaut/navidrome-playlist-generator/issues/58
[#71]: https://github.com/kgaut/navidrome-playlist-generator/issues/71
[#72]: https://github.com/kgaut/navidrome-playlist-generator/issues/72
[#73]: https://github.com/kgaut/navidrome-playlist-generator/issues/73
[#74]: https://github.com/kgaut/navidrome-playlist-generator/issues/74
[#75]: https://github.com/kgaut/navidrome-playlist-generator/issues/75
[#76]: https://github.com/kgaut/navidrome-playlist-generator/issues/76
[#77]: https://github.com/kgaut/navidrome-playlist-generator/issues/77
[#78]: https://github.com/kgaut/navidrome-playlist-generator/issues/78
[#79]: https://github.com/kgaut/navidrome-playlist-generator/issues/79
[#80]: https://github.com/kgaut/navidrome-playlist-generator/issues/80
[#81]: https://github.com/kgaut/navidrome-playlist-generator/issues/81
[#82]: https://github.com/kgaut/navidrome-playlist-generator/issues/82
[#83]: https://github.com/kgaut/navidrome-playlist-generator/issues/83
[#84]: https://github.com/kgaut/navidrome-playlist-generator/issues/84
