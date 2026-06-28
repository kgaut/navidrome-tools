<?php

namespace App\Playlist\Definition;

use App\Navidrome\NavidromeRepository;
use App\Playlist\PlaylistContext;
use App\Playlist\PlaylistDefinitionInterface;

/**
 * « Découvertes récentes » — tracks first heard in the last `$sinceDays`
 * days (their very first scrobble falls in the window), newest discovery
 * first. Reuses {@see NavidromeRepository::getRecentlyDiscoveredTracks()};
 * order is meaningful (recency of discovery) so no shuffle.
 */
final class DecouvertesRecentesDefinition implements PlaylistDefinitionInterface
{
    public function __construct(
        private readonly NavidromeRepository $navidrome,
        private readonly int $sinceDays = 30,
        private readonly int $limit = 50,
    ) {
    }

    public function getSlug(): string
    {
        return 'decouvertes-recentes';
    }

    public function getName(): string
    {
        return 'Découvertes récentes';
    }

    public function getDescription(): string
    {
        return sprintf('Morceaux découverts (1ʳᵉ écoute) durant les %d derniers jours, du plus récent au plus ancien.', $this->sinceDays);
    }

    public function build(PlaylistContext $context): array
    {
        $since = $context->now->modify(sprintf('-%d days', $this->sinceDays));

        return $this->navidrome->getRecentlyDiscoveredTracks($since, $this->limit);
    }
}
