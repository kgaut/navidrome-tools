<?php

namespace App\Entity;

use App\Doctrine\UtcDateTimeImmutableType;
use App\Repository\ListenBrainzLookupCacheRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Cached answer of the ListenBrainz metadata lookup used to love a track
 * without MBID (issue #319). `recording_mbid` null = not found: the track is
 * not searched again before the TTL.
 */
#[ORM\Entity(repositoryClass: ListenBrainzLookupCacheRepository::class)]
#[ORM\Table(name: 'listenbrainz_lookup_cache')]
#[ORM\UniqueConstraint(name: 'uniq_lb_lookup_key', columns: ['lookup_key'])]
class ListenBrainzLookupCacheEntry
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(length: 512)]
    private string $lookupKey;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $recordingMbid;

    #[ORM\Column(type: UtcDateTimeImmutableType::NAME)]
    private \DateTimeImmutable $checkedAt;

    public function __construct(string $lookupKey, ?string $recordingMbid, \DateTimeImmutable $checkedAt)
    {
        $this->lookupKey = $lookupKey;
        $this->recordingMbid = $recordingMbid;
        $this->checkedAt = $checkedAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLookupKey(): string
    {
        return $this->lookupKey;
    }

    public function getRecordingMbid(): ?string
    {
        return $this->recordingMbid;
    }

    public function getCheckedAt(): \DateTimeImmutable
    {
        return $this->checkedAt;
    }
}
