<?php

namespace App\Entity;

use App\Doctrine\UtcDateTimeImmutableType;
use App\Repository\ScrobbleSyncRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Tracks the sync status of a scrobble for a given target (navidrome, strawberry).
 * Created lazily when a sync command runs for the first time on a target.
 *
 * UNIQUE (scrobble_id, target) ensures one status row per scrobble per target.
 */
#[ORM\Entity(repositoryClass: ScrobbleSyncRepository::class)]
#[ORM\Table(name: 'scrobble_sync')]
#[ORM\UniqueConstraint(name: 'uniq_scrobble_sync_scrobble_target', columns: ['scrobble_id', 'target'])]
#[ORM\Index(columns: ['target', 'status'], name: 'idx_scrobble_sync_target_status')]
#[ORM\Index(columns: ['run_id'], name: 'idx_scrobble_sync_run')]
class ScrobbleSync
{
    public const TARGET_NAVIDROME = 'navidrome';
    public const TARGET_STRAWBERRY = 'strawberry';

    public const STATUS_PENDING = 'pending';
    public const STATUS_MATCHED = 'matched';
    public const STATUS_DUPLICATE = 'duplicate';
    public const STATUS_UNMATCHED = 'unmatched';
    public const STATUS_SKIPPED = 'skipped';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Scrobble::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Scrobble $scrobble;

    #[ORM\Column(length: 32)]
    private string $target;

    #[ORM\Column(length: 16)]
    private string $status = self::STATUS_PENDING;

    /** Navidrome media_file_id or Strawberry rowid, depending on target. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $targetId = null;

    /** Which matching heuristic resolved this scrobble (mbid, triplet, couple…). */
    #[ORM\Column(length: 32, nullable: true)]
    private ?string $strategy = null;

    #[ORM\Column(type: UtcDateTimeImmutableType::NAME, nullable: true)]
    private ?\DateTimeImmutable $attemptedAt = null;

    #[ORM\Column(type: UtcDateTimeImmutableType::NAME, nullable: true)]
    private ?\DateTimeImmutable $syncedAt = null;

    #[ORM\ManyToOne(targetEntity: RunHistory::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?RunHistory $run = null;

    public function __construct(Scrobble $scrobble, string $target)
    {
        $this->scrobble = $scrobble;
        $this->target = $target;
    }

    public function getId(): ?int { return $this->id; }
    public function getScrobble(): Scrobble { return $this->scrobble; }
    public function getTarget(): string { return $this->target; }
    public function getStatus(): string { return $this->status; }
    public function getTargetId(): ?string { return $this->targetId; }
    public function getStrategy(): ?string { return $this->strategy; }
    public function getAttemptedAt(): ?\DateTimeImmutable { return $this->attemptedAt; }
    public function getSyncedAt(): ?\DateTimeImmutable { return $this->syncedAt; }
    public function getRun(): ?RunHistory { return $this->run; }

    public function markMatched(string $targetId, string $strategy, ?RunHistory $run = null): void
    {
        $this->status = self::STATUS_MATCHED;
        $this->targetId = $targetId;
        $this->strategy = $strategy;
        $this->attemptedAt = new \DateTimeImmutable();
        $this->syncedAt = new \DateTimeImmutable();
        $this->run = $run;
    }

    public function markDuplicate(string $targetId, string $strategy, ?RunHistory $run = null): void
    {
        $this->status = self::STATUS_DUPLICATE;
        $this->targetId = $targetId;
        $this->strategy = $strategy;
        $this->attemptedAt = new \DateTimeImmutable();
        $this->run = $run;
    }

    public function markUnmatched(?RunHistory $run = null): void
    {
        $this->status = self::STATUS_UNMATCHED;
        $this->attemptedAt = new \DateTimeImmutable();
        $this->run = $run;
    }

    public function markSkipped(?RunHistory $run = null): void
    {
        $this->status = self::STATUS_SKIPPED;
        $this->attemptedAt = new \DateTimeImmutable();
        $this->run = $run;
    }

    public function resetToPending(): void
    {
        $this->status = self::STATUS_PENDING;
        $this->attemptedAt = null;
        $this->syncedAt = null;
        $this->run = null;
    }
}
