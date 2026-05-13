# Navidrome Tools

[![CI](https://github.com/kgaut/navidrome-playlist-generator/actions/workflows/ci.yml/badge.svg)](https://github.com/kgaut/navidrome-playlist-generator/actions/workflows/ci.yml)

Boîte à outils web self-hosted (Symfony 7) autour de
[Navidrome](https://www.navidrome.org/) : générateur de playlists basé
sur les écoutes, statistiques détaillées, import des scrobbles
Last.fm, intégration Lidarr et historique des runs cron.

> Le repo Git s'appelle toujours `navidrome-playlist-generator` ; le
> projet et l'image Docker s'appellent désormais `navidrome-tools`.

## Fonctionnalités

- **Génération de playlists** par règle, basée sur les écoutes
  Navidrome. 8 générateurs livrés + système de plugins (cf.
  [`docs/PLUGINS.md`](docs/PLUGINS.md)).
- **Statistiques d'écoute** : 7 pages cachées (tops, compare, charts,
  heatmaps, histoires Last.fm/Navidrome, wrapped annuel). Cf.
  [`docs/STATS.md`](docs/STATS.md).
- **Import Last.fm** en deux étapes (fetch → process) avec matching
  cascade à 4 paliers + alias track/artiste + re-match cumulé.
  Cf. [`docs/LASTFM.md`](docs/LASTFM.md).
- **Sync loved ↔ starred** entre Last.fm et Navidrome.
- **Intégration Lidarr** pour pousser un artiste manquant en un clic.
  Cf. [`docs/LIDARR.md`](docs/LIDARR.md).
- **Tagging assisté** : page « tracks sans MBID » + export CSV /
  queue beets. Cf. [`docs/TAGGING.md`](docs/TAGGING.md).
- **Pilotage du conteneur Navidrome** depuis le dashboard (start /
  stop + pré-flight + `--auto-stop` sur les commandes qui écrivent).
- **Historique des runs cron** avec audit par-track des imports.
  Cf. [`docs/CRON.md`](docs/CRON.md).
- **Notifications de fin de run** vers Gotify / Slack / Discord /
  Pushover (broadcast supporté). Cf. [`docs/NOTIFICATIONS.md`](docs/NOTIFICATIONS.md).
- **Auth UI** : un seul user défini dans `.env`, pas de base
  utilisateurs.
- **Image Docker** légère (FrankenPHP, multi-arch amd64/arm64) qui
  sert l'UI et expose les commandes Symfony via `APP_MODE=cli`.

## Documentation

Toute la doc utilisateur vit dans le dossier [`docs/`](docs/) :

| Document                              | Contenu                                                                  |
|---------------------------------------|--------------------------------------------------------------------------|
| [`DOCKER.md`](docs/DOCKER.md)         | Déploiement Docker Compose (intégration à une stack existante + Caddy).  |
| [`ENVIRONMENT.md`](docs/ENVIRONMENT.md) | Référence exhaustive de toutes les variables d'environnement.          |
| [`DEVELOPMENT.md`](docs/DEVELOPMENT.md) | Dev local (Lando ou natif), tests, qualité, éditeur.                   |
| [`CRON.md`](docs/CRON.md)             | Schedule des jobs récurrents + historique des runs.                      |
| [`LASTFM.md`](docs/LASTFM.md)         | Import scrobbles, matching, alias, rematch, sync loved↔starred.          |
| [`STATS.md`](docs/STATS.md)           | Toutes les pages stats / wrapped / histories.                            |
| [`PLAYLISTS.md`](docs/PLAYLISTS.md)   | Génération, gestion, schéma Navidrome utilisé.                           |
| [`LIDARR.md`](docs/LIDARR.md)         | Intégration Lidarr (bouton « + Lidarr »).                                |
| [`TAGGING.md`](docs/TAGGING.md)       | Tracks sans MBID, export CSV, queue beets.                               |
| [`HOMEPAGE.md`](docs/HOMEPAGE.md)     | Widget Homepage (gethomepage.dev) + healthcheck Docker.                  |
| [`NOTIFICATIONS.md`](docs/NOTIFICATIONS.md) | Notifications de fin de run (Gotify, Slack, Discord, Pushover).    |
| [`PLUGINS.md`](docs/PLUGINS.md)       | Créer un nouveau type de générateur de playlist.                         |

Contexte projet (stack, architecture, conventions, pièges connus,
roadmap) : [`CLAUDE.md`](CLAUDE.md).

## Quickstart Docker

> Guide complet (intégration à une stack existante, networks Docker
> partagés, exemple de Caddyfile, troubleshooting) :
> [`docs/DOCKER.md`](docs/DOCKER.md).

```bash
# 1. Récupérer le compose et l'éditer
curl -O https://raw.githubusercontent.com/kgaut/navidrome-playlist-generator/main/docker-compose.example.yml
cp docker-compose.example.yml docker-compose.yml

# 2. Créer un .env avec vos secrets
cat > .env <<EOF
APP_SECRET=$(openssl rand -hex 32)
NAVIDROME_URL=http://navidrome:4533
NAVIDROME_USER=admin
NAVIDROME_PASSWORD=...                  # mot de passe Navidrome
APP_AUTH_USER=admin
APP_AUTH_PASSWORD=...                   # mot de passe pour l'UI
NAVIDROME_DATA_DIR=/srv/navidrome/data  # dossier qui contient navidrome.db
EOF

# 3. Lancer le service web
docker compose up -d

# 4. Ouvrir l'UI
open http://localhost:8080
```

Au premier lancement, 4 définitions de playlist d'exemple sont créées
**désactivées** : Top 7j, Top 30j, Top mois passé, Top année passée.
Vous pouvez les éditer puis les activer.

### Mise à jour

```bash
docker compose pull
docker compose up -d
```

Les migrations Doctrine sont jouées automatiquement à chaque
démarrage (idempotent). Le volume `navidrome-tools-data` préserve la
configuration entre redémarrages.

## Image Docker publiée

L'image officielle est publiée sur GitHub Container Registry
(`ghcr.io/kgaut/navidrome-tools`) par la CI :

| Évènement                | Tags publiés                                              |
|--------------------------|-----------------------------------------------------------|
| Push sur `main`          | `latest`, `main-<sha7>`                                   |
| Push d'un tag `v1.2.3`   | `1.2.3`, `1.2`, `1`, `latest`                             |
| Push sur autre branche   | `<nom-de-branche>` (utile pour tester un PR mergé)        |
| Pull request             | aucun push, build de validation seulement                 |

Image multi-arch : `linux/amd64` + `linux/arm64`.

```bash
docker pull ghcr.io/kgaut/navidrome-tools:latest
```

> Pour héberger un miroir sur GitLab, le repo livre aussi un
> [`.gitlab-ci.yml`](.gitlab-ci.yml) avec les mêmes jobs (phpcs,
> phpstan, tests PHP 8.4, docker build + publish multi-arch). Par
> défaut il pousse vers `$CI_REGISTRY_IMAGE` ; surcharger via
> `REGISTRY_IMAGE` dans Settings → CI/CD pour viser une autre cible.

## Pré-requis

- **Navidrome ≥ 0.55** (la table `scrobbles` est requise pour la
  plupart des fonctionnalités). Avec une 0.54, l'app fonctionne avec
  des stats approximatives.
- **PHP 8.4 minimum** (image officielle), Composer 2 si vous buildez
  vous-même.
- Lecture sur le fichier SQLite Navidrome ; écriture seulement pour
  `app:lastfm:process` et `app:lastfm:rematch` (cf.
  [`docs/LASTFM.md`](docs/LASTFM.md)).

## Changelog

L'historique des évolutions est tenu dans [`CHANGELOG.md`](CHANGELOG.md)
au format [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/).

## Licence

MIT.
