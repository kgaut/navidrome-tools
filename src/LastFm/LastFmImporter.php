<?php

namespace App\LastFm;

use App\Navidrome\NavidromeRepository;
use App\Repository\LastFmAliasRepository;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class LastFmImporter
{
    private LoggerInterface $logger;

    public function __construct(
        private readonly LastFmClient $client,
        private readonly NavidromeRepository $navidrome,
        ?LoggerInterface $logger = null,
        private readonly int $fuzzyMaxDistance = 0,
        private readonly ?LastFmAliasRepository $aliasRepository = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * @param callable(int $fetched, int $inserted, int $duplicates, int $unmatched): void $progress
     * @param callable(LastFmScrobble $scrobble, string $status, ?string $mediaFileId): void $onScrobble
     *        Called once per processed scrobble, with status one of
     *        'inserted' | 'duplicate' | 'unmatched'. Used by callers that
     *        want to persist a per-track audit row.
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

            $mediaFileId = null;
            $status = null;

            // Manual alias overrides every heuristic. A `null` target means
            // « ignore this scrobble silently » (skipped status).
            $alias = $this->aliasRepository?->findByScrobble($scrobble->artist, $scrobble->title);
            if ($alias !== null) {
                if ($alias->isSkip()) {
                    $report->skipped++;
                    $status = 'skipped';
                } else {
                    $mediaFileId = $alias->getTargetMediaFileId();
                }
            }

            if ($status === null && $mediaFileId === null && $scrobble->mbid !== null) {
                $mediaFileId = $this->navidrome->findMediaFileByMbid($scrobble->mbid);
            }
            // Try the artist+title+album triplet before the bare couple — the
            // same song can live on a studio album AND a compilation AND a
            // single, and the album disambiguates which row the user played.
            if ($status === null && $mediaFileId === null && $scrobble->album !== '') {
                $mediaFileId = $this->navidrome->findMediaFileByArtistTitleAlbum(
                    $scrobble->artist,
                    $scrobble->title,
                    $scrobble->album,
                );
            }
            if ($status === null && $mediaFileId === null) {
                $mediaFileId = $this->navidrome->findMediaFileByArtistTitle($scrobble->artist, $scrobble->title);
            }
            // Last resort: fuzzy Levenshtein, opt-in via LASTFM_FUZZY_MAX_DISTANCE.
            if ($status === null && $mediaFileId === null && $this->fuzzyMaxDistance > 0) {
                $mediaFileId = $this->navidrome->findMediaFileFuzzy(
                    $scrobble->artist,
                    $scrobble->title,
                    $this->fuzzyMaxDistance,
                );
            }

            if ($status === 'skipped') {
                // Explicit skip via alias → no DB write, no unmatched report.
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
}
