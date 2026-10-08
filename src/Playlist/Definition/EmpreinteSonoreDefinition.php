<?php

namespace App\Playlist\Definition;

use App\AudioMuse\AudioMuseClient;
use App\Navidrome\NavidromeRepository;
use App\Playlist\PlaylistContext;
use App\Playlist\PlaylistDefinitionInterface;

/**
 * « Empreinte sonore » — reprend la playlist qu'AudioMuse-AI produisait
 * lui-même (tâche désactivée côté serveur, issue #257) : les morceaux les
 * plus proches du « centre » sonore de tes morceaux les plus écoutés,
 * pondérés par la récence (`POST /api/sonic_fingerprint/generate`).
 *
 * Calculée sur le compte Navidrome configuré dans AudioMuse (aucun
 * identifiant n'est envoyé). Ordre d'AudioMuse (les plus proches d'abord).
 * Inactive (vide) tant qu'AudioMuse n'est pas configuré.
 */
final class EmpreinteSonoreDefinition implements PlaylistDefinitionInterface
{
    public function __construct(
        private readonly NavidromeRepository $navidrome,
        private readonly AudioMuseClient $audioMuse,
        private readonly int $limit = 50,
    ) {
    }

    public function getSlug(): string
    {
        return 'empreinte-sonore';
    }

    public function getName(): string
    {
        return 'Empreinte sonore';
    }

    public function getDescription(): string
    {
        return 'Les morceaux les plus proches de ton empreinte sonore : le centre de tes morceaux '
            . 'les plus écoutés, pondérés par la récence (AudioMuse-AI).';
    }

    public function build(PlaylistContext $context): array
    {
        if (!$this->audioMuse->isConfigured()) {
            return [];
        }

        $ids = array_values(array_unique($this->audioMuse->sonicFingerprint($this->limit)));

        return PlaylistIds::dropMissing($this->navidrome, $ids, $this->limit);
    }
}
