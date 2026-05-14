# Claude Code — contexte du projet (v2)

Ce fichier est lu automatiquement par Claude Code. Il documente l'état courant
de la branche `develop`. La POC est taguée `poc-v0` sur `main`.

## Stack

- **PHP 8.4** — `composer.json` pinne `config.platform.php=8.4.0`
- **Symfony 7.1** + Doctrine ORM 3 / DBAL 4 / Migrations 3
- **Symfony Messenger** — transport `doctrine` (SQLite, `messenger_messages`)
- **SQLite** — DB outils locale (`var/data.db`) + Navidrome (`:ro` en prod)
- **FrankenPHP 1** (image `dunglas/frankenphp:1-php8.4-alpine`, multi-arch)
- `APP_MODE=web|worker|cli` dans `docker/entrypoint.sh`

## Architecture cible

Voir `ROADMAP.md` pour le lotissement complet.

Modules prévus :
```
src/
├── LastFm/        → fetch Last.fm + matching cascade (ScrobbleMatcher)
├── Navidrome/     → bridge DB SQLite, ContainerManager, backup
├── Strawberry/    → sync playcount, upload/download service
├── Message/       → DTOs Messenger
├── MessageHandler/→ handlers async (worker)
├── Notifier/      → drivers Gotify/Slack/Discord/Pushover
├── Security/      → EnvUserProvider (single user via env)
├── Doctrine/      → UtcDateTimeImmutableType
└── Service/       → RunHistoryRecorder, BackupService, …
```

## Pièges hérités de la POC

1. `APP_CACHE_DIR=/app/.symfony-cache` — NE PAS monter dans un volume partagé
   entre le conteneur web et le worker, sinon race sur `rm -rf cache/prod`.
2. `scrobbles.submission_time` Navidrome est INTEGER unix epoch (≥ 0.55).
   Binder avec `getTimestamp()` + `ParameterType::INTEGER`, ajouter
   `'unixepoch'` aux `strftime`/`date`/`datetime` SQLite.
3. `InMemoryUser` est `final` → utiliser `EnvUser` avec `EquatableInterface`
   pour éviter la boucle de déconnexion.
4. SQLite WAL checkpoint : `NAVIDROME_STOP_TIMEOUT_SECONDS=60` minimum avant
   SIGKILL, + polling `docker inspect` jusqu'à `Running=false`.
5. DBAL 4 : `ParameterType::INTEGER` (plus `\PDO::PARAM_INT`).
6. Twig 3 : pas de `{% for k, v in arr if cond %}` — utiliser `|filter(…)`.

## Conventions

- PSR-12 (ligne max 160), PHPStan niveau 6
- `composer ci` → phpcs + phpstan + phpunit
- Conventional Commits : `feat(scope):`, `fix(scope):`, `refactor(scope):`, etc.
- Tests : un par méthode publique non triviale, fixtures SQLite en mémoire
- Migrations : une par feature, nommée `VersionYYYYMMDDHHMMSS`
