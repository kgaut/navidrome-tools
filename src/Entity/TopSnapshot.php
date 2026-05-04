<?php

namespace App\Entity;

use App\Repository\TopSnapshotRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TopSnapshotRepository::class)]
#[ORM\Table(name: 'top_snapshot')]
#[ORM\UniqueConstraint(name: 'top_snapshot_window_uniq', columns: ['window_from', 'window_to', 'client'])]
class TopSnapshot
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::INTEGER)]
    private int $windowFrom;

    #[ORM\Column(type: Types::INTEGER)]
    private int $windowTo;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $client = null;

    /**
     * JSON payload :
     *   total_plays, distinct_tracks,
     *   top_artists [{artist, plays}],
     *   top_albums  [{album, album_artist, plays, track_count, sample_track_id}],
     *   top_tracks  [{id, title, artist, album, plays}]
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $data = [
        'total_plays' => 0,
        'distinct_tracks' => 0,
        'top_artists' => [],
        'top_albums' => [],
        'top_tracks' => [],
    ];

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $computedAt;

    public function __construct(\DateTimeImmutable $windowFrom, \DateTimeImmutable $windowTo, ?string $client = null)
    {
        $this->windowFrom = $windowFrom->getTimestamp();
        $this->windowTo = $windowTo->getTimestamp();
        $this->client = ($client !== null && $client !== '') ? $client : null;
        $this->computedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getWindowFrom(): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('@' . $this->windowFrom))
            ->setTimezone(new \DateTimeZone(date_default_timezone_get()));
    }

    public function getWindowTo(): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('@' . $this->windowTo))
            ->setTimezone(new \DateTimeZone(date_default_timezone_get()));
    }

    public function getWindowFromTimestamp(): int
    {
        return $this->windowFrom;
    }

    public function getWindowToTimestamp(): int
    {
        return $this->windowTo;
    }

    public function getClient(): ?string
    {
        return $this->client;
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
