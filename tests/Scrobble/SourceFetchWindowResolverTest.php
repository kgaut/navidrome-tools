<?php

namespace App\Tests\Scrobble;

use App\LastFm\FetchWindowResolver;
use App\Repository\ScrobbleRepository;
use App\Repository\SettingRepository;
use App\Scrobble\ScrobbleSourceInterface;
use App\Scrobble\SourceFetchWindowResolver;
use PHPUnit\Framework\TestCase;

/**
 * Issue #310: the first ListenBrainz fetch starts right after the most recent
 * listen already stored (raccord), then follows the usual smart date.
 */
class SourceFetchWindowResolverTest extends TestCase
{
    /** @var array<string, string> */
    private array $store = [];

    public function testFirstListenBrainzFetchStartsOneSecondAfterTheLastStoredListen(): void
    {
        $window = $this->resolver(new \DateTimeImmutable('2026-11-26 13:54:18', new \DateTimeZone('UTC')))
            ->resolve($this->source('listenbrainz'), null, null);

        $this->assertSame('raccord', $window['source']);
        $this->assertSame('2026-11-26 13:54:19', $window['dateMin']->format('Y-m-d H:i:s'));
        $this->assertNull($window['dateMax']);
    }

    public function testRecordedFetchWinsOverRaccord(): void
    {
        $resolver = $this->resolver(new \DateTimeImmutable('2026-11-26 13:54:18'));
        $resolver->markFetchedAt($this->source('listenbrainz'), new \DateTimeImmutable('2026-12-02 10:00:00'));

        $window = $resolver->resolve($this->source('listenbrainz'), null, null);

        $this->assertSame('smart', $window['source']);
        $this->assertSame('2026-12-02 09:00:00', $window['dateMin']->format('Y-m-d H:i:s'));
        $this->assertArrayHasKey('listenbrainz_last_fetch_bob', $this->store);
    }

    public function testEmptyTableFallsBackToTheDefaultWindow(): void
    {
        $window = $this->resolver(null)->resolve($this->source('listenbrainz'), null, '2026-12-01');

        $this->assertSame('default', $window['source']);
        $this->assertSame('2026-12-02 00:00:00', $window['dateMax']?->format('Y-m-d H:i:s'));
    }

    public function testExplicitDateMinWins(): void
    {
        $window = $this->resolver(new \DateTimeImmutable('2026-11-26'))->resolve($this->source('listenbrainz'), '2026-04-01', '2026-10-31');

        $this->assertSame('explicit', $window['source']);
        $this->assertSame('2026-04-01', $window['dateMin']->format('Y-m-d'));
    }

    public function testLastFmKeepsItsOwnSettingKeyAndNoRaccord(): void
    {
        $resolver = $this->resolver(new \DateTimeImmutable('2026-11-26'));

        $this->assertSame('default', $resolver->resolve($this->source('lastfm'), null, null)['source']);
        $resolver->markFetchedAt($this->source('lastfm'), new \DateTimeImmutable('2026-11-27 10:00:00'));
        $this->assertArrayHasKey(FetchWindowResolver::SETTING_KEY_PREFIX . 'bob', $this->store);
    }

    private function resolver(?\DateTimeImmutable $lastPlayed): SourceFetchWindowResolver
    {
        $settings = $this->createMock(SettingRepository::class);
        $settings->method('get')->willReturnCallback(fn (string $key): string => $this->store[$key] ?? '');
        $settings->method('set')->willReturnCallback(function (string $key, string $value): void {
            $this->store[$key] = $value;
        });
        $scrobbles = $this->createMock(ScrobbleRepository::class);
        $scrobbles->method('getLastPlayedAtOverall')->willReturn($lastPlayed);

        return new SourceFetchWindowResolver(new FetchWindowResolver($settings), $settings, $scrobbles);
    }

    private function source(string $name): ScrobbleSourceInterface
    {
        $source = $this->createMock(ScrobbleSourceInterface::class);
        $source->method('name')->willReturn($name);
        $source->method('user')->willReturn('bob');

        return $source;
    }
}
