<?php

namespace App\LastFm;

/**
 * Outcome of a `track.getInfo` or `track.getCorrection` Last.fm API call.
 * Holds the official MBID (when known) and the « corrected » spelling of
 * the artist / title pair when Last.fm's autocorrect actually changed
 * the input. Equal-after-trim-and-lower comparisons collapse to null so
 * callers can safely test « did Last.fm correct the spelling? » with a
 * `!== null` check.
 */
final class LastFmTrackInfo
{
    public function __construct(
        public readonly ?string $mbid,
        public readonly ?string $correctedArtist,
        public readonly ?string $correctedTitle,
    ) {
    }

    public static function empty(): self
    {
        return new self(null, null, null);
    }

    public function hasMbid(): bool
    {
        return $this->mbid !== null && $this->mbid !== '';
    }

    public function hasCorrection(): bool
    {
        return $this->correctedArtist !== null || $this->correctedTitle !== null;
    }
}
