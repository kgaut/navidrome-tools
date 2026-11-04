<?php

namespace App\Docker;

use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Is Navidrome up? Docker-independent probe (issue #296): any HTTP answer on
 * NAVIDROME_URL, whatever its status, means the server is running and holds
 * navidrome.db open. Used when NAVIDROME_CONTAINER_NAME is empty, where the
 * container state cannot be read — before, every write guard then let
 * writes through with Navidrome running.
 */
class NavidromeLiveness
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $baseUrl,
        private readonly float $timeoutSeconds = 3.0,
    ) {
    }

    /**
     * @return bool|null true = answered, false = no answer (connection
     *                   refused, unknown host, timeout), null = no URL
     *                   configured, cannot tell
     */
    public function isUp(): ?bool
    {
        if (trim($this->baseUrl) === '') {
            return null;
        }

        try {
            $this->httpClient->request('GET', rtrim($this->baseUrl, '/') . '/rest/ping', [
                'timeout' => $this->timeoutSeconds,
                'max_duration' => $this->timeoutSeconds,
            ])->getStatusCode();

            return true;
        } catch (TransportExceptionInterface) {
            return false;
        }
    }
}
