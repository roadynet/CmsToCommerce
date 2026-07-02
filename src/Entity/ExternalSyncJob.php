<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ExternalSyncMode;
use App\Enum\ExternalSystemType;
use App\Repository\ExternalSyncJobRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ExternalSyncJobRepository::class)]
#[ORM\HasLifecycleCallbacks]
class ExternalSyncJob
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 190)]
    private string $name;

    #[ORM\Column(enumType: ExternalSystemType::class)]
    private ExternalSystemType $system;

    #[ORM\Column(enumType: ExternalSyncMode::class)]
    private ExternalSyncMode $mode;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $sourceUrl = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $sourceFilePath = null;

    #[ORM\Column(length: 10)]
    private string $requestMethod = 'GET';

    #[ORM\Column(type: Types::JSON)]
    private array $requestHeaders = [];

    #[ORM\Column(type: Types::JSON)]
    private array $requestBody = [];

    #[ORM\Column]
    private int $intervalMinutes = 60;

    #[ORM\Column]
    private bool $enabled = true;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastRunAt = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $lastStatus = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $lastMessage = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $name, ExternalSystemType $system, ExternalSyncMode $mode)
    {
        $this->name = $name;
        $this->system = $system;
        $this->mode = $mode;
        $this->createdAt = new \DateTimeImmutable();
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
        $this->touch();

        return $this;
    }

    public function getSystem(): ExternalSystemType
    {
        return $this->system;
    }

    public function setSystem(ExternalSystemType $system): self
    {
        $this->system = $system;
        $this->touch();

        return $this;
    }

    public function getMode(): ExternalSyncMode
    {
        return $this->mode;
    }

    public function setMode(ExternalSyncMode $mode): self
    {
        $this->mode = $mode;
        $this->touch();

        return $this;
    }

    public function getSourceUrl(): ?string
    {
        return $this->sourceUrl;
    }

    public function setSourceUrl(?string $sourceUrl): self
    {
        $this->sourceUrl = $sourceUrl !== null ? trim($sourceUrl) : null;
        $this->touch();

        return $this;
    }

    public function getSourceFilePath(): ?string
    {
        return $this->sourceFilePath;
    }

    public function setSourceFilePath(?string $sourceFilePath): self
    {
        $this->sourceFilePath = $sourceFilePath !== null ? trim($sourceFilePath) : null;
        $this->touch();

        return $this;
    }

    public function getRequestMethod(): string
    {
        return $this->requestMethod;
    }

    public function setRequestMethod(string $requestMethod): self
    {
        $this->requestMethod = strtoupper(trim($requestMethod)) ?: 'GET';
        $this->touch();

        return $this;
    }

    public function getRequestHeaders(): array
    {
        return $this->requestHeaders;
    }

    public function setRequestHeaders(array $requestHeaders): self
    {
        $this->requestHeaders = $requestHeaders;
        $this->touch();

        return $this;
    }

    public function getRequestBody(): array
    {
        return $this->requestBody;
    }

    public function setRequestBody(array $requestBody): self
    {
        $this->requestBody = $requestBody;
        $this->touch();

        return $this;
    }

    public function getIntervalMinutes(): int
    {
        return $this->intervalMinutes;
    }

    public function setIntervalMinutes(int $intervalMinutes): self
    {
        $this->intervalMinutes = max(1, $intervalMinutes);
        $this->touch();

        return $this;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): self
    {
        $this->enabled = $enabled;
        $this->touch();

        return $this;
    }

    public function getLastRunAt(): ?\DateTimeImmutable
    {
        return $this->lastRunAt;
    }

    public function getLastStatus(): ?string
    {
        return $this->lastStatus;
    }

    public function getLastMessage(): ?string
    {
        return $this->lastMessage;
    }

    public function markSuccess(string $message): self
    {
        $this->lastRunAt = new \DateTimeImmutable();
        $this->lastStatus = 'succeeded';
        $this->lastMessage = $message;
        $this->touch();

        return $this;
    }

    public function markFailure(string $message): self
    {
        $this->lastRunAt = new \DateTimeImmutable();
        $this->lastStatus = 'failed';
        $this->lastMessage = $message;
        $this->touch();

        return $this;
    }

    public function nextDueAt(): \DateTimeImmutable
    {
        return ($this->lastRunAt ?? $this->createdAt)->modify(sprintf('+%d minutes', max(1, $this->intervalMinutes)));
    }

    public function isDue(\DateTimeImmutable $now): bool
    {
        return $this->enabled && $this->nextDueAt() <= $now;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    #[ORM\PrePersist]
    public function initializeTimestamps(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
