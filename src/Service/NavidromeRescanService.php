<?php

namespace App\Service;

use App\Entity\RunHistory;
use App\Subsonic\SubsonicClient;

/**
 * Triggers a Navidrome library scan via Subsonic (`startScan`) and records
 * the trigger in run_history for audit. Useful from the « tracks missing
 * MBID » page after the user has externally tagged files with beets — a
 * scan picks up the new MBIDs without waiting for Navidrome's scheduled run.
 *
 * The scan itself is asynchronous on Navidrome's side; we only log that the
 * trigger was sent. An empty success means the API accepted the call.
 */
class NavidromeRescanService
{
    public function __construct(
        private readonly SubsonicClient $subsonic,
        private readonly RunHistoryRecorder $recorder,
    ) {
    }

    /**
     * @throws \RuntimeException if Navidrome refuses or the Subsonic call fails;
     *                           the run is recorded as STATUS_ERROR before re-throw
     */
    public function rescan(string $reason = 'manual', bool $fullScan = false): RunHistory
    {
        $reference = $reason;
        $label = $fullScan ? 'Navidrome full rescan' : 'Navidrome rescan';

        return $this->recorder->record(
            type: RunHistory::TYPE_NAVIDROME_RESCAN,
            reference: $reference,
            label: $label,
            action: function (RunHistory $entry) use ($fullScan, $reason): RunHistory {
                // Set metrics BEFORE the call so they're preserved if the action
                // throws (RunHistoryRecorder doesn't clear metrics on exception).
                $entry->setMetrics([
                    'reason' => $reason,
                    'full_scan' => $fullScan,
                    'accepted' => false,
                ]);
                $ok = $this->subsonic->startScan($fullScan);
                if (!$ok) {
                    throw new \RuntimeException('Navidrome refused or did not answer the scan trigger.');
                }
                $entry->setMetrics([
                    'reason' => $reason,
                    'full_scan' => $fullScan,
                    'accepted' => true,
                ]);

                return $entry;
            },
        );
    }
}
