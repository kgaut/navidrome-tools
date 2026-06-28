<?php

namespace App\Recommendation;

use App\Navidrome\NavidromeRepository;
use App\Repository\SettingRepository;

/**
 * Persists the recommendation snapshot and the « ignored » set in the
 * key/value `setting` table — so the review UI shows the last computed list
 * without recomputing, and dismissed artists never resurface.
 *
 * Keys:
 *   - `recommendations.snapshot`  → JSON {generated_at, items: [...]}
 *   - `recommendations.ignored`   → JSON {mbids: [...], names: [...norm]}
 */
class RecommendationStore
{
    private const KEY_SNAPSHOT = 'recommendations.snapshot';
    private const KEY_IGNORED = 'recommendations.ignored';

    public function __construct(
        private readonly SettingRepository $settings,
    ) {
    }

    public function save(RecommendationResult $result, \DateTimeImmutable $generatedAt): void
    {
        $payload = [
            'generated_at' => $generatedAt->format(\DateTimeInterface::ATOM),
            'items' => array_map(static fn (ArtistRecommendation $r): array => $r->jsonSerialize(), $result->recommendations),
        ];
        $this->settings->set(self::KEY_SNAPSHOT, json_encode($payload, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE));
    }

    /**
     * The last saved snapshot, or null when none was computed yet.
     *
     * @return array{generated_at: ?string, items: list<ArtistRecommendation>}|null
     */
    public function load(): ?array
    {
        $raw = $this->settings->get(self::KEY_SNAPSHOT, '');
        if ($raw === '') {
            return null;
        }

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        $items = [];
        foreach ((array) ($decoded['items'] ?? []) as $row) {
            if (is_array($row)) {
                $items[] = ArtistRecommendation::fromArray($row);
            }
        }

        return [
            'generated_at' => isset($decoded['generated_at']) ? (string) $decoded['generated_at'] : null,
            'items' => $items,
        ];
    }

    /**
     * Mark an artist as ignored so the engine drops it from future runs.
     * Both the MBID (Lidarr's key) and the normalized name are stored so a
     * recommendation lacking an MBID can still be dismissed.
     */
    public function ignore(?string $mbid, string $name): void
    {
        $set = $this->ignoredRaw();
        if ($mbid !== null && $mbid !== '') {
            $set['mbids'][$mbid] = true;
        }
        $norm = NavidromeRepository::normalize($name);
        if ($norm !== '') {
            $set['names'][$norm] = true;
        }
        $this->settings->set(self::KEY_IGNORED, json_encode([
            'mbids' => array_keys($set['mbids']),
            'names' => array_keys($set['names']),
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE));
    }

    /** @return array<string, true> ignored MBIDs as a lookup set */
    public function ignoredMbids(): array
    {
        return $this->ignoredRaw()['mbids'];
    }

    /** @return array<string, true> ignored normalized names as a lookup set */
    public function ignoredNames(): array
    {
        return $this->ignoredRaw()['names'];
    }

    /**
     * @return array{mbids: array<string, true>, names: array<string, true>}
     */
    private function ignoredRaw(): array
    {
        $raw = $this->settings->get(self::KEY_IGNORED, '');
        $mbids = [];
        $names = [];
        if ($raw !== '') {
            try {
                /** @var array<string, mixed> $decoded */
                $decoded = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
                foreach ((array) ($decoded['mbids'] ?? []) as $m) {
                    $m = (string) $m;
                    if ($m !== '') {
                        $mbids[$m] = true;
                    }
                }
                foreach ((array) ($decoded['names'] ?? []) as $n) {
                    $n = (string) $n;
                    if ($n !== '') {
                        $names[$n] = true;
                    }
                }
            } catch (\JsonException) {
                // Corrupt setting → treat as empty ignored set.
            }
        }

        return ['mbids' => $mbids, 'names' => $names];
    }
}
