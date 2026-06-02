# Développement local

Environnement de dev basé sur Docker Compose (FrankenPHP + PHP 8.4 + SQLite),
au plus proche de la prod mais avec le code monté en volume et les dépendances
de dev installées.

## Démarrage

```bash
# 1. (si absent) créer le .env de dev
cp .env.dist .env        # le dépôt en fournit déjà un avec des valeurs de dev

# 2. lancer web + worker
docker compose -f docker-compose.dev.yml up --build
```

- Application : http://localhost:8080 (login `admin` / `changeme`, cf. `.env`).
- Le premier démarrage installe `vendor/` (avec deps dev) puis applique les
  migrations Doctrine ; le worker patiente que le web ait fini.
- Le code est monté en volume : modifiez les fichiers, rechargez la page.

Pour changer le port : `WEB_PORT=8081 docker compose -f docker-compose.dev.yml up`.

## Commandes courantes

```bash
DC="docker compose -f docker-compose.dev.yml"

$DC exec web composer ci                 # phpcs + phpstan + phpunit
$DC exec web composer phpstan
$DC exec web php bin/console <commande>   # console Symfony
$DC exec web php bin/console messenger:stats
$DC logs -f worker                        # suivre les jobs async
$DC down                                  # arrêter (var/data.db conservée)
```

## Notes

- Les conteneurs tournent avec votre uid hôte (1000 par défaut) ; surchargez
  avec `UID=$(id -u) GID=$(id -g) docker compose -f docker-compose.dev.yml up`
  si votre uid diffère, pour garder `var/`/`vendor/` éditables.
- `/app/var` (cache, sessions, SQLite `var/data.db`) vit dans le volume Docker
  `dev-var`, pas dans le dépôt — évite les conflits de permissions du
  bind-mount. Il survit aux `down`. Repartir de zéro :
  `docker compose -f docker-compose.dev.yml down -v`.
  Inspecter la base : `$DC exec web sqlite3 var/data.db`.
- Aucun Navidrome n'est inclus : renseignez `NAVIDROME_URL` / `NAVIDROME_DB_PATH`
  dans `.env` (ou montez votre base) pour tester le sync.
- Pour la prod, voir `docker-compose.example.yml` (image prébuildée
  `ghcr.io/kgaut/navidrome-tools`).
