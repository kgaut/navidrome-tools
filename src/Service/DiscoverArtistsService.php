<?php

namespace App\Service;

use App\Entity\StatsSnapshot;
use App\LastFm\LastFmClient;
use App\Lidarr\LidarrClient;
use App\Lidarr\LidarrConfig;
use App\Navidrome\NavidromeRepository;
use App\Repository\StatsSnapshotRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Builds and caches a list of « artists you don't have but might like »
 * by combining your top Navidrome listens with Last.fm's
 * `artist.getSimilar` graph. Results are cross-referenced with the Lidarr
 * library so the UI can offer a one-click « + Lidarr » action with a
 * « already there » hint.
 */
class DiscoverArtistsService
{
    public const KEY = 'discover-artists';
    public const TTL_HOURS = 24;
    public const DEFAULT_TOP_N = 20;
    public const DEFAULT_SIMILAR_PER_SEED = 10;
    public const SEED_WINDOW_DAYS = 90;

    public function __construct(
        private readonly NavidromeRepository $navidrome,
        private readonly LastFmClient $lastFm,
        private readonly LidarrClient $lidarr,
        private readonly LidarrConfig $lidarrConfig,
        private readonly StatsSnapshotRepository $repository,
        private readonly EntityManagerInterface $em,
        private readonly string $apiKey,
    ) {
    }

    public function isApiKeyConfigured(): bool
    {
        return $this->apiKey !== '';
    }

    public function getCached(): ?StatsSnapshot
    {
        return $this->repository->findOneByPeriod(self::KEY);
    }

    public function isFresh(StatsSnapshot $snapshot): bool
    {
        $age = (new \DateTimeImmutable())->getTimestamp() - $snapshot->getComputedAt()->getTimestamp();

        return $age < self::TTL_HOURS * 3600;
    }

    public function compute(int $topN = self::DEFAULT_TOP_N, int $simPerSeed = self::DEFAULT_SIMILAR_PER_SEED): StatsSnapshot
    {
        if (!$this->isApiKeyConfigured()) {
            throw new \RuntimeException('LASTFM_API_KEY n\'est pas configuré.');
        }
        if (!$this->navidrome->isAvailable()) {
            throw new \RuntimeException('La base Navidrome est inaccessible.');
        }

        $now = new \DateTimeImmutable();
        $from = $now->modify('-' . self::SEED_WINDOW_DAYS . ' days');
        $seeds = $this->navidrome->getTopArtists($from, $now, $topN);
        $known = $this->navidrome->getKnownArtistsNormalized();

        $suggestions = [];
        foreach ($seeds as $seed) {
            $seedName = $seed['artist'];
            try {
                $similar = $this->lastFm->artistGetSimilar($this->apiKey, $seedName, $simPerSeed);
            } catch (\Throwable) {
                // Network / API error on one seed shouldn't break the whole batch.
                continue;
            }
            foreach ($similar as $sim) {
                $norm = NavidromeRepository::normalize($sim['name']);
                if ($norm === '' || isset($known[$norm])) {
                    continue;
                }
                if (!isset($suggestions[$norm]) || $sim['match'] > $suggestions[$norm]['match']) {
                    $suggestions[$norm] = [
                        'name' => $sim['name'],
                        'mbid' => $sim['mbid'],
                        'match' => $sim['match'],
                        'url' => $sim['url'],
                        'seed' => $seedName,
                    ];
                }
            }
        }

        $items = array_values($suggestions);
        usort($items, static fn (array $a, array $b): int => $b['match'] <=> $a['match']);

        $lidarrIndex = [];
        if ($this->lidarrConfig->isConfigured()) {
            try {
                $lidarrIndex = $this->lidarr->indexExistingArtists();
            } catch (\Throwable) {
                $lidarrIndex = [];
            }
        }
        foreach ($items as &$it) {
            $key = mb_strtolower(trim($it['name']));
            $it['in_lidarr'] = $lidarrIndex !== [] && isset($lidarrIndex[$key]);
        }
        unset($it);

        $snapshot = $this->repository->findOneByPeriod(self::KEY) ?? new StatsSnapshot(self::KEY);
        $snapshot->setData([
            'items' => $items,
            'seeds' => array_map(static fn (array $s): string => $s['artist'], $seeds),
            'lidarr_configured' => $this->lidarrConfig->isConfigured(),
        ]);

        if ($snapshot->getId() === null) {
            $this->em->persist($snapshot);
        }
        $this->em->flush();

        return $snapshot;
    }
}
