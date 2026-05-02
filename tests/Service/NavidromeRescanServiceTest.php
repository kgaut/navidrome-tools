<?php

namespace App\Tests\Service;

use App\Entity\RunHistory;
use App\Service\NavidromeRescanService;
use App\Service\RunHistoryRecorder;
use App\Subsonic\SubsonicClient;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class NavidromeRescanServiceTest extends TestCase
{
    public function testSuccessfulRescanRecordsRunWithMetrics(): void
    {
        $em = $this->makeFakeEntityManager();
        $recorder = new RunHistoryRecorder($em['em']);
        $http = new MockHttpClient([
            new MockResponse(
                json_encode(['subsonic-response' => ['status' => 'ok']], JSON_THROW_ON_ERROR),
                ['response_headers' => ['content-type: application/json']],
            ),
        ]);
        $subsonic = new SubsonicClient($http, 'http://navi.test', 'admin', 'changeme');

        $service = new NavidromeRescanService($subsonic, $recorder);
        $run = $service->rescan(reason: 'unit-test');

        $this->assertSame(RunHistory::STATUS_SUCCESS, $run->getStatus());
        $this->assertSame(RunHistory::TYPE_NAVIDROME_RESCAN, $run->getType());
        $this->assertSame('unit-test', $run->getReference());
        $this->assertSame(['reason' => 'unit-test', 'full_scan' => false, 'accepted' => true], $run->getMetrics());
        $this->assertCount(1, $em['persisted']);
    }

    public function testFailedRescanMarksRunErrorAndRethrows(): void
    {
        $em = $this->makeFakeEntityManager();
        $recorder = new RunHistoryRecorder($em['em']);
        $http = new MockHttpClient([
            new MockResponse('', ['http_code' => 500]),
        ]);
        $subsonic = new SubsonicClient($http, 'http://navi.test', 'admin', 'changeme');

        $service = new NavidromeRescanService($subsonic, $recorder);

        try {
            $service->rescan();
            $this->fail('Expected the failed rescan to throw.');
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertCount(1, $em['persisted']);
        /** @var RunHistory $entry */
        $entry = $em['persisted'][0];
        $this->assertSame(RunHistory::STATUS_ERROR, $entry->getStatus());
        $this->assertNotNull($entry->getMessage());
        // Metrics set inside the action survive the exception path because
        // RunHistoryRecorder mutates entry status/message but keeps metrics.
        $this->assertSame(['reason' => 'manual', 'full_scan' => false, 'accepted' => false], $entry->getMetrics());
    }

    public function testFullScanFlagPropagates(): void
    {
        $em = $this->makeFakeEntityManager();
        $recorder = new RunHistoryRecorder($em['em']);
        $captured = null;
        $http = new MockHttpClient(function (string $method, string $url) use (&$captured): MockResponse {
            $captured = $url;

            return new MockResponse(
                json_encode(['subsonic-response' => ['status' => 'ok']], JSON_THROW_ON_ERROR),
                ['response_headers' => ['content-type: application/json']],
            );
        });
        $subsonic = new SubsonicClient($http, 'http://navi.test', 'admin', 'changeme');

        $service = new NavidromeRescanService($subsonic, $recorder);
        $run = $service->rescan(reason: 'manual', fullScan: true);

        $this->assertTrue($run->getMetrics()['full_scan'] ?? false);
        $this->assertNotNull($captured);
        $this->assertStringContainsString('fullScan=true', (string) $captured);
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
