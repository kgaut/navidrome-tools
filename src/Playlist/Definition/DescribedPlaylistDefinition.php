<?php

namespace App\Playlist\Definition;

use App\AudioMuse\AudioMuseClient;
use App\Entity\DescribedPlaylist;
use App\Navidrome\NavidromeRepository;
use App\Playlist\PlaylistContext;
use App\Playlist\PlaylistDefinitionInterface;

/**
 * A playlist built from a free-text description (« piano calme, pluie »)
 * through AudioMuse-AI's CLAP text search (issue #258). Not a service:
 * one instance per {@see DescribedPlaylist} row, handed to the generator
 * by {@see \App\Playlist\DescribedPlaylistProvider}.
 *
 * Tracks come in AudioMuse's order (best match first). With `maxPlays`
 * set, only little-played tracks are kept, so AudioMuse is asked for a
 * wider pool to still fill the playlist.
 */
final class DescribedPlaylistDefinition implements PlaylistDefinitionInterface
{
    public const SLUG_PREFIX = 'decrite-';
    private const POOL_FACTOR = 5;

    public function __construct(
        private readonly DescribedPlaylist $playlist,
        private readonly AudioMuseClient $audioMuse,
        private readonly NavidromeRepository $navidrome,
    ) {
    }

    public function getSlug(): string
    {
        return self::SLUG_PREFIX . $this->playlist->getId();
    }

    public function getName(): string
    {
        return $this->playlist->getName();
    }

    public function getDescription(): string
    {
        $description = sprintf('Décrite : « %s » (AudioMuse-AI)', $this->playlist->getQuery());
        if ($this->playlist->getMaxPlays() !== null) {
            $description .= sprintf(', morceaux écoutés au plus %d fois', $this->playlist->getMaxPlays());
        }

        return $description . '.';
    }

    public function build(PlaylistContext $context): array
    {
        if (!$this->audioMuse->isConfigured()) {
            return [];
        }

        $count = $this->playlist->getTrackCount();
        $maxPlays = $this->playlist->getMaxPlays();
        $pool = $maxPlays === null ? $count : $count * self::POOL_FACTOR;

        $ids = array_values(array_unique(array_column($this->audioMuse->textSearch($this->playlist->getQuery(), $pool), 'item_id')));
        if ($ids === []) {
            return [];
        }

        if ($maxPlays !== null) {
            $plays = $this->navidrome->getPlayCountsByMediaFileId($ids);
            $ids = array_values(array_filter($ids, static fn (string $id): bool => ($plays[$id] ?? 0) <= $maxPlays));
        }

        $missing = array_fill_keys($this->navidrome->filterMissingMediaFileIds($ids), true);
        $ids = array_values(array_filter($ids, static fn (string $id): bool => !isset($missing[$id])));

        return array_slice($ids, 0, $count);
    }
}
