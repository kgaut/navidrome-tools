<?php

namespace App\Entity;

use App\Repository\AudioFeatureRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Audio features of a Navidrome track, imported from AudioMuse-AI's
 * `/api/sync` export (issue #257). AudioMuse ids ARE Navidrome media_file
 * ids, so the row is keyed on it directly. Written in bulk by
 * {@see AudioFeatureRepository::upsert()}; `fingerprint` is AudioMuse's
 * `fp`, which changes whenever the track is re-analysed.
 *
 * Scales: `energy` is a dBFS-linear loudness on 0..1 (real music lands
 * around 0.32..0.93); the six mood scores are 0..1, a mood « applies » from
 * 0.5 (AudioMuse's MOOD_SCORE_MATCH_THRESHOLD). `tempo` is the detected BPM,
 * which can be half or double the perceived tempo.
 */
#[ORM\Entity(repositoryClass: AudioFeatureRepository::class)]
#[ORM\Table(name: 'audio_feature')]
#[ORM\Index(name: 'idx_audio_feature_tempo', columns: ['tempo'])]
class AudioFeature
{
    #[ORM\Id]
    #[ORM\Column(length: 64)]
    private string $mediaFileId;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $tempo = null;

    #[ORM\Column(length: 8, nullable: true)]
    private ?string $musicKey = null;

    #[ORM\Column(length: 8, nullable: true)]
    private ?string $scale = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $energy = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $danceable = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $aggressive = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $happy = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $party = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $relaxed = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $sad = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $topGenre = null;

    /** @var array<string, float> genre => weight */
    #[ORM\Column(type: Types::JSON)]
    private array $genres = [];

    #[ORM\Column(length: 32)]
    private string $fingerprint;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $syncedAt;

    public function __construct(string $mediaFileId, string $fingerprint)
    {
        $this->mediaFileId = $mediaFileId;
        $this->fingerprint = $fingerprint;
        $this->syncedAt = new \DateTimeImmutable();
    }

    public function getMediaFileId(): string
    {
        return $this->mediaFileId;
    }

    public function getTempo(): ?float
    {
        return $this->tempo;
    }

    public function getEnergy(): ?float
    {
        return $this->energy;
    }

    public function getRelaxed(): ?float
    {
        return $this->relaxed;
    }

    public function getTopGenre(): ?string
    {
        return $this->topGenre;
    }

    /** @return array<string, float> */
    public function getGenres(): array
    {
        return $this->genres;
    }

    public function getFingerprint(): string
    {
        return $this->fingerprint;
    }
}
