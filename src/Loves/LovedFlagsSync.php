<?php

namespace App\Loves;

use App\LastFm\LovedSyncReport;
use App\Repository\ScrobbleRepository;

/**
 * Flags `scrobbles.loved=1` for every (artist, title) — or matching MBID —
 * the provider reports as loved. Live listen feeds only carry the flag when
 * the track was loved BEFORE the listen landed; this closes that gap.
 */
class LovedFlagsSync
{
    public function __construct(
        private readonly ScrobbleRepository $scrobbles,
    ) {
    }

    public function sync(LovesProviderInterface $provider, bool $dryRun = false): LovedSyncReport
    {
        $report = new LovedSyncReport();
        $user = $provider->user();

        foreach ($provider->iterateLoved() as $loved) {
            $report->fetched++;
            if ($loved->artist === '' && $loved->mbid === null) {
                $report->unmatched++;
                continue;
            }

            if ($dryRun) {
                if ($this->scrobbles->hasScrobble($user, $loved->artist, $loved->title, $loved->mbid)) {
                    $report->matched++;
                } else {
                    $report->unmatched++;
                }
                continue;
            }

            $affected = $this->scrobbles->markLoved($user, $loved->artist, $loved->title, $loved->mbid);
            $report->updatedRows += $affected;

            if ($affected > 0 || $this->scrobbles->hasScrobble($user, $loved->artist, $loved->title, $loved->mbid)) {
                // Known track; when nothing changed, every scrobble was already flagged.
                $report->matched++;
            } else {
                $report->unmatched++;
            }
        }

        return $report;
    }
}
