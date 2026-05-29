<?php

namespace App\Tests\LastFm;

use App\LastFm\FetchWindowResolver;
use App\Repository\SettingRepository;
use PHPUnit\Framework\TestCase;

class FetchWindowResolverTest extends TestCase
{
    public function testExplicitDateMaxAsYmdMustCoverWholeDay(): void
    {
        // Reproduce: the CLI option doc says `--date-max=YYYY-MM-DD`. A
        // user picking today as date-max naturally expects « inclure tout
        // ce qui s'est passé aujourd'hui ». PHP's new DateTimeImmutable
        // ('2026-05-29') parses to 2026-05-29 00:00:00 UTC — the START
        // of the day. Sent verbatim as `to=` to Last.fm, that excludes
        // every scrobble made on 2026-05-29.
        //
        // Expected behaviour: a date-only value means « end of that day ».
        $settings = $this->createMock(SettingRepository::class);
        $resolver = new FetchWindowResolver($settings);

        $window = $resolver->resolve('alice', null, '2026-05-29');

        $this->assertNotNull($window['dateMax']);
        $this->assertSame(
            '2026-05-30 00:00:00',
            $window['dateMax']->format('Y-m-d H:i:s'),
            'date-only date-max must promote to end-of-day so today is included',
        );
    }

    public function testExplicitDateMaxWithTimeIsPreservedAsIs(): void
    {
        $settings = $this->createMock(SettingRepository::class);
        $resolver = new FetchWindowResolver($settings);

        $window = $resolver->resolve('alice', null, '2026-05-29 14:00:00');

        $this->assertNotNull($window['dateMax']);
        $this->assertSame('2026-05-29 14:00:00', $window['dateMax']->format('Y-m-d H:i:s'));
    }

    public function testExplicitDateMinAsYmdStaysAtMidnight(): void
    {
        // Symmetric: date-min as Y-m-d means « from the start of that day »,
        // which already corresponds to midnight. No promotion needed.
        $settings = $this->createMock(SettingRepository::class);
        $resolver = new FetchWindowResolver($settings);

        $window = $resolver->resolve('alice', '2026-05-29', null);

        $this->assertSame('2026-05-29 00:00:00', $window['dateMin']->format('Y-m-d H:i:s'));
    }
}
