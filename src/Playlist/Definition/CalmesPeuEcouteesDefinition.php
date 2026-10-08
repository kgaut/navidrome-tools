<?php

namespace App\Playlist\Definition;

use App\Navidrome\NavidromeRepository;
use App\Playlist\PlaylistContext;
use App\Playlist\PlaylistDefinitionInterface;
use App\Repository\AudioFeatureRepository;

/**
 * « Calmes et peu écoutés » — les morceaux les plus « relaxed » d'après
 * AudioMuse-AI (issue #257), parmi ceux écoutés au plus `maxPlays` fois.
 * On garde les plus calmes (3× la taille de la playlist), puis on mélange.
 */
final class CalmesPeuEcouteesDefinition implements PlaylistDefinitionInterface
{
    private const POOL = 3000;
    private const MIN_SCORE = 0.5;

    public function __construct(
        private readonly NavidromeRepository $navidrome,
        private readonly AudioFeatureRepository $features,
        private readonly int $maxPlays = 2,
        private readonly int $limit = 50,
    ) {
    }

    public function getSlug(): string
    {
        return 'calmes-peu-ecoutes';
    }

    public function getName(): string
    {
        return 'Calmes et peu écoutés';
    }

    public function getDescription(): string
    {
        return sprintf(
            'Les morceaux les plus calmes (ambiance « relaxed » d\'AudioMuse-AI) écoutés au plus %d fois, en aléatoire.',
            $this->maxPlays,
        );
    }

    public function build(PlaylistContext $context): array
    {
        $ids = $this->features->topByMood('relaxed', self::MIN_SCORE, self::POOL);
        if ($ids === []) {
            return [];
        }
        $plays = $this->navidrome->getPlayCountsByMediaFileId($ids);
        $calmest = array_slice(
            array_values(array_filter($ids, fn (string $id): bool => ($plays[$id] ?? 0) <= $this->maxPlays)),
            0,
            $this->limit * 3,
        );
        shuffle($calmest);

        return PlaylistIds::dropMissing($this->navidrome, $calmest, $this->limit);
    }
}
