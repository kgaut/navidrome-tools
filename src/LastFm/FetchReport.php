<?php

namespace App\LastFm;

final class FetchReport
{
    public int $fetched = 0;
    public int $inserted = 0;
    public int $duplicates = 0;
    public ?\DateTimeImmutable $firstPlayedAt = null;
    public ?\DateTimeImmutable $lastPlayedAt = null;
}
