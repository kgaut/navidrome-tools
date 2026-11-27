<?php

namespace App\Scrobble;

use App\Recommendation\ListenBrainzClient;

/**
 * ListenBrainz implementation of the listen source (issue #310). Uses
 * `LISTENBRAINZ_USER` (and `LISTENBRAINZ_TOKEN` when set, for higher rate
 * limits) — the same settings as the ListenBrainz recommendation source.
 */
final class ListenBrainzScrobbleSource implements ScrobbleSourceInterface
{
    public const NAME = 'listenbrainz';

    public function __construct(
        private readonly ListenBrainzClient $client,
    ) {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function user(): string
    {
        return $this->client->user();
    }

    public function isConfigured(): bool
    {
        return $this->client->isConfigured();
    }

    public function streamListens(?\DateTimeInterface $dateMin = null, ?\DateTimeInterface $dateMax = null): iterable
    {
        return $this->client->streamListens($dateMin, $dateMax);
    }
}
