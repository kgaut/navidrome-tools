<?php

namespace App\LastFm;

/**
 * Thrown when the Last.fm API responds with an `error` field. Carries the
 * numeric error code separately so callers can soft-handle expected codes
 * (e.g. 6 « Track not found » during the matching cascade) while letting
 * real failures (rate limiting, invalid key, service down) bubble up.
 *
 * Codes documented at https://www.last.fm/api/errorcodes — most relevant:
 *   6  → Invalid parameters / track not found
 *   8  → Operation failed
 *   10 → Invalid API key
 *   11 → Service offline
 *   16 → Service temporarily unavailable
 *   29 → Rate limit exceeded
 */
class LastFmApiException extends \RuntimeException
{
    public function __construct(
        public readonly int $errorCode,
        public readonly string $errorMessage,
    ) {
        parent::__construct(sprintf('Last.fm API error %d: %s', $errorCode, $errorMessage));
    }
}
