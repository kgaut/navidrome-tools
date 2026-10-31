<?php

namespace App\Tests\OAuth;

use App\Controller\OAuth\OAuthRegistrationController;
use App\OAuth\RedirectUriPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RedirectUriPolicyTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function uris(): iterable
    {
        yield 'claude.ai exact' => ['https://claude.ai/api/mcp/auth_callback', true];
        yield 'claude.ai with suffix' => ['https://claude.ai/api/mcp/auth_callback/x', false];
        yield 'claude.ai over http' => ['http://claude.ai/api/mcp/auth_callback', false];
        yield 'loopback localhost' => ['http://localhost:33418/callback', true];
        yield 'loopback ipv4 no port' => ['http://127.0.0.1/callback', true];
        yield 'loopback ipv6' => ['http://[::1]:8000/callback', true];
        yield 'loopback other path' => ['http://localhost:33418/evil', false];
        yield 'look-alike host' => ['http://localhost.evil.example/callback', false];
        yield 'foreign' => ['https://evil.example/cb', false];
    }

    #[DataProvider('uris')]
    public function testIsAllowed(string $uri, bool $expected): void
    {
        $policy = new RedirectUriPolicy('https://claude.ai/api/mcp/auth_callback, ,https://claude.com/api/mcp/auth_callback');

        $this->assertSame($expected, $policy->isAllowed($uri));
    }

    public function testEmptyListStillAcceptsLoopbackOnly(): void
    {
        $policy = new RedirectUriPolicy('');

        $this->assertTrue($policy->isAllowed('http://localhost:1/callback'));
        $this->assertFalse($policy->isAllowed('https://claude.ai/api/mcp/auth_callback'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function ips(): iterable
    {
        yield 'ipv4' => ['203.0.113.7', '203.0.113.7'];
        yield 'ipv4-mapped ipv6' => ['::ffff:203.0.113.7', '203.0.113.7'];
        yield 'ipv6 /64' => ['2001:db8:1:2:aaaa:bbbb:cccc:dddd', '2001:db8:1:2::/64'];
        yield 'same /64' => ['2001:db8:1:2::1', '2001:db8:1:2::/64'];
        yield 'garbage' => ['nope', 'invalid'];
    }

    #[DataProvider('ips')]
    public function testRateKey(string $ip, string $expected): void
    {
        $this->assertSame($expected, OAuthRegistrationController::rateKey($ip));
    }
}
