<?php

namespace App\AudioMuse;

/** Outcome of an {@see AudioFeatureSync} run. */
final class AudioFeatureSyncReport
{
    public function __construct(
        public readonly int $total = 0,
        public readonly int $added = 0,
        public readonly int $updated = 0,
        public readonly int $removed = 0,
        public readonly int $unchanged = 0,
    ) {
    }
}
