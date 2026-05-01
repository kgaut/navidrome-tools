<?php

namespace App\Service;

use App\Lidarr\LidarrClient;

class AddArtistToLidarrService
{
    public function __construct(private readonly LidarrClient $lidarr)
    {
    }

    /**
     * @return array{id: int, artistName: string, alreadyExists: bool}
     */
    public function add(string $artistName): array
    {
        $artistName = trim($artistName);
        if ($artistName === '') {
            throw new \InvalidArgumentException('Artist name is required.');
        }

        $hits = $this->lidarr->searchArtist($artistName);
        if ($hits === []) {
            throw new \RuntimeException(sprintf('Lidarr did not find any artist matching "%s" on MusicBrainz.', $artistName));
        }

        return $this->lidarr->addArtist($hits[0]);
    }
}
