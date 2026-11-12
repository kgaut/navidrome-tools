<?php

namespace App\Mcp\Tool;

use App\AudioMuse\AudioMuseAuthException;
use App\AudioMuse\AudioMuseClient;
use App\AudioMuse\AudioMuseException;
use App\AudioMuse\AudioMuseNotFoundException;
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
    private const MAX_PLAYLIST_PAGE = 500;
    private const MAX_SUMMARY_ARTISTS = 15;
    private const MAX_SIMILAR_SEEDS = 50;
    private const MAX_SIMILAR_PER_TRACK = 50;
    private const MAX_SIMILAR_RESULTS = 200;

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
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'playlist_get',
        description: 'Contenu d\'une playlist Navidrome désignée par son id (playlists_list → navidrome) : nom, '
            . 'commentaire, propriétaire, nombre de morceaux, durée (s), et ses morceaux dans l\'ordre (id, titre, artiste, '
            . 'album, année, durée, nombre d\'écoutes), paginés par limit (100 par défaut, ramené à 500) et offset. '
            . 'summary porte sur toute la playlist : artistes les plus présents (nombre de morceaux), années min/max, '
            . 'morceaux par décennie. Pour une playlist inspirée d\'une autre : passer ses ids à library_similar, ou '
            . 'chercher ses artistes avec library_search, puis playlist_create.',
        annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
    )]
    public function get(string $playlist_id, int $limit = 100, int $offset = 0): array
    {
        $playlistId = trim($playlist_id);
        if ($playlistId === '') {
            throw new ToolCallException('playlist_id est obligatoire (id Navidrome, donné par playlists_list).');
        }
        $known = array_filter($this->subsonic->getPlaylists(), static fn (array $p): bool => $p['id'] === $playlistId);
        if ($known === []) {
            throw new ToolCallException(sprintf(
                'Aucune playlist d\'id « %s » visible du compte Navidrome configuré. Les ids sont donnés par playlists_list.',
                $playlistId,
            ));
        }

        $playlist = $this->subsonic->getPlaylist($playlistId);
        $tracks = $playlist['tracks'];
        $limit = self::clamp($limit, self::MAX_PLAYLIST_PAGE);
        $offset = max(0, $offset);
        $page = array_slice($tracks, $offset, $limit);
        $plays = $this->navidrome->getPlayCountsByMediaFileId(array_map(static fn (array $t): string => $t['id'], $page));

        return [
            'id' => $playlist['id'],
            'name' => $playlist['name'],
            'comment' => $playlist['comment'],
            'owner' => $playlist['owner'],
            'track_count' => count($tracks),
            'duration' => $playlist['duration'],
            'summary' => self::summarize($tracks),
            'limit' => $limit,
            'offset' => $offset,
            'tracks' => array_map(static fn (array $t): array => [
                'id' => $t['id'],
                'title' => $t['title'],
                'artist' => $t['artist'],
                'album' => $t['album'],
                'year' => $t['year'] !== null && $t['year'] > 0 ? $t['year'] : null,
                'duration' => $t['duration'],
                'play_count' => (int) ($plays[$t['id']] ?? 0),
            ], $page),
            'message' => self::limitNote(count($page), $limit, count($tracks)),
        ];
    }

    /**
     * @param list<string>      $track_ids
     * @param list<string>|null $exclude_ids
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'library_similar',
        description: 'Morceaux de la bibliothèque proches sonorement d\'une liste de morceaux (track_ids : 1 à 50, ceux '
            . 'd\'une playlist via playlist_get par exemple), selon AudioMuse. Les voisins de chaque morceau (per_track, '
            . '10 par défaut, ramené à 50) sont fusionnés et classés : d\'abord les morceaux proches de plusieurs sources '
            . '(seed_count), puis par distance croissante. Les ids sources et exclude_ids sont écartés. Rend jusqu\'à limit '
            . 'morceaux (50 par défaut, ramené à 200) détaillés comme library_search, à passer à playlist_create. '
            . 'seeds_not_analysed : sources pas encore analysées par AudioMuse. Refusé si AudioMuse n\'est pas configuré.',
        annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: true),
    )]
    public function similar(
        #[Schema(items: ['type' => 'string'], minItems: 1)]
        array $track_ids,
        int $per_track = 10,
        int $limit = 50,
        #[Schema(items: ['type' => 'string'])]
        ?array $exclude_ids = null,
    ): array {
        if (!$this->audioMuse->isConfigured()) {
            throw new ToolCallException('AudioMuse n\'est pas configuré sur cette instance (AUDIOMUSE_BASE_URL) : recherche de morceaux proches impossible.');
        }
        $seeds = self::idList($track_ids, 'track_ids');
        if ($seeds === []) {
            throw new ToolCallException('track_ids est vide.');
        }
        if (count($seeds) > self::MAX_SIMILAR_SEEDS) {
            throw new ToolCallException(sprintf('Trop de morceaux sources : %d au plus.', self::MAX_SIMILAR_SEEDS));
        }
        $perTrack = self::clamp($per_track, self::MAX_SIMILAR_PER_TRACK);
        $limit = self::clamp($limit, self::MAX_SIMILAR_RESULTS);
        $excluded = array_fill_keys([...$seeds, ...self::idList($exclude_ids ?? [], 'exclude_ids')], true);

        /** @var array<string, array{seed_count: int, distance: float}> $candidates */
        $candidates = [];
        $notAnalysed = [];
        $failed = [];
        foreach ($seeds as $seed) {
            try {
                $neighbours = $this->audioMuse->similarTracks($seed, $perTrack);
            } catch (AudioMuseAuthException $e) {
                throw new ToolCallException('AudioMuse refuse l\'authentification : vérifier sa configuration. ' . $e->getMessage(), 0, $e);
            } catch (AudioMuseNotFoundException) {
                $notAnalysed[] = $seed;
                continue;
            } catch (AudioMuseException) {
                $failed[] = $seed;
                continue;
            }
            foreach ($neighbours as $row) {
                $id = $row['item_id'];
                if (isset($excluded[$id])) {
                    continue;
                }
                $candidates[$id] ??= ['seed_count' => 0, 'distance' => $row['distance']];
                $candidates[$id]['seed_count']++;
                $candidates[$id]['distance'] = min($candidates[$id]['distance'], $row['distance']);
            }
        }
        if ($failed !== [] && count($failed) === count($seeds)) {
            throw new ToolCallException('AudioMuse n\'a répondu pour aucun des morceaux sources : réessayer plus tard.');
        }

        uksort($candidates, static fn (string $a, string $b): int => [$candidates[$b]['seed_count'], $candidates[$a]['distance']]
            <=> [$candidates[$a]['seed_count'], $candidates[$b]['distance']]);
        // AudioMuse may know files Navidrome dropped since: keep only those still in the library.
        $known = $this->navidrome->getTrackSummaries(array_map('strval', array_keys($candidates)));
        $items = [];
        foreach ($candidates as $id => $score) {
            if (!isset($known[$id])) {
                continue;
            }
            $items[] = $known[$id] + ['seed_count' => $score['seed_count'], 'distance' => round($score['distance'], 4)];
            if (count($items) >= $limit) {
                break;
            }
        }

        return [
            'per_track' => $perTrack,
            'limit' => $limit,
            'items' => $items,
            'seeds_not_analysed' => $notAnalysed,
            'seeds_failed' => $failed,
            'message' => self::limitNote(count($items), $limit)
                . ($notAnalysed !== [] ? sprintf(' %d source(s) pas encore analysée(s) par AudioMuse.', count($notAnalysed)) : '')
                . ($failed !== [] ? sprintf(' %d source(s) ignorée(s) : AudioMuse en erreur.', count($failed)) : ''),
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

        $ids = self::idList($track_ids, 'track_ids');
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
     * Whole-playlist profile, to draw inspiration without paging through it.
     *
     * @param list<array{artist: string, year: ?int}> $tracks
     *
     * @return array{artists: list<array{artist: string, track_count: int}>, years: array{min: ?int, max: ?int}, decades: array<string, int>}
     */
    private static function summarize(array $tracks): array
    {
        $artists = [];
        $years = [];
        foreach ($tracks as $t) {
            $artist = trim($t['artist']);
            if ($artist !== '') {
                $artists[$artist] = ($artists[$artist] ?? 0) + 1;
            }
            if ($t['year'] !== null && $t['year'] > 0) {
                $years[] = $t['year'];
            }
        }
        uksort($artists, static fn (string $a, string $b): int => [$artists[$b], $a] <=> [$artists[$a], $b]);
        $decades = [];
        foreach ($years as $year) {
            $decade = sprintf('%ds', intdiv($year, 10) * 10);
            $decades[$decade] = ($decades[$decade] ?? 0) + 1;
        }
        ksort($decades);
        $top = [];
        foreach (array_slice($artists, 0, self::MAX_SUMMARY_ARTISTS, true) as $name => $count) {
            $top[] = ['artist' => (string) $name, 'track_count' => $count];
        }

        return [
            'artists' => $top,
            'years' => ['min' => $years === [] ? null : min($years), 'max' => $years === [] ? null : max($years)],
            'decades' => $decades,
        ];
    }

    /**
     * Trimmed, de-duplicated list of ids, in the given order.
     *
     * @param array<mixed> $ids
     *
     * @return list<string>
     */
    private static function idList(array $ids, string $field): array
    {
        $out = [];
        foreach ($ids as $id) {
            if (!is_string($id) || trim($id) === '') {
                throw new ToolCallException(sprintf('%s ne doit contenir que des chaînes non vides.', $field));
            }
            $out[trim($id)] = true;
        }

        // array_keys() turns numeric-string keys into ints: cast them back.
        return array_map('strval', array_keys($out));
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
