<?php

namespace App\Notifier\Driver;

use App\Notifier\Notification;
use App\Notifier\NotifierDriverInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class DiscordDriver implements NotifierDriverInterface
{
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $webhookUrl,
    ) {
    }

    public function getName(): string
    {
        return 'discord';
    }

    public function isConfigured(): bool
    {
        return $this->webhookUrl !== '';
    }

    public function send(Notification $notification): void
    {
        $content = sprintf("**%s**\n```%s```", $notification->title(), $notification->summary());

        // Discord caps `content` at 2000 chars — guard against very long
        // error messages stamped into the summary.
        if (strlen($content) > 1900) {
            $content = substr($content, 0, 1893) . "…```";
        }

        $this->http->request('POST', $this->webhookUrl, [
            'headers' => ['content-type' => 'application/json'],
            'json' => ['content' => $content],
            'timeout' => 10,
        ])->getStatusCode();
    }
}
