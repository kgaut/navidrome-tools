<?php

namespace App\Playlist\Definition;

use App\Navidrome\NavidromeRepository;
use App\Playlist\PlaylistContext;
use App\Playlist\PlaylistDefinitionInterface;

/**
 * « Top de tous les temps » — the all-time most played tracks, shuffled.
 * Reuses {@see NavidromeRepository::getTopTracksWithDates()} with no date
 * filter.
 */
final class TopAllTimeDefinition implements PlaylistDefinitionInterface
{
    public function __construct(
        private readonly NavidromeRepository $navidrome,
        private readonly int $limit = 50,
    ) {
    }

    public function getSlug(): string
    {
        return 'top-all-time';
    }

    public function getName(): string
    {
        return 'Top de tous les temps';
    }

    public function getDescription(): string
    {
        return sprintf('Top %d des morceaux les plus écoutés depuis toujours, en aléatoire.', $this->limit);
    }

    public function build(PlaylistContext $context): array
    {
        $rows = $this->navidrome->getTopTracksWithDates(null, null, null, $this->limit);

        $ids = array_map(static fn (array $r): string => (string) $r['id'], $rows);
        shuffle($ids);

        return $ids;
    }
}
