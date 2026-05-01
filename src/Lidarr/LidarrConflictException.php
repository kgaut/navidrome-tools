<?php

namespace App\Lidarr;

/**
 * Thrown when Lidarr signals that the artist already exists in its library.
 */
class LidarrConflictException extends \RuntimeException
{
}
