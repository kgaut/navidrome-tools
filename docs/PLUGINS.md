# Ajouter un nouveau type de playlist (plugin)

Chaque type de playlist proposé par le tool est une classe PHP qui
implémente `App\Generator\PlaylistGeneratorInterface`. L'autoconfigure
de Symfony tague automatiquement toute classe dans `src/Generator/`
(côté projet) ou `plugins/` (extension utilisateur) qui implémente cette
interface, et `GeneratorRegistry` la rend disponible dans :

- la liste déroulante du formulaire de définition (`/playlist/new`),
- la commande `bin/console app:playlist:run`,
- le dump du crontab `bin/console app:cron:dump`.

**Aucun changement de configuration n'est nécessaire** : les paramètres
sont stockés en JSON dans la colonne `parameters` de
`playlist_definition`.

Deux emplacements possibles :

| Emplacement      | Namespace      | Cas d'usage                                            |
|------------------|----------------|--------------------------------------------------------|
| `src/Generator/` | `App\Generator\` | Générateurs livrés avec le projet (PR upstream).     |
| `plugins/`       | `App\Plugin\`    | Générateurs custom de l'utilisateur, conservés hors du repo, montés via volume Docker. |

Les deux fonctionnent strictement de la même manière — seul l'endroit où
poser le fichier change.

## L'interface

```php
namespace App\Generator;

interface PlaylistGeneratorInterface
{
    public function getKey(): string;          // identifiant kebab-case stable
    public function getLabel(): string;        // libellé humain
    public function getDescription(): string;  // description courte
    /** @return ParameterDefinition[] */
    public function getParameterSchema(): array;
    /**
     * @param array<string, mixed> $parameters
     * @return string[] media_file ids ordonnés (≤ $limit)
     */
    public function generate(array $parameters, int $limit): array;
}
```

`ParameterDefinition` accepte les types `int`, `string`, `bool` et
`choice` (avec `$choices` = `valeur => label`). Le formulaire est
construit dynamiquement à partir de ce schéma.

## Exemple complet : « Top des morceaux d'un genre »

Créer le fichier `src/Generator/TopByGenreGenerator.php` :

```php
<?php

namespace App\Generator;

use Doctrine\DBAL\Connection;

class TopByGenreGenerator implements PlaylistGeneratorInterface
{
    public function __construct(
        private readonly Connection $navidromeConnection, // injected via doctrine.dbal.navidrome_connection
    ) {}

    public function getKey(): string
    {
        return 'top-by-genre';
    }

    public function getLabel(): string
    {
        return 'Top morceaux par genre';
    }

    public function getDescription(): string
    {
        return 'Les morceaux les plus écoutés appartenant à un genre donné.';
    }

    public function getParameterSchema(): array
    {
        return [
            new ParameterDefinition(
                name: 'genre',
                label: 'Genre exact (ex. "Jazz")',
                type: ParameterDefinition::TYPE_STRING,
                default: 'Jazz',
            ),
        ];
    }

    public function generate(array $parameters, int $limit): array
    {
        $genre = (string) ($parameters['genre'] ?? '');
        $rows = $this->navidromeConnection->fetchAllAssociative(
            'SELECT mf.id
             FROM media_file mf
             JOIN annotation a ON a.item_id = mf.id AND a.item_type = "media_file"
             WHERE mf.genre = :genre
             ORDER BY a.play_count DESC
             LIMIT :lim',
            ['genre' => $genre, 'lim' => $limit],
            ['lim' => \PDO::PARAM_INT],
        );
        return array_map(static fn ($r) => (string) $r['id'], $rows);
    }
}
```

C'est tout. Recharger l'UI : « Top morceaux par genre » apparaît dans
le dropdown, avec un champ texte pour le genre.

## Bonnes pratiques

- **Réutiliser `App\Navidrome\NavidromeRepository`** quand c'est possible
  (helpers `topTracksInWindow`, `topAllTime`, `neverPlayedRandom`,
  `summarize`). Il gère la détection automatique de `scrobbles` vs
  `annotation` et la résolution du `user_id` Navidrome.
- **Toujours respecter `$limit`** : `LIMIT :lim` dans la requête, pas de
  troncature en PHP qui ferait remonter inutilement des milliers de lignes.
- **Ordre du résultat** : la position dans le tableau retourné est
  conservée dans la playlist Navidrome.
- **Paramètres** : préférer `TYPE_INT` ou `TYPE_CHOICE` à `TYPE_STRING`
  quand c'est possible — la validation est gratuite côté formulaire.
- **Pas d'effet de bord** : `generate()` doit être idempotent et
  read-only (la création de la playlist est faite par `PlaylistRunner`
  via Subsonic, pas par le générateur).

## Tester un nouveau plugin

```php
// tests/Generator/TopByGenreGeneratorTest.php
class TopByGenreGeneratorTest extends \PHPUnit\Framework\TestCase
{
    public function testRespectsLimit(): void
    {
        // construire une DB SQLite de fixture (cf. tests/fixtures)
        // instancier le générateur
        // assert: count($result) <= $limit
    }
}
```

Voir `tests/Generator/TopLastDaysGeneratorTest.php` pour un exemple
complet utilisant une fixture SQLite minimaliste.

## Plugins custom en déploiement Docker

Quand on déploie via l'image GHCR (`ghcr.io/kgaut/navidrome-tools`), on
ne peut évidemment pas éditer `src/Generator/` à l'intérieur du
conteneur. À la place, on bind-mount un dossier hôte sur `/app/plugins`
et on y dépose une ou plusieurs classes dans le namespace `App\Plugin\`.

### 1. Préparer un dossier `plugins/` sur l'hôte

```
mkdir -p /srv/navidrome-tools/plugins
```

### 2. Y déposer un générateur

Fichier `/srv/navidrome-tools/plugins/HelloPluginGenerator.php` :

```php
<?php

namespace App\Plugin;

use App\Generator\ParameterDefinition;
use App\Generator\PlaylistGeneratorInterface;
use App\Navidrome\NavidromeRepository;

class HelloPluginGenerator implements PlaylistGeneratorInterface
{
    public function __construct(
        private readonly NavidromeRepository $navidrome,
    ) {}

    public function getKey(): string
    {
        return 'hello-plugin';
    }

    public function getLabel(): string
    {
        return 'Plugin de démo';
    }

    public function getDescription(): string
    {
        return 'Renvoie le top all-time, mais c\'est mon plugin à moi.';
    }

    public function getParameterSchema(): array
    {
        return [];
    }

    public function generate(array $parameters, int $limit): array
    {
        return $this->navidrome->topAllTime($limit);
    }
}
```

Règles :

- Le fichier **doit** s'appeler `HelloPluginGenerator.php` (PSR-4 strict :
  nom de fichier = nom de classe).
- Le namespace **doit** être `App\Plugin` (ou un sous-namespace, ex.
  `App\Plugin\Pop`, auquel cas le fichier va dans `plugins/Pop/`).
- `getKey()` doit être unique dans tout le système (collision avec un
  des 8 générateurs livrés ou avec un autre plugin = exception au boot).
- L'autowire fonctionne : on peut injecter `NavidromeRepository`,
  `Doctrine\DBAL\Connection`, etc., comme pour n'importe quel service.

### 3. Monter le dossier dans `docker-compose.yml`

Sur **les deux services** (web ET cron) :

```yaml
services:
  navidrome-tools-web:
    # ...
    volumes:
      - ./plugins:/app/plugins:ro
      # autres volumes...

  navidrome-tools-cron:
    # ...
    volumes:
      - ./plugins:/app/plugins:ro
      # autres volumes...
```

Si le mount est posé uniquement côté web, les définitions custom
apparaissent dans l'UI mais leurs runs cron échoueront avec
`Unknown generator key`.

### 4. Redémarrer le conteneur

```
docker compose restart navidrome-tools-web navidrome-tools-cron
```

Au démarrage, l'entrypoint régénère l'autoload Composer puis warme le
cache Symfony — les nouveaux générateurs sont alors visibles dans le
dropdown du formulaire de définition. Ajouter ou modifier un plugin
nécessite un `docker compose restart` (le cache DI n'est rebuildé qu'au
boot).

### 5. Vérifier

```
docker compose exec navidrome-tools-web php bin/console debug:container --tag=app.playlist_generator
```

doit lister votre classe (`App\Plugin\HelloPluginGenerator` par
exemple) à côté des 8 générateurs livrés.

### Limitations

- **Pas de dépendances Composer additionnelles**. Les plugins ne peuvent
  utiliser que les classes déjà présentes dans l'image (services du
  projet + dépendances de `composer.json`). Si vous avez besoin d'une
  lib externe, dérivez votre propre image (`FROM
  ghcr.io/kgaut/navidrome-tools:latest`) et faites votre `composer
  require` dedans.
- **Erreurs de syntaxe = boot cassé**. Si le fichier déposé contient
  une erreur fatale, le `cache:warmup` de l'entrypoint échoue et le
  conteneur crash. Lire les logs (`docker compose logs
  navidrome-tools-web`) pour le message d'erreur exact.
