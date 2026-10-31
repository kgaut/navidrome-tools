<?php

namespace App\Exception;

/**
 * A refusal the user can act on: invalid input, a name already taken, an
 * unknown id… Its message is written for the user and is safe to show as is
 * (no secret, no stack). The web UI turns it into a flash message, the MCP
 * server into a readable tool error (issue #276).
 */
class UserFacingException extends \DomainException
{
}
