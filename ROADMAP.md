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
| [#12] | Normalisation Unicode (accents, casse étendue)                  | S      |
| [#13] | Normalisation ponctuation et caractères spéciaux                | S      |
| [#14] | Suppression des suffixes parasites (Remastered, Live, feat.)    | S      |
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
[#12]: https://github.com/kgaut/navidrome-playlist-generator/issues/12
[#13]: https://github.com/kgaut/navidrome-playlist-generator/issues/13
[#14]: https://github.com/kgaut/navidrome-playlist-generator/issues/14
[#15]: https://github.com/kgaut/navidrome-playlist-generator/issues/15
[#16]: https://github.com/kgaut/navidrome-playlist-generator/issues/16
[#17]: https://github.com/kgaut/navidrome-playlist-generator/issues/17
[#18]: https://github.com/kgaut/navidrome-playlist-generator/issues/18
[#19]: https://github.com/kgaut/navidrome-playlist-generator/issues/19
[#20]: https://github.com/kgaut/navidrome-playlist-generator/issues/20
[#21]: https://github.com/kgaut/navidrome-playlist-generator/issues/21
[#22]: https://github.com/kgaut/navidrome-playlist-generator/issues/22
[#23]: https://github.com/kgaut/navidrome-playlist-generator/issues/23
