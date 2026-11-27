<?php

namespace App\Scrobble;

use App\LastFm\LastFmClient;

/**
 * Last.fm implementation of the listen source (`user.getRecentTracks`).
 * Built per call: the CLI accepts a user and an API key on the command line.
 */
final class LastFmScrobbleSource implements ScrobbleSourceInterface
{
    public const NAME = 'lastfm';

    public function __construct(
        private readonly LastFmClient $client,
        #[\SensitiveParameter]
        private readonly string $apiKey,
        private readonly string $user,
    ) {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function user(): string
    {
        return $this->user;
    }

    public function isConfigured(): bool
    {
        return trim($this->apiKey) !== '' && trim($this->user) !== '';
    }

    public function streamListens(?\DateTimeInterface $dateMin = null, ?\DateTimeInterface $dateMax = null): iterable
    {
        return $this->client->streamRecentTracks($this->apiKey, $this->user, $dateMin, $dateMax);
    }
}
