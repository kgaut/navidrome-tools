<?php

namespace App\Tests\LastFm;

use App\LastFm\LastFmClient;
use App\LastFm\LastFmScrobble;

/**
 * Stub LastFmClient that yields a pre-baked list of scrobbles instead of
 * performing real HTTP calls.
 */
final class FakeLastFmClient extends LastFmClient
{
    /** @param LastFmScrobble[] $scrobbles */
    public function __construct(private readonly array $scrobbles)
    {
        // Skip parent::__construct on purpose — we don't need the HTTP client.
    }

    public function streamRecentTracks(
        string $apiKey,
        string $user,
        ?\DateTimeInterface $from = null,
        ?\DateTimeInterface $to = null,
    ): \Generator {
        foreach ($this->scrobbles as $s) {
            yield $s;
        }
    }
}
