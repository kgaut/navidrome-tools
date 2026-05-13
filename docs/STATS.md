# Statistiques d'écoute

Sept pages stats accessibles via le menu déroulant **Statistiques**.
Toutes les pages requièrent la table `scrobbles` Navidrome (≥ 0.55) et
affichent un bandeau si elle n'est pas trouvée.

| Route                          | Contenu                                                                                                  |
|--------------------------------|----------------------------------------------------------------------------------------------------------|
| `/stats`                       | Vue d'ensemble par période (7d / 30d / last-month / last-year / all-time), cachée dans `stats_snapshot`. |
| `/stats/tops`                  | Tops sur fenêtre `[from, to]` arbitraire : 50 artistes / 100 albums / 500 morceaux, filtre par client.   |
| `/stats/compare`               | Comparaison côte à côte de deux périodes : top artistes / morceaux fusionnés avec deltas.                |
| `/stats/charts`                | Trois graphiques Chart.js : écoutes par mois, top 5 artistes au fil du temps, distribution par jour.     |
| `/stats/heatmap`               | Deux heatmaps HTML/CSS : jour×heure (90 derniers jours) et année×jour façon GitHub contribs.             |
| `/stats/lastfm-history`        | 100 derniers scrobbles cachés en local pour le user Last.fm (table `lastfm_history`).                    |
| `/stats/navidrome-history`     | Pendant symétrique, snapshot des 100 derniers scrobbles côté Navidrome (table `navidrome_history`).      |
| `/wrapped/{year}`              | Rétrospective annuelle façon Spotify Wrapped.                                                            |

## /stats — Vue d'ensemble

Pour chaque période (7d / 30d / last-month / last-year / all-time) :

- total plays sur la fenêtre,
- nombre de morceaux distincts joués,
- top 10 artistes,
- top 50 morceaux.

Cachée dans la table `stats_snapshot` (une row par période × type).
Refresh manuel via bouton sur la page, ou en cron via
`app:stats:compute` (cf. [`CRON.md`](CRON.md)).

## /stats/tops — Tops sur fenêtre arbitraire

Date-picker `from` / `to` + filtre client (DSub / Symfonium / web /
…). Cache dans `top_snapshot` keyé par `(window_from, window_to,
client)`. Les bornes sont arrondies au jour côté contrôleur
(`TopsService::normalizeWindow()`) pour réutiliser un snapshot entre
clics rapprochés.

**Bouton « + Créer playlist Navidrome »** sur le top morceaux :
crée une playlist Navidrome via Subsonic `createPlaylist` avec les N
premiers IDs (N borné à 500, configurable depuis l'UI). La création
trace le run dans `/history` sous le type `playlist`.

Refresh manuel via le bouton, ou en cron via
`app:stats:tops:compute --from=… --to=… [--client=…]` (cf.
[`CRON.md`](CRON.md)).

## /stats/compare — Diff entre deux périodes

Deux date-pickers (« période A » / « période B ») et un sélecteur
de granularité (artistes / morceaux). Le tableau fusionne les deux
tops par id (tracks) / nom (artists) et affiche pour chaque entrée :

- les rangs de la période A et B,
- un delta : `=` (rang inchangé), `↑N` (gain de N rangs), `↓N`
  (perte), `nouveau` (présent en B seulement), `disparu` (en A
  seulement).

Implémenté par `StatsCompareService` — pas de cache, recalcul à
chaque ouverture (rapide tant que les snapshots sont à jour).

## /stats/charts — Graphiques Chart.js

Trois charts :

1. **Plays par mois** — bar chart sur les 24 derniers mois.
2. **Top 5 artistes timeline** — line chart, courbe par artiste, mois
   par mois.
3. **Distribution jour de la semaine** — bar chart sur l'ensemble de
   l'historique.

Chart.js est chargé via CDN jsdelivr dans le block `stylesheets`.
Pas de toolchain front, pas de bundle.

## /stats/heatmap — Heatmaps HTML/CSS

Deux heatmaps en pur CSS (pas de JS, pas de canvas) :

- **Jour × heure** sur les 90 derniers jours — utile pour voir vos
  habitudes d'écoute par tranche horaire.
- **Année × jour** façon GitHub contribs, avec sélecteur d'année.

## /wrapped/{year} — Rétrospective annuelle

Cachée dans `stats_snapshot` (key `wrapped-<year>`). `WrappedService::compute()`
agrège :

- top 25 artistes + top 50 morceaux de l'année,
- new artists (jamais écoutés avant cette année),
- streak (plus long enchaînement de jours consécutifs avec au moins
  un scrobble),
- mois le plus actif,
- durée totale estimée (extrapolation depuis la durée des top tracks),
- compteur de morceaux distincts.

Refresh manuel par le bouton sur la page.

## /stats/lastfm-history & /stats/navidrome-history

Deux pages symétriques qui snapshotent en local les 100 derniers
scrobbles côté Last.fm (resp. côté Navidrome) :

- **Last.fm history** (`lastfm_history`) : permet de jeter un œil aux
  scrobbles récents sans rouvrir l'UI Last.fm. Refresh manuel via
  bouton — frappe `user.getRecentTracks`.
- **Navidrome history** (`navidrome_history`) : snapshot équivalent
  côté `scrobbles` Navidrome, avec lien direct vers la fiche morceau
  côté Navidrome pour chaque ligne.

Aucune des deux pages n'est mise à jour automatiquement — c'est de la
**capture sur demande**, pas un tableau live.
