<?php

namespace App\Loves;

use App\LastFm\LastFmClient;

final class LastFmLovesProvider implements LovesProviderInterface
{
    public function __construct(
        private readonly LastFmClient $client,
        #[\SensitiveParameter]
        private readonly string $apiKey,
        #[\SensitiveParameter]
        private readonly string $apiSecret,
        #[\SensitiveParameter]
        private readonly ?string $sessionKey,
        private readonly string $user,
    ) {
    }

    public function name(): string
    {
        return 'lastfm';
    }

    public function user(): string
    {
        return $this->user;
    }

    public function isConfigured(): bool
    {
        return trim($this->apiKey) !== '' && trim($this->user) !== '';
    }

    public function canWrite(): bool
    {
        return $this->isConfigured() && trim($this->apiSecret) !== '' && ($this->sessionKey ?? '') !== '';
    }

    public function iterateLoved(): iterable
    {
        return $this->client->iterateLovedTracks($this->apiKey, $this->user);
    }

    public function love(string $artist, string $title, ?string $recordingMbid): bool
    {
        $this->client->trackLove($this->apiKey, $this->apiSecret, (string) $this->sessionKey, $artist, $title);

        return true;
    }
}
