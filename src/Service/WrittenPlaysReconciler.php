<?php

namespace App\Service;

use App\Entity\ScrobbleSync;
use App\Navidrome\NavidromeRepository;
use App\Repository\ScrobbleSyncRepository;

/**
 * Brings data.db back in line with navidrome.db after Navidrome lost writes
 * — typically a restore of an older backup (issue #298). For every play
 * data.db holds as written (`scrobble_sync.status = matched`, Navidrome
 * target), Navidrome is the source of truth:
 *
 *  - a row on the recorded track at the play's second (± tolerance, the
 *    sync's own dedup window) → in line, nothing to do;
 *  - no row on that track, but exactly one track played at that exact
 *    second → `target_id` takes that track (a reverted relink: the play is
 *    back on its former track; a new relink will move it again);
 *  - no play at all at that second → back to `pending`: the next sync
 *    re-matches and re-writes it (its ±tolerance dedup still applies);
 *  - several tracks at that second → left, counted.
 *
 * Navidrome is only READ; data.db is written by pages, so Navidrome need not
 * be stopped. `duplicate` rows (native Navidrome plays) are not examined.
 */
class WrittenPlaysReconciler
{
    private const PAGE = 5000;
    private const SAMPLES = 10;

    public function __construct(
        private readonly ScrobbleSyncRepository $syncRepo,
        private readonly NavidromeRepository $navidrome,
        private readonly int $toleranceSeconds = 60,
    ) {
    }

    /**
     * @return array{
     *     examined: int,
     *     in_line: int,
     *     retargeted: int,
     *     requeued: int,
     *     ambiguous: int,
     *     dry_run: bool,
     *     samples: list<array{outcome: string, artist: string, title: string, played_at: string, recorded: string, navidrome: string}>
     * }
     */
    public function run(bool $dryRun): array
    {
        $out = [
            'examined' => 0, 'in_line' => 0, 'retargeted' => 0, 'requeued' => 0, 'ambiguous' => 0,
            'dry_run' => $dryRun, 'samples' => [],
        ];

        $bySecond = $this->navidrome->scrobbleTracksBySecond($this->navidrome->resolveUserId());
        $utc = new \DateTimeZone('UTC');

        $afterId = 0;
        while (true) {
            $page = $this->syncRepo->writtenNavidromePage($afterId, self::PAGE, [ScrobbleSync::STATUS_MATCHED]);
            if ($page === []) {
                break;
            }
            $afterId = $page[count($page) - 1]['id'];

            $retarget = [];
            $requeue = [];
            foreach ($page as $row) {
                $out['examined']++;
                $recorded = (string) $row['target_id'];
                $ts = (new \DateTimeImmutable($row['played_at'], $utc))->getTimestamp();

                if ($recorded !== '' && $this->playedNear($bySecond, $recorded, $ts)) {
                    $out['in_line']++;
                    continue;
                }

                $atSecond = isset($bySecond[$ts]) ? array_values(array_unique(explode("\x1f", $bySecond[$ts]))) : [];
                if (count($atSecond) === 1) {
                    $retarget[$row['id']] = $atSecond[0];
                    $out['retargeted']++;
                    $this->sample($out, 'retargeted', $row, $recorded, $atSecond[0]);
                } elseif ($atSecond === []) {
                    $requeue[] = $row['id'];
                    $out['requeued']++;
                    $this->sample($out, 'requeued', $row, $recorded, '');
                } else {
                    $out['ambiguous']++;
                }
            }

            if (!$dryRun) {
                $this->syncRepo->updateTargetIds($retarget);
                $this->syncRepo->resetIdsToPending($requeue);
            }
        }

        return $out;
    }

    /**
     * @param array<int, string> $bySecond
     */
    private function playedNear(array $bySecond, string $mediaFileId, int $ts): bool
    {
        for ($t = $ts - $this->toleranceSeconds; $t <= $ts + $this->toleranceSeconds; $t++) {
            if (isset($bySecond[$t]) && in_array($mediaFileId, explode("\x1f", $bySecond[$t]), true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{examined: int, in_line: int, retargeted: int, requeued: int, ambiguous: int, dry_run: bool, samples: list<array<string, string>>} $out
     * @param array{id: int, played_at: string, target_id: ?string, artist: string, title: string} $row
     */
    private function sample(array &$out, string $outcome, array $row, string $recorded, string $navidrome): void
    {
        $count = 0;
        foreach ($out['samples'] as $s) {
            $count += $s['outcome'] === $outcome ? 1 : 0;
        }
        if ($count >= self::SAMPLES / 2) {
            return;
        }
        $out['samples'][] = [
            'outcome' => $outcome,
            'artist' => $row['artist'],
            'title' => $row['title'],
            'played_at' => $row['played_at'],
            'recorded' => $recorded,
            'navidrome' => $navidrome,
        ];
    }
}
