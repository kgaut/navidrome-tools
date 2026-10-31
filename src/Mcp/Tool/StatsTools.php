<?php

namespace App\Mcp\Tool;

use App\Mcp\McpToolSupport;
use App\Navidrome\NavidromeRepository;
use App\Service\DailyListeningStatsService;
use App\Service\LastFmStatsService;
use App\Service\NavidromeStatsService;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;

/**
 * MCP tools — listening stats (issue #276). Read only.
 */
class StatsTools
{
    use McpToolSupport;

    private const MAX_RANGE_DAYS = 366;
    private const MAX_TOP = 100;

    public function __construct(
        private readonly NavidromeStatsService $navidromeStats,
        private readonly LastFmStatsService $lastFmStats,
        private readonly DailyListeningStatsService $daily,
        private readonly NavidromeRepository $navidrome,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'stats_overview',
        description: 'Vue d\'ensemble des statistiques d\'écoute, telles que calculées par la dernière exécution du calcul '
            . 'planifié (champ computed_at), pas en temps réel. source = "navidrome" (écoutes réelles du serveur, défaut) '
            . 'ou "lastfm" (historique Last.fm importé). Renvoie snapshot = null si le calcul n\'a jamais tourné.',
        annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
    )]
    public function overview(
        #[Schema(enum: ['navidrome', 'lastfm'])]
        string $source = 'navidrome',
    ): array {
        $snapshot = match ($source) {
            'navidrome' => $this->navidromeStats->get(),
            'lastfm' => $this->lastFmStats->get(),
            default => throw new ToolCallException('source invalide : "navidrome" ou "lastfm".'),
        };

        return [
            'source' => $source,
            'snapshot' => $snapshot,
            'message' => $snapshot === null
                ? 'Aucune statistique calculée pour cette source : lancer le calcul depuis l\'interface (Stats).'
                : 'Statistiques du dernier calcul planifié.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'stats_daily',
        description: 'Écoutes jour par jour, en heure locale du serveur : morceaux joués, morceaux distincts, artistes, albums, '
            . 'durée, coups de cœur ajoutés, artiste du jour. from et to au format AAAA-MM-JJ, bornes incluses ; '
            . 'absents = aujourd\'hui ; from absent = to. 366 jours au plus (refusé au-delà). Chaque jour de la période '
            . 'est servi, avec des zéros s\'il n\'y a pas eu d\'écoute. source = "navidrome" (défaut) ou "lastfm".',
        annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
    )]
    public function daily(
        #[Schema(enum: ['navidrome', 'lastfm'])]
        string $source = 'navidrome',
        #[Schema(description: 'Premier jour, AAAA-MM-JJ.')]
        ?string $from = null,
        #[Schema(description: 'Dernier jour, AAAA-MM-JJ.')]
        ?string $to = null,
    ): array {
        if (!in_array($source, DailyListeningStatsService::SOURCES, true)) {
            throw new ToolCallException('source invalide : "navidrome" ou "lastfm".');
        }
        $tz = new \DateTimeZone($this->daily->timezone());
        $toDay = self::parseDay($to, 'to', $tz) ?? new \DateTimeImmutable('today', $tz);
        $fromDay = self::parseDay($from, 'from', $tz) ?? $toDay;
        if ($fromDay > $toDay) {
            throw new ToolCallException('from doit précéder ou égaler to.');
        }
        if ((int) $fromDay->diff($toDay)->days > self::MAX_RANGE_DAYS - 1) {
            throw new ToolCallException(sprintf('Période trop longue : %d jours au plus.', self::MAX_RANGE_DAYS));
        }

        return [
            'source' => $source,
            'from' => $fromDay->format('Y-m-d'),
            'to' => $toDay->format('Y-m-d'),
            'timezone' => $tz->getName(),
            'days' => $this->daily->daily($source, $fromDay, $toDay),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'stats_top',
        description: 'Classement des écoutes Navidrome sur une période : kind = "artists", "albums" ou "tracks" (les titres '
            . 'portent leur id de morceau, réutilisable dans playlist_create). from et to au format AAAA-MM-JJ, bornes '
            . 'incluses ; les deux absents = depuis toujours ; un seul absent = sans borne de ce côté. limit : 20 par défaut, '
            . 'ramené à 100 au-delà (la valeur appliquée est renvoyée).',
        annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
    )]
    public function top(
        #[Schema(enum: ['artists', 'albums', 'tracks'])]
        string $kind,
        ?string $from = null,
        ?string $to = null,
        int $limit = 20,
    ): array {
        $tz = new \DateTimeZone($this->daily->timezone());
        $fromDay = self::parseDay($from, 'from', $tz);
        $toDay = self::parseDay($to, 'to', $tz);
        if ($fromDay !== null && $toDay !== null && $fromDay > $toDay) {
            throw new ToolCallException('from doit précéder ou égaler to.');
        }
        // The repository works on a half-open [from, to) range.
        $toExclusive = $toDay?->modify('+1 day');
        $limit = self::clamp($limit, self::MAX_TOP);

        $items = match ($kind) {
            'artists' => $this->navidrome->getTopArtists($fromDay, $toExclusive, $limit),
            'albums' => $this->navidrome->getTopAlbums($fromDay, $toExclusive, $limit),
            'tracks' => $this->navidrome->getTopTracksWithDetails($fromDay, $toExclusive, $limit),
            default => throw new ToolCallException('kind invalide : "artists", "albums" ou "tracks".'),
        };

        return [
            'kind' => $kind,
            'from' => $fromDay?->format('Y-m-d'),
            'to' => $toDay?->format('Y-m-d'),
            'limit' => $limit,
            'items' => $items,
            'message' => self::limitNote(count($items), $limit),
        ];
    }
}
