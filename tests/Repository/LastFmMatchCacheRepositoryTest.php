<?php

namespace App\Tests\Repository;

use App\Entity\LastFmMatchCacheEntry;
use App\Repository\LastFmMatchCacheRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class LastFmMatchCacheRepositoryTest extends TestCase
{
    /**
     * Regression for the SQLite UNIQUE constraint violation observed during
     * `app:lastfm:import` runs. The importer never flushes between scrobbles,
     * so `findOneBy()` (which only sees committed rows) returned null on the
     * second occurrence of the same (normalized artist, title) couple — and
     * the repo `persist()`-ed a duplicate. At end-of-run flush the unique
     * index `uniq_lastfm_match_cache_source_norm` then fired:
     *   « UNIQUE constraint failed: lastfm_match_cache.source_artist_norm,
     *      lastfm_match_cache.source_title_norm »
     */
    public function testRepeatedRecordPositiveForSameCoupleOnlyPersistsOnce(): void
    {
        $h = $this->buildRepo();
        $repo = $h['repo'];
        $persisted = &$h['persisted'];

        $repo->recordPositive('Hozier', 'Take Me to Church', 'mf-1', LastFmMatchCacheEntry::STRATEGY_COUPLE);
        $repo->recordPositive('Hozier', 'Take Me to Church', 'mf-1', LastFmMatchCacheEntry::STRATEGY_COUPLE);
        $repo->recordPositive('Hozier', 'Take Me to Church', 'mf-1', LastFmMatchCacheEntry::STRATEGY_COUPLE);

        $this->assertCount(1, $persisted);
    }

    public function testRepeatedRecordNegativeForSameCoupleOnlyPersistsOnce(): void
    {
        $h = $this->buildRepo();
        $repo = $h['repo'];
        $persisted = &$h['persisted'];

        $repo->recordNegative('Unknown', 'Nothing');
        $repo->recordNegative('Unknown', 'Nothing');

        $this->assertCount(1, $persisted);
    }

    /**
     * Two distinct source strings whose normalized form collapses to the
     * same value would also collide on the unique index — the in-memory
     * dedup must key on the normalized form too.
     */
    public function testInputsThatCollapseToSameNormalizedFormDoNotDoublePersist(): void
    {
        $h = $this->buildRepo();
        $repo = $h['repo'];
        $persisted = &$h['persisted'];

        $repo->recordPositive('Hozier', 'Take Me to Church', 'mf-1', LastFmMatchCacheEntry::STRATEGY_COUPLE);
        // Different casing + trailing punctuation → identical np_normalize output.
        $repo->recordPositive('HOZIER!', 'Take me to church.', 'mf-1', LastFmMatchCacheEntry::STRATEGY_COUPLE);

        $this->assertCount(1, $persisted);
    }

    public function testFindByCoupleReturnsPendingEntryBeforeFlush(): void
    {
        $h = $this->buildRepo();
        $repo = $h['repo'];

        $repo->recordPositive('Hozier', 'Take Me to Church', 'mf-1', LastFmMatchCacheEntry::STRATEGY_COUPLE);

        $found = $repo->findByCouple('Hozier', 'Take Me to Church');
        $this->assertNotNull($found);
        $this->assertSame('mf-1', $found->getTargetMediaFileId());
    }

    /**
     * If the cascade later upgrades the verdict for a couple already cached
     * in this run (e.g. negative → positive after a Last.fm correction),
     * upsert() must mutate the pending entry in place rather than persist a
     * second one — same root cause, same constraint to dodge.
     */
    public function testNegativeFollowedByPositiveMutatesPendingEntry(): void
    {
        $h = $this->buildRepo();
        $repo = $h['repo'];
        $persisted = &$h['persisted'];

        $repo->recordNegative('Hozier', 'Take Me to Church');
        $repo->recordPositive('Hozier', 'Take Me to Church', 'mf-1', LastFmMatchCacheEntry::STRATEGY_LASTFM_CORRECTION);

        $this->assertCount(1, $persisted);
        /** @var LastFmMatchCacheEntry $entry */
        $entry = $persisted[0];
        $this->assertSame('mf-1', $entry->getTargetMediaFileId());
        $this->assertSame(LastFmMatchCacheEntry::STRATEGY_LASTFM_CORRECTION, $entry->getStrategy());
    }

    public function testEmptyNormalizedKeyIsIgnoredAndNeverPersists(): void
    {
        $h = $this->buildRepo();
        $repo = $h['repo'];
        $persisted = &$h['persisted'];

        $repo->recordPositive('!!!', '   ', 'mf-x', LastFmMatchCacheEntry::STRATEGY_COUPLE);
        $repo->recordNegative('   ', '!!!');

        $this->assertCount(0, $persisted);
    }

    /**
     * @return array{repo: LastFmMatchCacheRepository, persisted: list<LastFmMatchCacheEntry>}
     */
    private function buildRepo(): array
    {
        $persisted = [];
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $e) use (&$persisted): void {
            $persisted[] = $e;
        });

        $repo = new class ($em) extends LastFmMatchCacheRepository {
            /** @var list<array<string, mixed>> */
            public array $findOneByCalls = [];

            public function __construct(private readonly EntityManagerInterface $em)
            {
                // Skip parent::__construct — no Doctrine registry needed for unit tests.
            }

            public function getEntityManager(): EntityManagerInterface
            {
                return $this->em;
            }

            // Stub the DB lookup: a real EM would query the unflushed rows
            // separately from the unit of work. Returning null forces the
            // repository to lean on its in-memory pending map — exactly the
            // production code path that triggered the unique constraint
            // failure before this fix.
            public function findOneBy(array $criteria, ?array $orderBy = null): ?object
            {
                $this->findOneByCalls[] = $criteria;

                return null;
            }
        };

        return ['repo' => $repo, 'persisted' => &$persisted];
    }
}
