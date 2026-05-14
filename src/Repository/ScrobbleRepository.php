<?php

namespace App\Repository;

use App\Entity\Scrobble;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Scrobble> */
class ScrobbleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Scrobble::class);
    }

    public function countByUser(string $user): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.lastfmUser = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countAll(): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Returns the most recent played_at for the given user, or null if no
     * scrobble has been stored yet. Used by app:lastfm:fetch for smart date.
     */
    public function getLastPlayedAt(string $user): ?\DateTimeImmutable
    {
        $result = $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT MAX(played_at) FROM scrobbles WHERE lastfm_user = ?',
            [$user],
        );

        if ($result === false || $result === null) {
            return null;
        }

        return new \DateTimeImmutable((string) $result, new \DateTimeZone('UTC'));
    }

    /**
     * Insert a scrobble using raw DBAL INSERT OR IGNORE for performance and
     * idempotency. Returns true when the row was inserted, false when it was
     * rejected by the unique constraint (duplicate).
     */
    public function insertOrIgnore(
        string $user,
        string $artist,
        string $title,
        ?string $album,
        ?string $albumArtist,
        ?string $mbidTrack,
        ?string $mbidArtist,
        ?string $mbidAlbum,
        \DateTimeImmutable $playedAt,
        bool $loved,
        ?string $imageUrl,
        string $fetchedAt,
    ): bool {
        $utc = new \DateTimeZone('UTC');

        $affected = $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT OR IGNORE INTO scrobbles
                (lastfm_user, artist, title, album, album_artist,
                 mbid_track, mbid_artist, mbid_album,
                 played_at, loved, image_url, fetched_at)
             VALUES
                (:user, :artist, :title, :album, :album_artist,
                 :mbid_track, :mbid_artist, :mbid_album,
                 :played_at, :loved, :image_url, :fetched_at)',
            [
                'user' => $user,
                'artist' => $artist,
                'title' => $title,
                'album' => $album,
                'album_artist' => $albumArtist,
                'mbid_track' => $mbidTrack,
                'mbid_artist' => $mbidArtist,
                'mbid_album' => $mbidAlbum,
                'played_at' => $playedAt->setTimezone($utc)->format('Y-m-d H:i:s'),
                'loved' => $loved ? 1 : 0,
                'image_url' => $imageUrl,
                'fetched_at' => $fetchedAt,
            ],
        );

        return $affected === 1;
    }

}
