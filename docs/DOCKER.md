# Déploiement via Docker Compose

Ce guide décrit la mise en place de **navidrome-tools** via Docker
Compose, depuis la récupération des fichiers de référence jusqu'à
l'exposition de l'UI derrière un reverse proxy Caddy.

Deux scénarios sont couverts :

- **Scénario A** : intégration à une stack Docker existante (Navidrome
  + beets, le cas le plus fréquent).
- **Scénario B** : création d'une stack complète from scratch.

Pour le tableau exhaustif des variables d'environnement, voir le
[`README.md`](../README.md#variables-denvironnement). Pour
ajouter ses propres générateurs de playlist, voir
[`docs/PLUGINS.md`](PLUGINS.md).

## 1. Prérequis

- Docker Engine 20.10+ et le plugin Compose v2 (`docker compose ...`).
- Une instance Navidrome **0.55+** déjà déployée (ou prête à l'être).
- L'accès en lecture au fichier SQLite `navidrome.db` côté hôte.
- Un user Subsonic (login + mot de passe Navidrome) pour l'API.

## 2. Récupérer les fichiers de référence

Le repo Git s'appelle toujours `navidrome-playlist-generator` (le
projet et l'image Docker s'appellent `navidrome-tools`, mais le repo
n'a pas été renommé). Les fichiers `docker-compose.example.yml` et
`.env.dist` se récupèrent directement depuis la branche `main` :

```bash
mkdir -p /srv/navidrome-tools && cd /srv/navidrome-tools

curl -fsSL -o docker-compose.yml \
  https://raw.githubusercontent.com/kgaut/navidrome-playlist-generator/main/docker-compose.example.yml

curl -fsSL -o .env \
  https://raw.githubusercontent.com/kgaut/navidrome-playlist-generator/main/.env.dist
```

Éditer ensuite `.env` pour renseigner au minimum :

```bash
APP_SECRET=$(openssl rand -hex 32)        # 32 caractères hex
NAVIDROME_PASSWORD=...                    # mot de passe Navidrome
APP_AUTH_PASSWORD=...                     # mot de passe pour l'UI du tool
NAVIDROME_DATA_DIR=/srv/navidrome/data    # dossier qui contient navidrome.db
```

Toutes les autres variables ont des valeurs par défaut raisonnables
(cf. `.env.dist` annoté).

## 3. Image Docker

Image officielle :

```
ghcr.io/kgaut/navidrome-tools:latest
```

Multi-arch (`linux/amd64` + `linux/arm64`), publiée par la CI à chaque
push sur `main`. Les autres tags disponibles (`main-<sha7>`,
`1.2.3`, `1.2`, `1`) sont décrits dans la section
[« Image Docker publiée » du README](../README.md#image-docker-publiée).

```bash
docker pull ghcr.io/kgaut/navidrome-tools:latest
```

## 4. Architecture du service

Le compose lance **un seul conteneur** à partir de l'image
`ghcr.io/kgaut/navidrome-tools` :

| Service                  | `APP_MODE` | Rôle                                              | Port |
|--------------------------|------------|---------------------------------------------------|------|
| `navidrome-tools-web`    | `web`      | UI HTTP (FrankenPHP + Caddy intégré)              | 8080 |

L'entrypoint joue les migrations Doctrine au démarrage (idempotent).

Les jobs récurrents (génération de playlists, refresh stats, fetch /
process Last.fm, purge d'historique) sont **lancés depuis le crontab
unix de l'hôte** via `docker compose exec -T navidrome-tools-web
php bin/console <cmd>` — il n'y a plus de service cron embarqué dans
l'image. Voir la section [« Lancement des jobs récurrents »](../README.md#lancement-des-jobs-récurrents)
du README pour des exemples de lignes crontab.

## 5. Points de montage (volumes)

| Type       | Source (host)                          | Cible (conteneur)        | Mode | Rôle                                                     |
|------------|----------------------------------------|--------------------------|------|----------------------------------------------------------|
| bind       | `${NAVIDROME_DATA_DIR}/navidrome.db`   | `/data/navidrome.db`     | `:ro`| Lecture du SQLite Navidrome                              |
| volume     | `navidrome-tools-data`                 | `/app/var`               | rw   | DB du tool (playlists, settings, runs, lastfm history)   |
| volume     | `navidrome-tools-covers`               | `/app/var/covers`        | rw   | Cache des pochettes album/artiste (peut peser ~MB)       |
| bind (opt.)| `/srv/shared/queue.txt`                | `/shared/queue.txt`      | rw   | Beets queue (cf. `BEETS_QUEUE_PATH`)                     |

> **Avertissement** : le mode `:ro` sur `navidrome.db` est sans risque
> tant qu'aucun job n'écrit dans Navidrome. Les commandes
> `app:lastfm:process` et `app:lastfm:rematch` écrivent dans la table
> `scrobbles` de Navidrome — elles nécessitent un **mount RW** *et*
> Navidrome **arrêté** pendant l'opération (le flag `--auto-stop`
> orchestre stop/run/restart quand `NAVIDROME_CONTAINER_NAME` est
> configuré). `app:lastfm:import` (le fetch) ne touche pas Navidrome
> et peut tourner avec le mount `:ro`.

## 6. Networks Docker et URL internes

Le `docker-compose.example.yml` ne déclare **pas** de network nommé :
Compose crée alors un network par projet (`<dossier>_default`), partagé
par tous les services du même `docker-compose.yml`. Trois cas pratiques
à distinguer.

### 6.a Tool dans la même stack que Navidrome (recommandé)

Aucune config réseau spéciale : si `navidrome` et `navidrome-tools-web`
sont déclarés dans le même `docker-compose.yml`, ils se voient via
leur nom de service.

Dans `.env` du tool :

```env
NAVIDROME_URL=http://navidrome:4533
LIDARR_URL=http://lidarr:8686       # si Lidarr aussi dans la stack
```

### 6.b Navidrome dans une autre stack (network `external`)

Cas typique : Navidrome tourne déjà dans `/srv/navidrome/docker-compose.yml`
et on veut ajouter navidrome-tools sans tout réorganiser.

**Étape 1.** Sur la stack Navidrome, déclarer un network nommé partagé :

```yaml
networks:
  media:
    name: media

services:
  navidrome:
    # ...
    networks:
      - media
```

**Étape 2.** Dans le `docker-compose.yml` de navidrome-tools, ajouter
ce network en `external: true` et l'attacher aux deux services :

```yaml
networks:
  media:
    external: true

services:
  navidrome-tools-web:
    # ... (config existante)
    networks:
      - media
```

**Étape 3.** Garder `NAVIDROME_URL=http://navidrome:4533` dans `.env` :
le DNS interne Docker résout le hostname via le network partagé.

### 6.c Navidrome sur une autre machine

Pas de network Docker partageable → utiliser l'URL publique :

```env
NAVIDROME_URL=https://navidrome.exemple.com
```

Le bind-mount de `navidrome.db` doit alors pointer vers une **copie
accessible** depuis la machine qui héberge le tool (NFS, rsync,
syncthing). Attention à la fraîcheur de la copie pour les pages
`/stats` et `/history`.

## 7. Scénario A : intégration à une stack existante

Cas typique : `/srv/media/docker-compose.yml` contient déjà
`navidrome`, `beets`, `lidarr` et un reverse proxy.

1. Récupérer le bloc `services:` de
   [`docker-compose.example.yml`](https://raw.githubusercontent.com/kgaut/navidrome-playlist-generator/main/docker-compose.example.yml)
   et le coller dans le compose existant (ou utiliser un
   `compose.override.yml` chargé en plus).

2. Attacher le service `navidrome-tools-web` au network qui contient
   déjà Navidrome (cf. §6.b). Si la stack utilise déjà un seul network
   par défaut, il n'y a rien à faire — tous les services y sont déjà.

3. Ajouter au `.env` racine de la stack les variables minimales (cf. §2)
   plus celles du tool. Compose lit `.env` automatiquement à côté du
   `docker-compose.yml`.

4. **Beets queue partagée** (optionnel — pour pousser des chemins
   `/music/...` depuis `/tagging/missing-mbid` vers un beets externe) :

   ```yaml
   services:
     beets:
       volumes:
         - /srv/shared:/shared           # rw, le cron beets consomme la queue
         - /srv/media/music:/music:ro    # déjà présent

     navidrome-tools-web:
       volumes:
         - /srv/shared:/shared           # même mount, rw
         # ... volumes existants
   ```

   Dans `.env` :

   ```env
   BEETS_QUEUE_PATH=/shared/queue.txt
   ```

   Côté beets, un cron consomme la queue (à adapter selon votre setup) :

   ```bash
   # /etc/cron.d/beets-queue
   */15 * * * * root flock -n /tmp/beets.lock -c \
     'test -s /srv/shared/queue.txt && \
      docker compose -f /srv/media/docker-compose.yml exec -T beets \
        sh -c "xargs -a /shared/queue.txt beet import -A --quiet && : > /shared/queue.txt"'
   ```

5. Démarrer uniquement le nouveau service pour ne pas perturber le
   reste de la stack :

   ```bash
   docker compose up -d navidrome-tools-web
   docker compose logs -f navidrome-tools-web
   ```

6. Configurer les jobs récurrents dans le **crontab unix** de l'hôte
   (cf. [README — Lancement des jobs récurrents](../README.md#lancement-des-jobs-récurrents))
   plutôt que dans un service cron Docker.

## 8. Scénario B : stack complète from scratch

Pour un nouvel auto-hébergeur. Le compose ci-dessous lance Navidrome,
les deux services du tool et Caddy comme reverse proxy. Beets et
Lidarr sont laissés de côté volontairement — ils peuvent s'ajouter
plus tard.

`docker-compose.yml` :

```yaml
networks:
  media:
    name: media

volumes:
  navidrome-data:
  navidrome-tools-data:
  navidrome-tools-covers:
  caddy_data:
  caddy_config:

services:
  navidrome:
    image: deluan/navidrome:latest
    restart: unless-stopped
    user: "1000:1000"
    environment:
      ND_LOGLEVEL: info
      ND_SCANSCHEDULE: 1h
    volumes:
      - navidrome-data:/data
      - /srv/navidrome/music:/music:ro
    networks:
      - media

  navidrome-tools-web:
    image: ghcr.io/kgaut/navidrome-tools:latest
    restart: unless-stopped
    environment:
      APP_MODE: web
      APP_ENV: prod
      APP_SECRET: ${APP_SECRET:?set APP_SECRET in .env}
      APP_TIMEZONE: ${APP_TIMEZONE:-Europe/Paris}
      NAVIDROME_DB_PATH: /data/navidrome.db
      NAVIDROME_URL: http://navidrome:4533
      NAVIDROME_USER: ${NAVIDROME_USER:-admin}
      NAVIDROME_PASSWORD: ${NAVIDROME_PASSWORD:?set NAVIDROME_PASSWORD in .env}
      APP_AUTH_USER: ${APP_AUTH_USER:-admin}
      APP_AUTH_PASSWORD: ${APP_AUTH_PASSWORD:?set APP_AUTH_PASSWORD in .env}
      DATABASE_URL: "sqlite:///%kernel.project_dir%/var/data.db"
      COVERS_CACHE_PATH: /app/var/covers
    volumes:
      - navidrome-data:/data:ro
      - navidrome-tools-data:/app/var
      - navidrome-tools-covers:/app/var/covers
    networks:
      - media
    depends_on:
      - navidrome

  caddy:
    image: caddy:2-alpine
    restart: unless-stopped
    ports:
      - "80:80"
      - "443:443"
    volumes:
      - ./Caddyfile:/etc/caddy/Caddyfile:ro
      - caddy_data:/data
      - caddy_config:/config
    networks:
      - media
```

À noter : ici Navidrome et navidrome-tools partagent le **même volume
nommé** `navidrome-data` (Navidrome écrit, le tool lit en `:ro`). Pas
besoin de bind-mount host comme dans le compose example.

## 9. Reverse proxy avec Caddy

navidrome-tools écoute en HTTP sur le port 8080 (FrankenPHP + Caddy
embarqué — pas de TLS interne). Pour exposer en HTTPS public, le plus
simple est de placer **un Caddy externe** devant.

### 9.a Caddy en service Docker (recommandé)

`Caddyfile` (à placer à côté du `docker-compose.yml`) :

```caddyfile
navidrome.exemple.com {
    encode zstd gzip
    reverse_proxy navidrome:4533
}

navidrome-tools.exemple.com {
    encode zstd gzip
    reverse_proxy navidrome-tools-web:8080
}
```

Caddy gère HTTPS automatiquement (Let's Encrypt) à condition que :

- les enregistrements DNS A/AAAA pointent vers le serveur ;
- les ports 80 et 443 soient ouverts et redirigés vers le conteneur
  Caddy ;
- le service `caddy` soit dans le **même network** que les backends
  (cf. §8 : `networks: [media]`).

Pour appliquer une modification du Caddyfile sans redémarrer le
conteneur :

```bash
docker compose exec caddy caddy reload --config /etc/caddy/Caddyfile
```

### 9.b Caddy installé sur l'hôte (pas dans Docker)

Si Caddy tourne déjà nativement sur la machine, exposer le port 8080
côté hôte (le compose example le fait par défaut) et pointer
`reverse_proxy` vers `localhost` :

```caddyfile
navidrome-tools.exemple.com {
    encode zstd gzip
    reverse_proxy localhost:8080
}
```

### 9.c Autres reverse proxies

- **Traefik** : ajouter des labels `traefik.http.routers.navidrome-tools.*`
  sur le service `navidrome-tools-web` et l'attacher au network
  Traefik. La règle est la même que pour n'importe quel backend HTTP
  port 8080.
- **Nginx Proxy Manager** : créer un Proxy Host vers
  `http://navidrome-tools-web:8080` (en attachant NPM au network
  partagé).

## 10. Premier démarrage et vérifications

```bash
docker compose up -d
docker compose logs -f navidrome-tools-web
```

Points à vérifier :

- **Migrations** : les logs doivent contenir `[OK] Already at the
  latest version` ou `Migration vXX executed successfully`.
- **UI** : ouvrir `http://localhost:8080` (ou l'URL configurée dans
  Caddy) → page de login → utiliser `APP_AUTH_USER` /
  `APP_AUTH_PASSWORD`.
- **Connexion Navidrome** : la card *Table scrobbles* du dashboard
  doit afficher un compteur > 0 si Navidrome ≥ 0.55 et que le
  bind-mount fonctionne.
- **Stats** : `/stats` doit afficher le top 10 artistes / top 50
  morceaux. Si la page est vide, lancer un refresh manuel.
- **Jobs récurrents** : ils ne tournent **pas** dans le conteneur —
  c'est le crontab unix de l'hôte qui les déclenche via
  `docker compose exec`. Vérifier ses propres logs cron / le retour
  de la commande exécutée.

## 11. Mise à jour

```bash
docker compose pull
docker compose up -d
```

Les migrations Doctrine sont jouées automatiquement au démarrage. Le
volume `navidrome-tools-data` préserve la configuration entre
redémarrages.

## 12. Troubleshooting

| Symptôme                                              | Cause probable / solution                                                                 |
|-------------------------------------------------------|-------------------------------------------------------------------------------------------|
| `no such file or directory: /data/navidrome.db`       | `NAVIDROME_DATA_DIR` ne pointe pas vers le bon dossier hôte, ou le fichier n'existe pas.  |
| Dashboard : « impossible de joindre Navidrome »       | Network non partagé. Tester `docker compose exec navidrome-tools-web wget -qO- http://navidrome:4533/ping`. |
| Job cron ne tourne pas                                | Le tool n'a plus de cron embarqué — c'est un crontab unix qui doit invoquer `docker compose exec -T navidrome-tools-web …`. Vérifier la ligne crontab et `docker compose logs navidrome-tools-web` pour voir l'invocation arriver. |
| 502 Bad Gateway derrière Caddy                        | Caddy n'est pas dans le même network que `navidrome-tools-web`. `docker network inspect media` doit lister les deux. |
| Pochettes manquantes ou cassées                       | Vérifier le volume `navidrome-tools-covers` (espace disque, permissions du user FrankenPHP). |
| Login en boucle (renvoie sur `/login` après submit)   | `APP_SECRET` vide ou changé entre redémarrages. Doit être stable.                         |
| `app:lastfm:process` échoue avec « database is locked »| Navidrome doit être **arrêté** (ou utiliser `--auto-stop`).                              |

## 13. Pour aller plus loin

- [`README.md`](../README.md#variables-denvironnement) — tableau
  exhaustif des variables d'environnement.
- [`README.md`](../README.md#lancement-des-jobs-récurrents) — lignes
  crontab unix prêtes à coller pour les jobs récurrents.
- [`docs/PLUGINS.md`](PLUGINS.md) — créer ses propres générateurs de
  playlist.
- [`CHANGELOG.md`](../CHANGELOG.md) — détail chronologique des
  changements.
