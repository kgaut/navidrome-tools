<?php

namespace App\Playlist\Definition;

use App\Navidrome\NavidromeRepository;
use App\Playlist\PlaylistContext;
use App\Playlist\PlaylistDefinitionInterface;

/**
 * « Très vieilles pépites » — même logique que « Pépites oubliées » mais avec
 * un silence bien plus long (par défaut 60 mois ≈ 5 ans) : des morceaux jadis
 * écoutés (≥ minPlays) plus rejoués depuis des années. Mélangé en aléatoire.
 */
final class TresVieillesPepitesDefinition implements PlaylistDefinitionInterface
{
    public function __construct(
        private readonly NavidromeRepository $navidrome,
        private readonly int $minPlays = 5,
        private readonly int $silenceMonths = 60,
        private readonly int $limit = 50,
    ) {
    }

    public function getSlug(): string
    {
        return 'tres-vieilles-pepites';
    }

    public function getName(): string
    {
        return 'Très vieilles pépites';
    }

    public function getDescription(): string
    {
        return sprintf(
            'Morceaux écoutés au moins %d fois mais plus joués depuis %d mois (≈ %d ans), en aléatoire.',
            $this->minPlays,
            $this->silenceMonths,
            (int) round($this->silenceMonths / 12),
        );
    }

    public function build(PlaylistContext $context): array
    {
        $cutoff = $context->now->modify(sprintf('-%d months', $this->silenceMonths));

        $ids = $this->navidrome->getSongsLovedAndForgotten($this->minPlays, $cutoff, $this->limit);
        shuffle($ids);

        return $ids;
    }
}
