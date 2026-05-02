<?php

namespace App\Service;

/**
 * Build an M3U Extended playlist body from track data. Used both by the
 * Navidrome playlist detail page (export real Subsonic playlists) and by
 * the PlaylistDefinition preview (export the dry-run track list before
 * creating the playlist on Navidrome).
 *
 * The exporter intentionally takes plain arrays so any source — Subsonic
 * `getPlaylist.view`, NavidromeRepository::summarize(), or generator
 * preview — can feed it without coupling to a specific class.
 */
final class M3uExporter
{
    /**
     * @param iterable<array{title?: string, artist?: string, duration?: int, path?: string}|object> $tracks
     *   Each item may be an array (Subsonic shape) or an object with the
     *   same field names readable as public properties (TrackSummary etc.).
     */
    public function export(iterable $tracks): string
    {
        $lines = ['#EXTM3U'];
        foreach ($tracks as $t) {
            $title = self::field($t, 'title');
            $artist = self::field($t, 'artist');
            $duration = (int) (self::field($t, 'duration') ?: 0);
            $path = (string) (self::field($t, 'path') ?: '');

            $label = trim($artist . ' - ' . $title, ' -');
            // EXTINF: comma is the field separator, so drop literal commas
            // from the label to keep the line parseable by VLC/mpv.
            $label = str_replace(',', '', $label);
            $lines[] = '#EXTINF:' . $duration . ',' . $label;
            $lines[] = $path !== '' ? $path : ($title !== '' ? $title : 'unknown');
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * Build a filename-safe slug for the playlist filename.
     */
    public function filenameFor(string $playlistName): string
    {
        $slug = preg_replace('/[^A-Za-z0-9._-]+/', '-', $playlistName) ?? 'playlist';
        $slug = trim($slug, '-');
        if ($slug === '') {
            $slug = 'playlist';
        }

        return $slug . '.m3u';
    }

    private static function field(mixed $row, string $name): mixed
    {
        if (is_array($row)) {
            return $row[$name] ?? null;
        }
        if (is_object($row) && isset($row->{$name})) {
            return $row->{$name};
        }

        return null;
    }
}
