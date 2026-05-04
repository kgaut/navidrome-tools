<?php

namespace App\Tests\Service;

use App\Entity\RunHistory;
use App\Service\RunHistoryRecorder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class RunHistoryRecorderTest extends TestCase
{
    public function testSuccessfulRunPersistsMetricsAndDuration(): void
    {
        $em = $this->makeFakeEntityManager();
        $recorder = new RunHistoryRecorder($em['em']);

        $result = $recorder->record(
            type: RunHistory::TYPE_PLAYLIST,
            reference: '42',
            label: 'Test playlist',
            action: fn () => ['ok' => true, 'count' => 7],
            extractMetrics: static fn (array $r) => ['tracks' => $r['count']],
        );

        $this->assertSame(['ok' => true, 'count' => 7], $result);
        $this->assertCount(1, $em['persisted']);
        /** @var RunHistory $entry */
        $entry = $em['persisted'][0];
        $this->assertSame(RunHistory::STATUS_SUCCESS, $entry->getStatus());
        $this->assertSame(['tracks' => 7], $entry->getMetrics());
        $this->assertNotNull($entry->getFinishedAt());
        $this->assertNotNull($entry->getDurationMs());
    }

    public function testFailingRunRecordsErrorAndRethrows(): void
    {
        $em = $this->makeFakeEntityManager();
        $recorder = new RunHistoryRecorder($em['em']);

        $thrownMessage = null;
        try {
            $recorder->record(
                type: RunHistory::TYPE_LASTFM_IMPORT,
                reference: 'me',
                label: 'Test import',
                action: function (): never {
                    throw new \RuntimeException('boom');
                },
            );
        } catch (\Throwable $e) {
            $thrownMessage = $e->getMessage();
        }

        $this->assertSame('boom', $thrownMessage);

        /** @var RunHistory $entry */
        $entry = $em['persisted'][0];
        $this->assertSame(RunHistory::STATUS_ERROR, $entry->getStatus());
        $this->assertSame('boom', $entry->getMessage());
        $this->assertNotNull($entry->getDurationMs());
    }

    public function testRecordExistingResumesAQueuedEntryAndFlipsToRunningThenSuccess(): void
    {
        $em = $this->makeFakeEntityManager();
        $recorder = new RunHistoryRecorder($em['em']);

        $entry = new RunHistory(RunHistory::TYPE_LASTFM_FETCH, 'me', 'Fetch test');
        $entry->setStatus(RunHistory::STATUS_QUEUED);

        $observedStatus = null;
        $result = $recorder->recordExisting(
            entry: $entry,
            action: function (RunHistory $e) use (&$observedStatus) {
                $observedStatus = $e->getStatus();
                return ['ok' => true];
            },
            extractMetrics: static fn (array $r) => ['flag' => $r['ok']],
        );

        $this->assertSame(['ok' => true], $result);
        $this->assertSame(RunHistory::STATUS_RUNNING, $observedStatus, 'Entry must be running while action executes');
        $this->assertSame(RunHistory::STATUS_SUCCESS, $entry->getStatus());
        $this->assertSame(['flag' => true], $entry->getMetrics());
        $this->assertNotNull($entry->getFinishedAt());
        $this->assertNotNull($entry->getDurationMs());
        $this->assertCount(0, $em['persisted'], 'recordExisting() must not persist a new RunHistory');
    }

    public function testUpdateProgressThrottlesFlushButAlwaysUpdatesEntity(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        // record() flushes once at creation; recordExisting flushes for status=running + the final.
        // updateProgress should only flush at most once during the action.
        $em->expects($this->atMost(4))->method('flush');
        $recorder = new RunHistoryRecorder($em);

        $entry = new RunHistory(RunHistory::TYPE_LASTFM_PROCESS, 'buffer', 'Process buffer');
        // Force an id so the throttling map can key on it.
        $reflection = new \ReflectionClass($entry);
        $idProp = $reflection->getProperty('id');
        $idProp->setAccessible(true);
        $idProp->setValue($entry, 999);

        // Fire 5 progress updates back-to-back — only the first should flush.
        $recorder->updateProgress($entry, current: 10, total: 100, message: 'tick 1');
        $recorder->updateProgress($entry, current: 20, total: 100, message: 'tick 2');
        $recorder->updateProgress($entry, current: 30, total: 100, message: 'tick 3');
        $recorder->updateProgress($entry, current: 40, total: 100, message: 'tick 4');
        $recorder->updateProgress($entry, current: 50, total: 100, message: 'tick 5');

        $progress = $entry->getProgress();
        $this->assertNotNull($progress);
        $this->assertSame(50, $progress['current']);
        $this->assertSame(100, $progress['total']);
        $this->assertSame(50.0, $progress['percent']);
        $this->assertSame('tick 5', $progress['message']);
    }

    /**
     * @return array{em: EntityManagerInterface, persisted: list<RunHistory>}
     */
    private function makeFakeEntityManager(): array
    {
        $persisted = [];
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });
        $em->method('flush');

        return ['em' => $em, 'persisted' => &$persisted];
    }
}
