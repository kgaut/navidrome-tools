<?php

namespace App\LastFm;

use App\Navidrome\NavidromeRepository;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class LastFmImporter
{
    private LoggerInterface $logger;

    public function __construct(
        private readonly LastFmClient $client,
        private readonly NavidromeRepository $navidrome,
        private readonly ScrobbleMatcher $matcher,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * @param callable(int $fetched, int $inserted, int $duplicates, int $unmatched): void $progress
     * @param callable(LastFmScrobble $scrobble, string $status, ?string $mediaFileId): void $onScrobble
     *        Called once per processed scrobble, with status one of
     *        'inserted' | 'duplicate' | 'unmatched' | 'skipped'. Used by callers
     *        that want to persist a per-track audit row.
     */
    public function import(
        string $apiKey,
        string $lastFmUser,
        ?\DateTimeInterface $dateMin = null,
        ?\DateTimeInterface $dateMax = null,
        int $toleranceSeconds = 60,
        bool $dryRun = false,
        ?callable $progress = null,
        ?int $maxScrobbles = null,
        ?callable $onScrobble = null,
    ): ImportReport {
        $report = new ImportReport();
        $userId = $this->navidrome->resolveUserId();

        // Bail out early with a clear message if scrobbles is missing.
        if (!$this->navidrome->hasScrobblesTable()) {
            throw new \RuntimeException(
                'The Navidrome scrobbles table does not exist. Upgrade Navidrome to >= 0.55.',
            );
        }

        foreach ($this->client->streamRecentTracks($apiKey, $lastFmUser, $dateMin, $dateMax) as $scrobble) {
            if ($maxScrobbles !== null && $report->fetched >= $maxScrobbles) {
                break;
            }
            $report->fetched++;

            $result = $this->matcher->match($scrobble);
            $mediaFileId = $result->mediaFileId;
            $this->bumpCacheCounters($report, $result);

            if ($result->status === MatchResult::STATUS_SKIPPED) {
                $report->skipped++;
                $status = 'skipped';
                $this->logger->debug('Skipped scrobble (alias): {artist} — {title}', [
                    'artist' => $scrobble->artist,
                    'title' => $scrobble->title,
                ]);
            } elseif ($mediaFileId === null) {
                $report->recordUnmatched($scrobble);
                $this->logger->debug('Unmatched scrobble: {artist} — {title}', [
                    'artist' => $scrobble->artist,
                    'title' => $scrobble->title,
                ]);
                $status = 'unmatched';
            } elseif ($this->navidrome->scrobbleExistsNear($userId, $mediaFileId, $scrobble->playedAt, $toleranceSeconds)) {
                $report->duplicates++;
                $status = 'duplicate';
            } else {
                if (!$dryRun) {
                    $this->navidrome->insertScrobble($userId, $mediaFileId, $scrobble->playedAt);
                }
                $report->inserted++;
                $status = 'inserted';
            }

            if ($onScrobble !== null) {
                $onScrobble($scrobble, $status, $mediaFileId);
            }

            if ($progress !== null && $report->fetched % 50 === 0) {
                $progress($report->fetched, $report->inserted, $report->duplicates, $report->unmatched);
            }
        }

        if ($progress !== null) {
            $progress($report->fetched, $report->inserted, $report->duplicates, $report->unmatched);
        }

        return $report;
    }

    private function bumpCacheCounters(ImportReport $report, MatchResult $result): void
    {
        match ($result->cacheStatus) {
            MatchResult::CACHE_HIT_POSITIVE => $report->cacheHitsPositive++,
            MatchResult::CACHE_HIT_NEGATIVE => $report->cacheHitsNegative++,
            MatchResult::CACHE_MISS => $report->cacheMisses++,
            default => null,
        };
    }
}
