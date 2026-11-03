<?php

namespace App\MessageHandler;

use App\Docker\NavidromeContainerManager;
use App\Entity\RunHistory;
use App\Message\RelinkMatchedMessage;
use App\Service\MatchedRelinker;
use App\Service\RunHistoryRecorder;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Worker side of the MCP `matched_relink` tool (issue #285): plans while
 * Navidrome runs (the cascade may call Last.fm), then stops Navidrome only
 * for the write — backup and quick_check by the container manager.
 */
#[AsMessageHandler]
class RelinkMatchedMessageHandler
{
    public function __construct(
        private readonly MatchedRelinker $relinker,
        private readonly RunHistoryRecorder $recorder,
        private readonly NavidromeContainerManager $container,
    ) {
    }

    public function __invoke(RelinkMatchedMessage $message): void
    {
        $this->recorder->record(
            type: RunHistory::TYPE_NAVIDROME_RELINK,
            reference: 'matched',
            label: 'Navidrome relink' . self::describe($message),
            action: function (RunHistory $entry) use ($message): array {
                $plan = $this->relinker->plan($message->artist, $message->title, $message->album, $message->fromAlbum, $message->limit);
                $written = $plan['moves'] === []
                    ? ['moved' => 0, 'duplicates' => 0, 'missing_old' => 0, 'backup' => null]
                    : $this->container->runWithNavidromeStopped(fn () => $this->relinker->apply($plan['moves'], $entry));

                return [
                    'considered' => $plan['considered'],
                    'planned' => count($plan['moves']),
                    'would_unmatch' => $plan['would_unmatch'],
                    'stale_target' => $plan['stale_target'],
                    'api_errors' => $plan['api_errors'],
                ] + $written;
            },
            extractMetrics: static fn (array $r): array => $r,
        );
    }

    private static function describe(RelinkMatchedMessage $m): string
    {
        $parts = array_filter([
            'artist' => $m->artist,
            'title' => $m->title,
            'album' => $m->album,
            'from_album' => $m->fromAlbum,
        ], static fn (?string $v) => $v !== null && $v !== '');
        if ($parts === []) {
            return '';
        }

        return ' [' . implode(', ', array_map(static fn (string $k, string $v) => $k . '=' . $v, array_keys($parts), $parts)) . ']';
    }
}
