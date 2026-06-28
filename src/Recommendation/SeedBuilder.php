<?php

namespace App\Recommendation;

use App\Navidrome\NavidromeRepository;

/**
 * Builds the weighted set of « seed » artists from my own listening, combining
 * three signals (all from Navidrome):
 *   - top artists by all-time play volume,
 *   - recent artists (current calendar year) — a recency boost,
 *   - loved/starred artists — the strongest taste signal.
 *
 * Artists are deduped by normalized name (weights summed) and the top
 * `$limit` returned. No external calls here — cheap, runs inline.
 */
class SeedBuilder
{
    private const RECENT_BOOST = 1.5;
    private const LOVED_BONUS_PER_TRACK = 10.0;

    public function __construct(
        private readonly NavidromeRepository $navidrome,
    ) {
    }

    /**
     * @return list<ArtistSeed>
     */
    public function build(int $limit): array
    {
        /** @var array<string, array{name: string, weight: float}> $acc */
        $acc = [];

        // Top all-time (weight = play volume).
        foreach ($this->navidrome->getTopArtistsWithDates(null, null, null, max($limit, 50)) as $row) {
            $this->add($acc, (string) $row['artist'], (float) $row['plays']);
        }

        // Recent (current calendar year) — boosted.
        $year = (int) (new \DateTimeImmutable())->format('Y');
        foreach ($this->navidrome->getTopArtistsWithDates($year, null, null, max($limit, 50)) as $row) {
            $this->add($acc, (string) $row['artist'], (float) $row['plays'] * self::RECENT_BOOST);
        }

        // Loved/starred — strong, flat-ish bonus per loved track.
        $lovedCounts = [];
        foreach ($this->navidrome->iterateStarredMediaFiles() as $r) {
            $artist = (string) ($r['artist'] ?? '');
            if ($artist !== '') {
                $lovedCounts[$artist] = ($lovedCounts[$artist] ?? 0) + 1;
            }
        }
        foreach ($lovedCounts as $artist => $count) {
            $this->add($acc, $artist, $count * self::LOVED_BONUS_PER_TRACK);
        }

        usort($acc, static fn (array $a, array $b): int => $b['weight'] <=> $a['weight']);

        $seeds = [];
        foreach (array_slice(array_values($acc), 0, $limit) as $s) {
            $seeds[] = new ArtistSeed($s['name'], $s['weight']);
        }

        return $seeds;
    }

    /**
     * @param array<string, array{name: string, weight: float}> $acc
     */
    private function add(array &$acc, string $artist, float $weight): void
    {
        $artist = trim($artist);
        if ($artist === '') {
            return;
        }
        $key = NavidromeRepository::normalize($artist);
        if ($key === '') {
            return;
        }
        if (!isset($acc[$key])) {
            $acc[$key] = ['name' => $artist, 'weight' => 0.0];
        }
        $acc[$key]['weight'] += $weight;
    }
}
