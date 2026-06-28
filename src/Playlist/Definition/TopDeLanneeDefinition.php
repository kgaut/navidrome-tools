<?php

namespace App\Playlist\Definition;

use App\Navidrome\NavidromeRepository;
use App\Playlist\PlaylistContext;
use App\Playlist\PlaylistDefinitionInterface;

/**
 * « Top de l'année » — a yearly retrospective (« Wrapped ») of the last
 * COMPLETED calendar year, shuffled. Reuses
 * {@see NavidromeRepository::getTopTracksWithDates()} with that year.
 *
 * Static name: the single « Top de l'année » playlist is overwritten each
 * run and always reflects the last full year.
 */
final class TopDeLanneeDefinition implements PlaylistDefinitionInterface
{
    public function __construct(
        private readonly NavidromeRepository $navidrome,
        private readonly int $limit = 50,
    ) {
    }

    public function getSlug(): string
    {
        return 'top-de-lannee';
    }

    public function getName(): string
    {
        return 'Top de l’année';
    }

    public function getDescription(): string
    {
        return sprintf('Top %d des morceaux les plus écoutés sur l’année calendaire écoulée, en aléatoire.', $this->limit);
    }

    public function build(PlaylistContext $context): array
    {
        $year = (int) $context->now->format('Y') - 1;
        $rows = $this->navidrome->getTopTracksWithDates($year, null, null, $this->limit);

        $ids = array_map(static fn (array $r): string => (string) $r['id'], $rows);
        shuffle($ids);

        return $ids;
    }
}
