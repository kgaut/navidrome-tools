<?php

namespace App\Tests\LastFm;

use App\LastFm\LastFmAuthService;

/**
 * Stub LastFmAuthService that returns a fixed (sk, user) pair without
 * touching the database. Used by LovedStarredSyncServiceTest to skip
 * the auth handshake.
 */
final class StubLastFmAuthService extends LastFmAuthService
{
    public function __construct(
        private readonly ?string $sk = 'SK',
        private readonly ?string $sessionUser = 'somebody',
    ) {
        // Skip parent::__construct on purpose.
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function getStoredSessionKey(): ?string
    {
        return $this->sk;
    }

    public function getStoredSessionUser(): ?string
    {
        return $this->sessionUser;
    }

    public function clearStoredSession(): void
    {
    }
}
