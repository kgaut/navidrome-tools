<?php

namespace App\Playlist\Definition;

use App\AudioMuse\AudioMuseClient;
use App\AudioMuse\AudioMuseException;
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
 *
 * Une empreinte vide est une erreur, pas un résultat (issue #273) : AudioMuse
 * répond `[]` en HTTP 200 quand il ne peut pas lire le top de l'utilisateur,
 * typiquement des identifiants Navidrome périmés dans son registre. Sans ce
 * contrôle, la playlist restait figée sans que rien ne le signale.
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
        if ($ids === []) {
            throw new AudioMuseException(
                'Empreinte sonore : AudioMuse a renvoyé une empreinte vide. Il ne lit probablement pas le top '
                . 'du compte Navidrome : vérifier les identifiants Navidrome de son registre de serveurs '
                . '(après un changement de mot de passe, redémarrer aussi son flask et son worker).',
            );
        }

        return PlaylistIds::dropMissing($this->navidrome, $ids, $this->limit);
    }
}
