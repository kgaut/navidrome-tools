<?php

namespace App\Tests\Repository;

use App\Entity\LastFmMatchCacheEntry;
use App\Repository\LastFmMatchCacheRepository;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\EntityManager;
use Doctrine\Persistence\AbstractManagerRegistry;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Real-DB regression tests for {@see LastFmMatchCacheRepository}: spins up a
 * Doctrine ORM stack on an in-memory SQLite, applies the schema by hand, and
 * exercises upsert / findByCouple as the production cascade does. The previous
 * test class mocked findOneBy() to always return null, which sidestepped the
 * codepath where Doctrine's identity map / unflushed-pending-state interacts
 * with the unique index — the exact codepath that actually fires in prod.
 */
class LastFmMatchCacheRepositoryDoctrineTest extends TestCase
{
    private EntityManagerInterface $em;
    private LastFmMatchCacheRepository $repo;

    protected function setUp(): void
    {
        $config = ORMSetup::createAttributeMetadataConfiguration(
            paths: [__DIR__ . '/../../src/Entity'],
            isDevMode: true,
        );
        $config->setNamingStrategy(new \Doctrine\ORM\Mapping\UnderscoreNamingStrategy(CASE_LOWER));
        $connection = DriverManager::getConnection(
            ['driver' => 'pdo_sqlite', 'memory' => true],
            $config,
        );
        $this->em = new EntityManager($connection, $config);

        $connection->executeStatement(<<<'SQL'
            CREATE TABLE lastfm_match_cache (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                source_artist VARCHAR(255) NOT NULL,
                source_title VARCHAR(255) NOT NULL,
                source_artist_norm VARCHAR(255) NOT NULL,
                source_title_norm VARCHAR(255) NOT NULL,
                target_media_file_id VARCHAR(255) DEFAULT NULL,
                strategy VARCHAR(32) NOT NULL,
                confidence_score INTEGER DEFAULT NULL,
                resolved_at DATETIME NOT NULL
            )
        SQL);
        $connection->executeStatement(
            'CREATE UNIQUE INDEX uniq_lastfm_match_cache_source_norm ON lastfm_match_cache (source_artist_norm, source_title_norm)',
        );

        $registry = new class ($this->em) extends AbstractManagerRegistry {
            public function __construct(private readonly EntityManagerInterface $em)
            {
                parent::__construct(
                    'ORM',
                    ['default' => 'doctrine.dbal.default_connection'],
                    ['default' => 'doctrine.orm.default_entity_manager'],
                    'default',
                    'default',
                    \Doctrine\Persistence\Proxy::class,
                );
            }

            protected function getService($name): object
            {
                return $this->em;
            }

            protected function resetService($name): void
            {
            }

            public function getAliasNamespace(string $alias): string
            {
                return $alias;
            }
        };

        $this->repo = new LastFmMatchCacheRepository($registry);
    }

    /**
     * Regression for the SQLite UNIQUE constraint violation observed in
     * production during `app:lastfm:process` runs (see CHANGELOG and #I4FsI).
     * The previous ORM-based implementation tracked unflushed persists in an
     * in-memory map keyed by `(artistNorm, titleNorm)` — but any drift
     * between that map and Doctrine's unit-of-work (a forgotten managed
     * entity from a prior `findOneBy()`, a purge that cleared the map but
     * not the EM, …) would let a duplicate `persist()` slip through to
     * `flush()` and fail on the unique index. Raw SQL UPSERT removes the
     * race entirely: the DB enforces uniqueness atomically.
     */
    public function testRepeatedRecordPositiveForSameCoupleOnlyKeepsOneRow(): void
    {
        $this->repo->recordPositive('Hozier', 'Take Me to Church', 'mf-1', LastFmMatchCacheEntry::STRATEGY_COUPLE);
        $this->repo->recordPositive('Hozier', 'Take Me to Church', 'mf-1', LastFmMatchCacheEntry::STRATEGY_COUPLE);
        $this->repo->recordPositive('Hozier', 'Take Me to Church', 'mf-1', LastFmMatchCacheEntry::STRATEGY_COUPLE);

        $count = (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM lastfm_match_cache');
        $this->assertSame(1, $count);
    }

    public function testRepeatedRecordNegativeForSameCoupleOnlyKeepsOneRow(): void
    {
        $this->repo->recordNegative('Unknown', 'Nothing');
        $this->repo->recordNegative('Unknown', 'Nothing');

        $count = (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM lastfm_match_cache');
        $this->assertSame(1, $count);
    }

    public function testInputsThatCollapseToSameNormalizedFormDoNotDoublePersist(): void
    {
        $this->repo->recordPositive('Hozier', 'Take Me to Church', 'mf-1', LastFmMatchCacheEntry::STRATEGY_COUPLE);
        // Different casing + trailing punctuation → identical np_normalize output.
        $this->repo->recordPositive('HOZIER!', 'Take me to church.', 'mf-1', LastFmMatchCacheEntry::STRATEGY_COUPLE);

        $count = (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM lastfm_match_cache');
        $this->assertSame(1, $count);
    }

    public function testFindByCoupleReturnsTheRowImmediatelyAfterUpsert(): void
    {
        $this->repo->recordPositive('Hozier', 'Take Me to Church', 'mf-1', LastFmMatchCacheEntry::STRATEGY_COUPLE);

        $found = $this->repo->findByCouple('Hozier', 'Take Me to Church');
        $this->assertNotNull($found);
        $this->assertSame('mf-1', $found->getTargetMediaFileId());
        $this->assertSame(LastFmMatchCacheEntry::STRATEGY_COUPLE, $found->getStrategy());
    }

    public function testEmptyNormalizedKeyIsIgnoredAndNeverPersists(): void
    {
        $this->repo->recordPositive('!!!', '   ', 'mf-x', LastFmMatchCacheEntry::STRATEGY_COUPLE);
        $this->repo->recordNegative('   ', '!!!');

        $count = (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM lastfm_match_cache');
        $this->assertSame(0, $count);
    }

    /**
     * Mixed scenario combining the failure paths the old in-memory map
     * struggled with: stale negatives already in DB, fresh persists,
     * normalized-form collisions, and same-couple repeats.
     */
    public function testRepeatedRecordNegativeAcrossManyScrobblesDoesNotConflict(): void
    {
        // Seed a few stale-negative rows (resolvedAt in the past) so findByCouple
        // returns managed entities from DB rather than null. This exercises the
        // path where upsert mutates a DB-loaded entity AND, in the same run, also
        // persists fresh entities for new couples.
        $this->seedRow('Artist A', 'Title A', stale: true);
        $this->seedRow('Artist B', 'Title B', stale: true);

        // Now hammer the repo: re-record negatives for the seeded couples (cascade
        // gave up again) AND register new ones for unseen couples. With the old
        // implementation this would persist N duplicates and blow up at flush.
        $couples = [
            ['Artist A', 'Title A'],   // mutates seed
            ['Artist B', 'Title B'],   // mutates seed
            ['Artist C', 'Title C'],   // fresh persist
            ['ARTIST a', 'title a'],   // collapses to same norm as #1
            ['Artist D', 'Title D'],   // fresh persist
            ['artist b!', 'title b.'], // collapses to same norm as #2
            ['Artist C', 'Title C'],   // re-hits #3 (now pending)
        ];
        foreach ($couples as [$artist, $title]) {
            $this->repo->recordNegative($artist, $title);
        }

        $this->em->flush();

        $count = (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM lastfm_match_cache');
        $this->assertSame(4, $count, 'Four distinct normalized couples → exactly 4 rows.');
    }

    /**
     * If the cascade later upgrades or downgrades the verdict for a couple
     * already in the cache, upsert() must update the row in place rather
     * than insert a second one — same root cause, same constraint to dodge.
     */
    public function testNegativeFollowedByPositiveMutatesTheRowInPlace(): void
    {
        $this->repo->recordNegative('Hozier', 'Take Me to Church');
        $this->repo->recordPositive('Hozier', 'Take Me to Church', 'mf-1', LastFmMatchCacheEntry::STRATEGY_LASTFM_CORRECTION);

        $rows = $this->em->getConnection()->fetchAllAssociative('SELECT * FROM lastfm_match_cache');
        $this->assertCount(1, $rows);
        $this->assertSame('mf-1', $rows[0]['target_media_file_id']);
        $this->assertSame(LastFmMatchCacheEntry::STRATEGY_LASTFM_CORRECTION, $rows[0]['strategy']);
    }

    public function testRecordPositiveThenNegativeForSameCoupleStaysSingleRow(): void
    {
        $this->repo->recordPositive('Hozier', 'Take Me to Church', 'mf-1', LastFmMatchCacheEntry::STRATEGY_COUPLE);
        $this->repo->recordNegative('Hozier', 'Take Me to Church');

        $rows = $this->em->getConnection()->fetchAllAssociative('SELECT * FROM lastfm_match_cache');
        $this->assertCount(1, $rows);
        $this->assertSame(LastFmMatchCacheEntry::STRATEGY_NEGATIVE, $rows[0]['strategy']);
        $this->assertNull($rows[0]['target_media_file_id']);
    }

    public function testFindByCoupleReflectsLatestUpsertAcrossDetachPending(): void
    {
        $this->repo->recordNegative('Foo', 'Bar');
        // Simulate the buffer processor's batch boundary.
        $this->repo->detachPending();

        // Second pass: cascade now finds a match — upsert must update the
        // existing row, not insert a duplicate.
        $this->repo->recordPositive('Foo', 'Bar', 'mf-99', LastFmMatchCacheEntry::STRATEGY_COUPLE);

        $rows = $this->em->getConnection()->fetchAllAssociative('SELECT * FROM lastfm_match_cache');
        $this->assertCount(1, $rows);
        $this->assertSame('mf-99', $rows[0]['target_media_file_id']);
        $this->assertSame(LastFmMatchCacheEntry::STRATEGY_COUPLE, $rows[0]['strategy']);

        $found = $this->repo->findByCouple('Foo', 'Bar');
        $this->assertNotNull($found);
        $this->assertSame('mf-99', $found->getTargetMediaFileId());
    }

    public function testPurgeByCoupleRemovesTheRow(): void
    {
        $this->repo->recordNegative('Foo', 'Bar');
        $deleted = $this->repo->purgeByCouple('Foo', 'Bar');

        $count = (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM lastfm_match_cache');
        $this->assertSame(0, $count);
        $this->assertSame(1, $deleted);
    }

    public function testPurgeByArtistRemovesAllItsCouples(): void
    {
        $this->repo->recordNegative('Foo', 'Bar');
        $this->repo->recordPositive('Foo', 'Baz', 'mf-1', LastFmMatchCacheEntry::STRATEGY_COUPLE);
        $this->repo->recordNegative('Other', 'Track');

        $deleted = $this->repo->purgeByArtist('Foo');

        $this->assertSame(2, $deleted);
        $remaining = $this->em->getConnection()->fetchAllAssociative('SELECT source_artist FROM lastfm_match_cache');
        $this->assertCount(1, $remaining);
        $this->assertSame('Other', $remaining[0]['source_artist']);
    }

    public function testPurgeStaleDropsNegativesOnlyOlderThanTtl(): void
    {
        $this->seedRow('Old Negative', 'Track', stale: true);
        $this->seedRow('Recent Negative', 'Track', stale: false);
        // Positive — never purged regardless of age.
        $this->em->getConnection()->executeStatement(
            'INSERT INTO lastfm_match_cache (source_artist, source_title, source_artist_norm, source_title_norm, target_media_file_id, strategy, confidence_score, resolved_at)
             VALUES (:a, :t, :an, :tn, :tid, :s, NULL, :r)',
            [
                'a' => 'Old Positive',
                't' => 'Track',
                'an' => \App\Navidrome\NavidromeRepository::normalize('Old Positive'),
                'tn' => \App\Navidrome\NavidromeRepository::normalize('Track'),
                'tid' => 'mf-keepme',
                's' => LastFmMatchCacheEntry::STRATEGY_COUPLE,
                'r' => (new \DateTimeImmutable('-365 days'))->format('Y-m-d H:i:s'),
            ],
        );

        $deleted = $this->repo->purgeStale(30);

        $this->assertSame(1, $deleted);
        $remaining = $this->em->getConnection()->fetchFirstColumn('SELECT source_artist FROM lastfm_match_cache ORDER BY source_artist');
        $this->assertSame(['Old Positive', 'Recent Negative'], $remaining);
    }

    public function testPurgeAllNegativeOnlyKeepsPositiveMatches(): void
    {
        $this->repo->recordPositive('Keep', 'Me', 'mf-keep', LastFmMatchCacheEntry::STRATEGY_COUPLE);
        $this->repo->recordNegative('Drop', 'Me');

        $deleted = $this->repo->purgeAll(negativeOnly: true);

        $this->assertSame(1, $deleted);
        $remaining = $this->em->getConnection()->fetchFirstColumn('SELECT source_artist FROM lastfm_match_cache');
        $this->assertSame(['Keep'], $remaining);
    }

    public function testFindByCouplePreservesResolvedAtFromDatabase(): void
    {
        $this->seedRow('Old Negative', 'Track', stale: true);

        $found = $this->repo->findByCouple('Old Negative', 'Track');

        $this->assertNotNull($found);
        // Stale ⇒ resolvedAt is more than 30 days in the past.
        $this->assertTrue($found->isStale(30));
    }

    private function seedRow(string $artist, string $title, bool $stale): void
    {
        $resolvedAt = $stale
            ? (new \DateTimeImmutable('-90 days'))->format('Y-m-d H:i:s')
            : (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->em->getConnection()->executeStatement(
            'INSERT INTO lastfm_match_cache (source_artist, source_title, source_artist_norm, source_title_norm, target_media_file_id, strategy, confidence_score, resolved_at)
             VALUES (:a, :t, :an, :tn, NULL, :s, NULL, :r)',
            [
                'a' => $artist,
                't' => $title,
                'an' => \App\Navidrome\NavidromeRepository::normalize($artist),
                'tn' => \App\Navidrome\NavidromeRepository::normalize($title),
                's' => LastFmMatchCacheEntry::STRATEGY_NEGATIVE,
                'r' => $resolvedAt,
            ],
        );
    }
}
