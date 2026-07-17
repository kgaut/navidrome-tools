<?php

namespace App\Playlist\Definition;

use App\Navidrome\NavidromeRepository;
use App\Playlist\PlaylistContext;
use App\Playlist\PlaylistDefinitionInterface;

/**
 * « Pépites redécouvertes » — tracks played again in the last `recentMonths`
 * after a silence of `silenceMonths`, having already been listened to before
 * that silence (within the `windowMonths` window). A genuine re-discovery.
 * Reuses {@see NavidromeRepository::findRediscoveredGems()} then shuffles for
 * a serendipitous order (like the other « pépites »).
 */
final class PepitesRedecouvertesDefinition implements PlaylistDefinitionInterface
{
    public function __construct(
        private readonly NavidromeRepository $navidrome,
        private readonly int $windowMonths = 24,
        private readonly int $silenceMonths = 12,
        private readonly int $recentMonths = 1,
        private readonly int $limit = 50,
    ) {
    }

    public function getSlug(): string
    {
        return 'pepites-redecouvertes';
    }

    public function getName(): string
    {
        return 'Pépites redécouvertes';
    }

    public function getDescription(): string
    {
        return sprintf(
            'Morceaux rejoués dans le dernier mois après %d mois de silence '
            . '(et déjà écoutés avant, sur %d mois), en aléatoire.',
            $this->silenceMonths,
            $this->windowMonths,
        );
    }

    public function build(PlaylistContext $context): array
    {
        $recentSince = $context->now->modify(sprintf('-%d months', $this->recentMonths));
        $silenceStart = $context->now->modify(sprintf('-%d months', $this->recentMonths + $this->silenceMonths));
        $windowStart = $context->now->modify(sprintf('-%d months', $this->windowMonths));

        $ids = $this->navidrome->findRediscoveredGems($windowStart, $silenceStart, $recentSince, $this->limit);
        shuffle($ids);

        return $ids;
    }
}
