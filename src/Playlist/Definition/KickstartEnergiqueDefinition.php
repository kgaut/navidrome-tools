<?php

namespace App\Playlist\Definition;

use App\Navidrome\NavidromeRepository;
use App\Playlist\PlaylistContext;
use App\Playlist\PlaylistDefinitionInterface;
use App\Repository\AudioFeatureRepository;

/**
 * « Kickstart énergique » — la Kickstart (les morceaux qui ouvrent le plus
 * souvent ta journée d'écoute) réduite aux morceaux énergiques et rapides,
 * d'après les caractéristiques importées d'AudioMuse-AI (issue #257).
 *
 * Énergie : au moins le `energyPercentile`-ième percentile de la
 * bibliothèque (seuil relatif : l'énergie d'AudioMuse est un niveau sonore
 * normalisé, pas une échelle absolue). Tempo : au moins `minBpm`, ou
 * « danceable » ≥ le `danceablePercentile`-ième percentile de la
 * bibliothèque, parce que le BPM détecté vaut souvent la moitié du tempo
 * ressenti. Seuil relatif là aussi : le score danceable d'AudioMuse est
 * tassé (≈ 0,6 partout), un seuil absolu de 0,5 laissait tout passer
 * (issue #271). L'ordre de la Kickstart (par fréquence) est conservé.
 * La Kickstart d'origine reste inchangée.
 */
final class KickstartEnergiqueDefinition implements PlaylistDefinitionInterface
{
    private const POOL = 500;

    public function __construct(
        private readonly NavidromeRepository $navidrome,
        private readonly AudioFeatureRepository $features,
        private readonly int $energyPercentile = 60,
        private readonly int $minBpm = 110,
        private readonly int $danceablePercentile = 75,
        private readonly int $limit = 50,
    ) {
    }

    public function getSlug(): string
    {
        return 'kickstart-energique';
    }

    public function getName(): string
    {
        return 'Kickstart énergique';
    }

    public function getDescription(): string
    {
        return sprintf(
            'Les morceaux qui ouvrent le plus souvent ta journée, parmi les plus énergiques (≥ %de percentile) '
            . 'et rapides (≥ %d BPM, ou parmi les plus dansants : ≥ %de percentile) d\'après AudioMuse-AI.',
            $this->energyPercentile,
            $this->minBpm,
            $this->danceablePercentile,
        );
    }

    public function build(PlaylistContext $context): array
    {
        $threshold = $this->features->energyPercentile($this->energyPercentile);
        if ($threshold === null) {
            return []; // nothing imported yet (app:audiomuse:sync)
        }
        // No danceable score at all → only the tempo criterion applies.
        $danceable = $this->features->moodPercentile('danceable', $this->danceablePercentile) ?? \INF;
        $pool = $this->navidrome->getDailyKickstartTracks(self::POOL);
        if ($pool === []) {
            return [];
        }

        $features = $this->features->featuresByIds($pool);
        $kept = array_values(array_filter($pool, function (string $id) use ($features, $threshold, $danceable): bool {
            $f = $features[$id] ?? null;
            if ($f === null || $f['energy'] === null || $f['energy'] < $threshold) {
                return false;
            }

            return ($f['tempo'] ?? 0.0) >= $this->minBpm || ($f['danceable'] ?? -\INF) >= $danceable;
        }));

        return PlaylistIds::dropMissing($this->navidrome, $kept, $this->limit);
    }
}
