<?php

namespace App\Playlist\Definition;

use App\Navidrome\NavidromeRepository;
use App\Playlist\PlaylistContext;
use App\Playlist\PlaylistDefinitionInterface;

/**
 * « Coups de cœur » — starred (loved) tracks, shuffled and capped. Reuses
 * {@see NavidromeRepository::iterateStarredMediaFiles()} and keeps only the
 * media_file ids.
 */
final class CoupsDeCoeurDefinition implements PlaylistDefinitionInterface
{
    public function __construct(
        private readonly NavidromeRepository $navidrome,
        private readonly int $limit = 50,
    ) {
    }

    public function getSlug(): string
    {
        return 'coups-de-coeur';
    }

    public function getName(): string
    {
        return 'Coups de cœur';
    }

    public function getDescription(): string
    {
        return sprintf('%d morceaux likés (starred) dans Navidrome, en aléatoire.', $this->limit);
    }

    public function build(PlaylistContext $context): array
    {
        $ids = [];
        foreach ($this->navidrome->iterateStarredMediaFiles() as $row) {
            $ids[] = (string) $row['id'];
        }

        shuffle($ids);

        return array_slice($ids, 0, $this->limit);
    }
}
