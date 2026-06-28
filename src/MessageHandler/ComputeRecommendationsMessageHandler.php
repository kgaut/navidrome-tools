<?php

namespace App\MessageHandler;

use App\Entity\RunHistory;
use App\Message\ComputeRecommendationsMessage;
use App\Recommendation\RecommendationEngine;
use App\Recommendation\RecommendationResult;
use App\Recommendation\RecommendationStore;
use App\Service\RunHistoryRecorder;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Worker-side handler for {@see ComputeRecommendationsMessage}: runs the
 * engine (throttling MusicBrainz lookups), saves the snapshot for the review
 * UI, and records a RunHistory entry so the outcome surfaces on the history
 * page — like Sync / Rematch / Playlists.
 */
#[AsMessageHandler]
class ComputeRecommendationsMessageHandler
{
    /** MusicBrainz rate-limits at 1 req/s per UA — stay just under. */
    private const MB_THROTTLE_MICROSECONDS = 1_100_000;

    public function __construct(
        private readonly RecommendationEngine $engine,
        private readonly RecommendationStore $store,
        private readonly RunHistoryRecorder $recorder,
    ) {
    }

    public function __invoke(ComputeRecommendationsMessage $message): void
    {
        $this->recorder->record(
            type: RunHistory::TYPE_RECOMMENDATIONS,
            reference: 'compute',
            label: 'Calcul des recommandations d\'artistes',
            action: function () use ($message): RecommendationResult {
                $result = $this->engine->compute(
                    $message->limit,
                    static function (string $name): void {
                        usleep(self::MB_THROTTLE_MICROSECONDS);
                    },
                );
                $this->store->save($result, new \DateTimeImmutable());

                return $result;
            },
            extractMetrics: static fn (RecommendationResult $r): array => [
                'recommandations' => $r->count(),
                'seeds' => $r->seedCount,
                'candidats' => $r->rawCandidates,
                'mbid_lookups' => $r->mbidLookups,
            ],
        );
    }
}
