<?php

namespace App\Mcp\Tool;

use App\Entity\ScrobbleSync;
use App\Mcp\McpToolSupport;
use App\Mcp\McpWriteGuard;
use App\Repository\ScrobbleSyncRepository;
use App\Message\RelinkMatchedMessage;
use App\Service\AliasManager;
use App\Service\MatchedRelinker;
use App\Service\TargetIdRepairer;
use App\Service\UnmatchedDiagnoser;
use App\Service\UnmatchedRetrier;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * MCP tools — matching of Last.fm scrobbles against the Navidrome library,
 * and reconciliation (aliases, retry) (issue #276).
 */
class MatchingTools
{
    use McpToolSupport;

    private const MAX_UNMATCHED = 100;
    private const MAX_RETRY_GROUPS = 100;
    private const MAX_RELINK_GROUPS = 100;

    public function __construct(
        private readonly ScrobbleSyncRepository $syncRepo,
        private readonly UnmatchedDiagnoser $diagnoser,
        private readonly UnmatchedRetrier $retrier,
        private readonly AliasManager $aliases,
        private readonly McpWriteGuard $guard,
        private readonly MatchedRelinker $relinker,
        private readonly MessageBusInterface $bus,
        private readonly TargetIdRepairer $targetRepairer,
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
            . 'artist_near_match, title_near_match, track_missing, matcher_gap) et des suggestions, pour chaque groupe servi. '
            . 'Pour relancer d\'un coup les groupes non matchés : unmatched_retry_many.',
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
        // The diagnosis is served from an in-memory library index, so every
        // served group gets one (the former 25-group cut is gone, issue #280).
        if ($diagnose) {
            foreach ($rows as &$row) {
                $row['diagnosis'] = $this->diagnoser->diagnose((string) $row['artist'], (string) $row['title']);
            }
            unset($row);
        }

        $message = self::limitNote(count($rows), $limit, $total);

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
        name: 'unmatched_retry_many',
        description: 'ÉCRITURE (scope mcp_write). Relance le matching de tous les couples (artiste, titre) non matchés parmi '
            . 'les limit premiers groupes de unmatched_list (mêmes filtres facultatifs artist, title, period ; limit 50 par '
            . 'défaut, ramené à 100), comme unmatched_retry sur chacun. À utiliser après un ajout à la bibliothèque, une '
            . 'série d\'alias ou une évolution du matching, plutôt que des dizaines d\'appels unmatched_retry. Les scrobbles '
            . 'des couples trouvés repassent en pending : ils seront écrits dans Navidrome au prochain sync. Renvoie les '
            . 'couples matchés et, pour les autres, le nombre par raison de diagnostic.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: true),
    )]
    public function retryMany(
        ?string $artist = null,
        ?string $title = null,
        #[Schema(description: 'AAAA ou AAAA-MM.')]
        ?string $period = null,
        int $limit = 50,
    ): array {
        $this->guard->assertCanWrite();
        $artist = $artist !== null && trim($artist) !== '' ? trim($artist) : null;
        $title = $title !== null && trim($title) !== '' ? trim($title) : null;
        $period = $period !== null && preg_match('/^\d{4}(-\d{2})?$/', trim($period)) === 1 ? trim($period) : null;
        $limit = self::clamp($limit, self::MAX_RETRY_GROUPS);

        $outcome = $this->retrier->retryMany($artist, $title, $period, $limit);
        // Map: an empty one must stay a JSON object.
        $outcome['still_unmatched'] = (object) $outcome['still_unmatched'];
        $outcome['filters'] = ['artist' => $artist, 'title' => $title, 'period' => $period];
        $outcome['message'] = sprintf(
            '%d couple(s) relancé(s) sur %d groupe(s) : %d matché(s), %d scrobble(s) remis en pending, écrits au prochain sync.%s',
            $outcome['couples'],
            $outcome['groups'],
            $outcome['matched'],
            $outcome['requeued'],
            $outcome['aborted'] ? ' Arrêté après 5 erreurs Last.fm consécutives : relancer plus tard.' : '',
        );

        return $outcome;
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'matched_relink_preview',
        description: 'Écoutes DÉJÀ matchées et écrites dans Navidrome que le matching actuel enverrait sur un autre morceau '
            . '(ex. écoutes d\'un album rattachées à sa version instrumentale avant un correctif). Ne concerne que les écoutes '
            . 'écrites par navidrome-tools, hors alias et MBID ; les écoutes enregistrées par Navidrome lui-même ne sont '
            . 'jamais déplacées. Filtres (sous-chaîne, insensible à la casse ; au moins un parmi artist, album, from_album) : '
            . 'artist, title, album = album scrobblé ; from_album = album du morceau actuellement visé (ex. '
            . '« Instrumentales »). limit = écoutes examinées (500 par défaut, ramené à 2000). Renvoie les déplacements '
            . 'groupés (album actuel → nouvel album, nombre d\'écoutes), le nombre d\'écoutes qui deviendraient non '
            . 'matchées et celui des écoutes dont le morceau enregistré a disparu de la bibliothèque (stale_target ; '
            . 'laissées en place dans les deux cas). Rien n\'est écrit dans Navidrome ; le cache de matching peut être complété. '
            . 'Appliquer avec matched_relink.',
        annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: true),
    )]
    public function relinkPreview(
        ?string $artist = null,
        ?string $title = null,
        ?string $album = null,
        ?string $from_album = null,
        int $limit = 500,
    ): array {
        [$artist, $title, $album, $from_album] = $this->relinkFilters($artist, $title, $album, $from_album);
        $limit = self::clamp($limit, MatchedRelinker::MAX_SCROBBLES);

        $plan = $this->relinker->plan($artist, $title, $album, $from_album, $limit);
        $groups = array_slice($plan['groups'], 0, self::MAX_RELINK_GROUPS);

        return [
            'filters' => ['artist' => $artist, 'title' => $title, 'album' => $album, 'from_album' => $from_album],
            'limit' => $limit,
            'considered' => $plan['considered'],
            'truncated' => $plan['truncated'],
            'to_move' => count($plan['moves']),
            'would_unmatch' => $plan['would_unmatch'],
            'stale_target' => $plan['stale_target'],
            'api_errors' => $plan['api_errors'],
            'groups' => $groups,
            'message' => sprintf(
                '%d écoute(s) examinée(s), %d à déplacer en %d groupe(s)%s, %d deviendrai(en)t non matchée(s) et %d visent un '
                    . 'morceau disparu de la bibliothèque (laissées en place).%s',
                $plan['considered'],
                count($plan['moves']),
                count($plan['groups']),
                count($plan['groups']) > count($groups) ? sprintf(' (%d premiers affichés)', count($groups)) : '',
                $plan['would_unmatch'],
                $plan['stale_target'],
                $plan['truncated'] ? ' Plafond atteint (truncated) : d\'autres écoutes passent les filtres ; relancer après application, ou affiner les filtres.' : '',
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'matched_relink',
        description: 'ÉCRITURE (scope mcp_write). Applique matched_relink_preview avec les mêmes filtres (au moins un parmi '
            . 'artist, album, from_album ; limit 500 par défaut, ramené à 2000) : chaque écoute concernée est retirée de son '
            . 'morceau actuel et réécrite sur le nouveau, et les compteurs d\'écoutes des deux morceaux sont recalculés. Le '
            . 'travail est mis en file pour le worker, qui arrête Navidrome le temps de l\'écriture (une à deux minutes, '
            . 'sauvegarde préalable) : la réponse est immédiate, l\'écriture suit. Vérifier ensuite avec '
            . 'matched_relink_preview (0 déplacement attendu). Appeler matched_relink_preview d\'abord.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: true, openWorldHint: true),
    )]
    public function relink(
        ?string $artist = null,
        ?string $title = null,
        ?string $album = null,
        ?string $from_album = null,
        int $limit = 500,
    ): array {
        $this->guard->assertCanWrite();
        [$artist, $title, $album, $from_album] = $this->relinkFilters($artist, $title, $album, $from_album);
        $limit = self::clamp($limit, MatchedRelinker::MAX_SCROBBLES);

        $this->bus->dispatch(new RelinkMatchedMessage($artist, $title, $album, $from_album, $limit));

        return [
            'queued' => true,
            'filters' => ['artist' => $artist, 'title' => $title, 'album' => $album, 'from_album' => $from_album],
            'limit' => $limit,
            'message' => 'Déplacement mis en file : le worker arrête Navidrome, sauvegarde, déplace puis redémarre (une à deux '
                . 'minutes). Vérifier ensuite avec matched_relink_preview et les mêmes filtres (0 déplacement attendu).',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'matched_targets_repair',
        description: 'Remet d\'aplomb le morceau enregistré pour les écoutes déjà écrites dans Navidrome quand ce morceau '
            . 'n\'existe plus dans la bibliothèque (identifiants changés depuis l\'écriture) : la cible devient le morceau '
            . 'sous lequel Navidrome garde l\'écoute, retrouvé à la même seconde. Une écoute sans écoute Navidrome à cette '
            . 'seconde, ou avec plusieurs morceaux différents, est laissée et comptée. Navidrome n\'est que lu ; seul le '
            . 'suivi local est modifié. Préalable à matched_relink_preview / matched_relink, qui laissent ces écoutes de côté '
            . '(stale_target). dry_run = true (défaut) : compte et montre des exemples sans rien écrire. dry_run = false : '
            . 'ÉCRITURE (scope mcp_write).',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false),
    )]
    public function repairTargets(bool $dry_run = true): array
    {
        if (!$dry_run) {
            $this->guard->assertCanWrite();
        }
        $report = $this->targetRepairer->run($dry_run);
        $report['message'] = sprintf(
            '%d écoute(s) examinée(s), %d avec une cible disparue : %d %s, %d sans écoute Navidrome à la seconde, %d ambiguë(s)%s.',
            $report['examined'],
            $report['stale'],
            $report['repaired'],
            $dry_run ? 'réparable(s) (dry-run, rien d\'écrit)' : 'réparée(s)',
            $report['not_found'],
            $report['ambiguous'],
            $report['title_mismatch'] > 0 ? sprintf(', dont %d au titre différent (alias probables)', $report['title_mismatch']) : '',
        );

        return $report;
    }

    /**
     * Trims the relink filters; refuses a call without artist, album or
     * from_album (a whole-library relink is a CLI decision, not an MCP one).
     *
     * @return array{0: ?string, 1: ?string, 2: ?string, 3: ?string}
     */
    private function relinkFilters(?string $artist, ?string $title, ?string $album, ?string $fromAlbum): array
    {
        $clean = static fn (?string $v): ?string => $v !== null && trim($v) !== '' ? trim($v) : null;
        [$artist, $title, $album, $fromAlbum] = [$clean($artist), $clean($title), $clean($album), $clean($fromAlbum)];
        if ($artist === null && $album === null && $fromAlbum === null) {
            throw new ToolCallException('Au moins un filtre parmi artist, album et from_album est obligatoire.');
        }

        return [$artist, $title, $album, $fromAlbum];
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
