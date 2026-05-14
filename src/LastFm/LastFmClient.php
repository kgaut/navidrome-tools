<?php

namespace App\LastFm;

use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class LastFmClient
{
    private const BASE_URL = 'https://ws.audioscrobbler.com/2.0/';
    private const PAGE_SIZE = 200;
    private const MAX_ATTEMPTS = 3;

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
            if (isset($tracks['name'])) {
                $tracks = [$tracks];
            }

            foreach ($tracks as $track) {
                // Skip currently playing entries — no date yet.
                if (($track['@attr']['nowplaying'] ?? null) === 'true') {
                    continue;
                }
                $ts = (int) ($track['date']['uts'] ?? 0);
                if ($ts === 0) {
                    continue;
                }

                $imageUrl = null;
                $images = $track['image'] ?? [];
                foreach (array_reverse($images) as $img) {
                    $url = (string) ($img['#text'] ?? '');
                    if ($url !== '') {
                        $imageUrl = $url;
                        break;
                    }
                }

                yield new LastFmScrobble(
                    artist: (string) ($track['artist']['#text'] ?? $track['artist']['name'] ?? ''),
                    title: (string) ($track['name'] ?? ''),
                    album: (string) ($track['album']['#text'] ?? ''),
                    albumArtist: '',
                    mbid: ($track['mbid'] ?? '') !== '' ? (string) $track['mbid'] : null,
                    mbidArtist: ($track['artist']['mbid'] ?? '') !== '' ? (string) $track['artist']['mbid'] : null,
                    mbidAlbum: ($track['album']['mbid'] ?? '') !== '' ? (string) $track['album']['mbid'] : null,
                    playedAt: (new \DateTimeImmutable('@' . $ts))->setTimezone(new \DateTimeZone('UTC')),
                    loved: ($track['loved'] ?? '0') === '1',
                    imageUrl: $imageUrl,
                );
            }

            if ($totalPages === null) {
                $totalPages = (int) ($payload['recenttracks']['@attr']['totalPages'] ?? 1);
            }
            $page++;

            if ($page <= $totalPages && $this->pageDelaySeconds > 0) {
                sleep($this->pageDelaySeconds);
            }
        } while ($page <= $totalPages);
    }

    /** @return \Generator<LastFmLovedTrack> */
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

    public function trackLove(string $apiKey, string $apiSecret, string $sk, string $artist, string $title): void
    {
        $this->writeAction('track.love', $apiKey, $apiSecret, $sk, $artist, $title);
    }

    public function trackUnlove(string $apiKey, string $apiSecret, string $sk, string $artist, string $title): void
    {
        $this->writeAction('track.unlove', $apiKey, $apiSecret, $sk, $artist, $title);
    }

    public function trackGetInfo(string $apiKey, string $artist, string $title): LastFmTrackInfo
    {
        return $this->lookup('track.getInfo', $apiKey, $artist, $title, 'track');
    }

    /** @return list<array{name: string, mbid: ?string, match: float, url: string}> */
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

    private function lookup(string $method, string $apiKey, string $artist, string $title, string $bodyKey): LastFmTrackInfo
    {
        try {
            $payload = $this->call([
                'method' => $method,
                'artist' => $artist,
                'track' => $title,
                'api_key' => $apiKey,
                'autocorrect' => 1,
                'format' => 'json',
            ]);
        } catch (LastFmApiException $e) {
            if ($e->errorCode === 6) {
                return LastFmTrackInfo::empty();
            }
            throw $e;
        }

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

    private function writeAction(string $method, string $apiKey, string $apiSecret, string $sk, string $artist, string $title): void
    {
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
            throw new \RuntimeException(sprintf('Last.fm %s failed for "%s — %s": %s', $method, $artist, $title, $e->getMessage()), 0, $e);
        }

        if (isset($body['error'])) {
            throw new \RuntimeException(sprintf('Last.fm %s error %s: %s', $method, $body['error'], $body['message'] ?? 'unknown'));
        }
    }

    /** @param array<string, scalar> $params
     *  @return array<string, mixed>
     */
    private function call(array $params): array
    {
        $attempt = 0;
        while (true) {
            $attempt++;
            try {
                $response = $this->httpClient->request('GET', self::BASE_URL, [
                    'query' => $params,
                    'timeout' => 30,
                ]);
                $body = $response->toArray(throw: true);
                break;
            } catch (\Throwable $e) {
                if ($attempt < self::MAX_ATTEMPTS && $this->isTransientHttpError($e)) {
                    if ($this->pageDelaySeconds > 0) {
                        sleep($this->pageDelaySeconds);
                    }
                    continue;
                }
                throw new \RuntimeException(sprintf(
                    'Last.fm API call failed (method=%s page=%s) after %d attempt(s): %s',
                    $params['method'] ?? '?',
                    $params['page'] ?? '?',
                    $attempt,
                    $e->getMessage(),
                ), 0, $e);
            }
        }

        if (isset($body['error'])) {
            throw new LastFmApiException((int) $body['error'], (string) ($body['message'] ?? 'unknown'));
        }

        return $body;
    }

    private function isTransientHttpError(\Throwable $e): bool
    {
        if ($e instanceof TransportExceptionInterface) {
            return true;
        }
        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getResponse()->getStatusCode();
            return $status === 429 || $status >= 500;
        }

        return false;
    }
}
