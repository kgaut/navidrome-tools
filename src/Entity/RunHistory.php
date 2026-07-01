<?php

namespace App\Entity;

use App\Repository\RunHistoryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: RunHistoryRepository::class)]
#[ORM\Table(name: 'run_history')]
#[ORM\Index(columns: ['type'], name: 'idx_run_history_type')]
#[ORM\Index(columns: ['status'], name: 'idx_run_history_status')]
#[ORM\Index(columns: ['started_at'], name: 'idx_run_history_started_at')]
class RunHistory
{
    public const TYPE_LASTFM_FETCH = 'lastfm-fetch';
    public const TYPE_LASTFM_LOVED_SYNC = 'lastfm-loved-sync';
    public const TYPE_LOVES_LASTFM_TO_NAVIDROME = 'loves-lastfm-to-navidrome';
    public const TYPE_LOVES_NAVIDROME_TO_LASTFM = 'loves-navidrome-to-lastfm';
    public const TYPE_NAVIDROME_SYNC = 'navidrome-sync';
    public const TYPE_NAVIDROME_REMATCH = 'navidrome-rematch';
    public const TYPE_NAVIDROME_REQUEUE = 'navidrome-requeue';
    public const TYPE_STRAWBERRY_REQUEUE = 'strawberry-requeue';
    public const TYPE_NAVIDROME_ALIAS_MUSICBRAINZ = 'navidrome-alias-musicbrainz';
    public const TYPE_NAVIDROME_WIPE = 'navidrome-wipe';
    public const TYPE_STRAWBERRY_SYNC = 'strawberry-sync';
    public const TYPE_STRAWBERRY_REMATCH = 'strawberry-rematch';
    public const TYPE_STATS = 'stats';
    public const TYPE_PLAYLIST_GENERATE = 'playlist-generate';
    public const TYPE_RECOMMENDATIONS = 'recommendations';
    public const TYPE_BACKUP_PURGE = 'backup-purge';
    public const TYPE_HISTORY_PURGE = 'history-purge';

    public const STATUS_SUCCESS = 'success';
    public const STATUS_ERROR = 'error';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_RUNNING = 'running';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(length: 32)]
    private string $type;

    #[ORM\Column(length: 255)]
    private string $reference;

    #[ORM\Column(length: 255)]
    private string $label;

    #[ORM\Column(length: 16)]
    private string $status = self::STATUS_SUCCESS;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $durationMs = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $message = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $metrics = null;

    public function __construct(string $type, string $reference, string $label)
    {
        $this->type = $type;
        $this->reference = $reference;
        $this->label = $label;
        $this->startedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }
    public function getType(): string
    {
        return $this->type;
    }
    public function getReference(): string
    {
        return $this->reference;
    }
    public function getLabel(): string
    {
        return $this->label;
    }
    public function getStatus(): string
    {
        return $this->status;
    }
    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }
    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }
    public function getDurationMs(): ?int
    {
        return $this->durationMs;
    }
    public function getMessage(): ?string
    {
        return $this->message;
    }

    /** @return array<string, mixed>|null */
    public function getMetrics(): ?array
    {
        return $this->metrics;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;
        return $this;
    }
    public function setFinishedAt(?\DateTimeImmutable $at): self
    {
        $this->finishedAt = $at;
        return $this;
    }
    public function setDurationMs(?int $ms): self
    {
        $this->durationMs = $ms;
        return $this;
    }

    public function setMessage(?string $message): self
    {
        if ($message !== null && strlen($message) > 5000) {
            $message = substr($message, 0, 5000) . '… (truncated)';
        }
        $this->message = $message;
        return $this;
    }

    /** @param array<string, mixed>|null $metrics */
    public function setMetrics(?array $metrics): self
    {
        $this->metrics = $metrics;
        return $this;
    }
}
