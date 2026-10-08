<?php

namespace App\AudioMuse;

/**
 * One track of AudioMuse-AI's `/api/sync` payload, parsed (issue #257).
 *
 * `mood_vector` carries weighted GENRE tags (« rock:0.547,folk:0.536,… »),
 * `other_features` the six MOOD scores (« danceable:0.59,relaxed:0.12,… »).
 */
final class TrackFeatures
{
    public const MOODS = ['danceable', 'aggressive', 'happy', 'party', 'relaxed', 'sad'];

    /**
     * @param array<string, float> $moods  subset of self::MOODS => 0..1
     * @param array<string, float> $genres genre => weight, heaviest first
     */
    public function __construct(
        public readonly string $id,
        public readonly string $fingerprint,
        public readonly ?float $tempo,
        public readonly ?string $key,
        public readonly ?string $scale,
        public readonly ?float $energy,
        public readonly array $moods,
        public readonly array $genres,
    ) {
    }

    /**
     * @param array<mixed> $row one entry of the payload's `tracks`
     */
    public static function fromSyncRow(array $row): ?self
    {
        $id = trim((string) ($row['id'] ?? ''));
        if ($id === '') {
            return null;
        }

        $genres = self::parseWeights($row['mood_vector'] ?? null);
        arsort($genres);
        $moods = array_intersect_key(self::parseWeights($row['other_features'] ?? null), array_flip(self::MOODS));

        return new self(
            $id,
            (string) ($row['fp'] ?? ''),
            self::toFloat($row['tempo'] ?? null),
            self::toString($row['key'] ?? null),
            self::toString($row['scale'] ?? null),
            self::toFloat($row['energy'] ?? null),
            $moods,
            $genres,
        );
    }

    public function topGenre(): ?string
    {
        return array_key_first($this->genres);
    }

    /**
     * « a:0.5,b:0.25 » → ['a' => 0.5, 'b' => 0.25]; malformed pairs skipped.
     *
     * @return array<string, float>
     */
    public static function parseWeights(mixed $raw): array
    {
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }
        $out = [];
        foreach (explode(',', $raw) as $pair) {
            $parts = explode(':', $pair);
            if (count($parts) !== 2 || trim($parts[0]) === '' || !is_numeric(trim($parts[1]))) {
                continue;
            }
            $out[trim($parts[0])] = (float) trim($parts[1]);
        }

        return $out;
    }

    private static function toFloat(mixed $v): ?float
    {
        return is_numeric($v) ? (float) $v : null;
    }

    private static function toString(mixed $v): ?string
    {
        return is_string($v) && trim($v) !== '' ? trim($v) : null;
    }
}
