<?php

namespace App\MusicBrainz;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Thin MusicBrainz Web Service v2 client — we only call /artist/?query=…
 * because the alias-suggester only needs artist candidates with their alias
 * list. Anything more comes later.
 *
 * Anonymous; just needs a polite User-Agent (MB rejects empty / bot UAs and
 * applies a strict 1 req/s rate-limit per UA). The caller is responsible for
 * throttling — this class only sends the request.
 */
class MusicBrainzClient
{
    private const DEFAULT_BASE_URL = 'https://musicbrainz.org/ws/2/';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $userAgent,
        private readonly string $baseUrl = self::DEFAULT_BASE_URL,
    ) {
    }

    /**
     * Search for an artist by name, asking MB to include alias records in the
     * response. `$limit` caps the candidate set returned; MB's own `score`
     * field tells how well each one matched.
     *
     * @return list<MusicBrainzArtistCandidate>
     */
    public function searchArtist(string $name, int $limit = 5): array
    {
        $name = trim($name);
        if ($name === '') {
            return [];
        }

        // Lucene-style query, value quoted to keep multi-word names intact and
        // backslash-escape the few characters MB treats as syntax (so a name
        // like `AC/DC` doesn't break parsing).
        $escaped = preg_replace('/([+\-!(){}\[\]^"~*?:\\\\\/])/', '\\\\$1', $name) ?? $name;
        $params = [
            'query' => sprintf('artist:"%s"', $escaped),
            'fmt' => 'json',
            'limit' => max(1, min(25, $limit)),
        ];

        try {
            $response = $this->httpClient->request('GET', rtrim($this->baseUrl, '/') . '/artist/', [
                'query' => $params,
                'headers' => [
                    'User-Agent' => $this->userAgent,
                    'Accept' => 'application/json',
                ],
                'timeout' => 15,
            ]);
            $status = $response->getStatusCode();
        } catch (ExceptionInterface $e) {
            throw new MusicBrainzException(
                sprintf('MusicBrainz request failed for "%s": %s', $name, $e->getMessage()),
                0,
                $e,
            );
        }

        if ($status === 503) {
            // 503 = rate-limited. Surface a typed error so the caller can back
            // off / retry rather than silently treating "no result" as truth.
            throw new MusicBrainzException(sprintf('MusicBrainz rate-limited (503) on "%s".', $name));
        }
        if ($status >= 400) {
            throw new MusicBrainzException(sprintf('MusicBrainz HTTP %d on "%s".', $status, $name));
        }

        try {
            /** @var array<string, mixed> $body */
            $body = $response->toArray(false);
        } catch (ExceptionInterface $e) {
            throw new MusicBrainzException(
                sprintf('MusicBrainz returned invalid JSON for "%s": %s', $name, $e->getMessage()),
                0,
                $e,
            );
        }

        $artists = $body['artists'] ?? [];
        if (!is_array($artists)) {
            return [];
        }

        $out = [];
        foreach ($artists as $a) {
            if (!is_array($a)) {
                continue;
            }
            $mbid = isset($a['id']) && is_string($a['id']) ? $a['id'] : '';
            $canonical = isset($a['name']) && is_string($a['name']) ? $a['name'] : '';
            if ($mbid === '' || $canonical === '') {
                continue;
            }
            $score = isset($a['score']) ? (int) $a['score'] : 0;
            $aliases = self::collectAliasNames($a['aliases'] ?? null);
            $out[] = new MusicBrainzArtistCandidate($mbid, $canonical, $score, $aliases);
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private static function collectAliasNames(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $alias) {
            if (!is_array($alias)) {
                continue;
            }
            $name = $alias['name'] ?? null;
            if (is_string($name) && trim($name) !== '') {
                $out[] = $name;
            }
        }

        return $out;
    }
}
