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

| #     | Titre                                                              | Effort |
|-------|--------------------------------------------------------------------|--------|
| [#22] | Profil strict / default / lax configurable                         | S      |
| [#17] | Récupérer le MBID manquant via `track.getInfo` / `track.getCorrection` | M  |
| [#19] | Suggestions automatiques + apprentissage par confirmation          | L      |

### Last.fm — autres

| #      | Titre                                                              | Effort |
|--------|--------------------------------------------------------------------|--------|
| [#3]   | Sync incrémentale Last.fm → Navidrome                              | M      |
| [#4]   | Page permanente : diff Last.fm vs lib Navidrome                    | M      |
| [#100] | Reconciliation des timestamps Last.fm après coup                   | M      |
| [#98]  | Enrichissement par tags (`track.getTopTags`) + générateur « mood » | M      |
| [#35]  | Supprimer la contrainte d'arrêt de Navidrome pour l'import         | L      |
| [#36]  | Sources de scrobbles alternatives (Listenbrainz, Maloja, …)        | L      |

### Stats / Curation

| #     | Titre                                                              | Effort |
|-------|--------------------------------------------------------------------|--------|
| [#93] | Courbe de diversité d'écoute (unique artists / plays par mois)     | S      |
| [#97] | Split des tops par client Subsonic (DSub, Symfonium, web…)         | S      |
| [#91] | Page « artistes oubliés » (high play_count + idle > N mois)        | M      |
| [#25] | Page « métadonnées incomplètes » : albums sans MBID groupés par artiste | M  |

### Playlists

| #     | Titre                                                              | Effort |
|-------|--------------------------------------------------------------------|--------|
| [#6]  | Auto-star des top morceaux (Subsonic `star.view`)                  | S      |
| [#90] | Générateur « anniversaire » (jour J il y a N années)               | S      |
| [#5]  | Diff entre deux runs d'une même playlist                           | M      |

### Cron / observabilité

| #     | Titre                                                              | Effort |
|-------|--------------------------------------------------------------------|--------|
| [#99] | Note libre éditable sur un RunHistory                              | S      |
| [#7]  | Notifications cron (Discord / Slack / Pushover)                    | M      |
| [#9]  | Webhooks sortants génériques (POST JSON après chaque run)          | M      |
| [#94] | Synthèse hebdo/mensuelle automatique (digest + RSS)                | M      |

### Intégrations / ops

| #     | Titre                                                              | Effort |
|-------|--------------------------------------------------------------------|--------|
| [#95] | Backup automatique programmable de la DB locale                    | S      |
| [#96] | Endpoint `/health` pour Docker healthcheck                         | S      |
| [#92] | Suggestions d'artistes via Last.fm `artist.getSimilar`             | M      |
| [#37] | Export/import de la DB locale du tool                              | M      |
| [#69] | Permettre des générateurs custom en déploiement Docker             | M      |

### UI

| #     | Titre                                                              | Effort |
|-------|--------------------------------------------------------------------|--------|
| [#87] | Dark theme par défaut sur l'UI                                     | S      |

### UI — Miniatures album/artiste (meta [#26])

Affichage de covers album et photos d'artiste partout où c'est
pertinent (stats, wrapped, histories, playlist preview). Stratégie
commune : proxy `/cover/*` qui tape l'API Subsonic `getCoverArt` et
cache sur disque dans un volume Docker dédié, déduplication par
`album_id`. Voir le meta [#26] pour la stratégie globale et l'ordre
d'attaque (A est prérequis pour B-F).

| #     | Titre                                                              | Effort |
|-------|--------------------------------------------------------------------|--------|
| [#28] | B. Miniatures sur /stats (index + compare)                         | S      |
| [#29] | C. Miniatures sur /wrapped/{year}                                  | S      |
| [#30] | D. Miniatures sur les histories (Last.fm, Navidrome, run detail)   | S      |
| [#31] | E. Miniatures sur la preview de playlist                           | S      |

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
- v0.9 — Gestion playlists Navidrome (epic [#71] : liste/détail/rename/
  delete/star/bulk star/duplicate/bulk delete + export M3U [#8]),
  tableau dashboard 10 derniers runs [#58], synthèse % sur les imports
  Last.fm [#47].

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
[#22]: https://github.com/kgaut/navidrome-playlist-generator/issues/22
[#25]: https://github.com/kgaut/navidrome-playlist-generator/issues/25
[#26]: https://github.com/kgaut/navidrome-playlist-generator/issues/26
[#28]: https://github.com/kgaut/navidrome-playlist-generator/issues/28
[#29]: https://github.com/kgaut/navidrome-playlist-generator/issues/29
[#30]: https://github.com/kgaut/navidrome-playlist-generator/issues/30
[#31]: https://github.com/kgaut/navidrome-playlist-generator/issues/31
[#35]: https://github.com/kgaut/navidrome-playlist-generator/issues/35
[#36]: https://github.com/kgaut/navidrome-playlist-generator/issues/36
[#37]: https://github.com/kgaut/navidrome-playlist-generator/issues/37
[#47]: https://github.com/kgaut/navidrome-playlist-generator/issues/47
[#58]: https://github.com/kgaut/navidrome-playlist-generator/issues/58
[#69]: https://github.com/kgaut/navidrome-playlist-generator/issues/69
[#71]: https://github.com/kgaut/navidrome-playlist-generator/issues/71
[#87]: https://github.com/kgaut/navidrome-playlist-generator/issues/87
[#90]: https://github.com/kgaut/navidrome-playlist-generator/issues/90
[#91]: https://github.com/kgaut/navidrome-playlist-generator/issues/91
[#92]: https://github.com/kgaut/navidrome-playlist-generator/issues/92
[#93]: https://github.com/kgaut/navidrome-playlist-generator/issues/93
[#94]: https://github.com/kgaut/navidrome-playlist-generator/issues/94
[#95]: https://github.com/kgaut/navidrome-playlist-generator/issues/95
[#96]: https://github.com/kgaut/navidrome-playlist-generator/issues/96
[#97]: https://github.com/kgaut/navidrome-playlist-generator/issues/97
[#98]: https://github.com/kgaut/navidrome-playlist-generator/issues/98
[#99]: https://github.com/kgaut/navidrome-playlist-generator/issues/99
[#100]: https://github.com/kgaut/navidrome-playlist-generator/issues/100
