<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class ProductVariant
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'variants')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Product $product = null;

    #[ORM\Column(length: 120)]
    private string $sku;

    #[ORM\Column(type: Types::JSON)]
    private array $optionSummary = [];

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $ean = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?string $priceGross = null;

    #[ORM\Column(length: 3)]
    private string $currency = 'EUR';

    #[ORM\Column(nullable: true)]
    private ?int $stock = null;

    #[ORM\Column]
    private bool $enabled = true;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $sku)
    {
        $this->sku = $sku;
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

    public function getSku(): string
    {
        return $this->sku;
    }

    public function getOptionSummary(): array
    {
        return $this->optionSummary;
    }

    public function setOptionSummary(array $optionSummary): self
    {
        $this->optionSummary = $optionSummary;

        return $this;
    }

    public function getEan(): ?string
    {
        return $this->ean;
    }

    public function setEan(?string $ean): self
    {
        $this->ean = $ean;

        return $this;
    }

    public function getPriceGross(): ?string
    {
        return $this->priceGross;
    }

    public function setPriceGross(?string $priceGross): self
    {
        $this->priceGross = $priceGross;

        return $this;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function setCurrency(string $currency): self
    {
        $this->currency = $currency;

        return $this;
    }

    public function getStock(): ?int
    {
        return $this->stock;
    }

    public function setStock(?int $stock): self
    {
        $this->stock = $stock;

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

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
