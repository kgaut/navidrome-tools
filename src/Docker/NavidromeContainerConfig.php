<?php

namespace App\Docker;

final class NavidromeContainerConfig
{
    public function __construct(
        public readonly string $containerName,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->containerName !== '';
    }
}
