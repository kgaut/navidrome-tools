<?php

namespace App\OAuth;

/**
 * OAuth scopes and grants of the MCP server (issue #276). `mcp` reads,
 * `mcp_write` also allows the write tools; the OAuth bundle exposes them
 * as roles `ROLE_OAUTH2_MCP` and `ROLE_OAUTH2_MCP_WRITE`.
 */
final class OAuthScopes
{
    public const READ = 'mcp';
    public const WRITE = 'mcp_write';
    public const ALL = [self::READ, self::WRITE];

    public const GRANTS = ['authorization_code', 'refresh_token'];
}
