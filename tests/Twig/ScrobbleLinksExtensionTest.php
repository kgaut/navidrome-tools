<?php

namespace App\Tests\Twig;

use App\Twig\ScrobbleLinksExtension;
use PHPUnit\Framework\TestCase;

class ScrobbleLinksExtensionTest extends TestCase
{
    public function testLastFmTrackUrlUsesPlusForSpacesAndKeepsAccents(): void
    {
        $ext = new ScrobbleLinksExtension('');

        $this->assertSame(
            'https://www.last.fm/music/Daft+Punk/_/Around+the+World',
            $ext->lastFmTrackUrl('Daft Punk', 'Around the World'),
        );
        $this->assertSame(
            'https://www.last.fm/music/Sigur+R%C3%B3s/_/Hopp%C3%ADpolla',
            $ext->lastFmTrackUrl('Sigur Rós', 'Hoppípolla'),
        );
    }

    public function testLastFmTrackUrlReturnsNullOnMissingPart(): void
    {
        $ext = new ScrobbleLinksExtension('');

        $this->assertNull($ext->lastFmTrackUrl('', 'Title'));
        $this->assertNull($ext->lastFmTrackUrl('Artist', '   '));
    }

    public function testLastFmArtistUrl(): void
    {
        $ext = new ScrobbleLinksExtension('');

        $this->assertSame('https://www.last.fm/music/AC%2FDC', $ext->lastFmArtistUrl('AC/DC'));
        $this->assertNull($ext->lastFmArtistUrl(''));
    }

    public function testMusicbrainzUrlForKnownTypes(): void
    {
        $ext = new ScrobbleLinksExtension('');
        $mbid = 'b10bbbfc-cf9e-42e0-be17-e2c3e1d2600d';

        $this->assertSame('https://musicbrainz.org/artist/' . $mbid, $ext->musicbrainzUrl('artist', $mbid));
        $this->assertSame('https://musicbrainz.org/release/' . $mbid, $ext->musicbrainzUrl('release', $mbid));
        $this->assertSame('https://musicbrainz.org/recording/' . $mbid, $ext->musicbrainzUrl('recording', $mbid));
    }

    public function testMusicbrainzUrlNullWhenMbidEmptyOrTypeUnknown(): void
    {
        $ext = new ScrobbleLinksExtension('');

        $this->assertNull($ext->musicbrainzUrl('artist', null));
        $this->assertNull($ext->musicbrainzUrl('artist', ''));
        $this->assertNull($ext->musicbrainzUrl('artist', '   '));
        $this->assertNull($ext->musicbrainzUrl('label', 'some-mbid'));
    }

    public function testNavidromeTrackUrlReturnsNullWhenBaseOrIdMissing(): void
    {
        $this->assertNull((new ScrobbleLinksExtension(''))->navidromeTrackUrl('media-1'));
        $this->assertNull((new ScrobbleLinksExtension('https://navi.local'))->navidromeTrackUrl(null));
        $this->assertNull((new ScrobbleLinksExtension('https://navi.local'))->navidromeTrackUrl('   '));
    }

    public function testNavidromeTrackUrlBuildsHashRouteAndStripsTrailingSlash(): void
    {
        $ext = new ScrobbleLinksExtension('https://navi.local/');

        $this->assertSame(
            'https://navi.local/app/#/song/abc-123/show',
            $ext->navidromeTrackUrl('abc-123'),
        );
    }

    public function testRegistersFourFunctions(): void
    {
        $ext = new ScrobbleLinksExtension('');
        $names = array_map(static fn ($f) => $f->getName(), $ext->getFunctions());

        $this->assertSame(
            ['lastfm_track_url', 'lastfm_artist_url', 'musicbrainz_url', 'navidrome_track_url'],
            $names,
        );
    }
}
