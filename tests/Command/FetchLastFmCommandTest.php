<?php

namespace App\Tests\Command;

use App\Command\FetchLastFmCommand;
use App\Entity\RunHistory;
use App\LastFm\FetchReport;
use App\LastFm\LastFmFetcher;
use App\Repository\SettingRepository;
use App\Service\RunHistoryRecorder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class FetchLastFmCommandTest extends TestCase
{
    public function testDefaultWindowIsLast48HoursOnFirstRun(): void
    {
        // Capture the window the fetcher is called with — that's what we
        // are actually asserting (the printed note is just for the user).
        $captured = null;
        $fetcher = $this->createMock(LastFmFetcher::class);
        $fetcher->expects($this->once())
            ->method('fetch')
            ->willReturnCallback(function (string $apiKey, string $user, ?\DateTimeInterface $dateMin, ?\DateTimeInterface $dateMax) use (&$captured): FetchReport {
                $captured = ['dateMin' => $dateMin, 'dateMax' => $dateMax];
                return new FetchReport();
            });

        $settings = $this->createMock(SettingRepository::class);
        $settings->method('get')->willReturn(''); // no previous fetch
        $settings->expects($this->never())->method('set');

        $tester = $this->makeTester($fetcher, $settings);
        $tester->execute(['user' => 'alice', '--api-key' => 'k']);

        $this->assertNotNull($captured['dateMin']);
        $this->assertNull($captured['dateMax']);
        // Default window is 48h — accept a 5s drift to account for test latency.
        $expected = (new \DateTimeImmutable())->modify('-48 hours')->getTimestamp();
        $this->assertLessThanOrEqual(5, abs($captured['dateMin']->getTimestamp() - $expected));
    }

    public function testSmartDateOverrides48hDefaultWhenLastFetchExists(): void
    {
        $captured = null;
        $fetcher = $this->createMock(LastFmFetcher::class);
        $fetcher->method('fetch')->willReturnCallback(
            function (string $apiKey, string $user, ?\DateTimeInterface $dateMin) use (&$captured): FetchReport {
                $captured = $dateMin;
                return new FetchReport();
            }
        );

        // Last fetch 7 days ago — smart date should use that minus 1h overlap,
        // NOT the 48h default.
        $lastFetch = (new \DateTimeImmutable('-7 days'))->format('Y-m-d H:i:s');
        $settings = $this->createMock(SettingRepository::class);
        $settings->method('get')->willReturn($lastFetch);

        $tester = $this->makeTester($fetcher, $settings);
        $tester->execute(['user' => 'alice', '--api-key' => 'k']);

        $this->assertNotNull($captured);
        $expected = (new \DateTimeImmutable($lastFetch))->modify('-1 hours')->getTimestamp();
        $this->assertSame($expected, $captured->getTimestamp());
    }

    public function testExplicitDateMinOverridesDefault(): void
    {
        $captured = null;
        $fetcher = $this->createMock(LastFmFetcher::class);
        $fetcher->method('fetch')->willReturnCallback(
            function (string $apiKey, string $user, ?\DateTimeInterface $dateMin) use (&$captured): FetchReport {
                $captured = $dateMin;
                return new FetchReport();
            }
        );

        $settings = $this->createMock(SettingRepository::class);
        $settings->method('get')->willReturn('');

        $tester = $this->makeTester($fetcher, $settings);
        $tester->execute(['user' => 'alice', '--api-key' => 'k', '--date-min' => '2024-01-01']);

        $this->assertSame('2024-01-01', $captured->format('Y-m-d'));
    }

    private function makeTester(LastFmFetcher $fetcher, SettingRepository $settings): CommandTester
    {
        // Recorder that just invokes the action — no DB writes in tests.
        $recorder = $this->createMock(RunHistoryRecorder::class);
        $recorder->method('record')->willReturnCallback(
            static fn (string $type, string $ref, string $label, callable $action) => $action(new RunHistory($type, $ref, $label)),
        );

        $command = new FetchLastFmCommand($fetcher, $recorder, $settings, 'default-key', 'default-user');
        $app = new Application();
        $app->add($command);

        return new CommandTester($app->find('app:lastfm:fetch'));
    }
}
