<?php

namespace App\Playlist\Definition;

use App\Navidrome\NavidromeRepository;
use App\Playlist\PlaylistContext;
use App\Playlist\PlaylistDefinitionInterface;
use App\Repository\AudioFeatureRepository;

/**
 * « Énergiques oubliées » — parmi les pépites oubliées (≥ `minPlays`
 * écoutes, silencieuses depuis `silenceMonths` mois), les plus énergiques
 * d'après AudioMuse-AI (issue #257). Classement relatif (les plus fortes
 * en énergie d'abord), pas de seuil absolu, puis mélange.
 */
final class EnergiquesOublieesDefinition implements PlaylistDefinitionInterface
{
    private const POOL = 1000;

    public function __construct(
        private readonly NavidromeRepository $navidrome,
        private readonly AudioFeatureRepository $features,
        private readonly int $minPlays = 5,
        private readonly int $silenceMonths = 12,
        private readonly int $limit = 50,
    ) {
    }

    public function getSlug(): string
    {
        return 'energiques-oubliees';
    }

    public function getName(): string
    {
        return 'Énergiques oubliées';
    }

    public function getDescription(): string
    {
        return sprintf(
            'Les plus énergiques (AudioMuse-AI) de tes morceaux écoutés au moins %d fois mais plus joués depuis %d mois.',
            $this->minPlays,
            $this->silenceMonths,
        );
    }

    public function build(PlaylistContext $context): array
    {
        $cutoff = $context->now->modify(sprintf('-%d months', $this->silenceMonths));
        $forgotten = $this->navidrome->getSongsLovedAndForgotten($this->minPlays, $cutoff, self::POOL);
        if ($forgotten === []) {
            return [];
        }

        $energy = $this->features->energyByIds(array_values($forgotten));
        arsort($energy);
        $loudest = array_map('strval', array_slice(array_keys($energy), 0, $this->limit * 2));
        shuffle($loudest);

        return PlaylistIds::dropMissing($this->navidrome, $loudest, $this->limit);
    }
}
