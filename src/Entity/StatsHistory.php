<?php

namespace App\Entity;

use App\Repository\StatsHistoryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One daily measurement of the Navidrome library size, recorded each time the
 * stats are computed. `day` is unique (one row per calendar day, upserted) so
 * re-running the compute on the same day refreshes the value rather than
 * piling up duplicates — giving a clean « une mesure par jour » time series
 * for the evolution chart.
 */
#[ORM\Entity(repositoryClass: StatsHistoryRepository::class)]
#[ORM\Table(name: 'stats_history')]
class StatsHistory
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(length: 10, unique: true)]
    private string $day;

    #[ORM\Column(type: Types::INTEGER)]
    private int $tracks;

    #[ORM\Column(type: Types::INTEGER)]
    private int $artists;

    #[ORM\Column(type: Types::INTEGER)]
    private int $albums;

    #[ORM\Column(type: Types::INTEGER)]
    private int $durationSeconds;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $computedAt;

    public function __construct(string $day, int $tracks, int $artists, int $albums, int $durationSeconds)
    {
        $this->day = $day;
        $this->tracks = $tracks;
        $this->artists = $artists;
        $this->albums = $albums;
        $this->durationSeconds = $durationSeconds;
        $this->computedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDay(): string
    {
        return $this->day;
    }

    public function getTracks(): int
    {
        return $this->tracks;
    }

    public function getArtists(): int
    {
        return $this->artists;
    }

    public function getAlbums(): int
    {
        return $this->albums;
    }

    public function getDurationSeconds(): int
    {
        return $this->durationSeconds;
    }

    public function getComputedAt(): \DateTimeImmutable
    {
        return $this->computedAt;
    }

    public function updateCounts(int $tracks, int $artists, int $albums, int $durationSeconds): self
    {
        $this->tracks = $tracks;
        $this->artists = $artists;
        $this->albums = $albums;
        $this->durationSeconds = $durationSeconds;
        $this->computedAt = new \DateTimeImmutable();

        return $this;
    }
}
