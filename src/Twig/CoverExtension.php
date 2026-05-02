<?php

namespace App\Twig;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class CoverExtension extends AbstractExtension
{
    public function __construct(private readonly UrlGeneratorInterface $urlGenerator)
    {
    }

    /**
     * @return TwigFunction[]
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('cover_url', $this->coverUrl(...)),
        ];
    }

    /**
     * Build the URL the browser should hit for an album/artist cover.
     * Empty id → empty string ; the macro falls through to its initials
     * fallback without round-tripping a 404.
     */
    public function coverUrl(string $type, ?string $id, int $size = 128): string
    {
        if ($id === null || $id === '' || preg_match('/^[A-Za-z0-9_-]+$/', $id) !== 1) {
            return '';
        }

        return $this->urlGenerator->generate('app_cover', [
            'type' => $type,
            'id' => $id,
            'size' => $size,
        ]);
    }
}
