<?php

namespace App\Recommendation;

use App\LastFm\LastFmLovedTrack;
use App\LastFm\LastFmScrobble;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Thin ListenBrainz client. Listening history (issue #310):
 *
 *   - GET /1/user/{user}/listens → listens, newest first, paginated
 *     backwards with `max_ts` (see {@see streamListens()}).
 *
 * Recommendation source, two endpoints:
 *
 *   - GET /1/cf/recommendation/user/{user}/recording  → personalized
 *     « recordings you might like » (collaborative filtering), each with a
 *     score. Public; a user token only raises rate limits.
 *   - GET /1/metadata/recording?recording_mbids=…&inc=artist → resolves each
 *     recording MBID to its artist MBID(s) + names (Lidarr indexes by artist
 *     MBID, so this is what makes a recording recommendation actionable).
 *
 * The class only fetches + decodes; aggregation lives in the source.
 */
class ListenBrainzClient
{
    public const DEFAULT_BASE_URL = 'https://api.listenbrainz.org';

    /** Metadata lookups are chunked to keep URLs/requests reasonable. */
    private const METADATA_CHUNK = 50;

    /** ListenBrainz caps `count` at 1000 listens per request. */
    public const LISTENS_PAGE_SIZE = 1000;

    /** Retries of one page after a HTTP 429 before giving up. */
    private const MAX_RATE_LIMIT_RETRIES = 5;

    /** @var \Closure(int): void */
    private \Closure $sleeper;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $user = '',
        private readonly string $baseUrl = self::DEFAULT_BASE_URL,
        #[\SensitiveParameter]
        private readonly string $token = '',
        ?\Closure $sleeper = null,
    ) {
        $this->sleeper = $sleeper ?? static function (int $seconds): void {
            sleep($seconds);
        };
    }

    public function user(): string
    {
        return trim($this->user);
    }

    /**
     * Listens of the configured user played in [$dateMin, $dateMax], newest
     * first. Pages backwards with `max_ts` (exclusive): the next page starts
     * one second after the oldest listen of the current one, so listens that
     * share that second are read twice rather than skipped — they are yielded
     * once (deduplicated on timestamp + msid). Honours the rate-limit headers.
     *
     * @return \Generator<int, LastFmScrobble>
     */
    public function streamListens(?\DateTimeInterface $dateMin = null, ?\DateTimeInterface $dateMax = null): \Generator
    {
        if (!$this->isConfigured()) {
            return;
        }

        $url = sprintf('%s/1/user/%s/listens', rtrim($this->baseUrl, '/'), rawurlencode($this->user()));
        $minTs = $dateMin?->getTimestamp();
        $maxTs = $dateMax !== null ? $dateMax->getTimestamp() + 1 : null;
        $seen = [];

        while (true) {
            $query = ['count' => self::LISTENS_PAGE_SIZE];
            if ($maxTs !== null) {
                $query['max_ts'] = $maxTs;
            }
            $listens = $this->get($url, $query)['payload']['listens'] ?? [];
            if (!is_array($listens) || $listens === []) {
                return;
            }

            $oldest = null;
            $pageKeys = [];
            foreach ($listens as $listen) {
                if (!is_array($listen)) {
                    continue;
                }
                $ts = (int) ($listen['listened_at'] ?? 0);
                if ($ts <= 0) {
                    continue;
                }
                $oldest = $oldest === null ? $ts : min($oldest, $ts);
                if ($minTs !== null && $ts < $minTs) {
                    continue;
                }
                $key = $ts . '|' . (string) ($listen['recording_msid'] ?? '') . '|' . (string) ($listen['track_metadata']['track_name'] ?? '');
                $pageKeys[$key] = true;
                if (isset($seen[$key])) {
                    continue;
                }
                $scrobble = self::toScrobble($listen, $ts);
                if ($scrobble !== null) {
                    yield $scrobble;
                }
            }
            // Only the boundary second can come back on the next page.
            $seen = $pageKeys;

            // A short page is the last one.
            if ($oldest === null || count($listens) < self::LISTENS_PAGE_SIZE || ($minTs !== null && $oldest < $minTs)) {
                return;
            }
            // `max_ts` is exclusive: oldest + 1 re-reads the boundary second.
            // A page made of one single second would loop: step past it.
            $next = $oldest + 1;
            $maxTs = ($maxTs !== null && $next >= $maxTs) ? $oldest : $next;
        }
    }

    /** True when a user token is set — required to write feedback. */
    public function hasToken(): bool
    {
        return trim($this->token) !== '';
    }

    /**
     * Recordings the user loved (feedback score 1), newest first, with their
     * metadata. Paginated by offset.
     *
     * @return \Generator<int, LastFmLovedTrack>
     */
    public function iterateLovedRecordings(): \Generator
    {
        if (!$this->isConfigured()) {
            return;
        }

        $url = sprintf('%s/1/feedback/user/%s/get-feedback', rtrim($this->baseUrl, '/'), rawurlencode($this->user()));
        $offset = 0;
        while (true) {
            $payload = $this->get($url, ['score' => 1, 'count' => self::LISTENS_PAGE_SIZE, 'offset' => $offset, 'metadata' => 'true']);
            $feedback = $payload['feedback'] ?? [];
            if (!is_array($feedback) || $feedback === []) {
                return;
            }
            foreach ($feedback as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $meta = is_array($row['track_metadata'] ?? null) ? $row['track_metadata'] : [];
                $mapping = is_array($meta['mbid_mapping'] ?? null) ? $meta['mbid_mapping'] : [];
                $created = (int) ($row['created'] ?? 0);
                yield new LastFmLovedTrack(
                    artist: trim((string) ($meta['artist_name'] ?? '')),
                    title: trim((string) ($meta['track_name'] ?? '')),
                    mbid: self::mbid($row['recording_mbid'] ?? null) ?? self::mbid($mapping['recording_mbid'] ?? null),
                    lovedAt: $created > 0 ? (new \DateTimeImmutable('@' . $created))->setTimezone(new \DateTimeZone('UTC')) : null,
                );
            }
            $offset += count($feedback);
            if (count($feedback) < self::LISTENS_PAGE_SIZE) {
                return;
            }
        }
    }

    /**
     * Love a recording (feedback score 1). Requires the user token.
     */
    public function loveRecording(string $recordingMbid): void
    {
        if (!$this->hasToken()) {
            throw new ListenBrainzException('LISTENBRAINZ_TOKEN requis pour écrire un coup de cœur.');
        }
        $this->request('POST', rtrim($this->baseUrl, '/') . '/1/feedback/recording-feedback', [
            'json' => ['recording_mbid' => $recordingMbid, 'score' => 1],
        ]);
    }

    /**
     * MusicBrainz recording MBID for an (artist, title), through the
     * ListenBrainz metadata lookup. The lookup is fuzzy: the answer is kept
     * only when its artist credit and title equal the query once normalized
     * ({@see normalizeForLookup()}: case, apostrophes, spaces, « feat. » part).
     */
    public function lookupRecordingMbid(string $artist, string $title): ?string
    {
        $payload = $this->get(rtrim($this->baseUrl, '/') . '/1/metadata/lookup/', [
            'artist_name' => $artist,
            'recording_name' => $title,
        ]);
        $mbid = self::mbid($payload['recording_mbid'] ?? null);
        if ($mbid === null) {
            return null;
        }
        $sameArtist = self::normalizeForLookup((string) ($payload['artist_credit_name'] ?? '')) === self::normalizeForLookup($artist);
        $sameTitle = self::normalizeForLookup((string) ($payload['recording_name'] ?? '')) === self::normalizeForLookup($title);

        return $sameArtist && $sameTitle ? $mbid : null;
    }

    /**
     * Normalization of the strict lookup comparison (#319): lower case,
     * typographic apostrophes and quotes, collapsed spaces, and the featured
     * artists dropped (« (feat. X) », « feat. X », « ft. X », « featuring X »).
     * Accents, versions (« live », « remix ») and other words are kept: a
     * different recording must not match.
     */
    public static function normalizeForLookup(string $value): string
    {
        $value = mb_strtolower($value);
        $value = str_replace(['’', '‘', '`', '´', '“', '”'], ["'", "'", "'", "'", '"', '"'], $value);
        $value = (string) preg_replace('/\s*[\(\[]\s*(?:feat\.?|ft\.?|featuring)\s[^\)\]]*[\)\]]/u', '', $value);
        $value = (string) preg_replace('/\s+(?:feat\.?|ft\.?|featuring)\s.*$/u', '', $value);
        $value = (string) preg_replace('/\s+/u', ' ', $value);

        return trim($value);
    }

    /**
     * @param array<mixed> $listen
     */
    private static function toScrobble(array $listen, int $ts): ?LastFmScrobble
    {
        $meta = is_array($listen['track_metadata'] ?? null) ? $listen['track_metadata'] : [];
        $artist = trim((string) ($meta['artist_name'] ?? ''));
        $title = trim((string) ($meta['track_name'] ?? ''));
        if ($artist === '' || $title === '') {
            return null;
        }
        $info = is_array($meta['additional_info'] ?? null) ? $meta['additional_info'] : [];
        $mapping = is_array($meta['mbid_mapping'] ?? null) ? $meta['mbid_mapping'] : [];

        // The MusicBrainz mapping (computed by ListenBrainz) wins over what
        // the submitting client sent.
        $recordingMbid = self::mbid($mapping['recording_mbid'] ?? null) ?? self::mbid($info['recording_mbid'] ?? null);
        $artistMbids = $mapping['artist_mbids'] ?? $info['artist_mbids'] ?? [];
        $artistMbid = is_array($artistMbids) ? self::mbid($artistMbids[0] ?? null) : null;
        $releaseMbid = self::mbid($mapping['release_mbid'] ?? null) ?? self::mbid($info['release_mbid'] ?? null);

        return new LastFmScrobble(
            artist: $artist,
            title: $title,
            album: trim((string) ($meta['release_name'] ?? '')),
            mbid: $recordingMbid,
            playedAt: (new \DateTimeImmutable('@' . $ts))->setTimezone(new \DateTimeZone('UTC')),
            mbidArtist: $artistMbid,
            mbidAlbum: $releaseMbid,
        );
    }

    private static function mbid(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value !== '' ? $value : null;
    }

    public function isConfigured(): bool
    {
        return trim($this->user) !== '';
    }

    /**
     * Personalized recommended recordings for the configured user, newest
     * model first. Returns an empty list when LB has no recommendations yet
     * (HTTP 204 / empty payload).
     *
     * @return list<array{recording_mbid: string, score: float}>
     */
    public function recommendedRecordings(int $count): array
    {
        if (!$this->isConfigured()) {
            return [];
        }

        $url = sprintf('%s/1/cf/recommendation/user/%s/recording', rtrim($this->baseUrl, '/'), rawurlencode($this->user));
        $payload = $this->get($url, ['count' => max(1, $count)]);

        $mbids = $payload['payload']['mbids'] ?? [];
        if (!is_array($mbids)) {
            return [];
        }

        $out = [];
        foreach ($mbids as $row) {
            if (!is_array($row)) {
                continue;
            }
            $mbid = trim((string) ($row['recording_mbid'] ?? ''));
            if ($mbid === '') {
                continue;
            }
            $out[] = ['recording_mbid' => $mbid, 'score' => (float) ($row['score'] ?? 0)];
        }

        return $out;
    }

    /**
     * Resolve recording MBIDs to their artists in batches.
     *
     * @param list<string> $recordingMbids
     *
     * @return array<string, list<array{mbid: string, name: string}>> recording MBID → its artists
     */
    public function resolveArtists(array $recordingMbids): array
    {
        $recordingMbids = array_values(array_unique(array_filter($recordingMbids, static fn (string $m): bool => $m !== '')));
        if ($recordingMbids === []) {
            return [];
        }

        $out = [];
        foreach (array_chunk($recordingMbids, self::METADATA_CHUNK) as $chunk) {
            $payload = $this->get(rtrim($this->baseUrl, '/') . '/1/metadata/recording', [
                'recording_mbids' => implode(',', $chunk),
                'inc' => 'artist',
            ]);

            foreach ($payload as $recordingMbid => $meta) {
                if (!is_string($recordingMbid) || !is_array($meta)) {
                    continue;
                }
                $artists = $meta['artist']['artists'] ?? [];
                if (!is_array($artists)) {
                    continue;
                }
                $resolved = [];
                foreach ($artists as $artist) {
                    if (!is_array($artist)) {
                        continue;
                    }
                    $mbid = trim((string) ($artist['artist_mbid'] ?? ''));
                    $name = trim((string) ($artist['name'] ?? ''));
                    if ($mbid !== '' && $name !== '') {
                        $resolved[] = ['mbid' => $mbid, 'name' => $name];
                    }
                }
                if ($resolved !== []) {
                    $out[$recordingMbid] = $resolved;
                }
            }
        }

        return $out;
    }

    /**
     * @param array<string, scalar> $query
     *
     * @return array<mixed>
     */
    private function get(string $url, array $query): array
    {
        return $this->request('GET', $url, ['query' => $query]);
    }

    /**
     * @param array<string, mixed> $options extra HttpClient options (query, json)
     *
     * @return array<mixed>
     */
    private function request(string $method, string $url, array $options): array
    {
        $headers = ['Accept' => 'application/json'];
        if (trim($this->token) !== '') {
            $headers['Authorization'] = 'Token ' . $this->token;
        }

        try {
            $attempt = 0;
            while (true) {
                $response = $this->httpClient->request($method, $url, $options + [
                    'headers' => $headers,
                    'timeout' => 20,
                ]);
                $status = $response->getStatusCode();
                $responseHeaders = $response->getHeaders(false);
                $resetIn = max(1, (int) ($responseHeaders['x-ratelimit-reset-in'][0] ?? 1));
                if ($status === 429 && $attempt < self::MAX_RATE_LIMIT_RETRIES) {
                    $attempt++;
                    ($this->sleeper)($resetIn);
                    continue;
                }
                break;
            }
            if ($status === 204) {
                return [];
            }
            if ($status >= 400) {
                throw new ListenBrainzException(sprintf('ListenBrainz HTTP %d on %s %s', $status, $method, $url));
            }

            /** @var array<mixed> $body */
            $body = $response->toArray(false);

            // Last call of the window: wait for the reset instead of
            // collecting a 429 on the next page.
            if (($responseHeaders['x-ratelimit-remaining'][0] ?? null) === '0') {
                ($this->sleeper)($resetIn);
            }
        } catch (ListenBrainzException $e) {
            throw $e;
        } catch (ExceptionInterface $e) {
            throw new ListenBrainzException(sprintf('ListenBrainz request failed: %s', $e->getMessage()), 0, $e);
        }

        return $body;
    }
}
