<?php

namespace App\Tests\Controller\OAuth;

use App\Security\EnvUser;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * End-to-end OAuth 2.1 + MCP flow (issue #276): discovery, dynamic client
 * registration, consent behind the login, code exchange with PKCE, then MCP
 * calls with the token. The test env keeps OAuth state in memory, hence a
 * single kernel for the whole flow (no reboot between requests).
 */
class McpOAuthFlowTest extends WebTestCase
{
    private const REDIRECT = 'http://localhost:33418/callback';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        static::getContainer()->get('cache.rate_limiter')->clear();
    }

    public function testMcpWithoutTokenAnswers401WithResourceMetadata(): void
    {
        $this->client->request('POST', '/mcp', server: ['CONTENT_TYPE' => 'application/json'], content: '{}');

        $response = $this->client->getResponse();
        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame(
            'Bearer resource_metadata="http://localhost/.well-known/oauth-protected-resource"',
            $response->headers->get('WWW-Authenticate'),
        );
    }

    public function testMcpRefusesSessionCookie(): void
    {
        // Reboot between requests so only the session cookie carries over,
        // as in production (no token left in memory by loginUser()).
        $this->client->enableReboot();
        $this->client->request('GET', '/login');
        $this->client->loginUser(new EnvUser('admin', 'x'));
        $this->client->request('POST', '/mcp', server: ['CONTENT_TYPE' => 'application/json'], content: '{}');

        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    public function testDiscoveryDocuments(): void
    {
        $this->client->request('GET', '/.well-known/oauth-protected-resource');
        $resource = $this->json();
        $this->assertSame('http://localhost/mcp', $resource['resource']);
        $this->assertSame(['http://localhost'], $resource['authorization_servers']);

        $this->client->request('GET', '/.well-known/oauth-authorization-server');
        $server = $this->json();
        $this->assertSame('http://localhost/oauth/register', $server['registration_endpoint']);
        $this->assertSame(['S256'], $server['code_challenge_methods_supported']);
    }

    public function testFullFlowWithWriteScope(): void
    {
        $token = $this->obtainToken('mcp mcp_write');
        $session = $this->mcpInitialize($token);

        $tools = $this->mcp($token, $session, 'tools/list');
        $names = array_column($tools['result']['tools'], 'name');
        $this->assertContains('playlist_create', $names);
        $this->assertContains('library_search', $names);

        // A business refusal is a readable tool error, not a protocol error.
        $result = $this->mcp($token, $session, 'tools/call', ['name' => 'stats_daily', 'arguments' => ['from' => '2026-02-31']]);
        $this->assertTrue($result['result']['isError']);
        $this->assertStringContainsString('AAAA-MM-JJ', $result['result']['content'][0]['text']);
    }

    public function testReadOnlyTokenIsRefusedOnWriteTools(): void
    {
        $token = $this->obtainToken('mcp');
        $session = $this->mcpInitialize($token);

        $result = $this->mcp($token, $session, 'tools/call', [
            'name' => 'alias_create_artist',
            'arguments' => ['source_artist' => 'A', 'target_artist' => 'B'],
        ]);
        $this->assertTrue($result['result']['isError']);
        $this->assertStringContainsString('mcp_write', $result['result']['content'][0]['text']);
    }

    public function testAuthorizeWithoutPkceIsRefused(): void
    {
        $clientId = $this->register(['token_endpoint_auth_method' => 'client_secret_basic'])['client_id'];
        $this->client->loginUser(new EnvUser('admin', 'x'));
        $this->client->request('GET', '/authorize?' . http_build_query([
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => self::REDIRECT,
            'state' => 's',
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('error=access_denied', (string) $response->headers->get('Location'));
    }

    public function testRegistrationRefusesForeignRedirectAndOtherGrants(): void
    {
        $this->client->request('POST', '/oauth/register', content: (string) json_encode(['redirect_uris' => ['https://evil.example/cb']]));
        $this->assertSame(400, $this->client->getResponse()->getStatusCode());
        $this->assertSame('invalid_redirect_uri', $this->json()['error']);

        $this->client->request('POST', '/oauth/register', content: (string) json_encode([
            'redirect_uris' => [self::REDIRECT],
            'grant_types' => ['client_credentials'],
        ]));
        $this->assertSame('invalid_client_metadata', $this->json()['error']);

        $this->client->request('POST', '/oauth/register', content: (string) json_encode([
            'redirect_uris' => [self::REDIRECT],
            'grant_types' => [['authorization_code']],
        ]));
        $this->assertSame('invalid_client_metadata', $this->json()['error']);
    }

    public function testRegistrationIsRateLimited(): void
    {
        for ($i = 0; $i < 10; ++$i) {
            $this->client->request('POST', '/oauth/register', content: '{}');
            $this->assertSame(400, $this->client->getResponse()->getStatusCode());
        }
        $this->client->request('POST', '/oauth/register', content: '{}');

        $this->assertSame(429, $this->client->getResponse()->getStatusCode());
        $this->assertNotNull($this->client->getResponse()->headers->get('Retry-After'));
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function register(array $extra = []): array
    {
        $this->client->request('POST', '/oauth/register', content: (string) json_encode($extra + [
            'client_name' => 'Test',
            'redirect_uris' => [self::REDIRECT],
            'token_endpoint_auth_method' => 'none',
        ]));
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());

        return $this->json();
    }

    private function obtainToken(string $scope): string
    {
        $clientId = $this->register()['client_id'];
        $verifier = bin2hex(random_bytes(32));
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => self::REDIRECT,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'state' => 'xyz',
            'scope' => $scope,
        ]);

        $this->client->loginUser(new EnvUser('admin', 'x'));
        $crawler = $this->client->request('GET', '/authorize?' . $query);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertStringContainsString('Autoriser « Test »', (string) $this->client->getResponse()->getContent());

        $this->client->submit($crawler->selectButton('Autoriser')->form());
        $location = (string) $this->client->getResponse()->headers->get('Location');
        $this->assertStringStartsWith(self::REDIRECT . '?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $params);
        $this->assertSame('xyz', $params['state']);

        $this->client->request('POST', '/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $clientId,
            'redirect_uri' => self::REDIRECT,
            'code_verifier' => $verifier,
            'code' => $params['code'],
        ]);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());

        return (string) $this->json()['access_token'];
    }

    private function mcpInitialize(string $token): string
    {
        $this->client->getCookieJar()->clear();
        $this->mcp($token, null, 'initialize', [
            'protocolVersion' => '2025-06-18',
            'capabilities' => new \stdClass(),
            'clientInfo' => ['name' => 'phpunit', 'version' => '1'],
        ]);
        $session = (string) $this->client->getResponse()->headers->get('Mcp-Session-Id');
        $this->assertNotSame('', $session);

        return $session;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function mcp(string $token, ?string $session, string $method, array $params = []): array
    {
        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'HTTP_MCP_PROTOCOL_VERSION' => '2025-06-18',
        ];
        if ($session !== null) {
            $server['HTTP_MCP_SESSION_ID'] = $session;
        }
        $this->client->request('POST', '/mcp', server: $server, content: (string) json_encode([
            'jsonrpc' => '2.0',
            'id' => random_int(1, 1_000_000),
            'method' => $method,
            'params' => $params === [] ? new \stdClass() : $params,
        ]));
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        return $this->json();
    }

    /**
     * @return array<string, mixed>
     */
    private function json(): array
    {
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertIsArray($data);

        return $data;
    }
}
