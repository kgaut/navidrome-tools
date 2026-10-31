<?php

namespace App\Controller\OAuth;

use App\OAuth\OAuthScopes;
use App\OAuth\RedirectUriPolicy;
use League\Bundle\OAuth2ServerBundle\Manager\ClientManagerInterface;
use League\Bundle\OAuth2ServerBundle\Model\Client;
use League\Bundle\OAuth2ServerBundle\ValueObject\Grant;
use League\Bundle\OAuth2ServerBundle\ValueObject\RedirectUri;
use League\Bundle\OAuth2ServerBundle\ValueObject\Scope;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\PasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * OAuth dynamic client registration (RFC 7591) for the MCP connector
 * (issue #276). Anonymous by nature, hence narrowed down:
 *
 * - only `authorization_code` and `refresh_token` grants, compared strictly
 *   (a non-string item is refused, not cast);
 * - only `response_types: [code]`;
 * - redirect URIs from {@see RedirectUriPolicy} only;
 * - 10 requests per hour per IPv4 address or IPv6 /64 prefix, refused or
 *   not; beyond, 429 with Retry-After.
 *
 * A registered client still obtains nothing without the user approving the
 * consent screen behind the login form.
 */
class OAuthRegistrationController
{
    private const AUTH_METHODS = ['client_secret_basic', 'client_secret_post', 'none'];
    private const MAX_NAME_LENGTH = 100;
    private const MAX_REDIRECT_URIS = 10;

    public function __construct(
        private readonly ClientManagerInterface $clients,
        #[Autowire(service: 'league.oauth2_server.password_hasher')]
        private readonly PasswordHasherInterface $secretHasher,
        private readonly RedirectUriPolicy $redirectUris,
        #[Autowire(service: 'limiter.oauth_registration')]
        private readonly RateLimiterFactoryInterface $limiter,
    ) {
    }

    #[Route('/oauth/register', name: 'oauth_register', methods: ['POST'])]
    public function register(Request $request): JsonResponse
    {
        $limit = $this->limiter->create(self::rateKey((string) $request->getClientIp()))->consume();
        if (!$limit->isAccepted()) {
            $response = $this->error('too_many_requests', 'Trop de demandes d\'enregistrement, réessayer plus tard.', Response::HTTP_TOO_MANY_REQUESTS);
            $response->headers->set('Retry-After', (string) max(1, $limit->getRetryAfter()->getTimestamp() - time()));

            return $response;
        }

        try {
            $data = json_decode($request->getContent(), true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->error('invalid_client_metadata', 'Corps JSON invalide.');
        }
        if (!is_array($data)) {
            return $this->error('invalid_client_metadata', 'Un objet JSON est attendu.');
        }

        $redirectUris = $data['redirect_uris'] ?? null;
        if (!is_array($redirectUris) || $redirectUris === [] || count($redirectUris) > self::MAX_REDIRECT_URIS) {
            return $this->error('invalid_redirect_uri', sprintf('redirect_uris : liste de 1 à %d URI attendue.', self::MAX_REDIRECT_URIS));
        }
        foreach ($redirectUris as $uri) {
            if (!is_string($uri) || !$this->redirectUris->isAllowed($uri)) {
                return $this->error('invalid_redirect_uri', sprintf('Adresse de retour non autorisée : %s.', is_string($uri) ? $uri : get_debug_type($uri)));
            }
        }

        $grantTypes = $data['grant_types'] ?? OAuthScopes::GRANTS;
        if (!is_array($grantTypes) || $grantTypes === []) {
            return $this->error('invalid_client_metadata', 'grant_types : liste attendue.');
        }
        foreach ($grantTypes as $grant) {
            if (!is_string($grant) || !in_array($grant, OAuthScopes::GRANTS, true)) {
                return $this->error('invalid_client_metadata', 'grant_types : seuls authorization_code et refresh_token sont acceptés.');
            }
        }

        $responseTypes = $data['response_types'] ?? ['code'];
        if ($responseTypes !== ['code']) {
            return $this->error('invalid_client_metadata', 'response_types : seul « code » est accepté.');
        }

        $authMethod = $data['token_endpoint_auth_method'] ?? 'client_secret_basic';
        if (!is_string($authMethod) || !in_array($authMethod, self::AUTH_METHODS, true)) {
            return $this->error('invalid_client_metadata', 'token_endpoint_auth_method non pris en charge.');
        }

        $name = $data['client_name'] ?? 'Client MCP';
        if (!is_string($name) || trim($name) === '') {
            $name = 'Client MCP';
        }
        $name = mb_substr(trim($name), 0, self::MAX_NAME_LENGTH);

        $identifier = bin2hex(random_bytes(16));
        $secret = $authMethod === 'none' ? null : bin2hex(random_bytes(32));

        $client = new Client($name, $identifier, $secret !== null ? $this->secretHasher->hash($secret) : null);
        $client->setRedirectUris(...array_map(static fn (string $u): RedirectUri => new RedirectUri($u), array_values(array_unique($redirectUris))));
        $client->setGrants(...array_map(static fn (string $g): Grant => new Grant($g), array_values(array_unique($grantTypes))));
        $client->setScopes(...array_map(static fn (string $s): Scope => new Scope($s), OAuthScopes::ALL));
        $client->setAllowPlainTextPkce(false);
        $client->setActive(true);
        $this->clients->save($client);

        $body = [
            'client_id' => $identifier,
            'client_id_issued_at' => time(),
            'client_name' => $name,
            'redirect_uris' => array_values(array_unique($redirectUris)),
            'grant_types' => array_values(array_unique($grantTypes)),
            'response_types' => ['code'],
            'token_endpoint_auth_method' => $authMethod,
            'scope' => implode(' ', OAuthScopes::ALL),
        ];
        if ($secret !== null) {
            $body['client_secret'] = $secret;
            $body['client_secret_expires_at'] = 0;
        }

        return $this->noStore(new JsonResponse($body, Response::HTTP_CREATED));
    }

    /**
     * Rate-limit key: the IPv4 address (an IPv4-mapped IPv6 counts as the
     * IPv4), or the /64 prefix of an IPv6 address — a subscriber gets at
     * least a /64, counting per address would let one rotate through it.
     */
    public static function rateKey(string $ip): string
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return 'invalid';
        }
        if (strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")) {
            return (string) inet_ntop(substr($packed, 12));
        }
        if (strlen($packed) === 16) {
            return (string) inet_ntop(substr($packed, 0, 8) . str_repeat("\0", 8)) . '/64';
        }

        return $ip;
    }

    private function error(string $code, string $description, int $status = Response::HTTP_BAD_REQUEST): JsonResponse
    {
        return $this->noStore(new JsonResponse(['error' => $code, 'error_description' => $description], $status));
    }

    private function noStore(JsonResponse $response): JsonResponse
    {
        $response->setEncodingOptions(JsonResponse::DEFAULT_ENCODING_OPTIONS | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
