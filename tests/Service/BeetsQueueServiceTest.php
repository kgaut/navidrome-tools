<?php

namespace App\Tests\Service;

use App\Entity\RunHistory;
use App\Service\BeetsQueueService;
use App\Service\RunHistoryRecorder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class BeetsQueueServiceTest extends TestCase
{
    private string $queuePath;

    protected function setUp(): void
    {
        $this->queuePath = sys_get_temp_dir() . '/beets-queue-test-' . uniqid() . '.txt';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->queuePath)) {
            unlink($this->queuePath);
        }
        $dir = dirname($this->queuePath);
        if (is_dir($dir) && str_contains($dir, 'beets-queue-test-')) {
            @rmdir($dir);
        }
    }

    public function testNotConfiguredWhenPathEmpty(): void
    {
        $em = $this->makeFakeEntityManager();
        $service = new BeetsQueueService('', new RunHistoryRecorder($em['em']));
        $this->assertFalse($service->isConfigured());
        $this->assertNull($service->pendingCount());
    }

    public function testPushAppendsPathsAndRecordsRun(): void
    {
        $em = $this->makeFakeEntityManager();
        $recorder = new RunHistoryRecorder($em['em']);
        $service = new BeetsQueueService($this->queuePath, $recorder);

        $run = $service->push(['/music/a.flac', '/music/b.flac']);

        $this->assertSame(RunHistory::STATUS_SUCCESS, $run->getStatus());
        $this->assertSame(RunHistory::TYPE_BEETS_QUEUE_PUSH, $run->getType());
        $metrics = $run->getMetrics();
        $this->assertSame(2, $metrics['submitted']);
        $this->assertSame(0, $metrics['skipped_invalid']);

        $contents = file_get_contents($this->queuePath);
        $this->assertSame("/music/a.flac\n/music/b.flac\n", $contents);

        // Subsequent push appends rather than truncating.
        $service->push(['/music/c.flac']);
        $this->assertStringEndsWith("/music/c.flac\n", (string) file_get_contents($this->queuePath));

        $this->assertSame(3, $service->pendingCount());
    }

    public function testPushThrowsWhenNotConfigured(): void
    {
        $em = $this->makeFakeEntityManager();
        $service = new BeetsQueueService('', new RunHistoryRecorder($em['em']));

        $this->expectException(\RuntimeException::class);
        $service->push(['/music/a.flac']);
    }

    public function testPushFiltersInvalidPaths(): void
    {
        $em = $this->makeFakeEntityManager();
        $service = new BeetsQueueService($this->queuePath, new RunHistoryRecorder($em['em']));

        $run = $service->push([
            '/music/ok.flac',
            '',                         // empty
            "/music/with\nnewline.flac", // newline → would corrupt one-per-line format
            "/music/with\rcr.flac",      // carriage return — same
            '/music/also-ok.flac',
        ]);

        $metrics = $run->getMetrics();
        $this->assertSame(2, $metrics['submitted']);
        $this->assertSame(3, $metrics['skipped_invalid']);
        $this->assertSame("/music/ok.flac\n/music/also-ok.flac\n", file_get_contents($this->queuePath));
    }

    public function testPushNoOpWhenAllPathsInvalid(): void
    {
        $em = $this->makeFakeEntityManager();
        $service = new BeetsQueueService($this->queuePath, new RunHistoryRecorder($em['em']));

        $run = $service->push(['', '']);
        $this->assertSame(0, $run->getMetrics()['submitted']);
        $this->assertFileDoesNotExist($this->queuePath);
    }

    public function testPushCreatesParentDirectoryIfMissing(): void
    {
        $this->queuePath = sys_get_temp_dir() . '/beets-queue-test-' . uniqid() . '/queue.txt';
        $em = $this->makeFakeEntityManager();
        $service = new BeetsQueueService($this->queuePath, new RunHistoryRecorder($em['em']));

        $service->push(['/music/a.flac']);

        $this->assertFileExists($this->queuePath);
    }

    public function testPendingCountReadsLineCount(): void
    {
        file_put_contents($this->queuePath, "/a\n/b\n\n/c\n");
        $em = $this->makeFakeEntityManager();
        $service = new BeetsQueueService($this->queuePath, new RunHistoryRecorder($em['em']));

        // Empty lines are not counted.
        $this->assertSame(3, $service->pendingCount());
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
