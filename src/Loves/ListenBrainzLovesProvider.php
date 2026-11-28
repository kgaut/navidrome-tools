<?php

namespace App\Loves;

use App\Recommendation\ListenBrainzClient;
use App\Repository\ListenBrainzLookupCacheRepository;

/**
 * ListenBrainz feedback as loved tracks. Writing needs a recording MBID: the
 * Navidrome tag when present, otherwise a strict metadata lookup whose answer
 * — found or not — is cached for LOOKUP_TTL_DAYS (#319).
 */
final class ListenBrainzLovesProvider implements LovesProviderInterface
{
    public const LOOKUP_TTL_DAYS = 30;

    public function __construct(
        private readonly ListenBrainzClient $client,
        private readonly ?ListenBrainzLookupCacheRepository $lookupCache = null,
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

    public function identify(string $artist, string $title, ?string $recordingMbid): ?string
    {
        if ($recordingMbid !== null && $recordingMbid !== '') {
            return $recordingMbid;
        }

        $key = ListenBrainzClient::normalizeForLookup($artist) . "\0" . ListenBrainzClient::normalizeForLookup($title);
        $cached = $this->lookupCache?->findFresh($key, self::LOOKUP_TTL_DAYS);
        if ($cached !== null) {
            return $cached['mbid'];
        }

        $mbid = $this->client->lookupRecordingMbid($artist, $title);
        $this->lookupCache?->save($key, $mbid);

        return $mbid;
    }

    public function love(string $artist, string $title, ?string $recordingMbid): bool
    {
        $mbid = $this->identify($artist, $title, $recordingMbid);
        if ($mbid === null) {
            return false;
        }
        $this->client->loveRecording($mbid);

        return true;
    }
}
