<?php

namespace App\Twig;

use App\Repository\LastFmAliasRepository;
use App\Repository\LastFmArtistAliasRepository;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Small counters surfaced as badges in the sidebar navigation. Lazily
 * evaluated (only when the sidebar calls them) and defensive: a DB hiccup
 * returns 0 rather than breaking every page's chrome.
 */
class NavBadgeExtension extends AbstractExtension
{
    public function __construct(
        private readonly LastFmAliasRepository $trackAliases,
        private readonly LastFmArtistAliasRepository $artistAliases,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('alias_count', $this->aliasCount(...)),
        ];
    }

    /** Total alias count (track + artist) shown on the « Alias » nav item. */
    public function aliasCount(): int
    {
        try {
            return $this->trackAliases->count([]) + $this->artistAliases->count([]);
        } catch (\Throwable) {
            return 0;
        }
    }
}
