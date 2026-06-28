<?php

namespace App\Playlist\Definition;

use App\Navidrome\NavidromeRepository;
use App\Playlist\PlaylistContext;
use App\Playlist\PlaylistDefinitionInterface;

/**
 * « Fidèles compagnons » — the tracks you come back to across the most
 * distinct days (regularity, not raw volume). Reuses
 * {@see NavidromeRepository::getMostConsistentTracks()}; ranked by
 * distinct-days desc, so no shuffle.
 */
final class FidelesCompagnonsDefinition implements PlaylistDefinitionInterface
{
    public function __construct(
        private readonly NavidromeRepository $navidrome,
        private readonly int $limit = 50,
    ) {
    }

    public function getSlug(): string
    {
        return 'fideles-compagnons';
    }

    public function getName(): string
    {
        return 'Fidèles compagnons';
    }

    public function getDescription(): string
    {
        return sprintf('Les %d morceaux écoutés sur le plus de jours différents — ceux qui t’accompagnent vraiment.', $this->limit);
    }

    public function build(PlaylistContext $context): array
    {
        return $this->navidrome->getMostConsistentTracks($this->limit);
    }
}
