<?php

namespace App\LastFm;

final class FetchReport
{
    /** Scrobbles read from the Last.fm API stream. */
    public int $fetched = 0;

    /** Scrobbles that landed in lastfm_import_buffer (a row was inserted). */
    public int $buffered = 0;

    /**
     * Scrobbles already present in the buffer (rejected by the unique
     * constraint on lastfm_user, played_at, artist, title). Re-fetching the
     * same window is therefore idempotent.
     */
    public int $alreadyBuffered = 0;
}
