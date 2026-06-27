<?php

namespace App\MessageHandler;

use App\Entity\RunHistory;
use App\Message\GeneratePlaylistsMessage;
use App\Playlist\PlaylistGenerator;
use App\Playlist\PlaylistRunResult;
use App\Service\RunHistoryRecorder;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Worker-side handler for {@see GeneratePlaylistsMessage}. Runs the
 * generator (always non-dry-run from the web) and records a RunHistory
 * entry so the outcome shows up on the history page — like Sync / Rematch.
 */
#[AsMessageHandler]
class GeneratePlaylistsMessageHandler
{
    public function __construct(
        private readonly PlaylistGenerator $generator,
        private readonly RunHistoryRecorder $recorder,
    ) {
    }

    public function __invoke(GeneratePlaylistsMessage $message): void
    {
        $label = $message->slug !== null
            ? sprintf('Génération playlist [%s]', $message->slug)
            : 'Génération playlists [toutes]';

        $this->recorder->record(
            type: RunHistory::TYPE_PLAYLIST_GENERATE,
            reference: $message->slug ?? 'all',
            label: $label,
            action: fn (): array => $this->generator->generate($message->slug, dryRun: false),
            extractMetrics: static function (array $results): array {
                $byAction = [];
                $tracks = 0;
                foreach ($results as $r) {
                    /** @var PlaylistRunResult $r */
                    $byAction[$r->action] = ($byAction[$r->action] ?? 0) + 1;
                    $tracks += $r->trackCount();
                }

                return ['playlists' => count($results), 'tracks' => $tracks] + $byAction;
            },
        );
    }
}
