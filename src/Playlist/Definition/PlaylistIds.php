<?php

namespace App\Playlist\Definition;

use App\Navidrome\NavidromeRepository;

/** Small list helpers shared by the audio-feature playlists (issue #257). */
final class PlaylistIds
{
    /**
     * Keep the first `$limit` ids that still exist in the library. Checks a
     * margin beyond the limit so a few vanished files don't shorten the list.
     *
     * @param list<string> $ids
     *
     * @return list<string>
     */
    public static function dropMissing(NavidromeRepository $navidrome, array $ids, int $limit): array
    {
        $head = array_slice($ids, 0, $limit * 2);
        $missing = array_fill_keys($navidrome->filterMissingMediaFileIds($head), true);

        return array_slice(array_values(array_filter($head, static fn (string $id): bool => !isset($missing[$id]))), 0, $limit);
    }
}
