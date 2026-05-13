# Développement local

Deux options sont supportées : [Lando](https://lando.dev/) (recommandé,
zéro install local) ou un setup natif PHP 8.4 + Symfony CLI.

## Avec Lando (recommandé)

Lando fournit l'environnement complet sans rien installer sur l'hôte.
La stack expose **deux services** :

- `appserver` — Symfony 7 (PHP 8.4 + nginx + Composer 2), UI sur
  `https://navidrome-tools.lndo.site`.
- `navidrome` — instance Navidrome de test sur
  `https://navidrome.lndo.site`, qui partage sa DB SQLite avec le tool
  via `var/navidrome-data/`.

### Setup initial

```bash
git clone https://github.com/kgaut/navidrome-playlist-generator
cd navidrome-playlist-generator

# Préparer le dossier partagé Navidrome ↔ tool (DB + cache).
mkdir -p var/navidrome-data

# (Optionnel) seeder avec votre vraie base, sinon Navidrome
# en créera une vide au premier boot.
cp /chemin/vers/navidrome.db var/navidrome-data/navidrome.db

# Copier le template Lando puis adapter le bind-mount du dossier
# musique (cherche « /change/me/path/to/music » dans .lando.yml).
# .lando.yml est gitignored.
cp .lando.yml.dist .lando.yml

lando start          # premier lancement : build + composer install
lando migrate        # crée la DB locale du tool
lando seed           # insère les 4 définitions d'exemple
```

URLs disponibles après `lando start` :

- UI tool : <https://navidrome-tools.lndo.site>
- Navidrome : <https://navidrome.lndo.site> (admin créé au premier
  démarrage via l'UI Navidrome elle-même)

### Commandes utiles

| Commande Lando                       | Effet                                                  |
|--------------------------------------|--------------------------------------------------------|
| `lando symfony cache:clear`          | Vider le cache Symfony.                                |
| `lando composer require X`           | Installer un package Composer.                         |
| `lando test`                         | Lancer PHPUnit.                                        |
| `lando migrate`                      | Jouer les migrations Doctrine.                         |
| `lando seed`                         | Réinsérer les fixtures (idempotent).                   |
| `lando playlist-run "Top 7j" --dry-run` | Tester une définition de playlist sans la publier.  |
| `lando logs -s navidrome -f`         | Suivre les logs du Navidrome embarqué.                 |

### Xdebug

Éditer votre copie locale `.lando.yml` et passer `xdebug: false` à
`xdebug: debug`, puis `lando rebuild -y`.

### Écrire dans la DB Navidrome sous Lando

`app:lastfm:process` et `app:lastfm:rematch` doivent être lancés
**Navidrome arrêté**. Le flag `--auto-stop` n'est pas câblé sous Lando
(c'est un setup dev, pas prod) — utilisez `lando stop navidrome` avant
puis `lando start navidrome` après :

```bash
lando stop navidrome
lando symfony app:lastfm:process
lando start navidrome
```

## Sans Lando

Pré-requis : PHP 8.4+, ext-pdo_sqlite, Composer 2,
[Symfony CLI](https://symfony.com/download).

```bash
composer install
cp .env.dist .env.local              # ajuster les valeurs
mkdir -p var
cp /chemin/vers/navidrome.db var/navidrome.db
php bin/console doctrine:migrations:migrate -n
php bin/console app:fixtures:seed
symfony serve                        # https://127.0.0.1:8000
```

> Si `composer install` rouspète sur le superuser, préfixer avec
> `COMPOSER_ALLOW_SUPERUSER=1` — c'est nécessaire pour que Symfony
> Flex tourne ses recipes et génère `vendor/autoload_runtime.php`.

## Qualité de code et tests

Le projet utilise PHPUnit 11, PHPStan niveau 6 et PHP_CodeSniffer
(PSR-12). Les trois tournent en CI sur chaque push / pull request,
plus un build de l'image Docker en parallèle.

```bash
composer test       # PHPUnit
composer phpstan    # Static analysis (level 6 + extensions Symfony/Doctrine/PHPUnit)
composer phpcs      # PSR-12 coding standard
composer phpcbf     # Auto-fix la plupart des erreurs PHPCS
composer ci         # phpcs + phpstan + tests, séquentiellement
```

Configuration : [`phpunit.xml.dist`](../phpunit.xml.dist),
[`phpstan.dist.neon`](../phpstan.dist.neon),
[`phpcs.xml.dist`](../phpcs.xml.dist).

Sous Lando :

```bash
lando composer test
lando composer phpstan
lando composer phpcs
lando composer ci
```

PHP cible : **8.4 minimum** (`composer.json` pinne
`config.platform.php=8.4.0`). La CI ne teste plus la 8.3 — le code
utilise certaines features 8.4 (chained `new ClassName(...)->method()`,
lazy objects natifs Doctrine).

## Configuration éditeur

Le repo livre une **config PhpStorm partageable** sous `.idea/`
(whitelistée dans `.gitignore`) :

- `.idea/codeStyles/` — schéma de code projet (PSR-12).
- `.idea/inspectionProfiles/Project_Default.xml` — inspections actives,
  câblées sur `phpcs.xml.dist` et `phpstan.dist.neon`.
- `.idea/php.xml` — version PHP, container Symfony, namespace Twig.
- `.idea/php-test-framework.xml` — PHPUnit version.

Le reste de `.idea/` (workspace, fichiers per-user) reste ignoré.

Un `.editorconfig` à la racine fournit le minimum universel pour les
autres éditeurs (VS Code, Vim, Sublime…) : indentation 4 espaces (2
pour YAML/JSON/XML/HTML/Twig), LF, UTF-8, trim trailing whitespace.

## Pour aller plus loin

- [`PLUGINS.md`](PLUGINS.md) — créer un nouveau type de générateur de
  playlist.
- [`CRON.md`](CRON.md) — schedule des jobs récurrents.
- [`ENVIRONMENT.md`](ENVIRONMENT.md) — référence des variables.
- [`CLAUDE.md`](../CLAUDE.md) — contexte projet complet (stack,
  architecture, pièges, conventions).
