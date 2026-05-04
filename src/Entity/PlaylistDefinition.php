<?php

namespace App\Entity;

use App\Repository\PlaylistDefinitionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: PlaylistDefinitionRepository::class)]
#[ORM\Table(name: 'playlist_definition')]
#[ORM\HasLifecycleCallbacks]
class PlaylistDefinition
{
    public const STATUS_SUCCESS = 'success';
    public const STATUS_ERROR = 'error';
    public const STATUS_SKIPPED = 'skipped';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(length: 255, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $name = '';

    #[ORM\Column(length: 64)]
    #[Assert\NotBlank]
    private string $generatorKey = '';

    /**
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $parameters = [];

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    #[Assert\Range(min: 1, max: 1000)]
    private ?int $limitOverride = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $playlistNameTemplate = null;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $enabled = true;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $replaceExisting = true;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastRunAt = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $lastRunStatus = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $lastRunMessage = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $lastSubsonicPlaylistId = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function getGeneratorKey(): string
    {
        return $this->generatorKey;
    }

    public function setGeneratorKey(string $key): self
    {
        $this->generatorKey = $key;
        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getParameters(): array
    {
        return $this->parameters;
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public function setParameters(array $parameters): self
    {
        $this->parameters = $parameters;
        return $this;
    }

    public function getLimitOverride(): ?int
    {
        return $this->limitOverride;
    }

    public function setLimitOverride(?int $limit): self
    {
        $this->limitOverride = $limit;
        return $this;
    }

    public function getPlaylistNameTemplate(): ?string
    {
        return $this->playlistNameTemplate;
    }

    public function setPlaylistNameTemplate(?string $template): self
    {
        $this->playlistNameTemplate = $template;
        return $this;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): self
    {
        $this->enabled = $enabled;
        return $this;
    }

    public function isReplaceExisting(): bool
    {
        return $this->replaceExisting;
    }

    public function setReplaceExisting(bool $replace): self
    {
        $this->replaceExisting = $replace;
        return $this;
    }

    public function getLastRunAt(): ?\DateTimeImmutable
    {
        return $this->lastRunAt;
    }

    public function setLastRunAt(?\DateTimeImmutable $at): self
    {
        $this->lastRunAt = $at;
        return $this;
    }

    public function getLastRunStatus(): ?string
    {
        return $this->lastRunStatus;
    }

    public function setLastRunStatus(?string $status): self
    {
        $this->lastRunStatus = $status;
        return $this;
    }

    public function getLastRunMessage(): ?string
    {
        return $this->lastRunMessage;
    }

    public function setLastRunMessage(?string $message): self
    {
        $this->lastRunMessage = $message;
        return $this;
    }

    public function getLastSubsonicPlaylistId(): ?string
    {
        return $this->lastSubsonicPlaylistId;
    }

    public function setLastSubsonicPlaylistId(?string $id): self
    {
        $this->lastSubsonicPlaylistId = $id;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
