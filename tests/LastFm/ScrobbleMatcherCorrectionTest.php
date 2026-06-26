<?php

namespace App\Tests\LastFm;

use App\LastFm\LastFmClient;
use App\LastFm\LastFmScrobble;
use App\LastFm\LastFmTrackInfo;
use App\LastFm\MatchResult;
use App\LastFm\ScrobbleMatcher;
use App\Navidrome\NavidromeRepository;
use App\Tests\Navidrome\NavidromeFixtureFactory;
use PHPUnit\Framework\TestCase;

/**
 * Covers the step-7 lightening (PR F): `track.getCorrection` is tried
 * first and, when it resolves the match, `track.getInfo` is never called.
 * getInfo stays as the MBID fallback when the correction doesn't resolve.
 */
class ScrobbleMatcherCorrectionTest extends TestCase
{
    /** @var list<string> */
    private array $cleanup = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $path) {
            foreach ([$path, $path . '-wal', $path . '-shm'] as $f) {
                if (file_exists($f)) {
                    unlink($f);
                }
            }
        }
    }

    public function testGetCorrectionResolvesWithoutCallingGetInfo(): void
    {
        // Library owns "Daft Punk / Get Lucky"; the scrobble is misspelled
        // "Daft Pnk / Get Lucky". getCorrection fixes the artist.
        $navidrome = $this->seed([
            ['id' => 'mf-1', 'title' => 'Get Lucky', 'artist' => 'Daft Punk'],
        ]);

        $client = $this->createMock(LastFmClient::class);
        $client->expects($this->once())
            ->method('trackGetCorrection')
            ->willReturn(new LastFmTrackInfo(mbid: null, correctedArtist: 'Daft Punk', correctedTitle: null));
        $client->expects($this->never())->method('trackGetInfo');

        $matcher = new ScrobbleMatcher(
            navidrome: $navidrome,
            lastFmClient: $client,
            lastFmApiKey: 'k',
        );

        $result = $matcher->match($this->scrobble('Daft Pnk', 'Get Lucky'));

        $this->assertSame(MatchResult::STATUS_MATCHED, $result->status);
        $this->assertSame('mf-1', $result->mediaFileId);
    }

    public function testFallsBackToGetInfoMbidWhenCorrectionDoesNotResolve(): void
    {
        // getCorrection returns nothing useful; getInfo supplies an MBID
        // that the library owns.
        $navidrome = $this->seed([
            ['id' => 'mf-1', 'title' => 'Some Track', 'artist' => 'Some Artist', 'mbz_track_id' => 'mbid-xyz'],
        ]);

        $client = $this->createMock(LastFmClient::class);
        $client->expects($this->once())
            ->method('trackGetCorrection')
            ->willReturn(LastFmTrackInfo::empty());
        $client->expects($this->once())
            ->method('trackGetInfo')
            ->willReturn(new LastFmTrackInfo(mbid: 'mbid-xyz', correctedArtist: null, correctedTitle: null));

        $matcher = new ScrobbleMatcher(
            navidrome: $navidrome,
            lastFmClient: $client,
            lastFmApiKey: 'k',
        );

        $result = $matcher->match($this->scrobble('Wrong Name', 'Other Title'));

        $this->assertSame(MatchResult::STATUS_MATCHED, $result->status);
        $this->assertSame('mf-1', $result->mediaFileId);
    }

    public function testUnmatchedCallsBothEndpointsThenGivesUp(): void
    {
        $navidrome = $this->seed([
            ['id' => 'mf-1', 'title' => 'Get Lucky', 'artist' => 'Daft Punk'],
        ]);

        $client = $this->createMock(LastFmClient::class);
        $client->expects($this->once())->method('trackGetCorrection')->willReturn(LastFmTrackInfo::empty());
        $client->expects($this->once())->method('trackGetInfo')->willReturn(LastFmTrackInfo::empty());

        $matcher = new ScrobbleMatcher(
            navidrome: $navidrome,
            lastFmClient: $client,
            lastFmApiKey: 'k',
        );

        $result = $matcher->match($this->scrobble('Nobody', 'Nothing'));

        $this->assertSame(MatchResult::STATUS_UNMATCHED, $result->status);
    }

    private function scrobble(string $artist, string $title): LastFmScrobble
    {
        return new LastFmScrobble(
            artist: $artist,
            title: $title,
            album: '',
            mbid: null,
            playedAt: new \DateTimeImmutable('2024-01-01T00:00:00Z'),
        );
    }

    /**
     * @param list<array{id: string, title: string, artist: string, mbz_track_id?: string}> $tracks
     */
    private function seed(array $tracks): NavidromeRepository
    {
        $path = sys_get_temp_dir() . '/nd-matcher-' . uniqid() . '.db';
        $this->cleanup[] = $path;
        $conn = NavidromeFixtureFactory::createDatabase($path, withScrobbles: false);
        foreach ($tracks as $t) {
            NavidromeFixtureFactory::insertTrack(
                $conn,
                $t['id'],
                $t['title'],
                $t['artist'],
                mbzTrackId: $t['mbz_track_id'] ?? null,
            );
        }
        $conn->close();

        return new NavidromeRepository($path, 'admin');
    }
}
