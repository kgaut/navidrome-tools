<?php

namespace App\LastFm;

use App\Entity\LastFmBufferedScrobble;
use App\Entity\LastFmImportTrack;
use App\Entity\RunHistory;
use App\Navidrome\NavidromeRepository;
use App\Repository\LastFmBufferedScrobbleRepository;
use App\Repository\LastFmMatchCacheRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Drains the lastfm_import_buffer: for each row, runs the matching cascade
 * ({@see ScrobbleMatcher}), inserts a Navidrome scrobble when a match is
 * found and not already present (±N seconds dedup), persists an audit row
 * in lastfm_import_track tied to the current RunHistory, then deletes the
 * buffer row.
 *
 * This is the only place that writes to Navidrome — call it with Navidrome
 * stopped (the caller owns the pre-flight via NavidromeContainerManager).
 *
 * Idempotent: a buffer row processed twice ends up with one Navidrome
 * scrobble + one audit row, then disappears from the buffer.
 */
class LastFmBufferProcessor
{
    private LoggerInterface $logger;

    public function __construct(
        private readonly LastFmBufferedScrobbleRepository $bufferRepo,
        private readonly ScrobbleMatcher $matcher,
        private readonly NavidromeRepository $navidrome,
        private readonly EntityManagerInterface $em,
        ?LoggerInterface $logger = null,
        private readonly ?LastFmMatchCacheRepository $cacheRepository = null,
        private readonly int $cacheTtlDays = 30,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * @param callable(int $considered, int $inserted, int $duplicates, int $unmatched): void $progress
     */
    public function process(
        int $limit = 0,
        bool $dryRun = false,
        int $toleranceSeconds = 60,
        ?RunHistory $auditRun = null,
        ?callable $progress = null,
    ): ProcessReport {
        if (!$this->navidrome->hasScrobblesTable()) {
            throw new \RuntimeException(
                'The Navidrome scrobbles table does not exist. Upgrade Navidrome to >= 0.55.',
            );
        }
        $userId = $this->navidrome->resolveUserId();

        // Same auto-purge as LastFmRematchService — stale negatives let
        // the cascade re-try couples that may have become matchable since
        // the cache row was written.
        $this->cacheRepository?->purgeStale($this->cacheTtlDays);

        $report = new ProcessReport();
        $batch = 0;
        $connection = $this->em->getConnection();

        foreach ($this->bufferRepo->streamAll($limit) as $buffered) {
            /** @var LastFmBufferedScrobble $buffered */
            $report->considered++;

            $scrobble = new LastFmScrobble(
                artist: $buffered->getArtist(),
                title: $buffered->getTitle(),
                album: $buffered->getAlbum() ?? '',
                mbid: $buffered->getMbid(),
                playedAt: $buffered->getPlayedAt(),
            );

            $result = $this->matcher->match($scrobble);
            $this->bumpCacheCounters($report, $result);

            if ($result->status === MatchResult::STATUS_SKIPPED) {
                $report->skipped++;
                $status = LastFmImportTrack::STATUS_SKIPPED;
                $matchedId = null;
                $this->logger->debug('Buffer skipped (alias): {artist} — {title}', [
                    'artist' => $buffered->getArtist(),
                    'title' => $buffered->getTitle(),
                ]);
            } elseif ($result->mediaFileId === null) {
                $report->unmatched++;
                $status = LastFmImportTrack::STATUS_UNMATCHED;
                $matchedId = null;
            } elseif (
                $this->navidrome->scrobbleExistsNear(
                    $userId,
                    $result->mediaFileId,
                    $buffered->getPlayedAt(),
                    $toleranceSeconds,
                )
            ) {
                $report->duplicates++;
                $status = LastFmImportTrack::STATUS_DUPLICATE;
                $matchedId = $result->mediaFileId;
            } else {
                $report->inserted++;
                $status = LastFmImportTrack::STATUS_INSERTED;
                $matchedId = $result->mediaFileId;
                if (!$dryRun) {
                    $this->navidrome->insertScrobble($userId, $matchedId, $buffered->getPlayedAt());
                }
            }

            if (!$dryRun) {
                if ($auditRun !== null) {
                    $this->em->persist(new LastFmImportTrack(
                        runHistory: $auditRun,
                        artist: $buffered->getArtist(),
                        title: $buffered->getTitle(),
                        album: $buffered->getAlbum(),
                        mbid: $buffered->getMbid(),
                        playedAt: $buffered->getPlayedAt(),
                        status: $status,
                        matchedMediaFileId: $matchedId,
                    ));
                }
                $connection->executeStatement(
                    'DELETE FROM lastfm_import_buffer WHERE id = :id',
                    ['id' => $buffered->getId()],
                );
            }

            // Periodic flush so memory does not grow unbounded on big
            // sweeps (mirrors LastFmRematchService).
            if (!$dryRun && ++$batch >= 100) {
                $this->em->flush();
                $batch = 0;
            }

            if ($progress !== null && $report->considered % 50 === 0) {
                $progress($report->considered, $report->inserted, $report->duplicates, $report->unmatched);
            }
        }

        if (!$dryRun) {
            $this->em->flush();
        }

        if ($progress !== null) {
            $progress($report->considered, $report->inserted, $report->duplicates, $report->unmatched);
        }

        return $report;
    }

    private function bumpCacheCounters(ProcessReport $report, MatchResult $result): void
    {
        match ($result->cacheStatus) {
            MatchResult::CACHE_HIT_POSITIVE => $report->cacheHitsPositive++,
            MatchResult::CACHE_HIT_NEGATIVE => $report->cacheHitsNegative++,
            MatchResult::CACHE_MISS => $report->cacheMisses++,
            default => null,
        };
    }
}
