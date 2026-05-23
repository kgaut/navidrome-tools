<?php

namespace App\LastFm;

/**
 * Outcome of a `user.getLovedTracks` sync: number of loved tracks pulled
 * from the API, how many had at least one matching scrobble in the local
 * DB (the rest are loved-but-never-scrobbled), and the total number of
 * `scrobbles.loved` rows newly flipped from 0 to 1.
 */
final class LovedSyncReport
{
    public int $fetched = 0;
    public int $matched = 0;
    public int $unmatched = 0;
    public int $updatedRows = 0;
}
