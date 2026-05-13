<?php

namespace App\Tests\Notifier;

use App\Entity\RunHistory;
use App\Notifier\Notification;
use PHPUnit\Framework\TestCase;

class NotificationTest extends TestCase
{
    public function testTitlePrefixDependsOnStatus(): void
    {
        $ok = new Notification('lastfm-fetch', 'Last.fm fetch — me', RunHistory::STATUS_SUCCESS, 1234);
        $ko = new Notification('lastfm-fetch', 'Last.fm fetch — me', RunHistory::STATUS_ERROR, 1234, errorMessage: 'boom');

        $this->assertStringStartsWith('[OK]', $ok->title());
        $this->assertStringStartsWith('[ERROR]', $ko->title());
        $this->assertFalse($ok->isError());
        $this->assertTrue($ko->isError());
    }

    public function testSummaryFormatsDurationAndMetrics(): void
    {
        $n = new Notification(
            type: 'lastfm-process',
            label: 'Process buffer',
            status: RunHistory::STATUS_SUCCESS,
            durationMs: 75_400,
            metrics: ['considered' => 100, 'inserted' => 42, 'duplicates' => 8],
        );

        $summary = $n->summary();
        $this->assertStringContainsString('Type: lastfm-process', $summary);
        $this->assertStringContainsString('Status: success', $summary);
        $this->assertStringContainsString('1m15s', $summary);
        $this->assertStringContainsString('considered=100', $summary);
        $this->assertStringContainsString('inserted=42', $summary);
    }

    public function testSummaryIncludesErrorMessageOnError(): void
    {
        $n = new Notification(
            type: 'lastfm-process',
            label: 'Process buffer',
            status: RunHistory::STATUS_ERROR,
            durationMs: 500,
            errorMessage: 'SQLITE_BUSY',
        );

        $this->assertStringContainsString('Error: SQLITE_BUSY', $n->summary());
        $this->assertStringContainsString('500ms', $n->summary());
    }

    public function testFromRunHistoryCopiesAllFields(): void
    {
        $run = new RunHistory(RunHistory::TYPE_LASTFM_FETCH, 'me', 'Last.fm fetch — me');
        $run->setStatus(RunHistory::STATUS_SUCCESS);
        $run->setDurationMs(2500);
        $run->setMetrics(['fetched' => 200]);

        $n = Notification::fromRunHistory($run);

        $this->assertSame('lastfm-fetch', $n->type);
        $this->assertSame('Last.fm fetch — me', $n->label);
        $this->assertSame(RunHistory::STATUS_SUCCESS, $n->status);
        $this->assertSame(2500, $n->durationMs);
        $this->assertSame(['fetched' => 200], $n->metrics);
        $this->assertNull($n->errorMessage);
    }

    public function testFromRunHistoryPreservesErrorMessageOnFailure(): void
    {
        $run = new RunHistory(RunHistory::TYPE_LASTFM_PROCESS, 'buffer', 'Process buffer');
        $run->setStatus(RunHistory::STATUS_ERROR);
        $run->setMessage('database is locked');
        $run->setDurationMs(120);

        $n = Notification::fromRunHistory($run);

        $this->assertTrue($n->isError());
        $this->assertSame('database is locked', $n->errorMessage);
    }
}
