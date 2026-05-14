<?php

namespace App\Docker;

final class NavidromeContainerConfig
{
    public function __construct(
        public readonly string $containerName,
        public readonly int $stopTimeoutSeconds = 60,
        public readonly int $stopWaitCeilingSeconds = 30,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->containerName !== '';
    }
}
