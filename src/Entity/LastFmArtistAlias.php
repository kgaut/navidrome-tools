<?php

namespace App\Entity;

use App\Navidrome\NavidromeRepository;
use App\Repository\LastFmArtistAliasRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Artist-level alias: rewrite the Last.fm artist name before the matching
 * cascade runs. Covers all tracks by that artist.
 */
#[ORM\Entity(repositoryClass: LastFmArtistAliasRepository::class)]
#[ORM\Table(name: 'lastfm_artist_alias')]
#[ORM\UniqueConstraint(name: 'uniq_lastfm_artist_alias_source_norm', columns: ['source_artist_norm'])]
class LastFmArtistAlias
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $sourceArtist;

    #[ORM\Column(length: 255)]
    private string $sourceArtistNorm;

    #[ORM\Column(length: 255)]
    private string $targetArtist;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $sourceArtist, string $targetArtist)
    {
        $this->setSource($sourceArtist);
        $this->setTargetArtist($targetArtist);
        $this->createdAt = new \DateTimeImmutable();
    }

    public function setSource(string $sourceArtist): void
    {
        $this->sourceArtist = trim($sourceArtist);
        $this->sourceArtistNorm = NavidromeRepository::normalize($sourceArtist);
    }

    public function setTargetArtist(string $targetArtist): void
    {
        $this->targetArtist = trim($targetArtist);
    }

    public function getId(): ?int
    {
        return $this->id;
    }
    public function getSourceArtist(): string
    {
        return $this->sourceArtist;
    }
    public function getSourceArtistNorm(): string
    {
        return $this->sourceArtistNorm;
    }
    public function getTargetArtist(): string
    {
        return $this->targetArtist;
    }
    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
