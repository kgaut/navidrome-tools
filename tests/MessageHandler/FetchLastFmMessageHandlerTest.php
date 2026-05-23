<?php

namespace App\Tests\MessageHandler;

use App\Entity\RunHistory;
use App\LastFm\FetchReport;
use App\LastFm\FetchWindowResolver;
use App\LastFm\LastFmFetcher;
use App\Message\FetchLastFmMessage;
use App\MessageHandler\FetchLastFmMessageHandler;
use App\Repository\SettingRepository;
use App\Service\RunHistoryRecorder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

class FetchLastFmMessageHandlerTest extends TestCase
{
    public function testWebTriggerDefaultsTo48hWhenNoPreviousFetch(): void
    {
        // Regression: handler used to fall through to `null` (full history)
        // when no setting was recorded — clicking the web button on a fresh
        // install pulled the entire Last.fm history.
        $captured = null;
        $fetcher = $this->createMock(LastFmFetcher::class);
        $fetcher->expects($this->once())
            ->method('fetch')
            ->willReturnCallback(function (string $apiKey, string $user, ?\DateTimeInterface $dateMin) use (&$captured): FetchReport {
                $captured = $dateMin;
                return new FetchReport();
            });

        $settings = $this->createMock(SettingRepository::class);
        $settings->method('get')->willReturn('');

        $handler = $this->makeHandler($fetcher, $settings);
        $handler(new FetchLastFmMessage('alice', 'k', null, null, null, false));

        $this->assertNotNull($captured);
        $expected = (new \DateTimeImmutable())->modify('-48 hours')->getTimestamp();
        $this->assertLessThanOrEqual(5, abs($captured->getTimestamp() - $expected));
    }

    private function makeHandler(LastFmFetcher $fetcher, SettingRepository $settings): FetchLastFmMessageHandler
    {
        $recorder = $this->createMock(RunHistoryRecorder::class);
        $recorder->method('record')->willReturnCallback(
            static fn (string $type, string $ref, string $label, callable $action) => $action(new RunHistory($type, $ref, $label)),
        );

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static fn (object $msg): Envelope => new Envelope($msg));

        return new FetchLastFmMessageHandler($fetcher, $recorder, new FetchWindowResolver($settings), $bus);
    }
}
