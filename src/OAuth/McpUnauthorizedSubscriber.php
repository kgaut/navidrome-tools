<?php

namespace App\OAuth;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Every 401 from `/mcp` (no token, expired or invalid token) carries
 * `WWW-Authenticate: Bearer resource_metadata="…"` (RFC 9728): that header
 * is what sends an MCP client into the OAuth flow (issue #276).
 */
class McpUnauthorizedSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly PublicUrl $publicUrl)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => 'onResponse'];
    }

    public function onResponse(ResponseEvent $event): void
    {
        $response = $event->getResponse();
        if ($response->getStatusCode() !== 401 || !str_starts_with($event->getRequest()->getPathInfo(), '/mcp')) {
            return;
        }

        $response->headers->set('WWW-Authenticate', sprintf(
            'Bearer resource_metadata="%s"',
            $this->publicUrl->to('/.well-known/oauth-protected-resource'),
        ));
    }
}
