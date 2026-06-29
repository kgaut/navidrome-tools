<?php

namespace App\Playlist\Definition;

use App\AudioMuse\AudioMuseClient;
use App\AudioMuse\AudioMuseException;
use App\Navidrome\NavidromeRepository;
use App\Playlist\PlaylistContext;
use App\Playlist\PlaylistDefinitionInterface;

/**
 * « Mix de la semaine » — une playlist façon Discover Weekly de Spotify,
 * appuyée sur l'analyse sonique d'AudioMuse-AI.
 *
 * Recette : on part des morceaux les plus écoutés des 7 derniers jours
 * (les « seeds »), on demande à AudioMuse-AI des morceaux soniquement
 * proches, on ne garde que ceux que l'on a peu ou pas écoutés, puis on
 * mélange seeds et découvertes à parts ~égales.
 *
 * Inactive (playlist vide) tant qu'AudioMuse n'est pas configuré ; un seed
 * qu'AudioMuse n'a pas (encore) analysé est simplement ignoré.
 */
final class MixSemaineDefinition implements PlaylistDefinitionInterface
{
    public function __construct(
        private readonly NavidromeRepository $navidrome,
        private readonly AudioMuseClient $audioMuse,
        private readonly int $seedDays = 7,
        private readonly int $seedCount = 15,
        private readonly int $perSeed = 20,
        private readonly int $maxFamiliarPlays = 5,
        private readonly int $limit = 50,
    ) {
    }

    public function getSlug(): string
    {
        return 'mix-semaine';
    }

    public function getName(): string
    {
        return 'Mix de la semaine';
    }

    public function getDescription(): string
    {
        return sprintf(
            'Tes morceaux des %d derniers jours mélangés à des titres soniquement proches '
            . '(AudioMuse-AI) que tu as peu ou pas écoutés (≤ %d écoutes).',
            $this->seedDays,
            $this->maxFamiliarPlays,
        );
    }

    public function build(PlaylistContext $context): array
    {
        if (!$this->audioMuse->isConfigured()) {
            return [];
        }

        // Seeds: most-played tracks of the last N days (already ordered by
        // play volume desc on the window).
        $from = $context->now->modify(sprintf('-%d days', $this->seedDays));
        $seeds = $this->navidrome->topTracksInWindow($from, $context->now, $this->seedCount);
        if ($seeds === []) {
            return [];
        }
        $seedSet = array_fill_keys($seeds, true);

        // Gather sonically similar candidates across all seeds.
        /** @var array<string, array{count: int, distance: float}> $candidates */
        $candidates = [];
        foreach ($seeds as $seedId) {
            try {
                $similar = $this->audioMuse->similarTracks($seedId, $this->perSeed);
            } catch (AudioMuseException) {
                // Seed not analysed / transient error — skip it, keep the rest.
                continue;
            }
            foreach ($similar as $row) {
                $id = $row['item_id'];
                if (isset($seedSet[$id])) {
                    continue; // never recommend a seed back to itself
                }
                if (!isset($candidates[$id])) {
                    $candidates[$id] = ['count' => 0, 'distance' => $row['distance']];
                }
                $candidates[$id]['count']++;
                $candidates[$id]['distance'] = min($candidates[$id]['distance'], $row['distance']);
            }
        }

        // Familiarity filter: keep only little/never-played candidates.
        $discoveries = $this->filterByFamiliarity(array_keys($candidates));
        $candidates = array_intersect_key($candidates, array_fill_keys($discoveries, true));

        // Rank discoveries: most cross-referenced first, then closest.
        uksort($candidates, static function (string $a, string $b) use ($candidates): int {
            return $candidates[$b]['count'] <=> $candidates[$a]['count']
                ?: $candidates[$a]['distance'] <=> $candidates[$b]['distance'];
        });
        $rankedDiscoveries = array_keys($candidates);

        // ~50/50 mix (favour discoveries on the odd one), then shuffle.
        $nDisc = (int) ceil($this->limit / 2);
        $nSeeds = $this->limit - $nDisc;
        $mix = array_merge(
            array_slice($seeds, 0, $nSeeds),
            array_slice($rankedDiscoveries, 0, $nDisc),
        );
        shuffle($mix);

        // Drop any ids that vanished from the library, cap to the limit.
        $missing = array_fill_keys($this->navidrome->filterMissingMediaFileIds($mix), true);
        $mix = array_values(array_filter($mix, static fn (string $id): bool => !isset($missing[$id])));

        return array_slice($mix, 0, $this->limit);
    }

    /**
     * Keep only ids played at most `$maxFamiliarPlays` times (0 = never).
     *
     * @param string[] $ids
     *
     * @return string[]
     */
    private function filterByFamiliarity(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $counts = $this->navidrome->getPlayCountsByMediaFileId($ids);

        return array_values(array_filter(
            $ids,
            fn (string $id): bool => ($counts[$id] ?? 0) <= $this->maxFamiliarPlays,
        ));
    }
}
