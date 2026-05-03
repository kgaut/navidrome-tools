<?php

namespace App\Tests\Controller\Api;

use App\Controller\Api\StatusController;
use App\Docker\ContainerStatus;
use App\Docker\NavidromeContainerManager;
use App\Entity\RunHistory;
use App\Navidrome\NavidromeRepository;
use App\Repository\LastFmImportTrackRepository;
use App\Repository\RunHistoryRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class StatusControllerTest extends TestCase
{
    public function testNoTokenReturnsBasicHealth(): void
    {
        $controller = $this->makeController(navidromeAvailable: true, apiToken: 'secret');

        $response = $controller->index(new Request());

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $payload = $this->decode($response);
        $this->assertSame(['status' => 'ok', 'navidrome_db' => true], $payload);
    }

    public function testNoTokenWithNavidromeDownReturns503(): void
    {
        $controller = $this->makeController(navidromeAvailable: false, apiToken: '');

        $response = $controller->index(new Request());

        $this->assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $response->getStatusCode());
        $payload = $this->decode($response);
        $this->assertSame(['status' => 'degraded', 'navidrome_db' => false], $payload);
    }

    public function testTokenWhenFeatureDisabledReturns404(): void
    {
        $controller = $this->makeController(navidromeAvailable: true, apiToken: '');

        $response = $controller->index(new Request(['token' => 'whatever']));

        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    public function testWrongTokenReturns401(): void
    {
        $controller = $this->makeController(navidromeAvailable: true, apiToken: 'secret');

        $response = $controller->index(new Request(['token' => 'nope']));

        $this->assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    public function testValidQueryTokenReturnsEnrichedPayload(): void
    {
        $lastRun = (new RunHistory(RunHistory::TYPE_LASTFM_IMPORT, 'me', 'Last.fm import (me)'))
            ->setStatus(RunHistory::STATUS_SUCCESS)
            ->setDurationMs(1234);

        $controller = $this->makeController(
            navidromeAvailable: true,
            apiToken: 'secret',
            scrobblesCount: 142387,
            unmatchedCount: 312,
            missingMbid: 47,
            containerStatus: ContainerStatus::Running,
            lastRun: $lastRun,
        );

        $response = $controller->index(new Request(['token' => 'secret']));

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $payload = $this->decode($response);

        $this->assertSame('ok', $payload['status']);
        $this->assertTrue($payload['navidrome_db']);
        $this->assertSame(142387, $payload['scrobbles_total']);
        $this->assertSame(312, $payload['unmatched_total']);
        $this->assertSame(47, $payload['missing_mbid']);
        $this->assertSame('running', $payload['navidrome_container']);
        $this->assertSame('lastfm-import', $payload['last_run']['type']);
        $this->assertSame('success', $payload['last_run']['status']);
        $this->assertSame(1234, $payload['last_run']['duration_ms']);
        $this->assertNotNull($payload['last_run']['started_at']);
    }

    public function testValidBearerHeaderTokenWorks(): void
    {
        $controller = $this->makeController(navidromeAvailable: true, apiToken: 'secret');

        $request = new Request();
        $request->headers->set('Authorization', 'Bearer secret');

        $response = $controller->index($request);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $payload = $this->decode($response);
        $this->assertArrayHasKey('scrobbles_total', $payload);
    }

    public function testValidTokenWithNoLastRunReturnsNullLastRun(): void
    {
        $controller = $this->makeController(navidromeAvailable: true, apiToken: 'secret', lastRun: null);

        $response = $controller->index(new Request(['token' => 'secret']));

        $payload = $this->decode($response);
        $this->assertNull($payload['last_run']);
    }

    public function testValidTokenWhenNavidromeDownReturnsZeroCounters(): void
    {
        $controller = $this->makeController(
            navidromeAvailable: false,
            apiToken: 'secret',
            scrobblesCount: 999,
            missingMbid: 999,
        );

        $response = $controller->index(new Request(['token' => 'secret']));

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $payload = $this->decode($response);
        $this->assertSame('degraded', $payload['status']);
        $this->assertFalse($payload['navidrome_db']);
        $this->assertSame(0, $payload['scrobbles_total']);
        $this->assertSame(0, $payload['missing_mbid']);
    }

    private function makeController(
        bool $navidromeAvailable,
        string $apiToken,
        int $scrobblesCount = 0,
        int $unmatchedCount = 0,
        int $missingMbid = 0,
        ContainerStatus $containerStatus = ContainerStatus::Disabled,
        ?RunHistory $lastRun = null,
    ): StatusController {
        return new StatusController(
            $this->fakeNavidrome($navidromeAvailable, $scrobblesCount, $missingMbid),
            $this->fakeImportTracks($unmatchedCount),
            $this->fakeRunHistory($lastRun),
            $this->fakeContainer($containerStatus),
            $apiToken,
        );
    }

    private function fakeNavidrome(bool $available, int $scrobblesCount, int $missingMbid): NavidromeRepository
    {
        return new class ($available, $scrobblesCount, $missingMbid) extends NavidromeRepository {
            public function __construct(
                private readonly bool $available,
                private readonly int $scrobblesCount,
                private readonly int $missingMbid,
            ) {
            }

            public function isAvailable(): bool
            {
                return $this->available;
            }

            public function hasScrobblesTable(): bool
            {
                return $this->available;
            }

            public function getScrobblesCount(): int
            {
                return $this->scrobblesCount;
            }

            public function countMediaFilesWithoutMbid(?string $artistFilter = null, ?string $albumFilter = null): int
            {
                return $this->missingMbid;
            }
        };
    }

    private function fakeImportTracks(int $unmatchedCount): LastFmImportTrackRepository
    {
        return new class ($unmatchedCount) extends LastFmImportTrackRepository {
            public function __construct(private readonly int $unmatchedCount)
            {
            }

            public function countUnmatched(?int $runId = null): int
            {
                return $this->unmatchedCount;
            }
        };
    }

    private function fakeRunHistory(?RunHistory $lastRun): RunHistoryRepository
    {
        return new class ($lastRun) extends RunHistoryRepository {
            public function __construct(private readonly ?RunHistory $lastRun)
            {
            }

            public function findFilteredPaginated(array $filters, int $page, int $perPage): array
            {
                return [
                    'items' => $this->lastRun === null ? [] : [$this->lastRun],
                    'total' => $this->lastRun === null ? 0 : 1,
                ];
            }
        };
    }

    private function fakeContainer(ContainerStatus $status): NavidromeContainerManager
    {
        return new class ($status) extends NavidromeContainerManager {
            public function __construct(private readonly ContainerStatus $status)
            {
            }

            public function getStatus(): ContainerStatus
            {
                return $this->status;
            }
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(JsonResponse $response): array
    {
        $body = (string) $response->getContent();
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
