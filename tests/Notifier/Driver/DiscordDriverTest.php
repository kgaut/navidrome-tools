<?php

namespace App\Tests\Notifier\Driver;

use App\Entity\RunHistory;
use App\Notifier\Driver\DiscordDriver;
use App\Notifier\Notification;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class DiscordDriverTest extends TestCase
{
    public function testSendPostsContentPayload(): void
    {
        $captured = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured) {
            $captured = [
                'method' => $method,
                'url' => $url,
                'body' => json_decode($options['body'] ?? '{}', true),
            ];

            return new MockResponse('', ['http_code' => 204]);
        });

        $driver = new DiscordDriver($http, 'https://discord.com/api/webhooks/1/abc');
        $driver->send(new Notification('lastfm-fetch', 'Fetch me', RunHistory::STATUS_SUCCESS, 4321));

        $this->assertSame('POST', $captured['method']);
        $this->assertStringContainsString('**[OK] Fetch me**', $captured['body']['content']);
    }

    public function testContentIsTruncatedBelowDiscordCap(): void
    {
        $captured = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured) {
            $captured['body'] = json_decode($options['body'] ?? '{}', true);

            return new MockResponse('', ['http_code' => 204]);
        });

        $hugeError = str_repeat('A', 5000);
        $driver = new DiscordDriver($http, 'https://discord.com/api/webhooks/1/abc');
        $driver->send(new Notification('lastfm-process', 'Process', RunHistory::STATUS_ERROR, 1, errorMessage: $hugeError));

        $this->assertLessThanOrEqual(2000, strlen($captured['body']['content']));
    }
}
