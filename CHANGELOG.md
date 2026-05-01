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
