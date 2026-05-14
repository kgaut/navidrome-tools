<?php

namespace App\Notifier\Driver;

use App\Notifier\Notification;
use App\Notifier\NotifierDriverInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class SlackDriver implements NotifierDriverInterface
{
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $webhookUrl,
    ) {
    }

    public function getName(): string
    {
        return 'slack';
    }

    public function isConfigured(): bool
    {
        return $this->webhookUrl !== '';
    }

    public function send(Notification $notification): void
    {
        $text = sprintf("*%s*\n```%s```", $notification->title(), $notification->summary());

        $this->http->request('POST', $this->webhookUrl, [
            'headers' => ['content-type' => 'application/json'],
            'json' => ['text' => $text],
            'timeout' => 10,
        ])->getStatusCode();
    }
}
