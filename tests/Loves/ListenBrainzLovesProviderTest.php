<?php

namespace App\Tests\Loves;

use App\Loves\ListenBrainzLovesProvider;
use App\Recommendation\ListenBrainzClient;
use App\Repository\ListenBrainzLookupCacheRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Issue #319: lookups are cached, found or not, so an unidentifiable track is
 * not searched again every night; the comparison tolerates spelling noise.
 */
class ListenBrainzLovesProviderTest extends TestCase
{
    private int $lookups = 0;

    public function testNegativeLookupIsCachedAndNotRepeated(): void
    {
        $http = new MockHttpClient(function (): MockResponse {
            $this->lookups++;

            return new MockResponse('{}');
        });
        $store = [];
        $provider = new ListenBrainzLovesProvider(new ListenBrainzClient($http, 'bob'), $this->cache($store));

        $this->assertNull($provider->identify('Nobody', 'Nothing', null));
        $this->assertNull($provider->identify('nobody', 'nothing', null)); // same normalized key
        $this->assertSame(1, $this->lookups);
        $this->assertSame([null], array_values($store));
    }

    public function testPositiveLookupIsCachedAndTheTagSkipsTheLookup(): void
    {
        $http = new MockHttpClient(function (): MockResponse {
            $this->lookups++;

            return new MockResponse(json_encode(['recording_mbid' => 'rec-1', 'artist_credit_name' => 'Air', 'recording_name' => 'Playground Love'], \JSON_THROW_ON_ERROR));
        });
        $store = [];
        $provider = new ListenBrainzLovesProvider(new ListenBrainzClient($http, 'bob'), $this->cache($store));

        $this->assertSame('rec-tag', $provider->identify('Air', 'Playground Love', 'rec-tag'));
        $this->assertSame(0, $this->lookups);
        $this->assertSame('rec-1', $provider->identify('Air', 'Playground Love', null));
        $this->assertSame('rec-1', $provider->identify('Air', 'Playground Love', null));
        $this->assertSame(1, $this->lookups);
    }

    public function testLookupComparisonIgnoresCaseApostrophesSpacesAndFeaturing(): void
    {
        $this->assertSame(
            ListenBrainzClient::normalizeForLookup("L'Été  Indien"),
            ListenBrainzClient::normalizeForLookup('l’été indien'),
        );
        $this->assertSame('song', ListenBrainzClient::normalizeForLookup('Song (feat. Someone)'));
        $this->assertSame('song', ListenBrainzClient::normalizeForLookup('Song ft. Someone'));
        $this->assertSame('artist', ListenBrainzClient::normalizeForLookup('Artist featuring Other'));
        // A different recording must not collapse onto the original.
        $this->assertNotSame(ListenBrainzClient::normalizeForLookup('Song'), ListenBrainzClient::normalizeForLookup('Song (Live)'));
        $this->assertNotSame(ListenBrainzClient::normalizeForLookup('Ete'), ListenBrainzClient::normalizeForLookup('Été'));
    }

    /**
     * @param array<string, ?string> $store
     */
    private function cache(array &$store): ListenBrainzLookupCacheRepository
    {
        $cache = $this->createMock(ListenBrainzLookupCacheRepository::class);
        $cache->method('findFresh')->willReturnCallback(static function (string $key) use (&$store): ?array {
            return array_key_exists($key, $store) ? ['mbid' => $store[$key]] : null;
        });
        $cache->method('save')->willReturnCallback(static function (string $key, ?string $mbid) use (&$store): void {
            $store[$key] = $mbid;
        });

        return $cache;
    }
}
