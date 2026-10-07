<?php

namespace App\AudioMuse;

/**
 * AudioMuse-AI refused the call (HTTP 401/403): wrong or missing API token.
 * A configuration error, never to be swallowed like a per-track miss.
 */
class AudioMuseAuthException extends AudioMuseException
{
}
