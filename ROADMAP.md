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
| [#15] | Désambiguation par triplet (artist, title, album)               | S      |
| [#20] | Cache de résolution (positif + négatif)                         | S      |
| [#22] | Profil strict / default / lax configurable                      | S      |
| [#16] | Fuzzy match Levenshtein avec seuil                              | M      |
| [#17] | MBID manquant via `track.getInfo` / `track.getCorrection`       | M      |
| [#18] | Table d'alias manuels Last.fm → media_file                      | M      |
| [#21] | Re-tenter les unmatched cumulés sans re-télécharger l'historique | M      |
| [#19] | Suggestions automatiques + apprentissage par confirmation       | L      |

### Last.fm — autres

| #   | Titre                                                              | Effort |
|-----|--------------------------------------------------------------------|--------|
| [#3]  | Sync incrémentale Last.fm → Navidrome                           | M      |
| [#4]  | Page permanente : diff Last.fm vs lib Navidrome                 | M      |
| [#10] | Détail d'un run Last.fm import : actions Lidarr par artiste     | M      |
| [#23] | Sync bidirectionnelle Last.fm loved ↔ Navidrome starred         | L      |

### Playlists

| #   | Titre                                                              | Effort |
|-----|--------------------------------------------------------------------|--------|
| [#6]  | Auto-star des top morceaux (Subsonic `star.view`)               | S      |
| [#8]  | Export M3U téléchargeable depuis la prévisualisation            | S      |
| [#5]  | Diff entre deux runs d'une même playlist                        | M      |

### Cron / observabilité

| #   | Titre                                                              | Effort |
|-----|--------------------------------------------------------------------|--------|
| [#7]  | Notifications cron (Discord / Slack / Pushover)                 | M      |
| [#9]  | Webhooks sortants génériques (POST JSON après chaque run)       | M      |

### UI — Miniatures album/artiste (meta [#26])

Affichage de covers album et photos d'artiste partout où c'est
pertinent (stats, wrapped, histories, playlist preview). Stratégie
commune : proxy `/cover/*` qui tape l'API Subsonic `getCoverArt` et
cache sur disque dans un volume Docker dédié, déduplication par
`album_id`. Voir le meta [#26] pour la stratégie globale et l'ordre
d'attaque (A est prérequis pour B-F).

| #   | Titre                                                              | Effort |
|-----|--------------------------------------------------------------------|--------|
| [#27] | A. Infra : proxy + cache disque + Twig extension + volume Docker | M      |
| [#28] | B. Miniatures sur /stats (index + compare)                      | S      |
| [#29] | C. Miniatures sur /wrapped/{year}                               | S      |
| [#30] | D. Miniatures sur les histories (Last.fm, Navidrome, run detail)| S      |
| [#31] | E. Miniatures sur la preview de playlist                        | S      |
| [#32] | F. Miniatures sur /stats/charts (légende top artistes)          | S      |

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
[#10]: https://github.com/kgaut/navidrome-playlist-generator/issues/10
[#11]: https://github.com/kgaut/navidrome-playlist-generator/issues/11
[#15]: https://github.com/kgaut/navidrome-playlist-generator/issues/15
[#16]: https://github.com/kgaut/navidrome-playlist-generator/issues/16
[#17]: https://github.com/kgaut/navidrome-playlist-generator/issues/17
[#18]: https://github.com/kgaut/navidrome-playlist-generator/issues/18
[#19]: https://github.com/kgaut/navidrome-playlist-generator/issues/19
[#20]: https://github.com/kgaut/navidrome-playlist-generator/issues/20
[#21]: https://github.com/kgaut/navidrome-playlist-generator/issues/21
[#22]: https://github.com/kgaut/navidrome-playlist-generator/issues/22
[#23]: https://github.com/kgaut/navidrome-playlist-generator/issues/23
[#26]: https://github.com/kgaut/navidrome-playlist-generator/issues/26
[#27]: https://github.com/kgaut/navidrome-playlist-generator/issues/27
[#28]: https://github.com/kgaut/navidrome-playlist-generator/issues/28
[#29]: https://github.com/kgaut/navidrome-playlist-generator/issues/29
[#30]: https://github.com/kgaut/navidrome-playlist-generator/issues/30
[#31]: https://github.com/kgaut/navidrome-playlist-generator/issues/31
[#32]: https://github.com/kgaut/navidrome-playlist-generator/issues/32
