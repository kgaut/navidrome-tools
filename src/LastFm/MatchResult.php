<?php

namespace App\LastFm;

final class MatchResult
{
    public const STATUS_MATCHED = 'matched';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_UNMATCHED = 'unmatched';

    private function __construct(
        public readonly string $status,
        public readonly ?string $mediaFileId,
    ) {
    }

    public static function matched(string $mediaFileId): self
    {
        return new self(self::STATUS_MATCHED, $mediaFileId);
    }

    public static function skipped(): self
    {
        return new self(self::STATUS_SKIPPED, null);
    }

    public static function unmatched(): self
    {
        return new self(self::STATUS_UNMATCHED, null);
    }
}
