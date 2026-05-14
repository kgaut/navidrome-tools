<?php

namespace App\Tests\Service;

use App\Entity\RunHistory;
use App\Service\RunHistoryRecorder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class RunHistoryRecorderTest extends TestCase
{
    public function testRecordSuccessCreatesRunHistory(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $persisted = [];
        $em->method('persist')->willReturnCallback(function (object $e) use (&$persisted): void {
            $persisted[] = $e;
        });
        $em->method('flush');

        $recorder = new RunHistoryRecorder($em);
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
        $em->method('persist');
        $em->method('flush');

        $recorder = new RunHistoryRecorder($em);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('boom');

        $recorder->record(
            type: RunHistory::TYPE_LASTFM_FETCH,
            reference: 'alice',
            label: 'Failing run',
            action: fn () => throw new \RuntimeException('boom'),
        );
    }
}
