<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\AssetType;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class ProductAsset
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'assets')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Product $product = null;

    #[ORM\Column(enumType: AssetType::class)]
    private AssetType $assetType;

    #[ORM\Column(length: 190)]
    private string $filename;

    #[ORM\Column(length: 190)]
    private string $originalName;

    #[ORM\Column(length: 120)]
    private string $mimeType;

    #[ORM\Column(length: 255)]
    private string $storagePath;

    #[ORM\Column]
    private int $position = 0;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $altText = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(AssetType $assetType, string $filename, string $originalName, string $mimeType, string $storagePath)
    {
        $this->assetType = $assetType;
        $this->filename = $filename;
        $this->originalName = $originalName;
        $this->mimeType = $mimeType;
        $this->storagePath = $storagePath;
        $this->createdAt = new \DateTimeImmutable();
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

    public function getAssetType(): AssetType
    {
        return $this->assetType;
    }

    public function getFilename(): string
    {
        return $this->filename;
    }

    public function getOriginalName(): string
    {
        return $this->originalName;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function getStoragePath(): string
    {
        return $this->storagePath;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): self
    {
        $this->position = $position;

        return $this;
    }

    public function getAltText(): ?string
    {
        return $this->altText;
    }

    public function setAltText(?string $altText): self
    {
        $this->altText = $altText;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
