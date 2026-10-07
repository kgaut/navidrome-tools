<?php

namespace App\Playlist;

use App\AudioMuse\AudioMuseClient;
use App\Navidrome\NavidromeRepository;
use App\Playlist\Definition\DescribedPlaylistDefinition;
use App\Repository\DescribedPlaylistRepository;

/** One {@see DescribedPlaylistDefinition} per saved described playlist (issue #258). */
final class DescribedPlaylistProvider implements PlaylistDefinitionProviderInterface
{
    public function __construct(
        private readonly DescribedPlaylistRepository $repository,
        private readonly AudioMuseClient $audioMuse,
        private readonly NavidromeRepository $navidrome,
    ) {
    }

    public function getDefinitions(): iterable
    {
        foreach ($this->repository->findAllOrdered() as $playlist) {
            yield new DescribedPlaylistDefinition($playlist, $this->audioMuse, $this->navidrome);
        }
    }
}
