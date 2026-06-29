<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ChannelType;
use App\Enum\ListingStatus;
use App\Repository\ChannelListingRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ChannelListingRepository::class)]
class ChannelListing
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'channelListings')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Product $product = null;

    #[ORM\Column(enumType: ChannelType::class)]
    private ChannelType $channel;

    #[ORM\Column(length: 190, nullable: true)]
    private ?string $externalId = null;

    #[ORM\Column(enumType: ListingStatus::class)]
    private ListingStatus $status = ListingStatus::Draft;

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\Column(type: Types::JSON)]
    private array $bulletPoints = [];

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: Types::JSON)]
    private array $technicalAttributes = [];

    #[ORM\Column(type: Types::JSON)]
    private array $searchTerms = [];

    #[ORM\Column(nullable: true)]
    private ?int $qualityScore = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $qualityNotes = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastSyncedAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $syncError = null;

    public function __construct(ChannelType $channel, string $title)
    {
        $this->channel = $channel;
        $this->title = $title;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProduct(): ?Product
    {
        return $this->product;
    }

    public function setProduct(?Product $product): self
    {
        $this->product = $product;

        return $this;
    }

    public function getChannel(): ChannelType
    {
        return $this->channel;
    }

    public function getExternalId(): ?string
    {
        return $this->externalId;
    }

    public function setExternalId(?string $externalId): self
    {
        $this->externalId = $externalId;

        return $this;
    }

    public function getStatus(): ListingStatus
    {
        return $this->status;
    }

    public function setStatus(ListingStatus $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): self
    {
        $this->title = $title;

        return $this;
    }

    public function getBulletPoints(): array
    {
        return $this->bulletPoints;
    }

    public function setBulletPoints(array $bulletPoints): self
    {
        $this->bulletPoints = $bulletPoints;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function getTechnicalAttributes(): array
    {
        return $this->technicalAttributes;
    }

    public function setTechnicalAttributes(array $technicalAttributes): self
    {
        $this->technicalAttributes = $technicalAttributes;

        return $this;
    }

    public function getSearchTerms(): array
    {
        return $this->searchTerms;
    }

    public function setSearchTerms(array $searchTerms): self
    {
        $this->searchTerms = $searchTerms;

        return $this;
    }

    public function getQualityScore(): ?int
    {
        return $this->qualityScore;
    }

    public function setQualityScore(?int $qualityScore): self
    {
        $this->qualityScore = $qualityScore;

        return $this;
    }

    public function getQualityNotes(): ?string
    {
        return $this->qualityNotes;
    }

    public function setQualityNotes(?string $qualityNotes): self
    {
        $this->qualityNotes = $qualityNotes;

        return $this;
    }

    public function getLastSyncedAt(): ?\DateTimeImmutable
    {
        return $this->lastSyncedAt;
    }

    public function markSynced(?string $externalId = null): self
    {
        $this->status = ListingStatus::Published;
        $this->lastSyncedAt = new \DateTimeImmutable();
        $this->syncError = null;
        $this->externalId = $externalId ?? $this->externalId;

        return $this;
    }

    public function markValidated(?string $externalId = null): self
    {
        $this->status = ListingStatus::Validated;
        $this->lastSyncedAt = new \DateTimeImmutable();
        $this->syncError = null;
        $this->externalId = $externalId ?? $this->externalId;

        return $this;
    }

    public function markSyncError(string $message): self
    {
        $this->status = ListingStatus::SyncError;
        $this->syncError = $message;
        $this->lastSyncedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getSyncError(): ?string
    {
        return $this->syncError;
    }
}
