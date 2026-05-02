<?php

namespace App\Entity;

use App\Navidrome\NavidromeRepository;
use App\Repository\LastFmMatchCacheRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Memoized resolution of a Last.fm `(artist, title)` couple to a Navidrome
 * `media_file` id. Consulted by {@see \App\LastFm\ScrobbleMatcher} *after*
 * the alias steps and *before* the heuristic cascade so repeated imports
 * (and especially `app:lastfm:rematch` runs) skip the SQL + Last.fm
 * `track.getInfo` round-trips when we already know the answer.
 *
 * `target_media_file_id` semantics:
 *   - non-null → positive cache hit, return matched.
 *   - null     → negative cache hit ; the cascade ran, found nothing,
 *                and we remember it for `LASTFM_MATCH_CACHE_TTL_DAYS`
 *                so we don't waste time / API calls re-trying.
 */
#[ORM\Entity(repositoryClass: LastFmMatchCacheRepository::class)]
#[ORM\Table(name: 'lastfm_match_cache')]
#[ORM\UniqueConstraint(name: 'uniq_lastfm_match_cache_source_norm', columns: ['source_artist_norm', 'source_title_norm'])]
#[ORM\Index(columns: ['source_artist_norm'], name: 'idx_lastfm_match_cache_artist_norm')]
#[ORM\Index(columns: ['resolved_at'], name: 'idx_lastfm_match_cache_resolved_at')]
class LastFmMatchCacheEntry
{
    public const STRATEGY_MBID = 'mbid';
    public const STRATEGY_TRIPLET = 'triplet';
    public const STRATEGY_COUPLE = 'couple';
    public const STRATEGY_FUZZY = 'fuzzy';
    public const STRATEGY_LASTFM_CORRECTION = 'lastfm-correction';
    public const STRATEGY_NEGATIVE = 'negative';

    private const VALID_STRATEGIES = [
        self::STRATEGY_MBID,
        self::STRATEGY_TRIPLET,
        self::STRATEGY_COUPLE,
        self::STRATEGY_FUZZY,
        self::STRATEGY_LASTFM_CORRECTION,
        self::STRATEGY_NEGATIVE,
    ];

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

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $targetMediaFileId = null;

    #[ORM\Column(length: 32)]
    private string $strategy;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $confidenceScore = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $resolvedAt;

    public function __construct(
        string $sourceArtist,
        string $sourceTitle,
        ?string $targetMediaFileId,
        string $strategy,
        ?int $confidenceScore = null,
    ) {
        $this->setSource($sourceArtist, $sourceTitle);
        $this->setResolution($targetMediaFileId, $strategy, $confidenceScore);
        $this->resolvedAt = new \DateTimeImmutable();
    }

    public function setSource(string $sourceArtist, string $sourceTitle): void
    {
        $this->sourceArtist = trim($sourceArtist);
        $this->sourceTitle = trim($sourceTitle);
        $this->sourceArtistNorm = NavidromeRepository::normalize($sourceArtist);
        $this->sourceTitleNorm = NavidromeRepository::normalize($sourceTitle);
    }

    public function setResolution(?string $targetMediaFileId, string $strategy, ?int $confidenceScore = null): void
    {
        if (!in_array($strategy, self::VALID_STRATEGIES, true)) {
            throw new \InvalidArgumentException(sprintf('Invalid match cache strategy "%s".', $strategy));
        }
        $this->targetMediaFileId = $targetMediaFileId !== null && $targetMediaFileId !== '' ? $targetMediaFileId : null;
        $this->strategy = $strategy;
        $this->confidenceScore = $confidenceScore;
        $this->resolvedAt = new \DateTimeImmutable();
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

    public function isPositive(): bool
    {
        return $this->targetMediaFileId !== null;
    }

    public function getStrategy(): string
    {
        return $this->strategy;
    }

    public function getConfidenceScore(): ?int
    {
        return $this->confidenceScore;
    }

    public function getResolvedAt(): \DateTimeImmutable
    {
        return $this->resolvedAt;
    }

    /**
     * A row is stale once `resolvedAt` is older than `$ttlDays` days.
     * `$ttlDays <= 0` means « never expire » — useful for setups that
     * trust positive matches forever and prefer manual purges.
     */
    public function isStale(int $ttlDays, ?\DateTimeImmutable $now = null): bool
    {
        if ($ttlDays <= 0) {
            return false;
        }
        $now ??= new \DateTimeImmutable();

        return $this->resolvedAt < $now->modify(sprintf('-%d days', $ttlDays));
    }
}
