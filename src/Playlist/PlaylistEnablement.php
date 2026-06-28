<?php

namespace App\Playlist;

use App\Repository\SettingRepository;

/**
 * Per-playlist on/off flag, persisted in the tools SQLite DB via the
 * generic key/value {@see SettingRepository} (key
 * `playlist.enabled.<slug>`). Drives which playlists the « generate all »
 * run touches. Toggled from the /playlists listing.
 *
 * Default = enabled: a fresh install regenerates every playlist out of the
 * box (no silent « 0 generated » surprise); the user unchecks the ones
 * they don't want.
 */
final class PlaylistEnablement
{
    private const PREFIX = 'playlist.enabled.';

    public function __construct(
        private readonly SettingRepository $settings,
    ) {
    }

    public function isEnabled(string $slug): bool
    {
        return $this->settings->get(self::PREFIX . $slug, '1') === '1';
    }

    public function setEnabled(string $slug, bool $enabled): void
    {
        $this->settings->set(self::PREFIX . $slug, $enabled ? '1' : '0');
    }
}
