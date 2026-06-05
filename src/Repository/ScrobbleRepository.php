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

    /**
     * Flip `loved=1` on every scrobble of (user, track). MBID match wins
     * when present (Last.fm and Navidrome agree on MusicBrainz ids when
     * tagged), case-insensitive (artist, title) otherwise. Returns the
     * number of rows actually flipped — 0 when the track has no matching
     * scrobble (loved-but-never-scrobbled), or when every matching row
     * was already loved.
     */
    public function markLoved(string $user, string $artist, string $title, ?string $mbidTrack): int
    {
        $conn = $this->getEntityManager()->getConnection();

        if ($mbidTrack !== null && $mbidTrack !== '') {
            $affected = (int) $conn->executeStatement(
                'UPDATE scrobbles SET loved = 1
                 WHERE lastfm_user = :u AND loved = 0 AND mbid_track = :mbid',
                ['u' => $user, 'mbid' => $mbidTrack],
            );
            if ($affected > 0) {
                return $affected;
            }
        }

        return (int) $conn->executeStatement(
            'UPDATE scrobbles SET loved = 1
             WHERE lastfm_user = :u AND loved = 0
               AND LOWER(artist) = LOWER(:a) AND LOWER(title) = LOWER(:t)',
            ['u' => $user, 'a' => $artist, 't' => $title],
        );
    }

    /**
     * Returns MIN/MAX played_at for the given user (or all users when null).
     *
     * @return array{first: ?\DateTimeImmutable, last: ?\DateTimeImmutable}
     */
    public function getScrobbleBounds(?string $user): array
    {
        $sql = 'SELECT MIN(played_at) AS first, MAX(played_at) AS last FROM scrobbles';
        $params = [];
        if ($user !== null && $user !== '') {
            $sql .= ' WHERE lastfm_user = ?';
            $params[] = $user;
        }

        $row = $this->getEntityManager()->getConnection()->fetchAssociative($sql, $params);
        if ($row === false || $row['first'] === null) {
            return ['first' => null, 'last' => null];
        }

        $utc = new \DateTimeZone('UTC');
        return [
            'first' => new \DateTimeImmutable((string) $row['first'], $utc),
            'last' => new \DateTimeImmutable((string) $row['last'], $utc),
        ];
    }

    /**
     * Count distinct (artist, title) pairs flagged as loved.
     */
    public function countLoved(?string $user): int
    {
        $sql = 'SELECT COUNT(*) FROM (SELECT 1 FROM scrobbles WHERE loved = 1';
        $params = [];
        if ($user !== null && $user !== '') {
            $sql .= ' AND lastfm_user = ?';
            $params[] = $user;
        }
        $sql .= ' GROUP BY artist, title)';

        return (int) $this->getEntityManager()->getConnection()->fetchOne($sql, $params);
    }

    /**
     * Count distinct non-empty artist values.
     */
    public function countDistinctArtists(?string $user): int
    {
        $sql = "SELECT COUNT(DISTINCT artist) FROM scrobbles WHERE artist != ''";
        $params = [];
        if ($user !== null && $user !== '') {
            $sql .= ' AND lastfm_user = ?';
            $params[] = $user;
        }

        return (int) $this->getEntityManager()->getConnection()->fetchOne($sql, $params);
    }

    /**
     * Count distinct (artist, title) pairs — unique tracks regardless of play count.
     */
    public function countDistinctTracks(?string $user): int
    {
        $sql = 'SELECT COUNT(*) FROM (SELECT 1 FROM scrobbles WHERE 1=1';
        $params = [];
        if ($user !== null && $user !== '') {
            $sql .= ' AND lastfm_user = ?';
            $params[] = $user;
        }
        $sql .= ' GROUP BY artist, title)';

        return (int) $this->getEntityManager()->getConnection()->fetchOne($sql, $params);
    }

    /**
     * True if (user, artist, title) has at least one scrobble in the DB,
     * regardless of its current loved state. Lets the loved-sync count
     * « known by Navidrome-tools » as matched even when every existing
     * scrobble was already flagged.
     */
    public function hasScrobble(string $user, string $artist, string $title, ?string $mbidTrack): bool
    {
        $conn = $this->getEntityManager()->getConnection();

        if ($mbidTrack !== null && $mbidTrack !== '') {
            $found = $conn->fetchOne(
                'SELECT 1 FROM scrobbles WHERE lastfm_user = :u AND mbid_track = :mbid LIMIT 1',
                ['u' => $user, 'mbid' => $mbidTrack],
            );
            if ($found !== false) {
                return true;
            }
        }

        $found = $conn->fetchOne(
            'SELECT 1 FROM scrobbles WHERE lastfm_user = :u
              AND LOWER(artist) = LOWER(:a) AND LOWER(title) = LOWER(:t) LIMIT 1',
            ['u' => $user, 'a' => $artist, 't' => $title],
        );

        return $found !== false;
    }
}
