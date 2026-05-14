<?php

namespace App\Entity;

use App\Doctrine\UtcDateTimeImmutableType;
use App\Repository\LastFmBufferedScrobbleRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Buffer row for a scrobble fetched from Last.fm, kept as a persistent log.
 * synced_navidrome is set to true once the row has been processed for
 * Navidrome (matched + inserted or deduped); synced_strawberry once synced
 * to the Strawberry music player database. Rows are never deleted — the
 * buffer is a permanent record.
 */
#[ORM\Entity(repositoryClass: LastFmBufferedScrobbleRepository::class)]
#[ORM\Table(name: 'lastfm_import_buffer')]
#[ORM\UniqueConstraint(
    name: 'uniq_lastfm_import_buffer_user_played_track',
    columns: ['lastfm_user', 'played_at', 'artist', 'title'],
)]
#[ORM\Index(columns: ['lastfm_user', 'played_at'], name: 'idx_lastfm_import_buffer_user_played')]
class LastFmBufferedScrobble
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

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $mbid = null;

    #[ORM\Column(type: UtcDateTimeImmutableType::NAME)]
    private \DateTimeImmutable $playedAt;

    #[ORM\Column(type: UtcDateTimeImmutableType::NAME)]
    private \DateTimeImmutable $fetchedAt;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $syncedNavidrome = false;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $syncedStrawberry = false;

    #[ORM\Column(type: UtcDateTimeImmutableType::NAME, nullable: true)]
    private ?\DateTimeImmutable $strawberryAttemptedAt = null;

    public function __construct(
        string $lastfmUser,
        string $artist,
        string $title,
        ?string $album,
        ?string $mbid,
        \DateTimeImmutable $playedAt,
        \DateTimeImmutable $fetchedAt,
    ) {
        $this->lastfmUser = $lastfmUser;
        $this->artist = $artist;
        $this->title = $title;
        $this->album = $album !== null && $album !== '' ? $album : null;
        $this->mbid = $mbid !== null && $mbid !== '' ? $mbid : null;
        $this->playedAt = $playedAt;
        $this->fetchedAt = $fetchedAt;
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

    public function getMbid(): ?string
    {
        return $this->mbid;
    }

    public function getPlayedAt(): \DateTimeImmutable
    {
        return $this->playedAt;
    }

    public function getFetchedAt(): \DateTimeImmutable
    {
        return $this->fetchedAt;
    }

    public function isSyncedNavidrome(): bool
    {
        return $this->syncedNavidrome;
    }

    public function setSyncedNavidrome(bool $syncedNavidrome): void
    {
        $this->syncedNavidrome = $syncedNavidrome;
    }

    public function isSyncedStrawberry(): bool
    {
        return $this->syncedStrawberry;
    }

    public function setSyncedStrawberry(bool $syncedStrawberry): void
    {
        $this->syncedStrawberry = $syncedStrawberry;
    }

    public function getStrawberryAttemptedAt(): ?\DateTimeImmutable
    {
        return $this->strawberryAttemptedAt;
    }

    public function setStrawberryAttemptedAt(?\DateTimeImmutable $at): void
    {
        $this->strawberryAttemptedAt = $at;
    }
}
