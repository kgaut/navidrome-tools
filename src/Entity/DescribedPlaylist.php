<?php

namespace App\Entity;

use App\Repository\DescribedPlaylistRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A playlist described in free text (« piano calme, pluie »), resolved by
 * AudioMuse-AI's CLAP text search and regenerated like any other
 * definition (issue #258). Exposed to the generator through
 * {@see \App\Playlist\DescribedPlaylistProvider}.
 */
#[ORM\Entity(repositoryClass: DescribedPlaylistRepository::class)]
#[ORM\Table(name: 'described_playlist')]
#[ORM\UniqueConstraint(name: 'uniq_described_playlist_name', columns: ['name'])]
class DescribedPlaylist
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private string $name;

    #[ORM\Column(length: 300)]
    private string $query;

    #[ORM\Column(type: Types::INTEGER)]
    private int $trackCount;

    /** Keep only tracks played at most this many times; null = no filter. */
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $maxPlays;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $name, string $query, int $trackCount, ?int $maxPlays = null)
    {
        $this->name = trim($name);
        $this->query = trim($query);
        $this->trackCount = $trackCount;
        $this->maxPlays = $maxPlays;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getQuery(): string
    {
        return $this->query;
    }

    public function getTrackCount(): int
    {
        return $this->trackCount;
    }

    public function getMaxPlays(): ?int
    {
        return $this->maxPlays;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
