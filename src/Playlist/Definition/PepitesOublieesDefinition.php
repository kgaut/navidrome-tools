<?php

namespace App\Playlist\Definition;

use App\Navidrome\NavidromeRepository;
use App\Playlist\PlaylistContext;
use App\Playlist\PlaylistDefinitionInterface;

/**
 * « Pépites oubliées » — tracks heavily played in the past (≥ minPlays)
 * but silent for at least `silenceMonths`. Reuses
 * {@see NavidromeRepository::getSongsLovedAndForgotten()} which orders by
 * play count desc / last play asc; order is preserved (no shuffle — the
 * « most loved, longest forgotten » first is the point).
 */
final class PepitesOublieesDefinition implements PlaylistDefinitionInterface
{
    public function __construct(
        private readonly NavidromeRepository $navidrome,
        private readonly int $minPlays = 5,
        private readonly int $silenceMonths = 12,
        private readonly int $limit = 50,
    ) {
    }

    public function getSlug(): string
    {
        return 'pepites-oubliees';
    }

    public function getName(): string
    {
        return 'Pépites oubliées';
    }

    public function getDescription(): string
    {
        return sprintf(
            'Morceaux écoutés au moins %d fois mais plus joués depuis %d mois.',
            $this->minPlays,
            $this->silenceMonths,
        );
    }

    public function build(PlaylistContext $context): array
    {
        $cutoff = $context->now->modify(sprintf('-%d months', $this->silenceMonths));

        return $this->navidrome->getSongsLovedAndForgotten($this->minPlays, $cutoff, $this->limit);
    }
}
