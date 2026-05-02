<?php

namespace App\Entity;

use App\Navidrome\NavidromeRepository;
use App\Repository\LastFmAliasRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Manual mapping from a Last.fm (artist, title) couple to a Navidrome
 * media_file id. Resolved before any heuristic during import. Setting
 * `targetMediaFileId` to null means « ignore scrobbles matching this
 * couple » (useful for podcasts or noise).
 *
 * `sourceArtistNorm` / `sourceTitleNorm` mirror the raw fields run
 * through {@see NavidromeRepository::normalize()} and are unique
 * together — looked up by the importer for an O(1) match.
 */
#[ORM\Entity(repositoryClass: LastFmAliasRepository::class)]
#[ORM\Table(name: 'lastfm_alias')]
#[ORM\UniqueConstraint(name: 'uniq_lastfm_alias_source_norm', columns: ['source_artist_norm', 'source_title_norm'])]
#[ORM\Index(columns: ['source_artist_norm', 'source_title_norm'], name: 'idx_lastfm_alias_source_norm')]
class LastFmAlias
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $sourceArtist;

    #[ORM\Column(length: 255)]
    private string $sourceTitle;

    #[ORM\Column(length: 255)]
    private string $sourceArtistNorm;

    #[ORM\Column(length: 255)]
    private string $sourceTitleNorm;

    /**
     * Navidrome media_file id. Null means « skip scrobbles matching
     * (sourceArtist, sourceTitle) » — they will be counted as `skipped`
     * in the import report instead of `unmatched`.
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $targetMediaFileId = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $sourceArtist, string $sourceTitle, ?string $targetMediaFileId)
    {
        $this->setSource($sourceArtist, $sourceTitle);
        $this->targetMediaFileId = $targetMediaFileId !== null && $targetMediaFileId !== '' ? $targetMediaFileId : null;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function setSource(string $sourceArtist, string $sourceTitle): void
    {
        $this->sourceArtist = trim($sourceArtist);
        $this->sourceTitle = trim($sourceTitle);
        $this->sourceArtistNorm = NavidromeRepository::normalize($sourceArtist);
        $this->sourceTitleNorm = NavidromeRepository::normalize($sourceTitle);
    }

    public function setTargetMediaFileId(?string $id): void
    {
        $this->targetMediaFileId = $id !== null && $id !== '' ? $id : null;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSourceArtist(): string
    {
        return $this->sourceArtist;
    }

    public function getSourceTitle(): string
    {
        return $this->sourceTitle;
    }

    public function getSourceArtistNorm(): string
    {
        return $this->sourceArtistNorm;
    }

    public function getSourceTitleNorm(): string
    {
        return $this->sourceTitleNorm;
    }

    public function getTargetMediaFileId(): ?string
    {
        return $this->targetMediaFileId;
    }

    public function isSkip(): bool
    {
        return $this->targetMediaFileId === null;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
