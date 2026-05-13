<?php

namespace App\Tests\Service;

use App\Entity\RunHistory;
use App\Notifier\Notifier;
use App\Service\RunHistoryRecorder;
use App\Tests\Notifier\FakeRecordingDriver;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class RunHistoryRecorderTest extends TestCase
{
    public function testSuccessfulRunPersistsMetricsAndDuration(): void
    {
        $em = $this->makeFakeEntityManager();
        $recorder = new RunHistoryRecorder($em['em']);

        $observedStatus = null;
        $result = $recorder->record(
            type: RunHistory::TYPE_PLAYLIST,
            reference: '42',
            label: 'Test playlist',
            action: function (RunHistory $entry) use (&$observedStatus): array {
                $observedStatus = $entry->getStatus();
                return ['ok' => true, 'count' => 7];
            },
            extractMetrics: static fn (array $r) => ['tracks' => $r['count']],
        );

        $this->assertSame(['ok' => true, 'count' => 7], $result);
        $this->assertCount(1, $em['persisted']);
        /** @var RunHistory $entry */
        $entry = $em['persisted'][0];
        $this->assertSame(RunHistory::STATUS_RUNNING, $observedStatus, 'Entry must be running while action executes');
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

    public function testMetricsExtractionFailureDoesNotBlockSuccess(): void
    {
        $em = $this->makeFakeEntityManager();
        $recorder = new RunHistoryRecorder($em['em']);

        $result = $recorder->record(
            type: RunHistory::TYPE_PLAYLIST,
            reference: '42',
            label: 'Test',
            action: fn () => 'ok',
            extractMetrics: static function (string $r): array {
                throw new \RuntimeException('metric extraction failure');
            },
        );

        $this->assertSame('ok', $result);
        /** @var RunHistory $entry */
        $entry = $em['persisted'][0];
        $this->assertSame(RunHistory::STATUS_SUCCESS, $entry->getStatus());
        $this->assertNull($entry->getMetrics());
    }

    public function testNotifierReceivesNotificationOnSuccessWhenOnAll(): void
    {
        $em = $this->makeFakeEntityManager();
        $driver = new FakeRecordingDriver('gotify');
        $notifier = new Notifier([$driver], 'gotify', Notifier::ON_ALL);
        $recorder = new RunHistoryRecorder($em['em'], $notifier);

        $recorder->record(
            type: RunHistory::TYPE_LASTFM_FETCH,
            reference: 'me',
            label: 'Fetch — me',
            action: fn () => 'ok',
        );

        $this->assertSame(1, $driver->sendCount);
        $this->assertNotNull($driver->lastNotification);
        $this->assertSame(RunHistory::STATUS_SUCCESS, $driver->lastNotification->status);
    }

    public function testNotifierStillReceivesErrorEvenWhenOnErrorIsTheDefault(): void
    {
        $em = $this->makeFakeEntityManager();
        $driver = new FakeRecordingDriver('gotify');
        $notifier = new Notifier([$driver], 'gotify', Notifier::ON_ERROR);
        $recorder = new RunHistoryRecorder($em['em'], $notifier);

        try {
            $recorder->record(
                type: RunHistory::TYPE_LASTFM_PROCESS,
                reference: 'buffer',
                label: 'Process — buffer',
                action: function (): never {
                    throw new \RuntimeException('boom');
                },
            );
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame(1, $driver->sendCount);
        $this->assertNotNull($driver->lastNotification);
        $this->assertSame(RunHistory::STATUS_ERROR, $driver->lastNotification->status);
        $this->assertSame('boom', $driver->lastNotification->errorMessage);
    }

    public function testSuccessRunDoesNotFireWhenNotifyOnIsErrorOnly(): void
    {
        $em = $this->makeFakeEntityManager();
        $driver = new FakeRecordingDriver('gotify');
        $notifier = new Notifier([$driver], 'gotify', Notifier::ON_ERROR);
        $recorder = new RunHistoryRecorder($em['em'], $notifier);

        $recorder->record(
            type: RunHistory::TYPE_LASTFM_FETCH,
            reference: 'me',
            label: 'Fetch — me',
            action: fn () => 'ok',
        );

        $this->assertSame(0, $driver->sendCount);
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
