<?php

namespace App\Tests\Notifier\Driver;

use App\Entity\RunHistory;
use App\Notifier\Driver\SlackDriver;
use App\Notifier\Notification;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class SlackDriverTest extends TestCase
{
    public function testIsConfiguredRequiresWebhookUrl(): void
    {
        $http = new MockHttpClient([]);

        $this->assertFalse((new SlackDriver($http, ''))->isConfigured());
        $this->assertTrue((new SlackDriver($http, 'https://hooks.slack.com/services/T/B/X'))->isConfigured());
    }

    public function testSendPostsTextPayload(): void
    {
        $captured = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured) {
            $captured = [
                'method' => $method,
                'url' => $url,
                'body' => json_decode($options['body'] ?? '{}', true),
            ];

            return new MockResponse('ok', ['http_code' => 200]);
        });

        $driver = new SlackDriver($http, 'https://hooks.slack.com/services/T/B/X');
        $driver->send(new Notification('lastfm-process', 'Process', RunHistory::STATUS_ERROR, 750, errorMessage: 'kaboom'));

        $this->assertSame('POST', $captured['method']);
        $this->assertSame('https://hooks.slack.com/services/T/B/X', $captured['url']);
        $this->assertStringContainsString('*[ERROR] Process*', $captured['body']['text']);
        $this->assertStringContainsString('Error: kaboom', $captured['body']['text']);
    }
}
