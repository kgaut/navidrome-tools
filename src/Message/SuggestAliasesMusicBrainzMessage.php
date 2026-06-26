<?php

namespace App\Message;

/**
 * Async request to query MusicBrainz for artist aliases bridging unmatched
 * scrobbles to library artists. Handled out-of-band by the Messenger
 * worker because MusicBrainz rate-limits at ~1 req/s — running it inline
 * in the web request would block for seconds-to-minutes.
 *
 * Mirrors the `app:aliases:musicbrainz` CLI in non-interactive mode:
 * unique-library-match aliases are applied automatically, ambiguous ones
 * are skipped. `minPlays` filters out long-tail artists so we only spend
 * MB calls on the source artists that actually move the unmatched needle.
 */
final class SuggestAliasesMusicBrainzMessage
{
    public function __construct(
        public readonly string $target,
        public readonly bool $dryRun = false,
        public readonly int $limit = 0,
        public readonly int $minPlays = 0,
    ) {
    }
}
