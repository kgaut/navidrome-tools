<?php

namespace App\Playlist\Definition;

use App\Navidrome\NavidromeRepository;
use App\Playlist\PlaylistContext;
use App\Playlist\PlaylistDefinitionInterface;
use App\Repository\AudioFeatureRepository;

/**
 * « Course » — des morceaux que tu écoutes, dont le tempo tombe dans une
 * fourchette de BPM réglable (cadence de course), d'après les tempos
 * importés d'AudioMuse-AI (issue #257).
 *
 * Le BPM détecté vaut souvent la moitié du tempo ressenti : avec
 * `halfTempo`, un morceau détecté entre min/2 et max/2 compte aussi.
 * Vide tant que les caractéristiques n'ont pas été importées
 * (`app:audiomuse:sync`).
 */
final class CourseDefinition implements PlaylistDefinitionInterface
{
    public function __construct(
        private readonly NavidromeRepository $navidrome,
        private readonly AudioFeatureRepository $features,
        private readonly int $bpmMin = 160,
        private readonly int $bpmMax = 180,
        private readonly bool $halfTempo = true,
        private readonly int $minPlays = 1,
        private readonly int $limit = 50,
    ) {
    }

    public function getSlug(): string
    {
        return 'course';
    }

    public function getName(): string
    {
        return 'Course';
    }

    public function getDescription(): string
    {
        return sprintf(
            'Des morceaux écoutés au moins %d fois, entre %d et %d BPM%s (tempos AudioMuse-AI), en aléatoire.',
            $this->minPlays,
            $this->bpmMin,
            $this->bpmMax,
            $this->halfTempo ? ' ou à demi-tempo' : '',
        );
    }

    public function build(PlaylistContext $context): array
    {
        $ranges = [[(float) $this->bpmMin, (float) $this->bpmMax]];
        if ($this->halfTempo) {
            $ranges[] = [$this->bpmMin / 2, $this->bpmMax / 2];
        }

        $ids = $this->features->idsInTempoRanges($ranges);
        if ($ids === []) {
            return [];
        }
        $plays = $this->navidrome->getPlayCountsByMediaFileId($ids);
        $ids = array_values(array_filter($ids, fn (string $id): bool => ($plays[$id] ?? 0) >= $this->minPlays));
        shuffle($ids);

        return PlaylistIds::dropMissing($this->navidrome, $ids, $this->limit);
    }
}
