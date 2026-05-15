<?php

namespace App\Entity;

use App\Doctrine\UtcDateTimeImmutableType;
use App\Repository\ScrobbleRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A Last.fm scrobble stored locally — the source of truth for all sync operations.
 * Rows are never deleted (except on explicit wipe). New scrobbles fetched from
 * Last.fm are inserted here; matching / syncing to Navidrome or Strawberry happens
 * in scrobble_sync rows created lazily by each sync service.
 */
#[ORM\Entity(repositoryClass: ScrobbleRepository::class)]
#[ORM\Table(name: 'scrobbles')]
#[ORM\UniqueConstraint(name: 'uniq_scrobble_user_played_track', columns: ['lastfm_user', 'played_at', 'artist', 'title'])]
#[ORM\Index(columns: ['lastfm_user', 'played_at'], name: 'idx_scrobble_user_played')]
#[ORM\Index(columns: ['played_at'], name: 'idx_scrobble_played_at')]
class Scrobble
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $lastfmUser;

    #[ORM\Column(length: 255)]
    private string $artist;

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $album = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $albumArtist = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $mbidTrack = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $mbidArtist = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $mbidAlbum = null;

    #[ORM\Column(type: UtcDateTimeImmutableType::NAME)]
    private \DateTimeImmutable $playedAt;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $loved = false;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $imageUrl = null;

    #[ORM\Column(type: UtcDateTimeImmutableType::NAME)]
    private \DateTimeImmutable $fetchedAt;

    public function __construct(
        string $lastfmUser,
        string $artist,
        string $title,
        ?string $album,
        ?string $albumArtist,
        ?string $mbidTrack,
        ?string $mbidArtist,
        ?string $mbidAlbum,
        \DateTimeImmutable $playedAt,
        bool $loved = false,
        ?string $imageUrl = null,
    ) {
        $this->lastfmUser = $lastfmUser;
        $this->artist = $artist;
        $this->title = $title;
        $this->album = $album !== '' ? $album : null;
        $this->albumArtist = $albumArtist !== '' ? $albumArtist : null;
        $this->mbidTrack = $mbidTrack !== '' ? $mbidTrack : null;
        $this->mbidArtist = $mbidArtist !== '' ? $mbidArtist : null;
        $this->mbidAlbum = $mbidAlbum !== '' ? $mbidAlbum : null;
        $this->playedAt = $playedAt;
        $this->loved = $loved;
        $this->imageUrl = $imageUrl;
        $this->fetchedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }
    public function getLastfmUser(): string
    {
        return $this->lastfmUser;
    }
    public function getArtist(): string
    {
        return $this->artist;
    }
    public function getTitle(): string
    {
        return $this->title;
    }
    public function getAlbum(): ?string
    {
        return $this->album;
    }
    public function getAlbumArtist(): ?string
    {
        return $this->albumArtist;
    }
    public function getMbidTrack(): ?string
    {
        return $this->mbidTrack;
    }
    public function getMbidArtist(): ?string
    {
        return $this->mbidArtist;
    }
    public function getMbidAlbum(): ?string
    {
        return $this->mbidAlbum;
    }
    public function getPlayedAt(): \DateTimeImmutable
    {
        return $this->playedAt;
    }
    public function isLoved(): bool
    {
        return $this->loved;
    }
    public function getImageUrl(): ?string
    {
        return $this->imageUrl;
    }
    public function getFetchedAt(): \DateTimeImmutable
    {
        return $this->fetchedAt;
    }
}
