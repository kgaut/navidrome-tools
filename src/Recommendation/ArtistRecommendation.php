<?php

namespace App\Recommendation;

/**
 * One recommended artist, aggregated across sources. `mbid` may be null
 * until resolved (Lidarr needs it to add the artist). `sources` lists which
 * engines surfaced it (e.g. `['lastfm','listenbrainz']`); `seeds` the
 * library artists that pulled it (for the « parce que tu écoutes X » UI).
 */
final class ArtistRecommendation implements \JsonSerializable
{
    /**
     * @param list<string> $sources
     * @param list<string> $seeds
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $mbid,
        public readonly float $score,
        public readonly array $sources,
        public readonly array $seeds,
    ) {
    }

    /**
     * @return array{name: string, mbid: ?string, score: float, sources: list<string>, seeds: list<string>}
     */
    public function jsonSerialize(): array
    {
        return [
            'name' => $this->name,
            'mbid' => $this->mbid,
            'score' => $this->score,
            'sources' => $this->sources,
            'seeds' => $this->seeds,
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            (string) ($row['name'] ?? ''),
            isset($row['mbid']) && $row['mbid'] !== '' ? (string) $row['mbid'] : null,
            (float) ($row['score'] ?? 0),
            array_values(array_map('strval', (array) ($row['sources'] ?? []))),
            array_values(array_map('strval', (array) ($row['seeds'] ?? []))),
        );
    }
}
