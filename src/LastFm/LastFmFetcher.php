<?php

namespace App\LastFm;

use App\Repository\ScrobbleRepository;

/**
 * Streams scrobbles from the Last.fm API and inserts them into the local
 * `scrobbles` table (the source of truth). Idempotent: re-fetching a window
 * already stored is a no-op thanks to the UNIQUE constraint on
 * (lastfm_user, played_at, artist, title).
 *
 * No Navidrome or Strawberry writes happen here — safe to run while any
 * other service is up.
 */
class LastFmFetcher
{
    public function __construct(
        private readonly LastFmClient $client,
        private readonly ScrobbleRepository $scrobbles,
    ) {
    }

    /**
     * @param callable(int $fetched, int $inserted, int $duplicates): void|null $progress
     */
    public function fetch(
        string $apiKey,
        string $lastFmUser,
        ?\DateTimeInterface $dateMin = null,
        ?\DateTimeInterface $dateMax = null,
        ?int $maxScrobbles = null,
        bool $dryRun = false,
        ?callable $progress = null,
    ): FetchReport {
        $report = new FetchReport();
        $fetchedAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        foreach ($this->client->streamRecentTracks($apiKey, $lastFmUser, $dateMin, $dateMax) as $scrobble) {
            if ($maxScrobbles !== null && $report->fetched >= $maxScrobbles) {
                break;
            }
            $report->fetched++;

            if ($report->firstPlayedAt === null) {
                $report->firstPlayedAt = $scrobble->playedAt;
            }
            $report->lastPlayedAt = $scrobble->playedAt;

            if ($dryRun) {
                $report->inserted++;
                if ($progress !== null && $report->fetched % 50 === 0) {
                    $progress($report->fetched, $report->inserted, $report->duplicates);
                }
                continue;
            }

            $inserted = $this->scrobbles->insertOrIgnore(
                user: $lastFmUser,
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
            );

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
