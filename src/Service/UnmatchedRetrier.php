<?php

namespace App\Service;

use App\Entity\ScrobbleSync;
use App\LastFm\LastFmApiException;
use App\LastFm\LastFmRequestException;
use App\LastFm\LastFmScrobble;
use App\LastFm\MatchResult;
use App\LastFm\ScrobbleMatcher;
use App\Navidrome\NavidromeRepository;
use App\Repository\LastFmMatchCacheRepository;
use App\Repository\ScrobbleSyncRepository;

/**
 * Re-runs the matching cascade for one unmatched (artist, title) couple.
 *
 * Purges the couple's negative cache entry so the cascade actually re-runs,
 * matches a synthetic scrobble, and on a hit re-queues the couple's
 * unmatched rows to `pending`: the write into navidrome.db happens on the
 * next sync or rematch. On a miss, returns the diagnosis. Reads Navidrome
 * only, so no container stop.
 *
 * Shared by the « retry » button of `/navidrome/unmatched` and the MCP
 * `unmatched_retry` tool (issue #276).
 */
class UnmatchedRetrier
{
    public function __construct(
        private readonly ScrobbleMatcher $matcher,
        private readonly ScrobbleSyncRepository $syncRepo,
        private readonly LastFmMatchCacheRepository $cache,
        private readonly UnmatchedDiagnoser $diagnoser,
        private readonly NavidromeRepository $navidrome,
    ) {
    }

    /**
     * @return array{
     *     matched: bool,
     *     strategy: ?string,
     *     media_file_id: ?string,
     *     label: ?string,
     *     requeued: int,
     *     diagnosis: ?array<string, mixed>
     * }
     */
    public function retry(string $artist, string $title, string $album = ''): array
    {
        $this->cache->purgeByCouple($artist, $title);

        $result = $this->matcher->match(new LastFmScrobble(
            artist: $artist,
            title: $title,
            album: $album,
            mbid: null,
            playedAt: new \DateTimeImmutable(),
        ));

        if ($result->status === MatchResult::STATUS_MATCHED && $result->mediaFileId !== null) {
            $meta = $this->navidrome->getMediaFileMetadata([$result->mediaFileId]);
            $label = $meta !== []
                ? sprintf('%s — %s', $meta[0]['artist'], $meta[0]['album'] !== '' ? $meta[0]['album'] : '?')
                : $result->mediaFileId;

            return [
                'matched' => true,
                'strategy' => $result->strategy,
                'media_file_id' => $result->mediaFileId,
                'label' => $label,
                'requeued' => $this->syncRepo->resetCoupleToPending(ScrobbleSync::TARGET_NAVIDROME, $artist, $title),
                'diagnosis' => null,
            ];
        }

        return [
            'matched' => false,
            'strategy' => null,
            'media_file_id' => null,
            'label' => null,
            'requeued' => 0,
            'diagnosis' => $this->diagnoser->diagnose($artist, $title),
        ];
    }

    /**
     * Retries every unmatched (artist, title) couple among the first
     * `$limit` groups of the unmatched list (same filters and order as
     * `/navidrome/unmatched` and `unmatched_list`), one {@see retry()} per
     * distinct couple — the bulk counterpart of the per-couple button
     * (issue #278). A Last.fm failure leaves its couple untouched and is
     * counted; five in a row stop the batch.
     *
     * @return array{
     *     groups: int,
     *     couples: int,
     *     matched: int,
     *     requeued: int,
     *     still_unmatched: array<string, int>,
     *     api_errors: int,
     *     aborted: bool,
     *     matches: list<array{artist: string, title: string, strategy: ?string, label: ?string, requeued: int}>
     * }
     */
    public function retryMany(?string $artist, ?string $title, ?string $period, int $limit): array
    {
        $rows = $this->syncRepo->aggregateUnmatched(ScrobbleSync::TARGET_NAVIDROME, $limit, 0, $artist, $title, $period);
        $out = [
            'groups' => count($rows),
            'couples' => 0,
            'matched' => 0,
            'requeued' => 0,
            'still_unmatched' => [],
            'api_errors' => 0,
            'aborted' => false,
            'matches' => [],
        ];

        $seen = [];
        $consecutiveErrors = 0;
        foreach ($rows as $row) {
            $coupleKey = $row['artist'] . "\0" . $row['title'];
            if (isset($seen[$coupleKey])) {
                continue;
            }
            $seen[$coupleKey] = true;
            $out['couples']++;

            try {
                $outcome = $this->retry($row['artist'], $row['title'], (string) ($row['album'] ?? ''));
                $consecutiveErrors = 0;
            } catch (LastFmRequestException | LastFmApiException) {
                $out['api_errors']++;
                if (++$consecutiveErrors >= 5) {
                    $out['aborted'] = true;
                    break;
                }
                continue;
            }

            if ($outcome['matched']) {
                $out['matched']++;
                $out['requeued'] += $outcome['requeued'];
                $out['matches'][] = [
                    'artist' => $row['artist'],
                    'title' => $row['title'],
                    'strategy' => $outcome['strategy'],
                    'label' => $outcome['label'],
                    'requeued' => $outcome['requeued'],
                ];
            } else {
                $reason = (string) ($outcome['diagnosis']['reason'] ?? UnmatchedDiagnoser::REASON_UNKNOWN);
                $out['still_unmatched'][$reason] = ($out['still_unmatched'][$reason] ?? 0) + 1;
            }
        }

        return $out;
    }

    /** Short French label for a diagnosis reason. */
    public static function reasonLabel(string $reason): string
    {
        return match ($reason) {
            UnmatchedDiagnoser::REASON_ARTIST_UNKNOWN => 'artiste inconnu de la bibliothèque',
            UnmatchedDiagnoser::REASON_ARTIST_NEAR_MATCH => 'artiste proche (voir suggestions d’alias)',
            UnmatchedDiagnoser::REASON_TITLE_NEAR_MATCH => 'titre proche (voir suggestions d’alias)',
            UnmatchedDiagnoser::REASON_TRACK_MISSING => 'artiste présent mais titre absent de la bibliothèque',
            UnmatchedDiagnoser::REASON_MATCHER_GAP => 'track présente mais ratée par la cascade',
            default => 'inconnue',
        };
    }
}
