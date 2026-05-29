<?php

namespace App\Service;

use App\LastFm\LastFmClient;
use App\Repository\SettingRepository;

/**
 * Storage adapter for Last.fm session keys (SK).
 *
 * Last.fm SKs returned by `auth.getMobileSession` are user-scoped and never
 * expire — we cache them in the Setting table so subsequent track.love /
 * track.unlove calls don't have to keep the password around. Keys are
 * namespaced by Last.fm username so a multi-user install can hold several.
 */
class LastFmSessionService
{
    private const SETTING_KEY_PREFIX = 'lastfm_session_key_';

    public function __construct(
        private readonly SettingRepository $settings,
        private readonly LastFmClient $client,
    ) {
    }

    public function get(string $user): ?string
    {
        $sk = $this->settings->get(self::SETTING_KEY_PREFIX . $user);

        return $sk !== '' ? $sk : null;
    }

    public function store(string $user, string $sessionKey): void
    {
        $this->settings->set(self::SETTING_KEY_PREFIX . $user, $sessionKey);
    }

    public function obtainAndStore(string $apiKey, string $apiSecret, string $user, string $password): string
    {
        $sk = $this->client->authGetMobileSession($apiKey, $apiSecret, $user, $password);
        $this->store($user, $sk);

        return $sk;
    }
}
