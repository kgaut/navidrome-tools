<?php

namespace App\OAuth;

use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Public base URL of the instance (scheme + host [+ path prefix]), used as
 * OAuth issuer and to build the metadata URLs (issue #276).
 *
 * `APP_PUBLIC_URL` wins when set: the issuer must be stable whatever proxy
 * or Host header the request came through. Otherwise the current request's
 * scheme and host are used, which requires `TRUSTED_PROXIES` behind a
 * reverse proxy terminating TLS.
 */
class PublicUrl
{
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly string $configured = '',
    ) {
    }

    public function base(): string
    {
        $configured = rtrim(trim($this->configured), '/');
        if ($configured !== '') {
            return $configured;
        }
        $request = $this->requestStack->getMainRequest();

        return $request !== null ? rtrim($request->getSchemeAndHttpHost() . $request->getBasePath(), '/') : '';
    }

    public function to(string $path): string
    {
        return $this->base() . '/' . ltrim($path, '/');
    }
}
