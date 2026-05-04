<?php

namespace App\Service;

use App\Entity\TopSnapshot;
use App\Navidrome\NavidromeRepository;
use App\Repository\TopSnapshotRepository;
use Doctrine\ORM\EntityManagerInterface;

class TopsService
{
    public const TOP_ARTISTS_LIMIT = 50;
    public const TOP_ALBUMS_LIMIT = 100;
    public const TOP_TRACKS_LIMIT = 500;

    public function __construct(
        private readonly NavidromeRepository $navidrome,
        private readonly TopSnapshotRepository $repository,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Round (from, to) to day boundaries so a date-picker hitting different
     * times within the same day reuses the same cache row.
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    public static function normalizeWindow(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $from = $from->setTime(0, 0, 0);
        $to = $to->setTime(0, 0, 0)->modify('+1 day');
        if ($to <= $from) {
            $to = $from->modify('+1 day');
        }

        return [$from, $to];
    }

    public function getCached(\DateTimeImmutable $from, \DateTimeImmutable $to, ?string $client = null): ?TopSnapshot
    {
        [$from, $to] = self::normalizeWindow($from, $to);

        return $this->repository->findOneByWindow($from, $to, $client);
    }

    public function compute(\DateTimeImmutable $from, \DateTimeImmutable $to, ?string $client = null): TopSnapshot
    {
        [$from, $to] = self::normalizeWindow($from, $to);
        $clientFilter = ($client !== null && $client !== '') ? $client : null;

        $data = [
            'total_plays' => $this->navidrome->getTotalPlays($from, $to, $clientFilter),
            'distinct_tracks' => $this->navidrome->getDistinctTracksPlayed($from, $to, $clientFilter),
            'top_artists' => $this->navidrome->getTopArtists($from, $to, self::TOP_ARTISTS_LIMIT, $clientFilter),
            'top_albums' => $this->navidrome->getTopAlbums($from, $to, self::TOP_ALBUMS_LIMIT, $clientFilter),
            'top_tracks' => $this->navidrome->getTopTracksWithDetails($from, $to, self::TOP_TRACKS_LIMIT, $clientFilter),
        ];

        $snapshot = $this->repository->findOneByWindow($from, $to, $clientFilter)
            ?? new TopSnapshot($from, $to, $clientFilter);
        $snapshot->setData($data);

        if ($snapshot->getId() === null) {
            $this->em->persist($snapshot);
        }
        $this->em->flush();

        return $snapshot;
    }

    public function invalidate(\DateTimeImmutable $from, \DateTimeImmutable $to, ?string $client = null): void
    {
        [$from, $to] = self::normalizeWindow($from, $to);
        $existing = $this->repository->findOneByWindow($from, $to, $client);
        if ($existing !== null) {
            $this->em->remove($existing);
            $this->em->flush();
        }
    }

    /**
     * @return TopSnapshot[]
     */
    public function recentSnapshots(int $limit = 10): array
    {
        return $this->repository->findRecent($limit);
    }
}
