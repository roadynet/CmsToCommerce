<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ChannelType;
use App\Enum\SyncStatus;
use App\Repository\PublicationRunRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PublicationRunRepository::class)]
class PublicationRun
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'publicationRuns')]
    #[ORM\JoinColumn(nullable: true)]
    private ?Product $product = null;

    #[ORM\Column(enumType: ChannelType::class)]
    private ChannelType $channel;

    #[ORM\Column(length: 80)]
    private string $action;

    #[ORM\Column(enumType: SyncStatus::class)]
    private SyncStatus $status = SyncStatus::Pending;

    #[ORM\Column(type: Types::JSON)]
    private array $payload = [];

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $summary = null;

    #[ORM\Column]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    public function __construct(ChannelType $channel, string $action)
    {
        $this->channel = $channel;
        $this->action = $action;
        $this->startedAt = new \DateTimeImmutable();
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

    public function getAction(): string
    {
        return $this->action;
    }

    public function getActionLabel(): string
    {
        return match ($this->action) {
            'publish' => 'Veröffentlichen',
            default => ucfirst(str_replace('_', ' ', $this->action)),
        };
    }

    public function getStatus(): SyncStatus
    {
        return $this->status;
    }

    public function setStatus(SyncStatus $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getPayload(): array
    {
        return $this->payload;
    }

    public function setPayload(array $payload): self
    {
        $this->payload = $payload;

        return $this;
    }

    public function getSummary(): ?string
    {
        return $this->summary;
    }

    public function setSummary(?string $summary): self
    {
        $this->summary = $summary;

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

    public function finish(SyncStatus $status, ?string $summary = null): self
    {
        $this->status = $status;
        $this->summary = $summary;
        $this->finishedAt = new \DateTimeImmutable();

        return $this;
    }
}
