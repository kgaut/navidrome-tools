<?php

namespace App\LastFm;

class SyncReport
{
    public int $lovedCount = 0;
    public int $starredCount = 0;
    public int $commonCount = 0;

    /**
     * media_file_ids that were starred in Navidrome because the
     * corresponding track was loved on Last.fm but not yet starred.
     *
     * @var list<array{media_file_id: string, artist: string, title: string}>
     */
    public array $starredAdded = [];

    /**
     * (artist, title) pairs that were loved on Last.fm because the
     * corresponding track was starred in Navidrome but not yet loved.
     *
     * @var list<array{artist: string, title: string}>
     */
    public array $lovedAdded = [];

    /**
     * Loved-on-Last.fm tracks that we couldn't resolve to a media_file
     * (no MBID match + no artist+title match). Listed with their loved
     * date so the user can manually map them via #18 aliases.
     *
     * @var list<array{artist: string, title: string, mbid: ?string, loved_at: ?\DateTimeImmutable}>
     */
    public array $lovedUnmatched = [];

    /**
     * Soft errors collected during the run (e.g., Last.fm rate-limit
     * on a single track.love). The whole sync does NOT abort on one
     * failure — we record and continue.
     *
     * @var list<array{action: string, artist: string, title: string, error: string}>
     */
    public array $errors = [];
}
