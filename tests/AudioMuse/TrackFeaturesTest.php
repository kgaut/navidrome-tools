<?php

namespace App\Tests\AudioMuse;

use App\AudioMuse\TrackFeatures;
use PHPUnit\Framework\TestCase;

class TrackFeaturesTest extends TestCase
{
    public function testParsesSyncRow(): void
    {
        $t = TrackFeatures::fromSyncRow([
            'id' => 'mf-1',
            'fp' => 'abc123',
            'tempo' => 172.3,
            'key' => 'A',
            'scale' => 'minor',
            'energy' => 0.71,
            'mood_vector' => 'folk:0.536,rock:0.547,bad,jazz:x',
            'other_features' => 'danceable:0.59,relaxed:0.12,unknown:0.9',
            'title' => 'ignored',
        ]);

        $this->assertNotNull($t);
        $this->assertSame('mf-1', $t->id);
        $this->assertSame('abc123', $t->fingerprint);
        $this->assertSame(172.3, $t->tempo);
        $this->assertSame('A', $t->key);
        $this->assertSame('minor', $t->scale);
        $this->assertSame(0.71, $t->energy);
        $this->assertSame(['rock' => 0.547, 'folk' => 0.536], $t->genres);
        $this->assertSame('rock', $t->topGenre());
        $this->assertSame(['danceable' => 0.59, 'relaxed' => 0.12], $t->moods);
    }

    public function testMissingValuesAreNullAndRowWithoutIdIsSkipped(): void
    {
        $t = TrackFeatures::fromSyncRow(['id' => 'mf-2', 'tempo' => null, 'key' => '', 'mood_vector' => null]);

        $this->assertNotNull($t);
        $this->assertNull($t->tempo);
        $this->assertNull($t->key);
        $this->assertSame([], $t->genres);
        $this->assertNull($t->topGenre());
        $this->assertNull(TrackFeatures::fromSyncRow(['tempo' => 120]));
    }
}
