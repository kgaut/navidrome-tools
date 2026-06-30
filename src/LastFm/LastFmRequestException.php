<?php

namespace App\LastFm;

/**
 * A Last.fm HTTP request that failed at the transport level (timeout, empty
 * body, connection reset…) after exhausting the client's retries — as opposed
 * to {@see LastFmApiException}, which carries a Last.fm API error code.
 *
 * Extends \RuntimeException so existing `catch (\RuntimeException)` call sites
 * keep working; callers that want to treat a transient network blip specially
 * (e.g. skip one scrobble during a long rematch rather than aborting the whole
 * run) can catch this narrower type.
 */
class LastFmRequestException extends \RuntimeException
{
}
