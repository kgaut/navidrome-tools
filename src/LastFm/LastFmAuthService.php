<?php

namespace App\LastFm;

use App\Entity\Setting;
use App\Repository\SettingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Handles the Last.fm web-auth handshake (token → user-consent →
 * session-key) and persists the resulting `sk` in the `setting` table so
 * subsequent authenticated calls (`track.love`, `track.unlove`…) can
 * reuse it.
 *
 * @see https://www.last.fm/api/webauth
 */
class LastFmAuthService
{
    private const API_BASE = 'https://ws.audioscrobbler.com/2.0/';
    private const AUTH_BASE = 'https://www.last.fm/api/auth/';

    public const SETTING_SESSION_KEY = 'lastfm.session_key';
    public const SETTING_SESSION_USER = 'lastfm.session_user';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly SettingRepository $settingRepository,
        private readonly EntityManagerInterface $em,
        private readonly string $apiKey,
        private readonly string $apiSecret,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '' && $this->apiSecret !== '';
    }

    public function getStoredSessionKey(): ?string
    {
        $row = $this->settingRepository->findOneByKey(self::SETTING_SESSION_KEY);
        $value = $row?->getValue();

        return $value !== null && $value !== '' ? $value : null;
    }

    public function getStoredSessionUser(): ?string
    {
        $row = $this->settingRepository->findOneByKey(self::SETTING_SESSION_USER);
        $value = $row?->getValue();

        return $value !== null && $value !== '' ? $value : null;
    }

    /**
     * Step 1: ask Last.fm for a fresh request token (lifetime 60 minutes).
     */
    public function getRequestToken(): string
    {
        $body = $this->call([
            'method' => 'auth.getToken',
            'api_key' => $this->apiKey,
            'format' => 'json',
        ]);
        $token = (string) ($body['token'] ?? '');
        if ($token === '') {
            throw new \RuntimeException('Last.fm auth.getToken returned an empty token.');
        }

        return $token;
    }

    /**
     * Step 2: build the URL the user must visit to grant access. After
     * approving, Last.fm redirects to $callbackUrl (which must include the
     * token in its query string — Last.fm does this automatically).
     */
    public function buildAuthorizeUrl(string $token, string $callbackUrl): string
    {
        return self::AUTH_BASE . '?' . http_build_query([
            'api_key' => $this->apiKey,
            'token' => $token,
            'cb' => $callbackUrl,
        ]);
    }

    /**
     * Step 3: exchange the now-authorized token for a long-lived session
     * key. Persists `sk` + the resolved username in `setting`.
     */
    public function exchangeTokenForSession(string $token): void
    {
        $params = [
            'method' => 'auth.getSession',
            'api_key' => $this->apiKey,
            'token' => $token,
        ];
        $params['api_sig'] = LastFmApiSigner::sign($params, $this->apiSecret);
        $params['format'] = 'json';

        $body = $this->call($params);
        $sessionKey = (string) ($body['session']['key'] ?? '');
        $sessionUser = (string) ($body['session']['name'] ?? '');
        if ($sessionKey === '' || $sessionUser === '') {
            throw new \RuntimeException('Last.fm auth.getSession returned an incomplete session payload.');
        }

        $this->setSetting(self::SETTING_SESSION_KEY, $sessionKey);
        $this->setSetting(self::SETTING_SESSION_USER, $sessionUser);
    }

    public function clearStoredSession(): void
    {
        foreach ([self::SETTING_SESSION_KEY, self::SETTING_SESSION_USER] as $key) {
            $row = $this->settingRepository->findOneByKey($key);
            if ($row !== null) {
                $this->em->remove($row);
            }
        }
        $this->em->flush();
    }

    /**
     * @param array<string, scalar> $params
     *
     * @return array<string, mixed>
     */
    private function call(array $params): array
    {
        try {
            $response = $this->httpClient->request('GET', self::API_BASE, [
                'query' => $params,
                'timeout' => 30,
            ]);
            $body = $response->toArray(throw: true);
        } catch (\Throwable $e) {
            throw new \RuntimeException(sprintf(
                'Last.fm API call failed (method=%s): %s',
                $params['method'] ?? '?',
                $e->getMessage(),
            ), 0, $e);
        }

        if (isset($body['error'])) {
            throw new \RuntimeException(sprintf(
                'Last.fm API error %s: %s',
                $body['error'],
                $body['message'] ?? 'unknown',
            ));
        }

        return $body;
    }

    private function setSetting(string $key, string $value): void
    {
        $row = $this->settingRepository->findOneByKey($key);
        if ($row === null) {
            $this->em->persist(new Setting($key, $value));
        } else {
            $row->setValue($value);
        }
        $this->em->flush();
    }
}
