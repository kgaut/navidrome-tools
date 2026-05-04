<?php

namespace App\Service;

use App\Entity\LastFmImportTrack;
use App\LastFm\LastFmScrobble;
use App\LastFm\MatchResult;
use App\LastFm\RematchReport;
use App\LastFm\ScrobbleMatcher;
use App\Navidrome\NavidromeRepository;
use App\Repository\LastFmImportTrackRepository;
use App\Repository\LastFmMatchCacheRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Re-applies the matching cascade ({@see ScrobbleMatcher}) on rows of
 * `lastfm_import_track` previously stored as `unmatched`. When a row finds
 * a match, the corresponding scrobble is inserted in Navidrome (with the
 * usual ±N seconds dedup) and the row's status flips to `inserted` /
 * `duplicate` / `skipped`.
 *
 * Idempotent: calling rematch twice in a row is a no-op past the first
 * call. Garde-fou via {@see NavidromeRepository::scrobbleExistsNear()}.
 */
class LastFmRematchService
{
    private LoggerInterface $logger;

    public function __construct(
        private readonly LastFmImportTrackRepository $trackRepo,
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
     * @param ?callable(int $considered, int $matched, int $stillUnmatched): void $progress
     */
    public function rematch(
        ?int $runId = null,
        int $limit = 0,
        bool $dryRun = false,
        int $toleranceSeconds = 60,
        bool $random = false,
        ?callable $progress = null,
    ): RematchReport {
        if (!$this->navidrome->hasScrobblesTable()) {
            throw new \RuntimeException(
                'The Navidrome scrobbles table does not exist. Upgrade Navidrome to >= 0.55.',
            );
        }
        $userId = $this->navidrome->resolveUserId();

        // Same auto-purge as the buffer processor — stale negatives let
        // the cascade re-try unmatched couples that may have become
        // matchable since the cache row was written.
        $this->cacheRepository?->purgeStale($this->cacheTtlDays);

        $report = new RematchReport();
        $batch = 0;
        /** @var list<LastFmImportTrack> $pendingTracks */
        $pendingTracks = [];
        foreach ($this->trackRepo->streamUnmatched($runId, $limit, $random) as $track) {
            /** @var LastFmImportTrack $track */
            $report->considered++;

            $scrobble = new LastFmScrobble(
                artist: $track->getArtist(),
                title: $track->getTitle(),
                album: $track->getAlbum() ?? '',
                mbid: $track->getMbid(),
                playedAt: $track->getPlayedAt(),
            );

            $result = $this->matcher->match($scrobble);
            match ($result->cacheStatus) {
                MatchResult::CACHE_HIT_POSITIVE => $report->cacheHitsPositive++,
                MatchResult::CACHE_HIT_NEGATIVE => $report->cacheHitsNegative++,
                MatchResult::CACHE_MISS => $report->cacheMisses++,
                default => null,
            };

            if ($result->status === MatchResult::STATUS_SKIPPED) {
                $report->skipped++;
                if (!$dryRun) {
                    $track->setStatus(LastFmImportTrack::STATUS_SKIPPED);
                }
                $this->logger->debug('Rematch skipped (alias): {artist} — {title}', [
                    'artist' => $track->getArtist(),
                    'title' => $track->getTitle(),
                ]);
            } elseif ($result->mediaFileId === null) {
                $report->stillUnmatched++;
            } elseif ($this->navidrome->scrobbleExistsNear($userId, $result->mediaFileId, $track->getPlayedAt(), $toleranceSeconds)) {
                $report->matchedAsDuplicate++;
                if (!$dryRun) {
                    $track->setStatus(LastFmImportTrack::STATUS_DUPLICATE);
                    $track->setMatchedMediaFileId($result->mediaFileId);
                }
                $this->logger->debug('Rematch found duplicate: {artist} — {title}', [
                    'artist' => $track->getArtist(),
                    'title' => $track->getTitle(),
                ]);
            } else {
                $report->matchedAsInserted++;
                if (!$dryRun) {
                    $this->navidrome->insertScrobble($userId, $result->mediaFileId, $track->getPlayedAt());
                    $track->setStatus(LastFmImportTrack::STATUS_INSERTED);
                    $track->setMatchedMediaFileId($result->mediaFileId);
                }
                $this->logger->debug('Rematch inserted: {artist} — {title}', [
                    'artist' => $track->getArtist(),
                    'title' => $track->getTitle(),
                ]);
            }

            $pendingTracks[] = $track;

            // Flush periodically so memory does not grow unbounded on big
            // sweeps. We then detach the just-flushed LastFmImportTrack
            // rows (their setStatus() / setMatchedMediaFileId() mutations
            // are now safely written) and drop the matcher's pending
            // cache entries — without that the identity map keeps every
            // iterated track + every cache entry for the entire run,
            // OOMing on large rematch sweeps.
            if (!$dryRun && ++$batch >= 100) {
                $this->flushAndDetach($pendingTracks);
                $pendingTracks = [];
                $batch = 0;
            }

            if ($progress !== null && $report->considered % 50 === 0) {
                $progress(
                    $report->considered,
                    $report->matchedAsInserted + $report->matchedAsDuplicate + $report->skipped,
                    $report->stillUnmatched,
                );
            }
        }

        if (!$dryRun) {
            $this->flushAndDetach($pendingTracks);
        }

        if ($progress !== null) {
            $progress(
                $report->considered,
                $report->matchedAsInserted + $report->matchedAsDuplicate + $report->skipped,
                $report->stillUnmatched,
            );
        }

        return $report;
    }

    /**
     * @param list<LastFmImportTrack> $pendingTracks
     */
    private function flushAndDetach(array $pendingTracks): void
    {
        $this->em->flush();
        foreach ($pendingTracks as $track) {
            if ($this->em->contains($track)) {
                $this->em->detach($track);
            }
        }
        $this->cacheRepository?->detachPending();
    }
}
