<?php

namespace App\OAuth;

/**
 * Redirect URIs a dynamically registered client may declare (issue #276).
 *
 * Exact match against an allow list (claude.ai connector callbacks by
 * default, extendable with `OAUTH_ALLOWED_REDIRECT_URIS`), plus the
 * loopback callbacks of native clients such as Claude Code
 * (`http://localhost|127.0.0.1|[::1]:<port>/callback`, RFC 8252). An empty
 * allow list still accepts loopback, nothing else.
 */
class RedirectUriPolicy
{
    private const LOOPBACK = '#^http://(localhost|127\.0\.0\.1|\[::1\])(:\d{1,5})?/callback$#';

    /** @var list<string> */
    private readonly array $allowed;

    /**
     * @param list<string>|string $allowed comma-separated list or list of URIs
     */
    public function __construct(array|string $allowed = [])
    {
        $list = is_string($allowed) ? explode(',', $allowed) : $allowed;
        $this->allowed = array_values(array_filter(array_map('trim', $list), static fn (string $u): bool => $u !== ''));
    }

    public function isAllowed(string $uri): bool
    {
        return in_array($uri, $this->allowed, true) || preg_match(self::LOOPBACK, $uri) === 1;
    }
}
