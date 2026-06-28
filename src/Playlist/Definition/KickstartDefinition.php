<?php

namespace App\Playlist\Definition;

use App\Navidrome\NavidromeRepository;
use App\Playlist\PlaylistContext;
use App\Playlist\PlaylistDefinitionInterface;

/**
 * « Kickstart » — the tracks that most often open the listening day. Ranks
 * songs by how many days they were the first scrobble of the day. Reuses
 * {@see NavidromeRepository::getDailyKickstartTracks()} (already ranked by
 * frequency desc, so no shuffle: « le top » means ordered).
 */
final class KickstartDefinition implements PlaylistDefinitionInterface
{
    public function __construct(
        private readonly NavidromeRepository $navidrome,
        private readonly int $limit = 50,
    ) {
    }

    public function getSlug(): string
    {
        return 'kickstart';
    }

    public function getName(): string
    {
        return 'Kickstart';
    }

    public function getDescription(): string
    {
        return sprintf('Les %d morceaux qui ouvrent le plus souvent ta journée d’écoute.', $this->limit);
    }

    public function build(PlaylistContext $context): array
    {
        return $this->navidrome->getDailyKickstartTracks($this->limit);
    }
}
