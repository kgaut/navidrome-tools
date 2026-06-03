<?php

namespace App\MusicBrainz;

/**
 * One artist returned by the MusicBrainz search endpoint, normalized to the
 * subset we actually use: the canonical name + the alias strings, plus the
 * search score MB attached to this candidate (0-100, 100 = best).
 */
final class MusicBrainzArtistCandidate
{
    /**
     * @param list<string> $aliases primary + non-primary alias names, deduped
     */
    public function __construct(
        public readonly string $mbid,
        public readonly string $name,
        public readonly int $score,
        public readonly array $aliases,
    ) {
    }

    /**
     * All known textual forms of this artist (canonical name + aliases).
     *
     * @return list<string>
     */
    public function allNames(): array
    {
        $names = [$this->name, ...$this->aliases];
        $seen = [];
        $out = [];
        foreach ($names as $n) {
            $key = mb_strtolower(trim($n));
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $n;
        }

        return $out;
    }
}
