<?php

namespace App\Tests\Recommendation;

use App\Recommendation\ListenBrainzClient;
use App\Recommendation\ListenBrainzException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Issue #310: loved recordings (feedback score 1), writing a love, and the
 * strict metadata lookup used to find a recording MBID.
 */
class ListenBrainzClientFeedbackTest extends TestCase
{
    public function testIteratesLovedRecordingsWithMetadata(): void
    {
        $urls = [];
        $http = new MockHttpClient(function (string $method, string $url) use (&$urls): MockResponse {
            $urls[] = $url;

            return new MockResponse(json_encode(['feedback' => [
                ['created' => 1_700_000_000, 'recording_mbid' => 'rec-1', 'score' => 1, 'track_metadata' => ['artist_name' => 'A', 'track_name' => 'T']],
                ['created' => 0, 'recording_mbid' => null, 'score' => 1, 'track_metadata' => ['artist_name' => 'B', 'track_name' => 'U', 'mbid_mapping' => ['recording_mbid' => 'rec-2']]],
            ]], \JSON_THROW_ON_ERROR));
        });

        $loved = iterator_to_array((new ListenBrainzClient($http, 'alice'))->iterateLovedRecordings(), false);

        $this->assertCount(2, $loved);
        $this->assertSame(['A', 'T', 'rec-1'], [$loved[0]->artist, $loved[0]->title, $loved[0]->mbid]);
        $this->assertSame(1_700_000_000, $loved[0]->lovedAt?->getTimestamp());
        $this->assertSame('rec-2', $loved[1]->mbid);
        $this->assertNull($loved[1]->lovedAt);
        $this->assertCount(1, $urls);
        $this->assertStringContainsString('/1/feedback/user/alice/get-feedback', $urls[0]);
        $this->assertStringContainsString('score=1', $urls[0]);
        $this->assertStringContainsString('metadata=true', $urls[0]);
    }

    public function testLoveRecordingPostsScoreOneWithTheToken(): void
    {
        $captured = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = [$method, $url, $options];

            return new MockResponse('{"status":"ok"}');
        });

        (new ListenBrainzClient($http, 'alice', ListenBrainzClient::DEFAULT_BASE_URL, 'tok'))->loveRecording('rec-9');

        [$method, $url, $options] = $captured;
        $this->assertSame('POST', $method);
        $this->assertStringEndsWith('/1/feedback/recording-feedback', $url);
        $this->assertSame(['recording_mbid' => 'rec-9', 'score' => 1], json_decode((string) $options['body'], true));
        $this->assertContains('Authorization: Token tok', $options['headers']);
    }

    public function testLoveRecordingWithoutTokenFails(): void
    {
        $this->expectException(ListenBrainzException::class);
        (new ListenBrainzClient(new MockHttpClient([]), 'alice'))->loveRecording('rec-9');
    }

    public function testLookupKeepsOnlyAnExactMatch(): void
    {
        $http = new MockHttpClient([
            new MockResponse(json_encode(['recording_mbid' => 'rec-ok', 'artist_credit_name' => 'Daft Punk', 'recording_name' => 'Da Funk'], \JSON_THROW_ON_ERROR)),
            new MockResponse(json_encode(['recording_mbid' => 'rec-fuzzy', 'artist_credit_name' => 'Daft Punk', 'recording_name' => 'Da Funk (live)'], \JSON_THROW_ON_ERROR)),
            new MockResponse('{}'),
        ]);
        $client = new ListenBrainzClient($http, 'alice');

        $this->assertSame('rec-ok', $client->lookupRecordingMbid('daft punk', 'da funk'));
        $this->assertNull($client->lookupRecordingMbid('Daft Punk', 'Da Funk'));
        $this->assertNull($client->lookupRecordingMbid('Nobody', 'Nothing'));
    }
}
