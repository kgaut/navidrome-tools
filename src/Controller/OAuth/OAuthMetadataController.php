<?php

namespace App\Controller\OAuth;

use App\OAuth\OAuthScopes;
use App\OAuth\PublicUrl;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * OAuth discovery documents for the MCP connector (issue #276):
 * protected resource metadata (RFC 9728), which points the client at the
 * authorization server, and authorization server metadata (RFC 8414).
 * Public, outside every firewall.
 */
class OAuthMetadataController
{
    public function __construct(private readonly PublicUrl $publicUrl)
    {
    }

    #[Route('/.well-known/oauth-protected-resource', name: 'oauth_protected_resource', methods: ['GET'])]
    #[Route('/.well-known/oauth-protected-resource/mcp', name: 'oauth_protected_resource_mcp', methods: ['GET'])]
    public function protectedResource(): JsonResponse
    {
        return $this->json([
            'resource' => $this->publicUrl->to('/mcp'),
            'authorization_servers' => [$this->publicUrl->base()],
            'scopes_supported' => OAuthScopes::ALL,
            'bearer_methods_supported' => ['header'],
            'resource_name' => 'navidrome-tools',
        ]);
    }

    #[Route('/.well-known/oauth-authorization-server', name: 'oauth_authorization_server', methods: ['GET'])]
    #[Route('/.well-known/oauth-authorization-server/mcp', name: 'oauth_authorization_server_mcp', methods: ['GET'])]
    public function authorizationServer(): JsonResponse
    {
        return $this->json([
            'issuer' => $this->publicUrl->base(),
            'authorization_endpoint' => $this->publicUrl->to('/authorize'),
            'token_endpoint' => $this->publicUrl->to('/token'),
            'registration_endpoint' => $this->publicUrl->to('/oauth/register'),
            'scopes_supported' => OAuthScopes::ALL,
            'response_types_supported' => ['code'],
            'grant_types_supported' => OAuthScopes::GRANTS,
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['client_secret_basic', 'client_secret_post', 'none'],
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function json(array $data): JsonResponse
    {
        $response = new JsonResponse($data);
        $response->setEncodingOptions(JsonResponse::DEFAULT_ENCODING_OPTIONS | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $response->headers->set('Access-Control-Allow-Origin', '*');
        $response->setPublic();
        $response->setMaxAge(3600);

        return $response;
    }
}
