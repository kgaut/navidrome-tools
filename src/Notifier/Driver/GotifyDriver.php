<?php

namespace App\Notifier\Driver;

use App\Notifier\Notification;
use App\Notifier\NotifierDriverInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class GotifyDriver implements NotifierDriverInterface
{
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $url,
        private readonly string $token,
        private readonly int $priority = 5,
    ) {
    }

    public function getName(): string
    {
        return 'gotify';
    }

    public function isConfigured(): bool
    {
        return $this->url !== '' && $this->token !== '';
    }

    public function send(Notification $notification): void
    {
        $endpoint = rtrim($this->url, '/') . '/message?token=' . rawurlencode($this->token);

        $this->http->request('POST', $endpoint, [
            'headers' => ['content-type' => 'application/json'],
            'json' => [
                'title' => $notification->title(),
                'message' => $notification->summary(),
                'priority' => $notification->isError() ? max($this->priority, 8) : $this->priority,
            ],
            'timeout' => 10,
        ])->getStatusCode();
    }
}
