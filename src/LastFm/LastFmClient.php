<?php

namespace App\LastFm;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class LastFmClient
{
    private const BASE_URL = 'https://ws.audioscrobbler.com/2.0/';
    private const PAGE_SIZE = 200;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly int $pageDelaySeconds = 10,
    ) {
    }

    /**
     * Iterate over all scrobbles for the given user, oldest first when
     * $dateMin is provided, newest first otherwise. Yields LastFmScrobble
     * objects one by one to avoid loading the whole history in memory.
     *
     * @return \Generator<LastFmScrobble>
     */
    public function streamRecentTracks(
        string $apiKey,
        string $user,
        ?\DateTimeInterface $dateMin = null,
        ?\DateTimeInterface $dateMax = null,
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
            if ($dateMin !== null) {
                $params['from'] = $dateMin->getTimestamp();
            }
            if ($dateMax !== null) {
                $params['to'] = $dateMax->getTimestamp();
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

            // Throttle: avoid hammering the Last.fm API between page fetches.
            // Sleep is skipped after the last page (loop will exit) and when
            // the caller breaks out mid-page (generator dies before reaching
            // this point).
            if ($page <= $totalPages && $this->pageDelaySeconds > 0) {
                sleep($this->pageDelaySeconds);
            }
        } while ($page <= $totalPages);
    }

    /**
     * Iterate over every loved track for $user (most-recent first).
     * 50 entries per page, paginated until exhaustion. Same throttling
     * as {@see streamRecentTracks()}.
     *
     * @return \Generator<LastFmLovedTrack>
     */
    public function iterateLovedTracks(string $apiKey, string $user): \Generator
    {
        $page = 1;
        $totalPages = null;

        do {
            $payload = $this->call([
                'method' => 'user.getLovedTracks',
                'user' => $user,
                'api_key' => $apiKey,
                'format' => 'json',
                'limit' => 50,
                'page' => $page,
            ]);

            $tracks = $payload['lovedtracks']['track'] ?? [];
            if (isset($tracks['name'])) {
                $tracks = [$tracks];
            }

            foreach ($tracks as $track) {
                $ts = isset($track['date']['uts']) ? (int) $track['date']['uts'] : 0;
                yield new LastFmLovedTrack(
                    artist: (string) ($track['artist']['name'] ?? $track['artist']['#text'] ?? ''),
                    title: (string) ($track['name'] ?? ''),
                    mbid: ($track['mbid'] ?? '') !== '' ? (string) $track['mbid'] : null,
                    lovedAt: $ts > 0
                        ? (new \DateTimeImmutable('@' . $ts))->setTimezone(new \DateTimeZone('UTC'))
                        : null,
                );
            }

            if ($totalPages === null) {
                $totalPages = (int) ($payload['lovedtracks']['@attr']['totalPages'] ?? 1);
            }
            $page++;
            if ($page <= $totalPages && $this->pageDelaySeconds > 0) {
                sleep($this->pageDelaySeconds);
            }
        } while ($page <= $totalPages);
    }

    /**
     * Mark a track as loved by the user owning $sk. Authenticated +
     * signed POST per https://www.last.fm/api/show/track.love.
     */
    public function trackLove(string $apiKey, string $apiSecret, string $sk, string $artist, string $title): void
    {
        $this->writeAction('track.love', $apiKey, $apiSecret, $sk, $artist, $title);
    }

    public function trackUnlove(string $apiKey, string $apiSecret, string $sk, string $artist, string $title): void
    {
        $this->writeAction('track.unlove', $apiKey, $apiSecret, $sk, $artist, $title);
    }

    /**
     * Look up track metadata at Last.fm. Returns the official MBID (when
     * present) plus any spelling correction the `autocorrect=1` flag
     * suggests. Used by the matching cascade to recover scrobbles whose
     * MBID was stripped at scrobble time and whose text matching failed.
     *
     * Last.fm always echoes back the artist / title in the response —
     * even when the input was already canonical. We collapse a
     * trim+lower-equal correction back to null so callers can test
     * « did Last.fm correct the spelling? » with a simple `!== null`.
     */
    public function trackGetInfo(string $apiKey, string $artist, string $title): LastFmTrackInfo
    {
        return $this->lookup('track.getInfo', $apiKey, $artist, $title, 'track');
    }

    /**
     * Returns up to $limit artists similar to $artist, ranked by Last.fm's
     * own `match` score (0..1). Each entry: name, MBID (when known), match
     * score, and the Last.fm URL of the artist page. Empty array when the
     * seed name is unknown or Last.fm returns no neighbours.
     *
     * @return list<array{name: string, mbid: ?string, match: float, url: string}>
     */
    public function artistGetSimilar(string $apiKey, string $artist, int $limit = 10): array
    {
        $payload = $this->call([
            'method' => 'artist.getSimilar',
            'artist' => $artist,
            'api_key' => $apiKey,
            'limit' => $limit,
            'autocorrect' => 1,
            'format' => 'json',
        ]);

        $items = $payload['similarartists']['artist'] ?? [];
        // Last.fm returns a single object instead of an array when one
        // sibling is found.
        if (isset($items['name'])) {
            $items = [$items];
        }

        $out = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $name = trim((string) ($item['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $out[] = [
                'name' => $name,
                'mbid' => ($item['mbid'] ?? '') !== '' ? (string) $item['mbid'] : null,
                'match' => (float) ($item['match'] ?? 0),
                'url' => (string) ($item['url'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * Cheaper sibling of {@see trackGetInfo()} : returns only the
     * canonical artist / track names suggested by Last.fm, no MBID. Same
     * autocorrect normalization as `trackGetInfo`.
     */
    public function trackGetCorrection(string $apiKey, string $artist, string $title): LastFmTrackInfo
    {
        return $this->lookup('track.getCorrection', $apiKey, $artist, $title, 'correction.track');
    }

    /**
     * Shared HTTP path for {@see trackGetInfo()} / {@see trackGetCorrection()}.
     * The two endpoints expose the same `{ artist: { name }, name, mbid }`
     * shape under different roots ; `$bodyKey` selects the root.
     *
     * @param string $bodyKey Either "track" (track.getInfo) or
     *                        "correction.track" (track.getCorrection,
     *                        nested under `correction`).
     */
    private function lookup(string $method, string $apiKey, string $artist, string $title, string $bodyKey): LastFmTrackInfo
    {
        $payload = $this->call([
            'method' => $method,
            'artist' => $artist,
            'track' => $title,
            'api_key' => $apiKey,
            'autocorrect' => 1,
            'format' => 'json',
        ]);

        $node = $payload;
        foreach (explode('.', $bodyKey) as $segment) {
            if (!isset($node[$segment]) || !is_array($node[$segment])) {
                return LastFmTrackInfo::empty();
            }
            $node = $node[$segment];
        }

        $mbid = isset($node['mbid']) && $node['mbid'] !== '' ? (string) $node['mbid'] : null;
        $rawArtist = (string) ($node['artist']['name'] ?? $node['artist'] ?? '');
        $rawTitle = (string) ($node['name'] ?? '');

        return new LastFmTrackInfo(
            mbid: $mbid,
            correctedArtist: $this->correctionOrNull($artist, $rawArtist),
            correctedTitle: $this->correctionOrNull($title, $rawTitle),
        );
    }

    private function correctionOrNull(string $input, string $candidate): ?string
    {
        $candidate = trim($candidate);
        if ($candidate === '') {
            return null;
        }
        if (mb_strtolower(trim($input)) === mb_strtolower($candidate)) {
            return null;
        }

        return $candidate;
    }

    private function writeAction(
        string $method,
        string $apiKey,
        string $apiSecret,
        string $sk,
        string $artist,
        string $title,
    ): void {
        $params = [
            'method' => $method,
            'api_key' => $apiKey,
            'artist' => $artist,
            'track' => $title,
            'sk' => $sk,
        ];
        $params['api_sig'] = LastFmApiSigner::sign($params, $apiSecret);
        $params['format'] = 'json';

        try {
            $response = $this->httpClient->request('POST', self::BASE_URL, [
                'body' => $params,
                'timeout' => 30,
            ]);
            $body = $response->toArray(throw: true);
        } catch (\Throwable $e) {
            throw new \RuntimeException(sprintf(
                'Last.fm %s failed for "%s — %s": %s',
                $method,
                $artist,
                $title,
                $e->getMessage(),
            ), 0, $e);
        }

        if (isset($body['error'])) {
            throw new \RuntimeException(sprintf(
                'Last.fm %s error %s: %s',
                $method,
                $body['error'],
                $body['message'] ?? 'unknown',
            ));
        }
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
