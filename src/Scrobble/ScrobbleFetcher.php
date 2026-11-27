<?php

namespace App\Scrobble;

use App\LastFm\FetchReport;
use App\Repository\ScrobbleRepository;

/**
 * Streams listens from a source and inserts them into `scrobbles` (the source
 * of truth). Idempotent: re-fetching a window already stored is a no-op thanks
 * to the UNIQUE constraint on (lastfm_user, played_at, artist, title).
 *
 * No Navidrome writes happen here — safe to run while any
 * other service is up.
 */
class ScrobbleFetcher
{
    public function __construct(
        private readonly ScrobbleRepository $scrobbles,
    ) {
    }

    /**
     * @param callable(int $fetched, int $inserted, int $duplicates): void|null $progress
     */
    public function fetch(
        ScrobbleSourceInterface $source,
        ?\DateTimeInterface $dateMin = null,
        ?\DateTimeInterface $dateMax = null,
        ?int $maxScrobbles = null,
        bool $dryRun = false,
        ?callable $progress = null,
    ): FetchReport {
        $report = new FetchReport();
        $fetchedAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        // Dry-run: rows already « inserted » by this run, so the forecast also
        // counts duplicates inside the source itself.
        $dryRunSeen = [];

        foreach ($source->streamListens($dateMin, $dateMax) as $scrobble) {
            // 0 (or negative) means no cap — `--max-scrobbles=0` is documented
            // as « pas de limite » (#253).
            if ($maxScrobbles !== null && $maxScrobbles > 0 && $report->fetched >= $maxScrobbles) {
                break;
            }
            $report->fetched++;

            if ($report->firstPlayedAt === null) {
                $report->firstPlayedAt = $scrobble->playedAt;
            }
            $report->lastPlayedAt = $scrobble->playedAt;

            if ($dryRun) {
                $key = $scrobble->playedAt->getTimestamp() . "\0" . $scrobble->artist . "\0" . $scrobble->title;
                $inserted = !isset($dryRunSeen[$key])
                    && !$this->scrobbles->existsExact($source->user(), $scrobble->playedAt, $scrobble->artist, $scrobble->title);
                $dryRunSeen[$key] = true;
            } else {
                $inserted = $this->scrobbles->insertOrIgnore(
                    user: $source->user(),
                    artist: $scrobble->artist,
                    title: $scrobble->title,
                    album: $scrobble->album !== '' ? $scrobble->album : null,
                    albumArtist: $scrobble->albumArtist !== '' ? $scrobble->albumArtist : null,
                    mbidTrack: $scrobble->mbid,
                    mbidArtist: $scrobble->mbidArtist,
                    mbidAlbum: $scrobble->mbidAlbum,
                    playedAt: $scrobble->playedAt,
                    loved: $scrobble->loved,
                    imageUrl: $scrobble->imageUrl,
                    fetchedAt: $fetchedAt,
                    source: $source->name(),
                );
            }
            if ($inserted) {
                $report->inserted++;
            } else {
                $report->duplicates++;
            }

            if ($progress !== null && $report->fetched % 50 === 0) {
                $progress($report->fetched, $report->inserted, $report->duplicates);
            }
        }

        if ($progress !== null) {
            $progress($report->fetched, $report->inserted, $report->duplicates);
        }

        return $report;
    }
}
