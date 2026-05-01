<?php

namespace App\LastFm;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class LastFmClient
{
    private const BASE_URL = 'https://ws.audioscrobbler.com/2.0/';
    private const PAGE_SIZE = 200;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    /**
     * Iterate over all scrobbles for the given user, oldest first when
     * $from is provided, newest first otherwise. Yields LastFmScrobble
     * objects one by one to avoid loading the whole history in memory.
     *
     * @return \Generator<LastFmScrobble>
     */
    public function streamRecentTracks(
        string $apiKey,
        string $user,
        ?\DateTimeInterface $from = null,
        ?\DateTimeInterface $to = null,
    ): \Generator {
        $page = 1;
        $totalPages = null;

        do {
            $params = [
                'method' => 'user.getRecentTracks',
                'user' => $user,
                'api_key' => $apiKey,
                'format' => 'json',
                'limit' => self::PAGE_SIZE,
                'page' => $page,
            ];
            if ($from !== null) {
                $params['from'] = $from->getTimestamp();
            }
            if ($to !== null) {
                $params['to'] = $to->getTimestamp();
            }

            $payload = $this->call($params);

            $tracks = $payload['recenttracks']['track'] ?? [];
            // The API returns a single object instead of an array when only one item is present.
            if (isset($tracks['name'])) {
                $tracks = [$tracks];
            }

            foreach ($tracks as $track) {
                // Skip currently playing entries (no @attr.nowplaying flag for scrobble).
                if (($track['@attr']['nowplaying'] ?? null) === 'true') {
                    continue;
                }
                $ts = (int) ($track['date']['uts'] ?? 0);
                if ($ts === 0) {
                    continue;
                }
                yield new LastFmScrobble(
                    artist: (string) ($track['artist']['#text'] ?? $track['artist']['name'] ?? ''),
                    title: (string) ($track['name'] ?? ''),
                    album: (string) ($track['album']['#text'] ?? ''),
                    mbid: ($track['mbid'] ?? '') !== '' ? (string) $track['mbid'] : null,
                    playedAt: (new \DateTimeImmutable('@' . $ts))->setTimezone(new \DateTimeZone('UTC')),
                );
            }

            if ($totalPages === null) {
                $totalPages = (int) ($payload['recenttracks']['@attr']['totalPages'] ?? 1);
            }
            $page++;
        } while ($page <= $totalPages);
    }

    /**
     * @param array<string, scalar> $params
     *
     * @return array<string, mixed>
     */
    private function call(array $params): array
    {
        try {
            $response = $this->httpClient->request('GET', self::BASE_URL, [
                'query' => $params,
                'timeout' => 30,
            ]);
            $body = $response->toArray(throw: true);
        } catch (\Throwable $e) {
            throw new \RuntimeException(sprintf(
                'Last.fm API call failed (method=%s page=%s): %s',
                $params['method'] ?? '?',
                $params['page'] ?? '?',
                $e->getMessage(),
            ), 0, $e);
        }

        if (isset($body['error'])) {
            throw new \RuntimeException(sprintf(
                'Last.fm API error %s: %s',
                $body['error'],
                $body['message'] ?? 'unknown',
            ));
        }

        return $body;
    }
}
