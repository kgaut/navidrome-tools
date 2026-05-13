<?php

namespace App\Tests\Notifier\Driver;

use App\Entity\RunHistory;
use App\Notifier\Driver\GotifyDriver;
use App\Notifier\Notification;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class GotifyDriverTest extends TestCase
{
    public function testIsConfiguredRequiresUrlAndToken(): void
    {
        $http = new MockHttpClient([]);

        $this->assertFalse((new GotifyDriver($http, '', ''))->isConfigured());
        $this->assertFalse((new GotifyDriver($http, 'https://g.example.com', ''))->isConfigured());
        $this->assertFalse((new GotifyDriver($http, '', 'token'))->isConfigured());
        $this->assertTrue((new GotifyDriver($http, 'https://g.example.com', 'token'))->isConfigured());
    }

    public function testSendPostsJsonWithTokenInQuery(): void
    {
        $captured = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured) {
            $captured = [
                'method' => $method,
                'url' => $url,
                'body' => json_decode($options['body'] ?? '{}', true),
            ];

            return new MockResponse('{}', ['http_code' => 200]);
        });

        $driver = new GotifyDriver($http, 'https://g.example.com/', 'tok en', 5);
        $driver->send(new Notification('lastfm-fetch', 'Test', RunHistory::STATUS_SUCCESS, 1500));

        $this->assertSame('POST', $captured['method']);
        $this->assertSame('https://g.example.com/message?token=tok%20en', $captured['url']);
        $this->assertSame('[OK] Test', $captured['body']['title']);
        $this->assertStringContainsString('Type: lastfm-fetch', $captured['body']['message']);
        $this->assertSame(5, $captured['body']['priority']);
    }

    public function testErrorBumpsPriorityToAtLeastEight(): void
    {
        $captured = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured) {
            $captured['body'] = json_decode($options['body'] ?? '{}', true);

            return new MockResponse('{}', ['http_code' => 200]);
        });

        $driver = new GotifyDriver($http, 'https://g.example.com', 'tk', 3);
        $driver->send(new Notification('lastfm-process', 'Process', RunHistory::STATUS_ERROR, 100, errorMessage: 'boom'));

        $this->assertSame(8, $captured['body']['priority']);
        $this->assertStringStartsWith('[ERROR]', $captured['body']['title']);
    }
}
