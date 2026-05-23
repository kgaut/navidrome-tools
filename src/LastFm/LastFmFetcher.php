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

    /**
     * Pull the full loved-tracks list from `user.getLovedTracks` and flip
     * `scrobbles.loved=1` for every (artist, title) — or matching MBID —
     * that has at least one scrobble in our DB.
     *
     * The Last.fm recent-tracks endpoint only reports `loved=1` if the user
     * had already loved the track BEFORE the scrobble landed, so most
     * historical scrobbles miss the flag even when the track is currently
     * loved. This sync closes that gap retroactively.
     */
    public function syncLoved(string $apiKey, string $lastFmUser, bool $dryRun = false): LovedSyncReport
    {
        $report = new LovedSyncReport();

        foreach ($this->client->iterateLovedTracks($apiKey, $lastFmUser) as $loved) {
            $report->fetched++;

            if ($dryRun) {
                if ($this->scrobbles->hasScrobble($lastFmUser, $loved->artist, $loved->title, $loved->mbid)) {
                    $report->matched++;
                } else {
                    $report->unmatched++;
                }
                continue;
            }

            $affected = $this->scrobbles->markLoved(
                $lastFmUser,
                $loved->artist,
                $loved->title,
                $loved->mbid,
            );
            $report->updatedRows += $affected;

            if ($affected > 0) {
                $report->matched++;
            } elseif ($this->scrobbles->hasScrobble($lastFmUser, $loved->artist, $loved->title, $loved->mbid)) {
                // Track is known but every scrobble was already flagged loved.
                $report->matched++;
            } else {
                $report->unmatched++;
            }
        }

        return $report;
    }
}
