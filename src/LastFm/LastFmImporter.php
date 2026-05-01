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
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * @param callable(int $fetched, int $inserted, int $duplicates, int $unmatched): void $progress
     */
    public function import(
        string $apiKey,
        string $lastFmUser,
        ?\DateTimeInterface $from = null,
        ?\DateTimeInterface $to = null,
        int $toleranceSeconds = 60,
        bool $dryRun = false,
        ?callable $progress = null,
    ): ImportReport {
        $report = new ImportReport();
        $userId = $this->navidrome->resolveUserId();

        // Bail out early with a clear message if scrobbles is missing.
        if (!$this->navidrome->hasScrobblesTable()) {
            throw new \RuntimeException(
                'The Navidrome scrobbles table does not exist. Upgrade Navidrome to >= 0.55.',
            );
        }

        foreach ($this->client->streamRecentTracks($apiKey, $lastFmUser, $from, $to) as $scrobble) {
            $report->fetched++;

            $mediaFileId = null;
            if ($scrobble->mbid !== null) {
                $mediaFileId = $this->navidrome->findMediaFileByMbid($scrobble->mbid);
            }
            if ($mediaFileId === null) {
                $mediaFileId = $this->navidrome->findMediaFileByArtistTitle($scrobble->artist, $scrobble->title);
            }

            if ($mediaFileId === null) {
                $report->recordUnmatched($scrobble);
                $this->logger->debug('Unmatched scrobble: {artist} — {title}', [
                    'artist' => $scrobble->artist,
                    'title' => $scrobble->title,
                ]);
            } elseif ($this->navidrome->scrobbleExistsNear($userId, $mediaFileId, $scrobble->playedAt, $toleranceSeconds)) {
                $report->duplicates++;
            } else {
                if (!$dryRun) {
                    $this->navidrome->insertScrobble($userId, $mediaFileId, $scrobble->playedAt);
                }
                $report->inserted++;
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
}
