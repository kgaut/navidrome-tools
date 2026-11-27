<?php

namespace App\Tests\Scrobble;

use App\LastFm\LastFmClient;
use App\Recommendation\ListenBrainzClient;
use App\Scrobble\ActiveScrobbleSource;
use App\Scrobble\LastFmScrobbleSource;
use App\Scrobble\ListenBrainzScrobbleSource;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;

class ActiveScrobbleSourceTest extends TestCase
{
    public function testDefaultsToLastFm(): void
    {
        $source = $this->active('')->get();

        $this->assertInstanceOf(LastFmScrobbleSource::class, $source);
        $this->assertSame('alice', $source->user());
        $this->assertTrue($source->isConfigured());
    }

    public function testSelectsListenBrainzCaseInsensitively(): void
    {
        $source = $this->active(' ListenBrainz ')->get();

        $this->assertInstanceOf(ListenBrainzScrobbleSource::class, $source);
        $this->assertSame('listenbrainz', $source->name());
        $this->assertSame('bob', $source->user());
    }

    public function testRejectsAnUnknownSource(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->active('maloja')->get();
    }

    private function active(string $name): ActiveScrobbleSource
    {
        return new ActiveScrobbleSource(
            $this->createMock(LastFmClient::class),
            new ListenBrainzClient(new MockHttpClient([]), 'bob'),
            $name,
            'key',
            'alice',
        );
    }
}
