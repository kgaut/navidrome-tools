<?php

namespace App\Tests\LastFm;

use App\Entity\Setting;
use App\LastFm\LastFmApiSigner;
use App\LastFm\LastFmAuthService;
use App\Repository\SettingRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class LastFmAuthServiceTest extends TestCase
{
    public function testGetRequestTokenReturnsTokenFromApi(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode(['token' => 'TOK123'], JSON_THROW_ON_ERROR), [
                'response_headers' => ['content-type: application/json'],
            ]),
        ]);

        $service = $this->makeService($client);
        $this->assertSame('TOK123', $service->getRequestToken());
    }

    public function testBuildAuthorizeUrlIncludesTokenAndCallback(): void
    {
        $service = $this->makeService(new MockHttpClient([]));
        $url = $service->buildAuthorizeUrl('TOK123', 'https://app.test/cb');

        $this->assertStringStartsWith('https://www.last.fm/api/auth/?', $url);
        $this->assertStringContainsString('api_key=KEY', $url);
        $this->assertStringContainsString('token=TOK123', $url);
        $this->assertStringContainsString('cb=' . urlencode('https://app.test/cb'), $url);
    }

    public function testExchangeTokenForSessionPersistsKeyAndUser(): void
    {
        $expectedSig = LastFmApiSigner::sign([
            'method' => 'auth.getSession',
            'api_key' => 'KEY',
            'token' => 'TOK123',
        ], 'SECRET');

        $client = new MockHttpClient(function (string $method, string $url) use ($expectedSig): MockResponse {
            $this->assertSame('GET', $method);
            $this->assertStringContainsString('api_sig=' . $expectedSig, $url);
            $this->assertStringContainsString('token=TOK123', $url);

            return new MockResponse(
                json_encode(['session' => ['key' => 'SK456', 'name' => 'somebody']], JSON_THROW_ON_ERROR),
                ['response_headers' => ['content-type: application/json']],
            );
        });

        $persisted = [];
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->atLeastOnce())->method('persist')->willReturnCallback(function ($entity) use (&$persisted): void {
            $persisted[] = $entity;
        });
        $em->expects($this->atLeastOnce())->method('flush');

        $repo = $this->createMock(SettingRepository::class);
        $repo->method('findOneByKey')->willReturn(null);

        $service = new LastFmAuthService($client, $repo, $em, 'KEY', 'SECRET');
        $service->exchangeTokenForSession('TOK123');

        $byKey = [];
        foreach ($persisted as $s) {
            if ($s instanceof Setting) {
                $byKey[$s->getKey()] = $s->getValue();
            }
        }
        $this->assertSame('SK456', $byKey[LastFmAuthService::SETTING_SESSION_KEY] ?? null);
        $this->assertSame('somebody', $byKey[LastFmAuthService::SETTING_SESSION_USER] ?? null);
    }

    public function testApiErrorIsRethrownAsRuntimeException(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode(['error' => 14, 'message' => 'Unauthorized Token'], JSON_THROW_ON_ERROR), [
                'response_headers' => ['content-type: application/json'],
            ]),
        ]);

        $service = $this->makeService($client);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Last.fm API error 14');
        $service->exchangeTokenForSession('TOK123');
    }

    private function makeService(MockHttpClient $client): LastFmAuthService
    {
        $repo = $this->createMock(SettingRepository::class);
        $repo->method('findOneByKey')->willReturn(null);
        $em = $this->createMock(EntityManagerInterface::class);

        return new LastFmAuthService($client, $repo, $em, 'KEY', 'SECRET');
    }
}
