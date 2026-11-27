<?php

namespace App\LastFm;

use App\Repository\ScrobbleRepository;
use App\Scrobble\LastFmScrobbleSource;
use App\Scrobble\ScrobbleFetcher;

/**
 * Last.fm entry points kept for the existing callers: `fetch()` delegates to
 * the source-agnostic {@see ScrobbleFetcher} (issue #310), `syncLoved()` is
 * still Last.fm-specific.
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
        return (new ScrobbleFetcher($this->scrobbles))->fetch(
            new LastFmScrobbleSource($this->client, $apiKey, $lastFmUser),
            $dateMin,
            $dateMax,
            $maxScrobbles,
            $dryRun,
            $progress,
        );
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
