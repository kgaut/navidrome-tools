<?php

namespace App\Mcp\Tool;

use App\AudioMuse\AudioMuseClient;
use App\Mcp\McpToolSupport;
use App\Mcp\McpWriteGuard;
use App\Navidrome\NavidromeRepository;
use App\Playlist\PlaylistGenerator;
use App\Service\DescribedPlaylistCreator;
use App\Subsonic\SubsonicClient;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;

/**
 * MCP tools — library search and playlists written to Navidrome (issue #276).
 */
class PlaylistTools
{
    use McpToolSupport;

    private const MAX_SEARCH = 100;
    private const MAX_PLAYLIST_TRACKS = 500;
    private const MAX_NAME_LENGTH = 100;

    public function __construct(
        private readonly NavidromeRepository $navidrome,
        private readonly SubsonicClient $subsonic,
        private readonly PlaylistGenerator $generator,
        private readonly DescribedPlaylistCreator $describedCreator,
        private readonly AudioMuseClient $audioMuse,
        private readonly McpWriteGuard $guard,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'library_search',
        description: 'Cherche des morceaux dans la bibliothèque Navidrome et renvoie leur id (à passer à playlist_create ou '
            . 'alias_create_track), titre, artiste, album, année, durée (s) et nombre d\'écoutes. Critères facultatifs, '
            . 'combinés (ET), en sous-chaîne insensible à la casse : query (titre, artiste ou album), artist (artiste ou '
            . 'artiste de l\'album), title, album. Aucun critère = toute la bibliothèque. Pagination : limit (25 par défaut, '
            . 'ramené à 100) et offset ; total = nombre de morceaux correspondants.',
        annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
    )]
    public function search(
        ?string $query = null,
        ?string $artist = null,
        ?string $title = null,
        ?string $album = null,
        int $limit = 25,
        int $offset = 0,
    ): array {
        $limit = self::clamp($limit, self::MAX_SEARCH);
        $offset = max(0, $offset);
        $result = $this->navidrome->searchMediaFiles($query, $artist, $title, $album, $limit, $offset);

        return [
            'limit' => $limit,
            'offset' => $offset,
            'total' => $result['total'],
            'items' => $result['items'],
            'message' => self::limitNote(count($result['items']), $limit, $result['total']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'playlists_list',
        description: 'Playlists connues : definitions = playlists générées par navidrome-tools (slug, nom, description, '
            . 'enabled = régénérée par le cron ; les slugs « decrite-<id> » sont des playlists décrites) ; navidrome = '
            . 'playlists présentes sur le serveur Navidrome (id, nom, nombre de morceaux, durée, commentaire).',
        annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
    )]
    public function list(): array
    {
        return [
            'definitions' => $this->generator->listDefinitions(),
            'navidrome' => $this->subsonic->getPlaylists(),
        ];
    }

    /**
     * @param list<string> $track_ids
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'playlist_create',
        description: 'ÉCRITURE (scope mcp_write). Crée dans Navidrome une playlist à partir d\'une liste ordonnée d\'ids de '
            . 'morceaux (track_ids, obtenus par library_search ou stats_top ; 1 à 500, doublons retirés). Elle n\'est pas '
            . 'régénérée ensuite. name : 100 caractères au plus, ne peut pas reprendre le nom d\'une playlist générée '
            . '(elle serait écrasée par le cron). Si une playlist de ce nom existe déjà dans Navidrome : refusé, sauf '
            . 'replace = true qui remplace son contenu. Refusé si un id est inconnu (la liste des ids inconnus est donnée). '
            . 'comment facultatif, posé sur la playlist.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: true, openWorldHint: false),
    )]
    public function create(
        string $name,
        #[Schema(items: ['type' => 'string'], minItems: 1)]
        array $track_ids,
        bool $replace = false,
        ?string $comment = null,
    ): array {
        $this->guard->assertCanWrite();

        $name = trim($name);
        if ($name === '' || mb_strlen($name) > self::MAX_NAME_LENGTH) {
            throw new ToolCallException(sprintf('name est obligatoire (%d caractères au plus).', self::MAX_NAME_LENGTH));
        }
        $definition = $this->generatedDefinitionNamed($name);
        if ($definition !== null) {
            throw new ToolCallException(sprintf(
                '« %s » est le nom d\'une playlist générée (%s) : elle serait écrasée au prochain cron. Choisir un autre nom.',
                $name,
                $definition['slug'],
            ));
        }

        $ids = [];
        foreach ($track_ids as $id) {
            if (!is_string($id) || trim($id) === '') {
                throw new ToolCallException('track_ids ne doit contenir que des chaînes non vides.');
            }
            $ids[trim($id)] = true;
        }
        $ids = array_keys($ids);
        if ($ids === []) {
            throw new ToolCallException('track_ids est vide.');
        }
        if (count($ids) > self::MAX_PLAYLIST_TRACKS) {
            throw new ToolCallException(sprintf('Trop de morceaux : %d au plus.', self::MAX_PLAYLIST_TRACKS));
        }
        $missing = $this->navidrome->filterMissingMediaFileIds($ids);
        if ($missing !== []) {
            throw new ToolCallException(sprintf(
                'Id(s) inconnu(s) de la bibliothèque, rien n\'a été créé : %s.',
                implode(', ', array_slice($missing, 0, 20)) . (count($missing) > 20 ? ' …' : ''),
            ));
        }

        $existing = $this->subsonic->findPlaylistByName($name);
        if ($existing !== null && !$replace) {
            throw new ToolCallException(sprintf(
                'Une playlist « %s » existe déjà dans Navidrome (id %s, %d morceaux). Passer replace = true pour remplacer son contenu, ou choisir un autre nom.',
                $name,
                $existing['id'],
                $existing['songCount'],
            ));
        }

        if ($existing !== null) {
            $playlistId = $existing['id'];
            $this->subsonic->replacePlaylist($playlistId, $name, $ids);
        } else {
            $playlistId = $this->subsonic->createPlaylist($name, $ids);
        }
        if ($comment !== null && trim($comment) !== '') {
            $this->subsonic->updatePlaylist($playlistId, comment: trim($comment));
        }

        return [
            'id' => $playlistId,
            'name' => $name,
            'track_count' => count($ids),
            'replaced' => $existing !== null,
            'message' => $existing !== null
                ? sprintf('Playlist « %s » remplacée (%d morceaux).', $name, count($ids))
                : sprintf('Playlist « %s » créée (%d morceaux).', $name, count($ids)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'playlist_rename',
        description: 'ÉCRITURE (scope mcp_write). Renomme une playlist Navidrome du compte configuré, désignée par son id '
            . '(playlists_list → navidrome). name : nouveau nom, 100 caractères au plus. Refusé pour une playlist générée '
            . '(le cron la retrouve par son nom et la recréerait), si le nouveau nom est celui d\'une playlist générée '
            . '(elle serait écrasée au cron) ou d\'une autre playlist du compte. Le contenu n\'est pas modifié.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false),
    )]
    public function rename(string $playlist_id, string $name): array
    {
        $this->guard->assertCanWrite();

        $name = trim($name);
        if ($name === '' || mb_strlen($name) > self::MAX_NAME_LENGTH) {
            throw new ToolCallException(sprintf('name est obligatoire (%d caractères au plus).', self::MAX_NAME_LENGTH));
        }
        $playlist = $this->ownedPlaylist($playlist_id);
        $this->assertNotGenerated($playlist, 'renommée');
        if ($playlist['name'] === $name) {
            return [
                'id' => $playlist['id'],
                'previous_name' => $playlist['name'],
                'name' => $name,
                'message' => sprintf('La playlist s\'appelle déjà « %s » : rien n\'a été modifié.', $name),
            ];
        }
        $definition = $this->generatedDefinitionNamed($name);
        if ($definition !== null) {
            throw new ToolCallException(sprintf(
                '« %s » est le nom d\'une playlist générée (%s) : elle serait écrasée au prochain cron. Choisir un autre nom.',
                $name,
                $definition['slug'],
            ));
        }
        $homonym = $this->subsonic->findPlaylistByName($name);
        if ($homonym !== null && $homonym['id'] !== $playlist['id']) {
            throw new ToolCallException(sprintf(
                'Une autre playlist s\'appelle déjà « %s » (id %s, %d morceaux) : choisir un autre nom.',
                $name,
                $homonym['id'],
                $homonym['songCount'],
            ));
        }

        $this->subsonic->updatePlaylist($playlist['id'], name: $name);

        return [
            'id' => $playlist['id'],
            'previous_name' => $playlist['name'],
            'name' => $name,
            'message' => sprintf('Playlist « %s » renommée en « %s ».', $playlist['name'], $name),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'playlist_delete',
        description: 'ÉCRITURE (scope mcp_write), IRRÉVERSIBLE. Supprime une playlist Navidrome du compte configuré, '
            . 'désignée par son id (playlists_list → navidrome). name : son nom actuel, exact, vérifié avant suppression '
            . '(garde contre un mauvais id). Refusé pour une playlist générée : le cron la recréerait, désactiver sa '
            . 'définition à la place. Les morceaux eux-mêmes ne sont pas touchés. La réponse rend les ids des morceaux '
            . '(track_ids, dans l\'ordre) et le commentaire, de quoi la recréer avec playlist_create.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: false, openWorldHint: false),
    )]
    public function delete(string $playlist_id, string $name): array
    {
        $this->guard->assertCanWrite();

        $name = trim($name);
        $playlist = $this->ownedPlaylist($playlist_id);
        if ($playlist['name'] !== $name) {
            throw new ToolCallException(sprintf(
                'La playlist %s s\'appelle « %s », pas « %s » : rien n\'a été supprimé. Vérifier l\'id avec playlists_list.',
                $playlist['id'],
                $playlist['name'],
                $name,
            ));
        }
        $this->assertNotGenerated($playlist, 'supprimée');

        $trackIds = array_map(static fn (array $t): string => $t['id'], $this->subsonic->getPlaylist($playlist['id'])['tracks']);
        $this->subsonic->deletePlaylist($playlist['id']);

        return [
            'id' => $playlist['id'],
            'name' => $playlist['name'],
            'comment' => $playlist['comment'],
            'track_count' => count($trackIds),
            'track_ids' => $trackIds,
            'message' => sprintf('Playlist « %s » supprimée (%d morceaux).', $playlist['name'], count($trackIds)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'described_playlist_create',
        description: 'ÉCRITURE (scope mcp_write). Crée une playlist décrite par une phrase (query, 300 caractères au plus, '
            . 'ex. « piano calme pour travailler ») : AudioMuse cherche les morceaux qui y ressemblent (recherche CLAP). '
            . 'Elle est enregistrée et régénérée par le cron comme les autres playlists ; la première génération part en '
            . 'arrière-plan (quelques secondes à quelques minutes). name : 100 caractères au plus, unique parmi les playlists '
            . 'générées. track_count : 1 à 200 (30 par défaut). max_plays facultatif : écarte les morceaux écoutés plus de '
            . 'max_plays fois. Refusé si AudioMuse n\'est pas configuré.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: true),
    )]
    public function createDescribed(string $name, string $query, int $track_count = 30, ?int $max_plays = null): array
    {
        $this->guard->assertCanWrite();
        if (!$this->audioMuse->isConfigured()) {
            throw new ToolCallException('AudioMuse n\'est pas configuré sur cette instance (AUDIOMUSE_BASE_URL) : playlist décrite impossible.');
        }
        $playlist = $this->refusable(fn () => $this->describedCreator->create($name, $query, $track_count, $max_plays));

        return [
            'id' => $playlist->getId(),
            'slug' => DescribedPlaylistCreator::slugFor($playlist),
            'name' => $playlist->getName(),
            'message' => sprintf('Playlist décrite « %s » enregistrée ; génération lancée en arrière-plan.', $playlist->getName()),
        ];
    }

    /**
     * @return array{slug: string, name: string, description: string, enabled: bool}|null
     */
    private function generatedDefinitionNamed(string $name): ?array
    {
        foreach ($this->generator->listDefinitions() as $definition) {
            if (mb_strtolower($definition['name']) === mb_strtolower($name)) {
                return $definition;
            }
        }

        return null;
    }

    /**
     * @return array{id: string, name: string, owner: string, songCount: int, duration: int, public: bool, created: ?string, changed: ?string, comment: string}
     */
    private function ownedPlaylist(string $playlistId): array
    {
        $playlistId = trim($playlistId);
        if ($playlistId === '') {
            throw new ToolCallException('playlist_id est obligatoire (id Navidrome, donné par playlists_list).');
        }

        return $this->subsonic->findOwnedPlaylist($playlistId) ?? throw new ToolCallException(sprintf(
            'Aucune playlist d\'id « %s » sur le compte Navidrome configuré : rien n\'a été modifié. Les ids sont donnés par playlists_list.',
            $playlistId,
        ));
    }

    /**
     * The generator finds its playlists by name: renamed or deleted, a
     * generated playlist would be recreated by the next cron.
     *
     * @param array{id: string, name: string} $playlist
     */
    private function assertNotGenerated(array $playlist, string $action): void
    {
        $definition = $this->generatedDefinitionNamed($playlist['name']);
        if ($definition !== null) {
            throw new ToolCallException(sprintf(
                '« %s » est une playlist générée (%s) : le cron la retrouve par son nom et la recréerait, elle ne peut pas être %s ici. '
                . 'Désactiver sa définition à la place. Rien n\'a été modifié.',
                $playlist['name'],
                $definition['slug'],
                $action,
            ));
        }
    }
}
