<?php

namespace App\Tests\Service;

use App\Entity\RunHistory;
use App\Service\RunHistoryRecorder;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;

class RunHistoryRecorderTest extends TestCase
{
    public function testRecordSuccessCreatesRunHistory(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('isOpen')->willReturn(true);
        $persisted = [];
        $em->method('persist')->willReturnCallback(function (object $e) use (&$persisted): void {
            $persisted[] = $e;
        });
        $em->method('flush');

        $registry = $this->createMock(ManagerRegistry::class);

        $recorder = new RunHistoryRecorder($registry, $em);
        $result = $recorder->record(
            type: RunHistory::TYPE_LASTFM_FETCH,
            reference: 'alice',
            label: 'Test run',
            action: fn () => 42,
            extractMetrics: static fn (int $v) => ['value' => $v],
        );

        $this->assertSame(42, $result);
        $runHistory = $persisted[0];
        $this->assertInstanceOf(RunHistory::class, $runHistory);
        $this->assertSame(RunHistory::TYPE_LASTFM_FETCH, $runHistory->getType());
        $this->assertSame(RunHistory::STATUS_SUCCESS, $runHistory->getStatus());
    }

    public function testRecordErrorSetsStatusAndRethrows(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('isOpen')->willReturn(true);
        $em->method('persist');
        $em->method('flush');

        $registry = $this->createMock(ManagerRegistry::class);

        $recorder = new RunHistoryRecorder($registry, $em);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('boom');

        $recorder->record(
            type: RunHistory::TYPE_LASTFM_FETCH,
            reference: 'alice',
            label: 'Failing run',
            action: fn () => throw new \RuntimeException('boom'),
        );
    }

    public function testEntityManagerClosedDuringActionIsResetSoErrorTrailLands(): void
    {
        // Scenario: the wrapped action triggers a Doctrine flush failure
        // (e.g. constraint violation in NavidromeSyncService::flushBatch),
        // which closes the EM. The catch's own flush would otherwise throw
        // « The EntityManager is closed », masking the real root cause.
        // The recorder must reset the manager, re-fetch the entry, and
        // persist the error state — then re-throw the original.

        $closedEm = $this->createMock(EntityManagerInterface::class);
        $closedEm->method('isOpen')->willReturn(false);
        $closedEm->method('persist');
        $closedEm->method('flush');

        $entry = null;
        $freshEm = $this->createMock(EntityManagerInterface::class);
        $freshEm->method('isOpen')->willReturn(true);
        $freshEm->method('find')->willReturnCallback(
            fn (string $cls, mixed $id) => $cls === RunHistory::class
                ? new RunHistory(RunHistory::TYPE_LASTFM_FETCH, 'alice', 'Resilient run')
                : null,
        );
        $flushedFresh = false;
        $freshEm->expects($this->atLeastOnce())
            ->method('flush')
            ->willReturnCallback(function () use (&$flushedFresh): void {
                $flushedFresh = true;
            });

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->expects($this->once())->method('resetManager');
        $registry->method('getManager')->willReturn($freshEm);

        $recorder = new RunHistoryRecorder($registry, $closedEm);

        $caughtMessage = null;
        try {
            $recorder->record(
                type: RunHistory::TYPE_LASTFM_FETCH,
                reference: 'alice',
                label: 'Resilient run',
                action: fn () => throw new \RuntimeException('real cause'),
            );
        } catch (\RuntimeException $e) {
            $caughtMessage = $e->getMessage();
        }

        $this->assertSame('real cause', $caughtMessage, 'Original cause must propagate untouched.');
        $this->assertTrue($flushedFresh, 'Error trail must land on the fresh EM.');
    }
}
