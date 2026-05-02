<?php

namespace App\Controller;

use App\Service\CoverArtCache;
use App\Subsonic\SubsonicClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Proxy that serves cover art from Navidrome behind a local disk cache.
 * Returns the cached file when present, otherwise fetches via Subsonic
 * `getCoverArt`, persists, and returns. Subsonic errors degrade to a
 * 404 so the template can swap in its initials fallback.
 */
class CoverArtController extends AbstractController
{
    private const DEFAULT_SIZE = 128;
    private const MAX_SIZE = 1024;
    private const MIN_SIZE = 1;

    public function __construct(
        private readonly CoverArtCache $cache,
        private readonly SubsonicClient $subsonic,
    ) {
    }

    #[Route(
        '/cover/{type}/{id}.jpg',
        name: 'app_cover',
        requirements: ['type' => 'album|artist|song', 'id' => '[A-Za-z0-9_-]+'],
        methods: ['GET'],
    )]
    public function show(string $type, string $id, Request $request): Response
    {
        $size = $this->clampSize((int) $request->query->get('size', (string) self::DEFAULT_SIZE));

        try {
            $path = $this->cache->get($type, $id, $size);
        } catch (\InvalidArgumentException) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        if ($path === null) {
            try {
                $bytes = $this->subsonic->fetchCoverArt($id, $size);
                $path = $this->cache->store($type, $id, $size, $bytes);
            } catch (\Throwable) {
                return new Response('', Response::HTTP_NOT_FOUND);
            }
        }

        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', 'image/jpeg');
        $response->setPublic();
        $response->setMaxAge(86400);
        $response->setSharedMaxAge(86400);

        return $response;
    }

    private function clampSize(int $size): int
    {
        if ($size < self::MIN_SIZE) {
            return self::DEFAULT_SIZE;
        }
        if ($size > self::MAX_SIZE) {
            return self::MAX_SIZE;
        }

        return $size;
    }
}
