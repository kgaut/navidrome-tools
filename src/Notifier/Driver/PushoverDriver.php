<?php

namespace App\Notifier\Driver;

use App\Notifier\Notification;
use App\Notifier\NotifierDriverInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class PushoverDriver implements NotifierDriverInterface
{
    private const ENDPOINT = 'https://api.pushover.net/1/messages.json';

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $token,
        private readonly string $user,
    ) {
    }

    public function getName(): string
    {
        return 'pushover';
    }

    public function isConfigured(): bool
    {
        return $this->token !== '' && $this->user !== '';
    }

    public function send(Notification $notification): void
    {
        $this->http->request('POST', self::ENDPOINT, [
            'body' => [
                'token' => $this->token,
                'user' => $this->user,
                'title' => $notification->title(),
                'message' => $notification->summary(),
                // Pushover priority scale -2..2 ; bump to 1 on error so
                // it bypasses quiet hours but doesn't require ack.
                'priority' => $notification->isError() ? 1 : 0,
            ],
            'timeout' => 10,
        ])->getStatusCode();
    }
}
