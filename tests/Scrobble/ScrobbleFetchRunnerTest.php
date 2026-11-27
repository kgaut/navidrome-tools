<?php

namespace App\Tests\Scrobble;

use App\Entity\RunHistory;
use App\LastFm\FetchReport;
use App\Scrobble\ActiveScrobbleSource;
use App\Scrobble\ScrobbleFetcher;
use App\Scrobble\ScrobbleFetchRunner;
use App\Scrobble\ScrobbleSourceInterface;
use App\Scrobble\SourceFetchWindowResolver;
use App\Service\RunHistoryRecorder;
use PHPUnit\Framework\TestCase;

/**
 * Issue #310: one fetch of the active source, shared by the CLI and the web
 * import page. The last-fetch mark is stored only for computed windows.
 */
class ScrobbleFetchRunnerTest extends TestCase
{
    /** @var list<string> */
    private array $types = [];

    public function testRaccordWithNothingNewStillMarksTheFetch(): void
    {
        $resolver = $this->resolver('raccord');
        $resolver->expects($this->once())->method('markFetchedAt');

        $report = $this->runner($resolver, fetched: 0)->run(null, null, null, false);

        $this->assertSame(0, $report->fetched);
        $this->assertSame([RunHistory::TYPE_LISTENBRAINZ_FETCH], $this->types);
    }

    public function testExplicitWindowAndDryRunDoNotMarkTheFetch(): void
    {
        $explicit = $this->resolver('explicit');
        $explicit->expects($this->never())->method('markFetchedAt');
        $this->runner($explicit, fetched: 5)->run('2026-05-01', '2026-05-31', null, false);

        $dryRun = $this->resolver('smart');
        $dryRun->expects($this->never())->method('markFetchedAt');
        $this->runner($dryRun, fetched: 5)->run(null, null, null, true);
    }

    public function testSmartWindowMarksOnlyWhenSomethingWasFetched(): void
    {
        $empty = $this->resolver('smart');
        $empty->expects($this->never())->method('markFetchedAt');
        $this->runner($empty, fetched: 0)->run(null, null, null, false);

        $some = $this->resolver('smart');
        $some->expects($this->once())->method('markFetchedAt');
        $this->runner($some, fetched: 3)->run(null, null, null, false);
    }

    public function testUnconfiguredSourceIsRefused(): void
    {
        $source = $this->createMock(ScrobbleSourceInterface::class);
        $source->method('isConfigured')->willReturn(false);
        $active = $this->createMock(ActiveScrobbleSource::class);
        $active->method('get')->willReturn($source);

        $runner = new ScrobbleFetchRunner(
            $active,
            $this->createMock(ScrobbleFetcher::class),
            $this->createMock(SourceFetchWindowResolver::class),
            $this->createMock(RunHistoryRecorder::class),
        );

        $this->expectException(\InvalidArgumentException::class);
        $runner->run(null, null, null, false);
    }

    private function resolver(string $windowSource): SourceFetchWindowResolver&\PHPUnit\Framework\MockObject\MockObject
    {
        $resolver = $this->createMock(SourceFetchWindowResolver::class);
        $resolver->method('resolve')->willReturn([
            'dateMin' => new \DateTimeImmutable('2026-11-26 13:54:19'),
            'dateMax' => null,
            'source' => $windowSource,
        ]);

        return $resolver;
    }

    private function runner(SourceFetchWindowResolver $resolver, int $fetched): ScrobbleFetchRunner
    {
        $source = $this->createMock(ScrobbleSourceInterface::class);
        $source->method('isConfigured')->willReturn(true);
        $source->method('name')->willReturn('listenbrainz');
        $source->method('user')->willReturn('bob');
        $active = $this->createMock(ActiveScrobbleSource::class);
        $active->method('get')->willReturn($source);

        $report = new FetchReport();
        $report->fetched = $fetched;
        $fetcher = $this->createMock(ScrobbleFetcher::class);
        $fetcher->method('fetch')->willReturn($report);

        $recorder = $this->createMock(RunHistoryRecorder::class);
        $recorder->method('record')->willReturnCallback(function (string $type, string $reference, string $label, callable $action): mixed {
            $this->types[] = $type;

            return $action();
        });

        return new ScrobbleFetchRunner($active, $fetcher, $resolver, $recorder);
    }
}
