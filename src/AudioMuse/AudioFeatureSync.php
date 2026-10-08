<?php

namespace App\AudioMuse;

use App\Repository\AudioFeatureRepository;

/**
 * Incremental import of AudioMuse-AI audio features into the local
 * `audio_feature` table (issue #257):
 *
 *   1. read the whole manifest (`/api/sync?fields=index`, id => fp) ;
 *   2. fetch details only for new tracks and tracks whose fp changed
 *      (re-analysed), 500 at a time ;
 *   3. drop local rows AudioMuse no longer knows.
 *
 * Safety: an empty manifest while rows exist locally aborts the run
 * instead of wiping the table (AudioMuse restarting, empty index…).
 */
class AudioFeatureSync
{
    private const BATCH = 500;

    public function __construct(
        private readonly AudioMuseClient $audioMuse,
        private readonly AudioFeatureRepository $features,
    ) {
    }

    public function run(?\DateTimeImmutable $now = null): AudioFeatureSyncReport
    {
        $now ??= new \DateTimeImmutable();
        if (!$this->audioMuse->isConfigured()) {
            throw new AudioMuseException('AudioMuse base URL is not set (AUDIOMUSE_BASE_URL).');
        }

        $remote = [];
        $page = 1;
        do {
            $chunk = $this->audioMuse->syncManifest($page++);
            $remote += $chunk['tracks'];
        } while ($chunk['has_more'] && $chunk['tracks'] !== []);

        $local = $this->features->fingerprints();
        if ($remote === [] && $local !== []) {
            throw new AudioMuseException(sprintf(
                'AudioMuse returned an empty manifest while %d tracks are stored locally: sync aborted, nothing removed.',
                count($local),
            ));
        }

        $toFetch = [];
        $added = 0;
        foreach ($remote as $id => $fp) {
            if (!isset($local[$id])) {
                $toFetch[] = (string) $id;
                $added++;
            } elseif ($local[$id] !== $fp) {
                $toFetch[] = (string) $id;
            }
        }

        foreach (array_chunk($toFetch, self::BATCH) as $ids) {
            $this->features->upsert($this->audioMuse->syncTracks($ids), $now);
        }

        $gone = array_map('strval', array_keys(array_diff_key($local, $remote)));
        $removed = $gone === [] ? 0 : $this->features->deleteIds($gone);

        return new AudioFeatureSyncReport(
            total: count($remote),
            added: $added,
            updated: count($toFetch) - $added,
            removed: $removed,
            unchanged: count($remote) - count($toFetch),
        );
    }
}
