<?php

namespace App\Loves;

use App\Recommendation\ListenBrainzClient;

/**
 * ListenBrainz feedback as loved tracks. Writing needs a recording MBID: the
 * Navidrome tag when present, otherwise a strict metadata lookup.
 */
final class ListenBrainzLovesProvider implements LovesProviderInterface
{
    public function __construct(
        private readonly ListenBrainzClient $client,
    ) {
    }

    public function name(): string
    {
        return 'listenbrainz';
    }

    public function user(): string
    {
        return $this->client->user();
    }

    public function isConfigured(): bool
    {
        return $this->client->isConfigured();
    }

    public function canWrite(): bool
    {
        return $this->client->isConfigured() && $this->client->hasToken();
    }

    public function iterateLoved(): iterable
    {
        return $this->client->iterateLovedRecordings();
    }

    public function love(string $artist, string $title, ?string $recordingMbid): bool
    {
        $mbid = $recordingMbid ?? $this->client->lookupRecordingMbid($artist, $title);
        if ($mbid === null) {
            return false;
        }
        $this->client->loveRecording($mbid);

        return true;
    }
}
