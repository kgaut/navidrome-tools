<?php

namespace App\Repository;

use App\AudioMuse\TrackFeatures;
use App\Entity\AudioFeature;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Bulk storage of AudioMuse audio features (issue #257). Writes go through
 * DBAL (one upsert per track, in a transaction per batch): tens of
 * thousands of rows would be needlessly slow through the ORM unit of work.
 *
 * @extends ServiceEntityRepository<AudioFeature>
 */
class AudioFeatureRepository extends ServiceEntityRepository
{
    private const CHUNK = 500;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AudioFeature::class);
    }

    /**
     * @return array<string, string> media_file id => AudioMuse fingerprint
     */
    public function fingerprints(): array
    {
        $out = [];
        foreach ($this->db()->fetchAllAssociative('SELECT media_file_id, fingerprint FROM audio_feature') as $r) {
            $out[(string) $r['media_file_id']] = (string) $r['fingerprint'];
        }

        return $out;
    }

    /**
     * @param list<TrackFeatures> $tracks
     */
    public function upsert(array $tracks, \DateTimeImmutable $now): void
    {
        if ($tracks === []) {
            return;
        }
        $sql = <<<'SQL'
            INSERT INTO audio_feature (
                media_file_id, tempo, music_key, scale, energy,
                danceable, aggressive, happy, party, relaxed, sad,
                top_genre, genres, fingerprint, synced_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON CONFLICT (media_file_id) DO UPDATE SET
                tempo = excluded.tempo, music_key = excluded.music_key, scale = excluded.scale,
                energy = excluded.energy, danceable = excluded.danceable, aggressive = excluded.aggressive,
                happy = excluded.happy, party = excluded.party, relaxed = excluded.relaxed, sad = excluded.sad,
                top_genre = excluded.top_genre, genres = excluded.genres,
                fingerprint = excluded.fingerprint, synced_at = excluded.synced_at
            SQL;
        $syncedAt = $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        $this->db()->transactional(function (Connection $db) use ($tracks, $sql, $syncedAt): void {
            foreach ($tracks as $t) {
                $db->executeStatement($sql, [
                    $t->id, $t->tempo, $t->key, $t->scale, $t->energy,
                    $t->moods['danceable'] ?? null, $t->moods['aggressive'] ?? null, $t->moods['happy'] ?? null,
                    $t->moods['party'] ?? null, $t->moods['relaxed'] ?? null, $t->moods['sad'] ?? null,
                    $t->topGenre(), json_encode($t->genres, \JSON_THROW_ON_ERROR), $t->fingerprint, $syncedAt,
                ]);
            }
        });
    }

    /**
     * @param list<string> $ids
     */
    public function deleteIds(array $ids): int
    {
        $deleted = 0;
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $deleted += (int) $this->db()->executeStatement(
                'DELETE FROM audio_feature WHERE media_file_id IN (?)',
                [$chunk],
                [ArrayParameterType::STRING],
            );
        }

        return $deleted;
    }

    public function countAll(): int
    {
        return (int) $this->db()->fetchOne('SELECT COUNT(*) FROM audio_feature');
    }

    /**
     * Tracks whose detected tempo falls in one of the `[min, max]` BPM
     * ranges (bounds included).
     *
     * @param list<array{0: float, 1: float}> $ranges
     *
     * @return list<string> media_file ids
     */
    public function idsInTempoRanges(array $ranges): array
    {
        if ($ranges === []) {
            return [];
        }
        $where = implode(' OR ', array_fill(0, count($ranges), '(tempo BETWEEN ? AND ?)'));
        $params = array_merge(...array_map(static fn (array $r): array => [$r[0], $r[1]], $ranges));

        return array_map('strval', $this->db()->fetchFirstColumn(
            sprintf('SELECT media_file_id FROM audio_feature WHERE %s', $where),
            $params,
        ));
    }

    /**
     * The `$limit` tracks scoring highest on a mood, at least `$minScore`.
     *
     * @return list<string> media_file ids, strongest first
     */
    public function topByMood(string $mood, float $minScore, int $limit): array
    {
        if (!in_array($mood, TrackFeatures::MOODS, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown mood "%s".', $mood));
        }

        return array_map('strval', $this->db()->fetchFirstColumn(
            sprintf('SELECT media_file_id FROM audio_feature WHERE %1$s >= ? ORDER BY %1$s DESC LIMIT ?', $mood),
            [$minScore, $limit],
            [ParameterType::STRING, ParameterType::INTEGER],
        ));
    }

    /**
     * @param list<string> $ids
     *
     * @return array<string, float> media_file id => energy, for ids that have one
     */
    public function energyByIds(array $ids): array
    {
        $out = [];
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $rows = $this->db()->fetchAllAssociative(
                'SELECT media_file_id, energy FROM audio_feature WHERE energy IS NOT NULL AND media_file_id IN (?)',
                [$chunk],
                [ArrayParameterType::STRING],
            );
            foreach ($rows as $r) {
                $out[(string) $r['media_file_id']] = (float) $r['energy'];
            }
        }

        return $out;
    }

    /**
     * @param list<string> $ids
     *
     * @return array<string, array{tempo: ?float, energy: ?float, danceable: ?float}> for ids that have a row
     */
    public function featuresByIds(array $ids): array
    {
        $out = [];
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $rows = $this->db()->fetchAllAssociative(
                'SELECT media_file_id, tempo, energy, danceable FROM audio_feature WHERE media_file_id IN (?)',
                [$chunk],
                [ArrayParameterType::STRING],
            );
            foreach ($rows as $r) {
                $out[(string) $r['media_file_id']] = [
                    'tempo' => $r['tempo'] === null ? null : (float) $r['tempo'],
                    'energy' => $r['energy'] === null ? null : (float) $r['energy'],
                    'danceable' => $r['danceable'] === null ? null : (float) $r['danceable'],
                ];
            }
        }

        return $out;
    }

    /**
     * Library-wide energy at the given percentile (0..100), e.g. 60 → the
     * value 60 % of analysed tracks stay under. Null when nothing is imported.
     */
    public function energyPercentile(int $percentile): ?float
    {
        $count = (int) $this->db()->fetchOne('SELECT COUNT(*) FROM audio_feature WHERE energy IS NOT NULL');
        if ($count === 0) {
            return null;
        }
        $offset = (int) floor((max(0, min(100, $percentile)) / 100) * ($count - 1));
        $value = $this->db()->fetchOne(
            'SELECT energy FROM audio_feature WHERE energy IS NOT NULL ORDER BY energy ASC LIMIT 1 OFFSET ?',
            [$offset],
            [ParameterType::INTEGER],
        );

        return $value === false ? null : (float) $value;
    }

    private function db(): Connection
    {
        return $this->getEntityManager()->getConnection();
    }
}
