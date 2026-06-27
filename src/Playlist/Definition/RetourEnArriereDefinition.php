<?php

namespace App\Playlist\Definition;

use App\Navidrome\NavidromeRepository;
use App\Playlist\PlaylistContext;
use App\Playlist\PlaylistDefinitionInterface;

/**
 * « Retour en arrière » — a nostalgia playlist. For each of the past N
 * years (bounded by the first scrobble), it takes the top `perYear`
 * tracks from the `windowDays`-day window leading up to today's
 * anniversary that year, unions them (dedup, first-seen order) and
 * shuffles the result.
 *
 * E.g. on 27 June with the defaults: top 10 of [12–27 June 2025], top 10
 * of [12–27 June 2024], … — « what was I listening to around now, in
 * years past ».
 *
 * Pure read against {@see NavidromeRepository::topTracksInWindow()} (which
 * already returns media_file ids) — no Subsonic dependency here.
 */
final class RetourEnArriereDefinition implements PlaylistDefinitionInterface
{
    public function __construct(
        private readonly NavidromeRepository $navidrome,
        private readonly int $maxYears = 10,
        private readonly int $windowDays = 15,
        private readonly int $perYear = 10,
    ) {
    }

    public function getSlug(): string
    {
        return 'retour-en-arriere';
    }

    public function getName(): string
    {
        return 'Retour en arrière';
    }

    public function getDescription(): string
    {
        return sprintf(
            'Top %d des %d derniers jours, pour chaque année écoulée depuis le premier scrobble, en aléatoire.',
            $this->perYear,
            $this->windowDays,
        );
    }

    public function build(PlaylistContext $context): array
    {
        $first = $this->navidrome->getScrobbleBounds()['first'] ?? null;
        if ($first === null) {
            return [];
        }

        $now = $context->now;
        // Clamp the number of anniversary windows to the years of history
        // we actually have, so we never query a window before the first
        // scrobble (it would just return nothing anyway).
        $years = min($this->maxYears, (int) $first->diff($now)->y);
        if ($years < 1) {
            return [];
        }

        $ids = [];
        $seen = [];
        for ($y = 1; $y <= $years; $y++) {
            $anniversary = $now->modify(sprintf('-%d years', $y));
            $from = $anniversary->modify(sprintf('-%d days', $this->windowDays));
            foreach ($this->navidrome->topTracksInWindow($from, $anniversary, $this->perYear) as $id) {
                if (!isset($seen[$id])) {
                    $seen[$id] = true;
                    $ids[] = $id;
                }
            }
        }

        shuffle($ids);

        return $ids;
    }
}
