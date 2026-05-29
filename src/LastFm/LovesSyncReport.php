<?php

namespace App\LastFm;

/**
 * Outcome of a one-direction love sync (Navidrome → Last.fm or the inverse).
 *
 *  - considered: source rows iterated.
 *  - applied: rows actually propagated to the destination this run
 *    (Last.fm track.love calls made, or Navidrome annotation rows
 *    promoted/inserted).
 *  - alreadyInSync: source rows where the destination already had the
 *    love flag set — no write needed.
 *  - unmatched: source rows we couldn't map to a destination row (no
 *    matching media_file on Navidrome, or no scrobble providing the
 *    (artist, title) pair to send to Last.fm). For visibility only.
 *  - errors: rows that hit a hard failure (API error, DB error). Logged
 *    in RunHistory metrics for follow-up.
 */
final class LovesSyncReport
{
    public int $considered = 0;
    public int $applied = 0;
    public int $alreadyInSync = 0;
    public int $unmatched = 0;
    public int $errors = 0;
}
