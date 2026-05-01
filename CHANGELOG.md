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
