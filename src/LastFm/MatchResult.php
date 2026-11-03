<?php

namespace App\LastFm;

final class MatchResult
{
    public const STATUS_MATCHED = 'matched';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_UNMATCHED = 'unmatched';

    /** Strategy of a match resolved by a track-level manual alias (issue #280). */
    public const STRATEGY_ALIAS = 'alias';

    public const CACHE_HIT_POSITIVE = 'hit-positive';
    public const CACHE_HIT_NEGATIVE = 'hit-negative';
    public const CACHE_MISS = 'miss';

    private function __construct(
        public readonly string $status,
        public readonly ?string $mediaFileId,
        public readonly ?string $strategy = null,
        public readonly ?string $cacheStatus = null,
    ) {
    }

    public static function matched(string $mediaFileId, ?string $strategy = null, ?string $cacheStatus = null): self
    {
        return new self(self::STATUS_MATCHED, $mediaFileId, $strategy, $cacheStatus);
    }

    public static function skipped(): self
    {
        return new self(self::STATUS_SKIPPED, null);
    }

    public static function unmatched(?string $cacheStatus = null): self
    {
        return new self(self::STATUS_UNMATCHED, null, null, $cacheStatus);
    }
}
