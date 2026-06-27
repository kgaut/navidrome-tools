<?php

namespace App\Playlist\Definition;

use App\Navidrome\NavidromeRepository;
use App\Playlist\PlaylistContext;
use App\Playlist\PlaylistDefinitionInterface;

/**
 * « Top du mois dernier » — the most played tracks of the previous
 * calendar month, shuffled. Reuses
 * {@see NavidromeRepository::getTopTracksWithDates()} with the year/month
 * of `now - 1 month`.
 */
final class TopDuMoisDefinition implements PlaylistDefinitionInterface
{
    public function __construct(
        private readonly NavidromeRepository $navidrome,
        private readonly int $limit = 50,
    ) {
    }

    public function getSlug(): string
    {
        return 'top-du-mois';
    }

    public function getName(): string
    {
        return 'Top du mois dernier';
    }

    public function getDescription(): string
    {
        return sprintf('Top %d des morceaux les plus écoutés le mois calendaire précédent, en aléatoire.', $this->limit);
    }

    public function build(PlaylistContext $context): array
    {
        $lastMonth = $context->now->modify('first day of last month');
        $rows = $this->navidrome->getTopTracksWithDates(
            (int) $lastMonth->format('Y'),
            (int) $lastMonth->format('n'),
            null,
            $this->limit,
        );

        $ids = array_map(static fn (array $r): string => (string) $r['id'], $rows);
        shuffle($ids);

        return $ids;
    }
}
