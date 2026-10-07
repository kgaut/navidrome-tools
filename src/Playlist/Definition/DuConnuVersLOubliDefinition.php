<?php

namespace App\Playlist\Definition;

use App\AudioMuse\AudioMuseClient;
use App\AudioMuse\AudioMuseException;
use App\AudioMuse\AudioMuseNotFoundException;
use App\Navidrome\NavidromeRepository;
use App\Playlist\PlaylistContext;
use App\Playlist\PlaylistDefinitionInterface;

/**
 * « Du connu vers l'oubli » — une playlist « parcours » qui glisse d'un
 * morceau du moment vers une pépite oubliée, en passant par des titres
 * soniquement proches (AudioMuse-AI, `/api/find_path`).
 *
 * Départ : un morceau tiré au hasard dans le top des `startDays` derniers
 * jours. Arrivée : une pépite oubliée (≥ `minPlays` écoutes, silencieuse
 * depuis `silenceMonths` mois). Les deux extrémités changent à chaque
 * génération. L'ordre est celui du chemin : jamais mélangé.
 *
 * Inactive (playlist vide) tant qu'AudioMuse n'est pas configuré. Si
 * AudioMuse ne trouve pas de chemin (404) pour une paire, on en tente une
 * autre, jusqu'à `attempts` paires, puis la génération échoue (la playlist
 * existante est conservée) ; un refus d'authentification remonte.
 */
final class DuConnuVersLOubliDefinition implements PlaylistDefinitionInterface
{
    private const CANDIDATES = 20;

    public function __construct(
        private readonly NavidromeRepository $navidrome,
        private readonly AudioMuseClient $audioMuse,
        private readonly int $length = 25,
        private readonly int $startDays = 30,
        private readonly int $minPlays = 5,
        private readonly int $silenceMonths = 12,
        private readonly int $attempts = 3,
    ) {
    }

    public function getSlug(): string
    {
        return 'du-connu-vers-l-oubli';
    }

    public function getName(): string
    {
        return 'Du connu vers l\'oubli';
    }

    public function getDescription(): string
    {
        return sprintf(
            'Un parcours sonore (AudioMuse-AI) d\'un morceau de tes %d derniers jours '
            . 'vers une pépite oubliée (≥ %d écoutes, silencieuse depuis %d mois).',
            $this->startDays,
            $this->minPlays,
            $this->silenceMonths,
        );
    }

    public function build(PlaylistContext $context): array
    {
        if (!$this->audioMuse->isConfigured()) {
            return [];
        }

        $starts = $this->navidrome->topTracksInWindow(
            $context->now->modify(sprintf('-%d days', $this->startDays)),
            $context->now,
            self::CANDIDATES,
        );
        $ends = $this->navidrome->getSongsLovedAndForgotten(
            $this->minPlays,
            $context->now->modify(sprintf('-%d months', $this->silenceMonths)),
            self::CANDIDATES,
        );
        // A track can't be both « du moment » and « oublié », but stay safe.
        $ends = array_values(array_diff($ends, $starts));
        if ($starts === [] || $ends === []) {
            return [];
        }
        shuffle($starts);
        shuffle($ends);

        $tries = min($this->attempts, count($starts), count($ends));
        for ($i = 0; $i < $tries; $i++) {
            try {
                $path = $this->audioMuse->findPath($starts[$i], $ends[$i], $this->length);
            } catch (AudioMuseNotFoundException) {
                continue; // no path for this pair (or a track not analysed): try another
            }
            $path = $this->anchor($path, $starts[$i], $ends[$i]);
            $missing = array_fill_keys($this->navidrome->filterMissingMediaFileIds($path), true);

            return array_values(array_filter($path, static fn (string $id): bool => !isset($missing[$id])));
        }

        throw new AudioMuseException(sprintf('Du connu vers l\'oubli : aucun chemin trouvé par AudioMuse en %d essais.', $tries));
    }

    /**
     * Deduplicate and make sure the path starts on `$start` and ends on
     * `$end`, whether AudioMuse included the endpoints or not.
     *
     * @param list<string> $path
     *
     * @return list<string>
     */
    private function anchor(array $path, string $start, string $end): array
    {
        $middle = array_values(array_diff(array_unique($path), [$start, $end]));

        return [$start, ...$middle, $end];
    }
}
