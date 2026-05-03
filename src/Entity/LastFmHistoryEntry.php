<?php

namespace App\Entity;

use App\Doctrine\UtcDateTimeImmutableType;
use App\Repository\LastFmHistoryEntryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Cached snapshot of one of the most recent Last.fm scrobbles for a user.
 *
 * The whole snapshot is wiped + re-inserted on each refresh, so this table
 * never grows beyond a few hundred rows per user. Lives in the tool's own
 * SQLite DB (not Navidrome's) to avoid hitting the Last.fm API on every page
 * view.
 */
#[ORM\Entity(repositoryClass: LastFmHistoryEntryRepository::class)]
#[ORM\Table(name: 'lastfm_history')]
#[ORM\Index(columns: ['lastfm_user', 'played_at'], name: 'idx_lastfm_history_user_played')]
class LastFmHistoryEntry
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $lastfmUser;

    #[ORM\Column(type: UtcDateTimeImmutableType::NAME)]
    private \DateTimeImmutable $playedAt;

    #[ORM\Column(length: 255)]
    private string $artist;

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $album = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $mbid = null;

    #[ORM\Column(type: UtcDateTimeImmutableType::NAME)]
    private \DateTimeImmutable $fetchedAt;

    public function __construct(
        string $lastfmUser,
        \DateTimeImmutable $playedAt,
        string $artist,
        string $title,
        ?string $album = null,
        ?string $mbid = null,
    ) {
        $this->lastfmUser = $lastfmUser;
        $this->playedAt = $playedAt;
        $this->artist = $artist;
        $this->title = $title;
        $this->album = $album !== null && $album !== '' ? $album : null;
        $this->mbid = $mbid !== null && $mbid !== '' ? $mbid : null;
        $this->fetchedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLastfmUser(): string
    {
        return $this->lastfmUser;
    }

    public function getPlayedAt(): \DateTimeImmutable
    {
        return $this->playedAt;
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

    public function getFetchedAt(): \DateTimeImmutable
    {
        return $this->fetchedAt;
    }
}
