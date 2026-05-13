<?php

namespace App\Tests\Notifier\Driver;

use App\Entity\RunHistory;
use App\Notifier\Driver\PushoverDriver;
use App\Notifier\Notification;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class PushoverDriverTest extends TestCase
{
    public function testIsConfiguredRequiresTokenAndUser(): void
    {
        $http = new MockHttpClient([]);

        $this->assertFalse((new PushoverDriver($http, '', ''))->isConfigured());
        $this->assertFalse((new PushoverDriver($http, 'tok', ''))->isConfigured());
        $this->assertFalse((new PushoverDriver($http, '', 'usr'))->isConfigured());
        $this->assertTrue((new PushoverDriver($http, 'tok', 'usr'))->isConfigured());
    }

    public function testSendPostsFormBodyToOfficialEndpoint(): void
    {
        $captured = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured) {
            parse_str($options['body'] ?? '', $form);
            $captured = [
                'method' => $method,
                'url' => $url,
                'form' => $form,
            ];

            return new MockResponse('{"status":1}', ['http_code' => 200]);
        });

        $driver = new PushoverDriver($http, 'app-token', 'user-key');
        $driver->send(new Notification('stats', 'Stats compute', RunHistory::STATUS_SUCCESS, 200));

        $this->assertSame('POST', $captured['method']);
        $this->assertSame('https://api.pushover.net/1/messages.json', $captured['url']);
        $this->assertSame('app-token', $captured['form']['token']);
        $this->assertSame('user-key', $captured['form']['user']);
        $this->assertSame('[OK] Stats compute', $captured['form']['title']);
        $this->assertSame('0', $captured['form']['priority']);
    }

    public function testErrorRaisesPriorityToOne(): void
    {
        $captured = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured) {
            parse_str($options['body'] ?? '', $form);
            $captured['form'] = $form;

            return new MockResponse('{"status":1}', ['http_code' => 200]);
        });

        $driver = new PushoverDriver($http, 'app-token', 'user-key');
        $driver->send(new Notification('lastfm-process', 'Process', RunHistory::STATUS_ERROR, 100, errorMessage: 'oops'));

        $this->assertSame('1', $captured['form']['priority']);
    }
}
