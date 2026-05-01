<?php

namespace App\Lidarr;

final class LidarrConfig
{
    public function __construct(
        public readonly string $url,
        #[\SensitiveParameter] public readonly string $apiKey,
        public readonly string $rootFolderPath,
        public readonly int $qualityProfileId,
        public readonly int $metadataProfileId,
        public readonly string $monitor,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->url !== '' && $this->apiKey !== '';
    }
}
