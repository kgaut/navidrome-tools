<?php

namespace App\Mcp;

use Mcp\Exception\ToolCallException;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Write tools are listed to every token and refused at run time when the
 * token lacks the `mcp_write` scope (issue #276) — a readable refusal,
 * nothing written. The OAuth bundle maps scope `mcp_write` to the role
 * `ROLE_OAUTH2_MCP_WRITE`.
 */
class McpWriteGuard
{
    public const WRITE_ROLE = 'ROLE_OAUTH2_MCP_WRITE';

    public function __construct(private readonly Security $security)
    {
    }

    /** @throws ToolCallException when the current token cannot write */
    public function assertCanWrite(): void
    {
        if (!$this->security->isGranted(self::WRITE_ROLE)) {
            throw new ToolCallException(
                'Écriture refusée : le jeton de ce client n\'a pas le scope « mcp_write ». Rien n\'a été modifié. '
                . 'Reconnectez le connecteur en autorisant l\'écriture.',
            );
        }
    }
}
