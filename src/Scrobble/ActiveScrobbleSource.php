<?php

namespace App\Scrobble;

use App\LastFm\LastFmClient;
use App\Recommendation\ListenBrainzClient;

/**
 * Builds the listen source selected by `SCROBBLE_SOURCE` (issue #310, ADR
 * 0310-scrobbles-listenbrainz-source-unique-raccord-par-horodatage). One
 * source is active per instance; `lastfm` stays the default.
 */
class ActiveScrobbleSource
{
    public function __construct(
        private readonly LastFmClient $lastFmClient,
        private readonly ListenBrainzClient $listenBrainzClient,
        private readonly string $sourceName,
        #[\SensitiveParameter]
        private readonly string $lastFmApiKey,
        private readonly string $lastFmUser,
    ) {
    }

    /**
     * @throws \InvalidArgumentException on an unknown `SCROBBLE_SOURCE`
     */
    public function get(): ScrobbleSourceInterface
    {
        return match (strtolower(trim($this->sourceName))) {
            '', LastFmScrobbleSource::NAME => new LastFmScrobbleSource($this->lastFmClient, $this->lastFmApiKey, $this->lastFmUser),
            ListenBrainzScrobbleSource::NAME => new ListenBrainzScrobbleSource($this->listenBrainzClient),
            default => throw new \InvalidArgumentException(sprintf(
                'SCROBBLE_SOURCE inconnue : « %s » (valeurs : lastfm, listenbrainz).',
                $this->sourceName,
            )),
        };
    }
}
