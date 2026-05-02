<?php

namespace App\Tests\LastFm;

use App\LastFm\LastFmClient;
use App\LastFm\LastFmLovedTrack;
use App\LastFm\LastFmScrobble;
use App\LastFm\LastFmTrackInfo;

/**
 * Stub LastFmClient that yields a pre-baked list of scrobbles / loved
 * tracks instead of performing real HTTP calls. Captures track.love
 * calls so tests can assert what would have been propagated.
 */
final class FakeLastFmClient extends LastFmClient
{
    /**
     * @var list<array{action: string, artist: string, title: string}>
     */
    public array $writeActions = [];

    /**
     * @var list<array{method: string, artist: string, title: string}>
     */
    public array $lookupCalls = [];

    /** @var array<string, LastFmTrackInfo> indexed by "artistNorm\0titleNorm" */
    private array $trackInfoByKey = [];

    /**
     * @param LastFmScrobble[]   $scrobbles
     * @param LastFmLovedTrack[] $loved
     */
    public function __construct(
        private readonly array $scrobbles,
        private readonly array $loved = [],
        private readonly bool $writeShouldFail = false,
    ) {
        // Skip parent::__construct on purpose — we don't need the HTTP client.
    }

    /**
     * Pre-program a `trackGetInfo` response for a given (artist, title)
     * couple. Tests use this to stub Last.fm's track.getInfo without
     * spinning up a MockHttpClient.
     */
    public function programTrackInfo(string $artist, string $title, LastFmTrackInfo $info): void
    {
        $this->trackInfoByKey[$this->key($artist, $title)] = $info;
    }

    public function streamRecentTracks(
        string $apiKey,
        string $user,
        ?\DateTimeInterface $dateMin = null,
        ?\DateTimeInterface $dateMax = null,
    ): \Generator {
        foreach ($this->scrobbles as $s) {
            yield $s;
        }
    }

    public function iterateLovedTracks(string $apiKey, string $user): \Generator
    {
        foreach ($this->loved as $l) {
            yield $l;
        }
    }

    public function trackLove(string $apiKey, string $apiSecret, string $sk, string $artist, string $title): void
    {
        if ($this->writeShouldFail) {
            throw new \RuntimeException('forced failure');
        }
        $this->writeActions[] = ['action' => 'love', 'artist' => $artist, 'title' => $title];
    }

    public function trackUnlove(string $apiKey, string $apiSecret, string $sk, string $artist, string $title): void
    {
        if ($this->writeShouldFail) {
            throw new \RuntimeException('forced failure');
        }
        $this->writeActions[] = ['action' => 'unlove', 'artist' => $artist, 'title' => $title];
    }

    public function trackGetInfo(string $apiKey, string $artist, string $title): LastFmTrackInfo
    {
        $this->lookupCalls[] = ['method' => 'getInfo', 'artist' => $artist, 'title' => $title];

        return $this->trackInfoByKey[$this->key($artist, $title)] ?? LastFmTrackInfo::empty();
    }

    public function trackGetCorrection(string $apiKey, string $artist, string $title): LastFmTrackInfo
    {
        $this->lookupCalls[] = ['method' => 'getCorrection', 'artist' => $artist, 'title' => $title];

        return $this->trackInfoByKey[$this->key($artist, $title)] ?? LastFmTrackInfo::empty();
    }

    private function key(string $artist, string $title): string
    {
        return mb_strtolower(trim($artist)) . "\0" . mb_strtolower(trim($title));
    }
}
