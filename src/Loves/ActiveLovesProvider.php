<?php

namespace App\Loves;

use App\LastFm\LastFmClient;
use App\Recommendation\ListenBrainzClient;
use App\Scrobble\LastFmScrobbleSource;
use App\Scrobble\ListenBrainzScrobbleSource;
use App\Service\LastFmSessionService;

/**
 * Loves provider matching `SCROBBLE_SOURCE` (issue #310).
 */
class ActiveLovesProvider
{
    public function __construct(
        private readonly LastFmClient $lastFmClient,
        private readonly LastFmSessionService $sessions,
        private readonly ListenBrainzClient $listenBrainzClient,
        private readonly string $sourceName,
        #[\SensitiveParameter]
        private readonly string $lastFmApiKey,
        #[\SensitiveParameter]
        private readonly string $lastFmApiSecret,
        private readonly string $lastFmUser,
    ) {
    }

    /**
     * @throws \InvalidArgumentException on an unknown `SCROBBLE_SOURCE`
     */
    public function get(): LovesProviderInterface
    {
        return match (strtolower(trim($this->sourceName))) {
            '', LastFmScrobbleSource::NAME => new LastFmLovesProvider(
                $this->lastFmClient,
                $this->lastFmApiKey,
                $this->lastFmApiSecret,
                $this->lastFmUser !== '' ? $this->sessions->get($this->lastFmUser) : null,
                $this->lastFmUser,
            ),
            ListenBrainzScrobbleSource::NAME => new ListenBrainzLovesProvider($this->listenBrainzClient),
            default => throw new \InvalidArgumentException(sprintf(
                'SCROBBLE_SOURCE inconnue : « %s » (valeurs : lastfm, listenbrainz).',
                $this->sourceName,
            )),
        };
    }
}
