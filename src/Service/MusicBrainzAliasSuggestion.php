<?php

namespace App\Service;

/**
 * One suggestion produced by {@see MusicBrainzAliasSuggester}: rewriting the
 * unmatched `$sourceArtist` to the library-owned `$targetArtist`, backed by
 * the MusicBrainz candidate(s) listed in `$evidence`.
 *
 *  - `unique`     → exactly one library artist matched across the MB
 *                   candidates and their aliases. Safe to auto-apply.
 *  - `ambiguous`  → multiple distinct library artists matched. Requires
 *                   confirmation (interactive prompt or manual review).
 *  - `no_match`   → MB returned candidates but none of them or their aliases
 *                   normalise to a library artist. Kept for reporting.
 */
final class MusicBrainzAliasSuggestion
{
    public const KIND_UNIQUE = 'unique';
    public const KIND_AMBIGUOUS = 'ambiguous';
    public const KIND_NO_MATCH = 'no_match';

    /**
     * @param list<string>                 $targetCandidates library artists matched (canonical names)
     * @param list<array{mbid: string, name: string, score: int, matched_via: ?string}> $evidence
     */
    public function __construct(
        public readonly string $sourceArtist,
        public readonly int $plays,
        public readonly string $kind,
        public readonly array $targetCandidates,
        public readonly array $evidence,
    ) {
    }

    public function uniqueTarget(): ?string
    {
        return $this->kind === self::KIND_UNIQUE ? ($this->targetCandidates[0] ?? null) : null;
    }
}
