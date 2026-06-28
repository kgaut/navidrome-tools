<?php

namespace App\Playlist;

use App\Subsonic\SubsonicClient;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Orchestrates the playlist-definition plugins: discovers every service
 * tagged `app.playlist_definition`, builds their track lists and writes
 * them to Navidrome via Subsonic. Mirrors {@see \App\Notifier\Notifier}
 * (tagged iterator + best-effort per-item isolation).
 *
 * `generate(null)` regenerates EVERY defined playlist; `generate($slug)`
 * just that one. (There is no enable/disable list — « generate all » means
 * all.)
 *
 * Idempotent write: a playlist of the same name owned by the configured
 * user is overwritten in place; otherwise a new one is created.
 */
class PlaylistGenerator
{
    /** @var list<PlaylistDefinitionInterface> */
    private readonly array $definitions;

    /**
     * @param iterable<PlaylistDefinitionInterface> $definitions
     */
    public function __construct(
        iterable $definitions,
        private readonly SubsonicClient $subsonic,
        private readonly int $defaultLimit = 50,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        $this->definitions = is_array($definitions) ? array_values($definitions) : iterator_to_array($definitions, false);
    }

    /**
     * @return list<array{slug: string, name: string, description: string}>
     */
    public function listDefinitions(): array
    {
        $out = [];
        foreach ($this->definitions as $def) {
            $out[] = [
                'slug' => $def->getSlug(),
                'name' => $def->getName(),
                'description' => $def->getDescription(),
            ];
        }

        return $out;
    }

    /**
     * Generate playlists and (unless dry-run) write them to Navidrome.
     *
     * @param ?string $slug   null → every defined playlist ; a slug → that
     *                        one definition.
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
        if ($slug === null) {
            return $this->definitions;
        }

        foreach ($this->definitions as $def) {
            if ($def->getSlug() === $slug) {
                return [$def];
            }
        }

        throw new \InvalidArgumentException(sprintf('Unknown playlist definition "%s".', $slug));
    }
}
