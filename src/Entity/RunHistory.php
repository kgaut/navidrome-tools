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
    public const TYPE_PLAYLIST = 'playlist';
    public const TYPE_STATS = 'stats';
    public const TYPE_LASTFM_IMPORT = 'lastfm-import';
    public const TYPE_LASTFM_LOVE_SYNC = 'lastfm-love-sync';
    public const TYPE_LASTFM_REMATCH = 'lastfm-rematch';

    public const STATUS_SUCCESS = 'success';
    public const STATUS_ERROR = 'error';
    public const STATUS_SKIPPED = 'skipped';

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

    public function setStatus(string $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function setFinishedAt(?\DateTimeImmutable $at): self
    {
        $this->finishedAt = $at;
        return $this;
    }

    public function getDurationMs(): ?int
    {
        return $this->durationMs;
    }

    public function setDurationMs(?int $ms): self
    {
        $this->durationMs = $ms;
        return $this;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function setMessage(?string $message): self
    {
        if ($message !== null && strlen($message) > 5000) {
            $message = substr($message, 0, 5000) . '… (truncated)';
        }
        $this->message = $message;
        return $this;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getMetrics(): ?array
    {
        return $this->metrics;
    }

    /**
     * @param array<string, mixed>|null $metrics
     */
    public function setMetrics(?array $metrics): self
    {
        $this->metrics = $metrics;
        return $this;
    }
}
