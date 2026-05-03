<?php

namespace App\Entity;

use App\Doctrine\UtcDateTimeImmutableType;
use App\Repository\LastFmImportTrackRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Per-scrobble row persisted for each Last.fm import run, so the import
 * detail page can show what was matched / duplicated / unmatched and let
 * the user filter the listing. CASCADE delete via the FK on RunHistory →
 * the retention purge cleans these up automatically.
 */
#[ORM\Entity(repositoryClass: LastFmImportTrackRepository::class)]
#[ORM\Table(name: 'lastfm_import_track')]
#[ORM\Index(columns: ['run_history_id', 'status'], name: 'idx_lastfm_import_track_run_status')]
#[ORM\Index(columns: ['run_history_id', 'played_at'], name: 'idx_lastfm_import_track_run_played')]
class LastFmImportTrack
{
    public const STATUS_INSERTED = 'inserted';
    public const STATUS_DUPLICATE = 'duplicate';
    public const STATUS_UNMATCHED = 'unmatched';
    public const STATUS_SKIPPED = 'skipped';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: RunHistory::class)]
    #[ORM\JoinColumn(name: 'run_history_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private RunHistory $runHistory;

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

    #[ORM\Column(length: 16)]
    private string $status;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $matchedMediaFileId = null;

    public function __construct(
        RunHistory $runHistory,
        string $artist,
        string $title,
        ?string $album,
        ?string $mbid,
        \DateTimeImmutable $playedAt,
        string $status,
        ?string $matchedMediaFileId = null,
    ) {
        $this->runHistory = $runHistory;
        $this->artist = $artist;
        $this->title = $title;
        $this->album = $album !== null && $album !== '' ? $album : null;
        $this->mbid = $mbid !== null && $mbid !== '' ? $mbid : null;
        $this->playedAt = $playedAt;
        $this->status = $status;
        $this->matchedMediaFileId = $matchedMediaFileId;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRunHistory(): RunHistory
    {
        return $this->runHistory;
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

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function getMatchedMediaFileId(): ?string
    {
        return $this->matchedMediaFileId;
    }

    public function setMatchedMediaFileId(?string $mediaFileId): self
    {
        $this->matchedMediaFileId = $mediaFileId;
        return $this;
    }
}
