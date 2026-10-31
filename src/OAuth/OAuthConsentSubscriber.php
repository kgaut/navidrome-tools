<?php

namespace App\OAuth;

use League\Bundle\OAuth2ServerBundle\Event\AuthorizationRequestResolveEvent;
use League\Bundle\OAuth2ServerBundle\OAuth2Events;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

/**
 * Consent screen of the OAuth authorization endpoint (issue #276).
 *
 * `/authorize` sits behind the login form (main firewall). On GET, the
 * logged-in user sees the client name, the scopes and the host the code
 * will be sent to; the form posts back to the same URL. On POST with a
 * valid CSRF token, the decision is applied. PKCE with S256 is required
 * from every client (OAuth 2.1), confidential ones included.
 */
class OAuthConsentSubscriber implements EventSubscriberInterface
{
    public const CSRF_ID = 'oauth_consent';

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly CsrfTokenManagerInterface $csrf,
        private readonly Environment $twig,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [OAuth2Events::AUTHORIZATION_REQUEST_RESOLVE => 'onAuthorizationRequest'];
    }

    public function onAuthorizationRequest(AuthorizationRequestResolveEvent $event): void
    {
        if ($event->getCodeChallenge() === null || $event->getCodeChallengeMethod() !== 'S256') {
            $event->resolveAuthorization(AuthorizationRequestResolveEvent::AUTHORIZATION_DENIED);

            return;
        }

        $request = $this->requestStack->getCurrentRequest();
        if ($request !== null && $request->isMethod('POST')) {
            $tokenValid = $this->csrf->isTokenValid(new CsrfToken(self::CSRF_ID, (string) $request->request->get('_token')));
            $event->resolveAuthorization(
                $tokenValid && $request->request->get('decision') === 'approve'
                    ? AuthorizationRequestResolveEvent::AUTHORIZATION_APPROVED
                    : AuthorizationRequestResolveEvent::AUTHORIZATION_DENIED,
            );

            return;
        }

        $redirectUri = $event->getRedirectUri() ?? (string) ($event->getClient()->getRedirectUris()[0] ?? '');
        $event->setResponse(new Response($this->twig->render('oauth/consent.html.twig', [
            'client_name' => $event->getClient()->getName(),
            'scopes' => array_map(static fn ($s): string => (string) $s, $event->getScopes()),
            'redirect_host' => parse_url($redirectUri, PHP_URL_HOST) ?: $redirectUri,
        ])));
    }
}
