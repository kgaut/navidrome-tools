# Changelog

Toutes les évolutions notables de ce projet sont consignées dans ce fichier.

Le format suit [Keep a Changelog 1.1.0](https://keepachangelog.com/fr/1.1.0/)
et le projet adhère à [Semantic Versioning 2.0](https://semver.org/lang/fr/).

## [Unreleased]

### Added
- `CHANGELOG.md` au format Keep a Changelog ; documentation du workflow
  de release dans `CLAUDE.md` (§5) et lien depuis le `README.md`.
- Favicon SVG (note de musique, slate-900) en `public/favicon.svg`,
  référencée depuis `base.html.twig`.
- Variable d'environnement `LASTFM_USER` : pré-remplit le champ
  « Identifiant Last.fm » du formulaire d'import et sert de fallback
  quand l'argument `lastfm-user` est omis sur la CLI
  (`app:lastfm:import`).
- Variable d'environnement `LASTFM_PAGE_DELAY_SECONDS` (défaut 10) :
  pause configurable entre deux pages successives de l'API Last.fm
  pour éviter le rate-limiting. Passer à 0 pour désactiver.
- Période d'import (`date_min`, `date_max`) ajoutée aux métriques
  persistées des runs Last.fm — visible directement dans la colonne
  Métriques de l'historique et dans le dump JSON de la page détail.
- Compteur de scrobbles affiché dans la card « Table scrobbles » du
  dashboard (`SELECT COUNT(*) FROM scrobbles`, formaté avec séparateur
  de milliers).
- Pipeline GitLab CI (`.gitlab-ci.yml`) miroir des GitHub Actions :
  5 jobs (phpcs, phpstan, tests matrix 8.3+8.4, docker-build,
  docker-publish multi-arch). Pousse vers le registre du projet par
  défaut, override via la variable `REGISTRY_IMAGE`.
- Page **Historique Last.fm** (`/stats/lastfm-history`) dans le menu
  Statistiques : affiche les 100 derniers scrobbles cachés en local
  pour le user Last.fm courant (`LASTFM_USER` ou `?user=` dans
  l'URL). Bouton Rafraîchir qui interroge `user.getRecentTracks` et
  fait un wipe + re-insert atomique en transaction. Cache stocké
  dans la nouvelle table `lastfm_history` (migration
  `Version20260501300000`). Run audité dans `RunHistory` avec la
  référence `history:<user>`.
- Page **Historique Navidrome** (`/stats/navidrome-history`), pendant
  symétrique de la précédente : snapshot des 100 derniers scrobbles
  de la table `scrobbles` Navidrome, joins media_file pour artiste/
  titre/album, lien direct vers la fiche Navidrome de chaque
  morceau. Cache dans `navidrome_history` (migration
  `Version20260501400000`), refresh atomique. Nouveau helper
  `NavidromeRepository::getRecentScrobbles(int $limit)`.
- Audit **par-track** des imports Last.fm : chaque scrobble traité
  par un import (CLI ou UI) est désormais persisté dans la nouvelle
  table `lastfm_import_track` (migration `Version20260501500000`)
  avec son statut (`inserted` / `duplicate` / `unmatched`), l'id du
  media_file matché si applicable, le mbid Last.fm, et un FK CASCADE
  vers `run_history`. La page de détail d'un run (`/history/{id}`)
  affiche pour les `lastfm-import` un tableau filtrable par statut
  + recherche full-text sur artiste/titre. Filtre par défaut sur
  les non matchés s'il y en a, sinon tous (page jamais surprenante­
  ment vide). Limité à 500 lignes par vue avec un message si
  tronqué.

### Changed
- `RunHistoryRecorder::record()` passe maintenant la `RunHistory`
  fraîchement persistée à l'action callback en premier argument —
  permet aux callers d'attacher des entités enfants au run via FK
  pendant l'exécution. Les arrow-fns existantes ignorent
  l'argument supplémentaire (PHP discard les args en trop).
- `LastFmImporter::import()` accepte un nouveau callback optionnel
  `onScrobble(scrobble, status, mediaFileId|null)` appelé une fois
  par scrobble traité, utilisé par les callers qui veulent un audit
  détaillé.
- `NavidromeRepository::findMediaFileByArtistTitle()` ne renvoie
  plus `null` quand la même chanson existe sur plusieurs albums.
  Pick déterministe : préfère la row où `album_artist = artist`
  (album studio canonique vs compilation tierce), tie-break par
  `id` ASC. Conséquence : un import Last.fm matche désormais les
  morceaux présents dans plusieurs versions au lieu de les laisser
  unmatched.
- Fallback featuring sur le matching : quand l'artiste Last.fm
  est `Orelsan feat. Thomas Bangalter` (ou variante `ft.` /
  `featuring` / `(feat. …)`) et que le strict-match échoue, retry
  une fois avec uniquement l'artiste lead (`Orelsan`). Couvre les
  cas où Navidrome ne crédite que l'artiste principal sur la
  piste alors que Last.fm cite tous les featuring.
- Fallback marqueurs de version sur le titre : `Soleil Bleu -
  Radio Edit` et `Soleil Bleu (Radio Edit)` retombent sur
  `Soleil Bleu` quand le strict-match échoue. Couvre Radio /
  Album / Single / Extended Edit/Mix/Version, Mono/Stereo
  Version, Remaster(ed) avec ou sans année. Live / Remix /
  Acoustic / Demo / Instrumental sont volontairement **non**
  strippés (différents enregistrements). Les deux fallbacks
  (featuring artiste + version titre) se cumulent : `Orelsan
  feat. Stromae` / `La pluie - Radio Edit` matche bien
  `Orelsan` / `La pluie`.

### Changed
- Page historique des runs : la colonne Métriques masque maintenant
  les valeurs nulles ou vides plutôt que d'afficher `clé=`.
- Preview d'une playlist : la colonne « Plays » reflète désormais le
  total d'écoutes **sur la période du générateur** (top 30 derniers
  jours → plays sur 30 jours, etc.) au lieu du compteur lifetime.
  Pour les générateurs sans période (`top-all-time`,
  `never-played`, `songs-you-used-to-love`), le comportement reste
  inchangé (lifetime, sous-titre `lifetime` ajouté pour clarté).

### Internal
- Nouvelle méthode `PlaylistGeneratorInterface::getActiveWindow()`
  qui retourne `['from', 'to']` ou `null`. Implémentée dans les 8
  générateurs livrés. `NavidromeRepository::summarize()` accepte
  maintenant `?\DateTimeInterface $from, $to` ; quand fournis et que
  la table `scrobbles` existe, le compte de plays vient de
  `scrobbles` au lieu de `annotation.play_count`.

### Fixed
- Page Wrapped : disparition du warning PHP « non-numeric value
  encountered » qui pétait le rendu du bouton « + Créer une playlist
  Top YYYY » à `wrapped/show.html.twig:57`. Causé par
  `number_format(0)` qui injectait un séparateur de milliers dans la
  string d'année avant la soustraction.
- Page `/stats` (période *All-time*) : le total d'écoutes ne
  bougeait plus, même après un refresh, parce que les requêtes
  all-time interrogeaient `annotation.play_count` (qui n'est jamais
  mis à jour par l'import Last.fm — Navidrome ne propage pas) au lieu
  de `scrobbles`. Les 4 méthodes du repo (`getTotalPlays`,
  `getDistinctTracksPlayed`, `getTopArtists`,
  `getTopTracksWithDetails`) utilisent maintenant `scrobbles` aussi
  pour all-time quand la table existe ; fallback `annotation` inchangé
  quand `scrobbles` est absente.

<!--
Sections disponibles pour les futures entrées :
### Added       — nouvelles fonctionnalités
### Changed     — modifications d'une fonctionnalité existante
### Deprecated  — fonctionnalités bientôt retirées
### Removed     — fonctionnalités retirées
### Fixed       — corrections de bugs
### Security    — failles corrigées
-->

<!--
Template pour une release (à coller au-dessus des releases existantes,
en-dessous du bloc [Unreleased] qui doit toujours rester en tête) :

## [X.Y.Z] - YYYY-MM-DD

### Added
- ...

### Fixed
- ...
-->
