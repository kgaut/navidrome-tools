<?php

namespace App\Tests\LastFm;

use App\LastFm\LastFmClient;
use App\LastFm\LastFmLovedTrack;
use App\LastFm\LastFmScrobble;

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
}
