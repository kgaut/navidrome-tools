<?php

namespace App\Tests\MessageHandler;

use App\Entity\RunHistory;
use App\Message\ComputeRecommendationsMessage;
use App\MessageHandler\ComputeRecommendationsMessageHandler;
use App\Recommendation\ArtistRecommendation;
use App\Recommendation\RecommendationEngine;
use App\Recommendation\RecommendationResult;
use App\Recommendation\RecommendationStore;
use App\Service\RunHistoryRecorder;
use PHPUnit\Framework\TestCase;

class ComputeRecommendationsMessageHandlerTest extends TestCase
{
    public function testComputesAndSavesSnapshot(): void
    {
        $result = new RecommendationResult([
            new ArtistRecommendation('Aphex Twin', 'mbid-aphex', 13.0, ['lastfm'], ['Radiohead']),
        ], seedCount: 5, rawCandidates: 40, mbidLookups: 2);

        $engine = $this->createMock(RecommendationEngine::class);
        $engine->expects($this->once())->method('compute')->willReturn($result);

        $store = $this->createMock(RecommendationStore::class);
        $store->expects($this->once())->method('save')->with($result, $this->isInstanceOf(\DateTimeImmutable::class));

        $recorder = $this->createMock(RunHistoryRecorder::class);
        $recorder->method('record')->willReturnCallback(
            static fn (string $type, string $ref, string $label, callable $action) => $action(new RunHistory($type, $ref, $label)),
        );

        $handler = new ComputeRecommendationsMessageHandler($engine, $store, $recorder);
        $handler(new ComputeRecommendationsMessage());
    }
}
