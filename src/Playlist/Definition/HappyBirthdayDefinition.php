<?php

namespace App\Playlist\Definition;

use App\Navidrome\NavidromeRepository;
use App\Playlist\PlaylistContext;
use App\Playlist\PlaylistDefinitionInterface;

/**
 * « Happy birthday » — a random selection among the tracks you played the
 * most on your birthday (a fixed month/day, every year). Reuses
 * {@see NavidromeRepository::getTopTracksOnDayOfYear()}: it fetches the
 * top `limit` tracks ranked by play count on that day-of-year, then
 * shuffles them so the playlist order is random.
 */
final class HappyBirthdayDefinition implements PlaylistDefinitionInterface
{
    public function __construct(
        private readonly NavidromeRepository $navidrome,
        private readonly int $month = 5,
        private readonly int $day = 22,
        private readonly int $limit = 50,
    ) {
    }

    public function getSlug(): string
    {
        return 'happy-birthday';
    }

    public function getName(): string
    {
        return 'Happy birthday';
    }

    public function getDescription(): string
    {
        return sprintf(
            '%d morceaux au hasard parmi tes plus écoutés un %02d/%02d, toutes années confondues.',
            $this->limit,
            $this->day,
            $this->month,
        );
    }

    public function build(PlaylistContext $context): array
    {
        $ids = $this->navidrome->getTopTracksOnDayOfYear($this->month, $this->day, $this->limit);
        shuffle($ids);

        return $ids;
    }
}
