<?php

namespace App\MessageHandler;

use App\Entity\RunHistory;
use App\Message\SuggestAliasesMusicBrainzMessage;
use App\Service\MusicBrainzAliasReport;
use App\Service\MusicBrainzAliasSuggester;
use App\Service\RunHistoryRecorder;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Worker-side handler for {@see SuggestAliasesMusicBrainzMessage}. Runs the
 * same non-interactive flow as the `app:aliases:musicbrainz` CLI: it
 * throttles MusicBrainz requests (~1 req/s) via the suggester's
 * `beforeQuery` hook, applies unique-library-match aliases, skips
 * ambiguous ones, and records a RunHistory entry so the result shows up
 * on the history page — exactly like Sync / Rematch jobs.
 */
#[AsMessageHandler]
class SuggestAliasesMusicBrainzMessageHandler
{
    /** MB asks 1 req/s per UA. 1100 ms keeps a small safety margin. */
    private const RATE_LIMIT_MS = 1100;

    public function __construct(
        private readonly MusicBrainzAliasSuggester $suggester,
        private readonly RunHistoryRecorder $recorder,
    ) {
    }

    public function __invoke(SuggestAliasesMusicBrainzMessage $message): void
    {
        $beforeQuery = static function (string $artist): void {
            usleep(self::RATE_LIMIT_MS * 1000);
        };

        $label = sprintf(
            'MusicBrainz alias suggest [%s%s]',
            $message->target,
            $message->dryRun ? ' dry-run' : '',
        );

        $this->recorder->record(
            type: RunHistory::TYPE_NAVIDROME_ALIAS_MUSICBRAINZ,
            reference: $message->target,
            label: $label,
            action: fn (): MusicBrainzAliasReport => $this->suggester->suggest(
                target: $message->target,
                dryRun: $message->dryRun,
                limit: $message->limit,
                beforeQuery: $beforeQuery,
                confirm: null,
                minPlays: $message->minPlays,
            ),
            extractMetrics: static fn (MusicBrainzAliasReport $r): array => [
                'queried' => $r->artistsQueried,
                'created' => $r->aliasesCreated,
                'ambiguous' => $r->ambiguous,
                'no_match' => $r->noMatch,
                'mb_errors' => $r->mbErrors,
                'plays_covered' => $r->playsCovered,
            ],
        );
    }
}
