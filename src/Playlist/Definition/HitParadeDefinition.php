<?php

namespace App\Playlist\Definition;

use App\Navidrome\NavidromeRepository;
use App\Playlist\PlaylistContext;
use App\Playlist\PlaylistDefinitionInterface;

/**
 * « Hit parade » — the top `perWeek` tracks of each of the last `weeks`
 * rolling 7-day windows, unioned from the most recent week to the oldest
 * (dedup, first-seen order — no shuffle). A running weekly chart of the
 * past year.
 *
 * Reuses {@see NavidromeRepository::topTracksInWindow()} once per week
 * (per-window top-N, which `topTracksInWindows()` can't do since it caps
 * the whole union). No Subsonic dependency here.
 */
final class HitParadeDefinition implements PlaylistDefinitionInterface
{
    public function __construct(
        private readonly NavidromeRepository $navidrome,
        private readonly int $weeks = 52,
        private readonly int $perWeek = 3,
    ) {
    }

    public function getSlug(): string
    {
        return 'hit-parade';
    }

    public function getName(): string
    {
        return 'Hit parade';
    }

    public function getDescription(): string
    {
        return sprintf(
            'Le top %d de chacune des %d dernières semaines, de la plus récente à la plus ancienne.',
            $this->perWeek,
            $this->weeks,
        );
    }

    public function build(PlaylistContext $context): array
    {
        $now = $context->now;
        $ids = [];
        $seen = [];

        // Week 1 = the most recent 7 days, … week N = N*7 days ago. The
        // loop walks recent → old, so the list flows that way as-is.
        for ($w = 1; $w <= $this->weeks; $w++) {
            $end = $now->modify(sprintf('-%d days', ($w - 1) * 7));
            $start = $now->modify(sprintf('-%d days', $w * 7));
            foreach ($this->navidrome->topTracksInWindow($start, $end, $this->perWeek) as $id) {
                if (!isset($seen[$id])) {
                    $seen[$id] = true;
                    $ids[] = $id;
                }
            }
        }

        return $ids;
    }
}
