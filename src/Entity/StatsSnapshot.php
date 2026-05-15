<?php

namespace App\Entity;

use App\Repository\StatsSnapshotRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: StatsSnapshotRepository::class)]
#[ORM\Table(name: 'stats_snapshot')]
class StatsSnapshot
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(length: 32, unique: true)]
    private string $period;

    /**
     * Free-form JSON payload. The 'stats' periods carry the keys total_plays,
     * distinct_tracks, top_artists, top_tracks, window_from, window_to ; the
     * 'wrapped-<year>' periods add wrapped_* keys (year, total seconds estimate,
     * new artists list, streak days, most active month).
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $data = [
        'total_plays' => 0,
        'distinct_tracks' => 0,
        'top_artists' => [],
        'top_tracks' => [],
        'window_from' => null,
        'window_to' => null,
    ];

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $computedAt;

    public function __construct(string $period)
    {
        $this->period = $period;
        $this->computedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPeriod(): string
    {
        return $this->period;
    }

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        return $this->data;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function setData(array $data): self
    {
        $this->data = $data;
        $this->computedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getComputedAt(): \DateTimeImmutable
    {
        return $this->computedAt;
    }
}
