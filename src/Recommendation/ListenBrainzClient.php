<?php

namespace App\Recommendation;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Thin ListenBrainz client for the recommendation source. Two endpoints:
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

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $user = '',
        private readonly string $baseUrl = self::DEFAULT_BASE_URL,
        #[\SensitiveParameter]
        private readonly string $token = '',
    ) {
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
        $headers = ['Accept' => 'application/json'];
        if (trim($this->token) !== '') {
            $headers['Authorization'] = 'Token ' . $this->token;
        }

        try {
            $response = $this->httpClient->request('GET', $url, [
                'query' => $query,
                'headers' => $headers,
                'timeout' => 20,
            ]);
            $status = $response->getStatusCode();
            if ($status === 204) {
                return [];
            }
            if ($status >= 400) {
                throw new ListenBrainzException(sprintf('ListenBrainz HTTP %d on %s', $status, $url));
            }

            /** @var array<mixed> $body */
            $body = $response->toArray(false);
        } catch (ListenBrainzException $e) {
            throw $e;
        } catch (ExceptionInterface $e) {
            throw new ListenBrainzException(sprintf('ListenBrainz request failed: %s', $e->getMessage()), 0, $e);
        }

        return $body;
    }
}
