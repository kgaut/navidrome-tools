<?php

namespace App\Mcp\Tool;

use App\Entity\ScrobbleSync;
use App\Mcp\McpToolSupport;
use App\Mcp\McpWriteGuard;
use App\Repository\ScrobbleSyncRepository;
use App\Service\AliasManager;
use App\Service\UnmatchedDiagnoser;
use App\Service\UnmatchedRetrier;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;

/**
 * MCP tools — matching of Last.fm scrobbles against the Navidrome library,
 * and reconciliation (aliases, retry) (issue #276).
 */
class MatchingTools
{
    use McpToolSupport;

    private const MAX_UNMATCHED = 100;
    private const DIAGNOSE_MAX = 25;

    public function __construct(
        private readonly ScrobbleSyncRepository $syncRepo,
        private readonly UnmatchedDiagnoser $diagnoser,
        private readonly UnmatchedRetrier $retrier,
        private readonly AliasManager $aliases,
        private readonly McpWriteGuard $guard,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'matching_summary',
        description: 'État du matching des scrobbles Last.fm vers Navidrome et Strawberry : nombre de scrobbles par statut '
            . '(pending = pas encore traité, matched = rattaché à un morceau, duplicate = déjà présent, unmatched = aucun '
            . 'morceau trouvé, skipped = ignoré par un alias), répartition des matchés par stratégie, et les 10 artistes '
            . 'qui concentrent le plus de non-matchés côté Navidrome.',
        annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
    )]
    public function summary(): array
    {
        $out = [];
        foreach ([ScrobbleSync::TARGET_NAVIDROME, ScrobbleSync::TARGET_STRAWBERRY] as $target) {
            $out[$target] = [
                // Maps: an empty one must stay a JSON object.
                'by_status' => (object) $this->syncRepo->countByStatus($target),
                'matched_by_strategy' => (object) $this->syncRepo->countMatchedByStrategy($target),
            ];
        }
        $out[ScrobbleSync::TARGET_NAVIDROME]['top_unmatched_artists'] = $this->syncRepo->topUnmatchedArtists(ScrobbleSync::TARGET_NAVIDROME, 10);

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'unmatched_list',
        description: 'Scrobbles Last.fm non matchés côté Navidrome, groupés par (artiste, titre, album), du plus écouté au '
            . 'moins écouté. Filtres facultatifs (sous-chaîne, insensible à la casse) : artist, title ; period = "AAAA" ou '
            . '"AAAA-MM" (ignoré si autre format). Pagination : limit (25 par défaut, ramené à 100) et offset ; total = '
            . 'nombre de groupes. diagnose = true (défaut) ajoute à chaque groupe la raison probable (artist_unknown, '
            . 'artist_near_match, title_near_match, track_missing, matcher_gap) et des suggestions ; le diagnostic est '
            . 'coupé au-delà de 25 groupes servis.',
        annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
    )]
    public function unmatchedList(
        ?string $artist = null,
        ?string $title = null,
        #[Schema(description: 'AAAA ou AAAA-MM.')]
        ?string $period = null,
        int $limit = 25,
        int $offset = 0,
        bool $diagnose = true,
    ): array {
        $artist = $artist !== null && trim($artist) !== '' ? trim($artist) : null;
        $title = $title !== null && trim($title) !== '' ? trim($title) : null;
        $period = $period !== null && preg_match('/^\d{4}(-\d{2})?$/', trim($period)) === 1 ? trim($period) : null;
        $limit = self::clamp($limit, self::MAX_UNMATCHED);
        $offset = max(0, $offset);

        $rows = $this->syncRepo->aggregateUnmatched(ScrobbleSync::TARGET_NAVIDROME, $limit, $offset, $artist, $title, $period);
        $total = $this->syncRepo->countUnmatchedGroups(ScrobbleSync::TARGET_NAVIDROME, $artist, $title, $period);
        $diagnosed = $diagnose && count($rows) <= self::DIAGNOSE_MAX;
        if ($diagnosed) {
            foreach ($rows as &$row) {
                $row['diagnosis'] = $this->diagnoser->diagnose((string) $row['artist'], (string) $row['title']);
            }
            unset($row);
        }

        $message = self::limitNote(count($rows), $limit, $total);
        if ($diagnose && !$diagnosed) {
            $message .= sprintf(' Diagnostic omis : plus de %d groupes servis, réduire limit.', self::DIAGNOSE_MAX);
        }

        return [
            'filters' => ['artist' => $artist, 'title' => $title, 'period' => $period],
            'limit' => $limit,
            'offset' => $offset,
            'total' => $total,
            'items' => $rows,
            'message' => $message,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'unmatched_diagnose',
        description: 'Pourquoi un couple (artiste, titre) scrobblé ne trouve pas de morceau dans la bibliothèque. reason : '
            . 'artist_unknown (artiste absent), artist_near_match (artiste proche : voir artist_suggestions, candidat à '
            . 'alias_create_artist), title_near_match (titre proche : voir title_suggestions, candidat à alias_create_track), '
            . 'track_missing (artiste présent, titre absent : le morceau manque), matcher_gap (morceau présent mais raté : '
            . 'unmatched_retry suffit, target_media_file_id donné), unknown. Lecture seule.',
        annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
    )]
    public function diagnose(string $artist, string $title): array
    {
        return ['artist' => $artist, 'title' => $title] + $this->diagnoser->diagnose($artist, $title);
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'unmatched_retry',
        description: 'ÉCRITURE (scope mcp_write). Relance le matching d\'un couple (artiste, titre) non matché, après avoir '
            . 'vidé son cache négatif : à utiliser après un alias ou un ajout à la bibliothèque. Si le morceau est trouvé, '
            . 'les scrobbles du couple repassent en pending (requeued) : ils seront écrits dans Navidrome au prochain sync, '
            . 'pas immédiatement. Sinon, renvoie le diagnostic. album facultatif, aide le matching.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: true),
    )]
    public function retry(string $artist, string $title, ?string $album = null): array
    {
        $this->guard->assertCanWrite();
        if (trim($artist) === '' || trim($title) === '') {
            throw new ToolCallException('artist et title sont obligatoires.');
        }
        $outcome = $this->retrier->retry(trim($artist), trim($title), trim((string) $album));
        $outcome['message'] = $outcome['matched']
            ? sprintf('Matché (stratégie %s) : %d scrobble(s) remis en pending, écrits au prochain sync.', $outcome['strategy'] ?? '?', $outcome['requeued'])
            : sprintf('Toujours non matché : %s.', UnmatchedRetrier::reasonLabel((string) ($outcome['diagnosis']['reason'] ?? '')));

        return $outcome;
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'alias_create_track',
        description: 'ÉCRITURE (scope mcp_write). Crée un alias de morceau : le couple scrobblé (source_artist, source_title) '
            . 'sera rattaché au morceau target_media_file_id (id obtenu par library_search). target_media_file_id absent ou '
            . 'vide = le couple est ignoré (statut skipped). Refusé si un alias existe déjà pour ce couple ou si l\'id est '
            . 'inconnu. Ne relance pas le matching : appeler unmatched_retry ensuite.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false),
    )]
    public function aliasTrack(string $source_artist, string $source_title, ?string $target_media_file_id = null): array
    {
        $this->guard->assertCanWrite();
        $alias = $this->refusable(fn () => $this->aliases->createTrackAlias($source_artist, $source_title, $target_media_file_id));

        return [
            'id' => $alias->getId(),
            'source_artist' => $alias->getSourceArtist(),
            'source_title' => $alias->getSourceTitle(),
            'target_media_file_id' => $alias->getTargetMediaFileId(),
            'message' => 'Alias créé. Appeler unmatched_retry pour rattacher les scrobbles concernés.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'alias_create_artist',
        description: 'ÉCRITURE (scope mcp_write). Crée un alias d\'artiste : les scrobbles de source_artist seront cherchés '
            . 'sous target_artist, qui doit exister dans la bibliothèque (cf. artist_suggestions de unmatched_diagnose). '
            . 'Refusé si un alias existe déjà pour source_artist. Ne relance pas le matching : appeler unmatched_retry '
            . 'sur les couples concernés ensuite.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false),
    )]
    public function aliasArtist(string $source_artist, string $target_artist): array
    {
        $this->guard->assertCanWrite();
        $alias = $this->refusable(fn () => $this->aliases->createArtistAlias($source_artist, $target_artist));

        return [
            'id' => $alias->getId(),
            'source_artist' => $alias->getSourceArtist(),
            'target_artist' => $alias->getTargetArtist(),
            'message' => 'Alias d\'artiste créé. Appeler unmatched_retry sur les couples de cet artiste.',
        ];
    }
}
