<?php

namespace App\Generator;

use App\Navidrome\NavidromeRepository;

class SongsYouUsedToLoveGenerator implements PlaylistGeneratorInterface
{
    public function __construct(private readonly NavidromeRepository $navidrome)
    {
    }

    public function getKey(): string
    {
        return 'songs-you-used-to-love';
    }

    public function getLabel(): string
    {
        return 'Songs you used to love';
    }

    public function getDescription(): string
    {
        return 'Morceaux que vous avez beaucoup écoutés (≥ N plays) mais plus écoutés depuis X mois.';
    }

    public function getParameterSchema(): array
    {
        return [
            new ParameterDefinition(
                name: 'min_plays',
                label: 'Nombre minimum d\'écoutes',
                type: ParameterDefinition::TYPE_INT,
                default: 5,
                min: 1,
                max: 1000,
                help: 'Seuil pour considérer qu\'un morceau était un favori.',
            ),
            new ParameterDefinition(
                name: 'months_silent',
                label: 'Mois sans écoute',
                type: ParameterDefinition::TYPE_INT,
                default: 6,
                min: 1,
                max: 240,
                help: 'Combien de mois doivent s\'être écoulés depuis la dernière écoute.',
            ),
        ];
    }

    public function generate(array $parameters, int $limit): array
    {
        $minPlays = max(1, (int) ($parameters['min_plays'] ?? 5));
        $monthsSilent = max(1, (int) ($parameters['months_silent'] ?? 6));
        $cutoff = (new \DateTimeImmutable('now'))->sub(new \DateInterval('P' . $monthsSilent . 'M'));

        return $this->navidrome->getSongsLovedAndForgotten($minPlays, $cutoff, $limit);
    }
}
