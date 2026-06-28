<?php

namespace App\Playlist;

use App\Subsonic\SubsonicClient;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Orchestrates the playlist-definition plugins: discovers every service
 * tagged `app.playlist_definition`, decides which ones run, builds their
 * track lists and writes them to Navidrome via Subsonic. Mirrors
 * {@see \App\Notifier\Notifier} (tagged iterator + CSV enable list +
 * best-effort per-item isolation).
 *
 * Enablement model:
 *   - `PLAYLISTS_ENABLED` (CSV of slugs) gates the « generate all » run
 *     (`generate(null)`), so a fresh install ships nothing until opted in.
 *   - An explicit `generate($slug)` (CLI `--slug`, per-row UI button)
 *     bypasses the CSV — generating one by name is an explicit intent.
 *
 * Idempotent write: a playlist of the same name owned by the configured
 * user is overwritten in place; otherwise a new one is created.
 */
class PlaylistGenerator
{
    /** @var list<PlaylistDefinitionInterface> */
    private readonly array $definitions;

    /** @var list<string> */
    private readonly array $enabledSlugs;

    /**
     * @param iterable<PlaylistDefinitionInterface> $definitions
     */
    public function __construct(
        iterable $definitions,
        string $enabledCsv,
        private readonly SubsonicClient $subsonic,
        private readonly int $defaultLimit = 50,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        $this->definitions = is_array($definitions) ? array_values($definitions) : iterator_to_array($definitions, false);
        $this->enabledSlugs = self::parseCsv($enabledCsv);
    }

    /**
     * @return list<array{slug: string, name: string, description: string, enabled: bool}>
     */
    public function listDefinitions(): array
    {
        $out = [];
        foreach ($this->definitions as $def) {
            $out[] = [
                'slug' => $def->getSlug(),
                'name' => $def->getName(),
                'description' => $def->getDescription(),
                'enabled' => in_array($def->getSlug(), $this->enabledSlugs, true),
            ];
        }

        return $out;
    }

    /**
     * Generate playlists and (unless dry-run) write them to Navidrome.
     *
     * @param ?string $slug   null → every ENABLED definition ; a slug →
     *                        that one definition regardless of the CSV.
     *
     * @return list<PlaylistRunResult>
     *
     * @throws \InvalidArgumentException when an explicit slug is unknown
     */
    public function generate(?string $slug, bool $dryRun): array
    {
        $targets = $this->resolveTargets($slug);
        $context = new PlaylistContext(new \DateTimeImmutable(), $this->defaultLimit);

        $results = [];
        foreach ($targets as $def) {
            try {
                $results[] = $this->runOne($def, $context, $dryRun);
            } catch (\Throwable $e) {
                // One broken algorithm never blocks the others.
                $this->logger->error('Playlist definition "{slug}" failed: {message}', [
                    'slug' => $def->getSlug(),
                    'message' => $e->getMessage(),
                    'exception' => $e,
                ]);
                $results[] = new PlaylistRunResult(
                    slug: $def->getSlug(),
                    name: $def->getName(),
                    action: PlaylistRunResult::ACTION_ERROR,
                    error: $e->getMessage(),
                );
            }
        }

        return $results;
    }

    private function runOne(PlaylistDefinitionInterface $def, PlaylistContext $context, bool $dryRun): PlaylistRunResult
    {
        $ids = array_values($def->build($context));

        if ($dryRun) {
            return new PlaylistRunResult($def->getSlug(), $def->getName(), PlaylistRunResult::ACTION_DRY_RUN, $ids);
        }
        if ($ids === []) {
            // Don't overwrite an existing playlist with an empty list — a
            // transient empty result (e.g. no recent scrobbles) shouldn't
            // wipe what's there.
            return new PlaylistRunResult($def->getSlug(), $def->getName(), PlaylistRunResult::ACTION_EMPTY);
        }

        $existing = $this->subsonic->findPlaylistByName($def->getName());
        if ($existing !== null) {
            $this->subsonic->replacePlaylist($existing['id'], $def->getName(), $ids);
            $this->stampDescription($existing['id'], $def);

            return new PlaylistRunResult(
                $def->getSlug(),
                $def->getName(),
                PlaylistRunResult::ACTION_REPLACED,
                $ids,
                $existing['id'],
            );
        }

        $newId = $this->subsonic->createPlaylist($def->getName(), $ids);
        $this->stampDescription($newId, $def);

        return new PlaylistRunResult($def->getSlug(), $def->getName(), PlaylistRunResult::ACTION_CREATED, $ids, $newId);
    }

    /**
     * Write the definition's description onto the Navidrome playlist as its
     * comment, so a listener can see how the playlist is computed. Subsonic
     * `createPlaylist` can't carry a comment, hence this follow-up call.
     */
    private function stampDescription(string $playlistId, PlaylistDefinitionInterface $def): void
    {
        $description = $def->getDescription();
        if ($description !== '') {
            $this->subsonic->updatePlaylist($playlistId, comment: $description);
        }
    }

    /**
     * @return list<PlaylistDefinitionInterface>
     */
    private function resolveTargets(?string $slug): array
    {
        if ($slug !== null) {
            foreach ($this->definitions as $def) {
                if ($def->getSlug() === $slug) {
                    return [$def];
                }
            }

            throw new \InvalidArgumentException(sprintf('Unknown playlist definition "%s".', $slug));
        }

        return array_values(array_filter(
            $this->definitions,
            fn (PlaylistDefinitionInterface $d): bool => in_array($d->getSlug(), $this->enabledSlugs, true),
        ));
    }

    /**
     * @return list<string>
     */
    private static function parseCsv(string $csv): array
    {
        $slugs = [];
        foreach (explode(',', $csv) as $raw) {
            $slug = strtolower(trim($raw));
            if ($slug !== '') {
                $slugs[] = $slug;
            }
        }

        return array_values(array_unique($slugs));
    }
}
