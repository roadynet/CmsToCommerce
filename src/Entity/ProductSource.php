<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\SourceType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class ProductSource
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'sources')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Product $product = null;

    #[ORM\Column(enumType: SourceType::class)]
    private SourceType $sourceType;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $cmsSystem = null;

    #[ORM\Column(length: 190, nullable: true)]
    private ?string $externalReference = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $rawPayload;

    #[ORM\Column(length: 10)]
    private string $languageCode = 'de';

    #[ORM\Column]
    private \DateTimeImmutable $importedAt;

    public function __construct(SourceType $sourceType, string $rawPayload)
    {
        $this->sourceType = $sourceType;
        $this->rawPayload = $rawPayload;
        $this->importedAt = new \DateTimeImmutable();
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

    public function getSourceType(): SourceType
    {
        return $this->sourceType;
    }

    public function getCmsSystem(): ?string
    {
        return $this->cmsSystem;
    }

    public function setCmsSystem(?string $cmsSystem): self
    {
        $this->cmsSystem = $cmsSystem;

        return $this;
    }

    public function getExternalReference(): ?string
    {
        return $this->externalReference;
    }

    public function setExternalReference(?string $externalReference): self
    {
        $this->externalReference = $externalReference;

        return $this;
    }

    public function getRawPayload(): string
    {
        return $this->rawPayload;
    }

    public function setRawPayload(string $rawPayload): self
    {
        $this->rawPayload = $rawPayload;

        return $this;
    }

    public function getLanguageCode(): string
    {
        return $this->languageCode;
    }

    public function setLanguageCode(string $languageCode): self
    {
        $this->languageCode = $languageCode;

        return $this;
    }

    public function getImportedAt(): \DateTimeImmutable
    {
        return $this->importedAt;
    }
}
