<?php

namespace App\Service;

use App\Navidrome\NavidromeRepository;
use App\Navidrome\NavidromeStringNormalizer;
use App\Repository\ScrobbleSyncRepository;

/**
 * Re-points `scrobble_sync.target_id` at the track Navidrome actually holds
 * the play under, for rows written to Navidrome whose recorded target is no
 * longer in the library (issue #291) — 85 % of them in production after the
 * track ids changed (April 2026, supposed).
 *
 * Navidrome is the source of truth and is only READ: the sync wrote our play
 * at the scrobble's exact second (`submission_time` = `played_at`), and
 * Navidrome migrated its own rows to the new ids. A row is repaired only when
 * exactly one track was played at that second; otherwise (no play, or
 * several tracks) it is left and counted. Writes data.db only, by pages, so
 * Navidrome need not be stopped.
 *
 * `title_mismatch` counts repairs whose new track title differs from the
 * scrobbled one in bare form — expected for alias matches, informative only.
 */
class TargetIdRepairer
{
    private const PAGE = 5000;
    private const SAMPLES = 10;

    public function __construct(
        private readonly ScrobbleSyncRepository $syncRepo,
        private readonly NavidromeRepository $navidrome,
    ) {
    }

    /**
     * @return array{
     *     examined: int,
     *     stale: int,
     *     repaired: int,
     *     not_found: int,
     *     ambiguous: int,
     *     title_mismatch: int,
     *     dry_run: bool,
     *     samples: list<array{artist: string, title: string, old_target: string, new_target: string, new_title: string}>
     * }
     */
    public function run(bool $dryRun): array
    {
        $out = [
            'examined' => 0, 'stale' => 0, 'repaired' => 0, 'not_found' => 0, 'ambiguous' => 0,
            'title_mismatch' => 0, 'dry_run' => $dryRun, 'samples' => [],
        ];

        $titles = $this->navidrome->mediaFileTitles();
        $bySecond = $this->navidrome->scrobbleTrackBySecond($this->navidrome->resolveUserId());
        $utc = new \DateTimeZone('UTC');

        $afterId = 0;
        while (true) {
            $page = $this->syncRepo->writtenNavidromePage($afterId, self::PAGE);
            if ($page === []) {
                break;
            }
            $afterId = $page[count($page) - 1]['id'];

            $targets = [];
            foreach ($page as $row) {
                $out['examined']++;
                $old = (string) $row['target_id'];
                if (isset($titles[$old])) {
                    continue;
                }
                $out['stale']++;

                $ts = (new \DateTimeImmutable($row['played_at'], $utc))->getTimestamp();
                $new = $bySecond[$ts] ?? null;
                if ($new === null || ($new !== '' && !isset($titles[$new]))) {
                    // No play at that second, or one on a track itself gone.
                    $out['not_found']++;
                    continue;
                }
                if ($new === '') {
                    $out['ambiguous']++;
                    continue;
                }

                $targets[$row['id']] = $new;
                $out['repaired']++;
                if (NavidromeStringNormalizer::bareTitle($row['title']) !== NavidromeStringNormalizer::bareTitle($titles[$new])) {
                    $out['title_mismatch']++;
                }
                if (count($out['samples']) < self::SAMPLES) {
                    $out['samples'][] = [
                        'artist' => $row['artist'],
                        'title' => $row['title'],
                        'old_target' => $old,
                        'new_target' => $new,
                        'new_title' => $titles[$new],
                    ];
                }
            }

            if (!$dryRun) {
                $this->syncRepo->updateTargetIds($targets);
            }
        }

        return $out;
    }
}
