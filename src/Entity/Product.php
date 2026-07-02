<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ChannelType;
use App\Enum\ProductStatus;
use App\Repository\ProductRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Ulid;

#[ORM\Entity(repositoryClass: ProductRepository::class)]
#[ORM\HasLifecycleCallbacks]
class Product
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'ulid', unique: true)]
    private Ulid $publicId;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $slug = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $brand = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $categoryPath = null;

    #[ORM\Column(enumType: ProductStatus::class)]
    private ProductStatus $status = ProductStatus::Draft;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, ProductSource> */
    #[ORM\OneToMany(mappedBy: 'product', targetEntity: ProductSource::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['importedAt' => 'DESC'])]
    private Collection $sources;

    /** @var Collection<int, ProductAsset> */
    #[ORM\OneToMany(mappedBy: 'product', targetEntity: ProductAsset::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $assets;

    /** @var Collection<int, ProductVariant> */
    #[ORM\OneToMany(mappedBy: 'product', targetEntity: ProductVariant::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $variants;

    /** @var Collection<int, ChannelListing> */
    #[ORM\OneToMany(mappedBy: 'product', targetEntity: ChannelListing::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $channelListings;

    /** @var Collection<int, PublicationRun> */
    #[ORM\OneToMany(mappedBy: 'product', targetEntity: PublicationRun::class, cascade: ['persist', 'remove'])]
    private Collection $publicationRuns;

    public function __construct(string $name)
    {
        $this->publicId = new Ulid();
        $this->name = $name;
        $this->sources = new ArrayCollection();
        $this->assets = new ArrayCollection();
        $this->variants = new ArrayCollection();
        $this->channelListings = new ArrayCollection();
        $this->publicationRuns = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPublicId(): Ulid
    {
        return $this->publicId;
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

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function setSlug(?string $slug): self
    {
        $this->slug = $slug;
        $this->touch();

        return $this;
    }

    public function getBrand(): ?string
    {
        return $this->brand;
    }

    public function setBrand(?string $brand): self
    {
        $this->brand = $brand;
        $this->touch();

        return $this;
    }

    public function getCategoryPath(): ?string
    {
        return $this->categoryPath;
    }

    public function setCategoryPath(?string $categoryPath): self
    {
        $this->categoryPath = $categoryPath;
        $this->touch();

        return $this;
    }

    public function getStatus(): ProductStatus
    {
        return $this->status;
    }

    public function setStatus(ProductStatus $status): self
    {
        $this->status = $status;
        $this->touch();

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;
        $this->touch();

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

    /** @return Collection<int, ProductSource> */
    public function getSources(): Collection
    {
        return $this->sources;
    }

    public function addSource(ProductSource $source): self
    {
        if (!$this->sources->contains($source)) {
            $this->sources->add($source);
            $source->setProduct($this);
            $this->touch();
        }

        return $this;
    }

    public function removeSource(ProductSource $source): self
    {
        if ($this->sources->removeElement($source) && $source->getProduct() === $this) {
            $source->setProduct(null);
            $this->touch();
        }

        return $this;
    }

    /** @return Collection<int, ProductAsset> */
    public function getAssets(): Collection
    {
        return $this->assets;
    }

    public function addAsset(ProductAsset $asset): self
    {
        if (!$this->assets->contains($asset)) {
            $this->assets->add($asset);
            $asset->setProduct($this);
            $this->touch();
        }

        return $this;
    }

    public function removeAsset(ProductAsset $asset): self
    {
        if ($this->assets->removeElement($asset) && $asset->getProduct() === $this) {
            $asset->setProduct(null);
            $this->touch();
        }

        return $this;
    }

    /** @return Collection<int, ProductVariant> */
    public function getVariants(): Collection
    {
        return $this->variants;
    }

    public function addVariant(ProductVariant $variant): self
    {
        if (!$this->variants->contains($variant)) {
            $this->variants->add($variant);
            $variant->setProduct($this);
            $this->touch();
        }

        return $this;
    }

    public function removeVariant(ProductVariant $variant): self
    {
        if ($this->variants->removeElement($variant) && $variant->getProduct() === $this) {
            $variant->setProduct(null);
            $this->touch();
        }

        return $this;
    }

    /** @return Collection<int, ChannelListing> */
    public function getChannelListings(): Collection
    {
        return $this->channelListings;
    }

    public function addChannelListing(ChannelListing $listing): self
    {
        if (!$this->channelListings->contains($listing)) {
            $this->channelListings->add($listing);
            $listing->setProduct($this);
            $this->touch();
        }

        return $this;
    }

    public function getChannelListingFor(ChannelType $channel): ?ChannelListing
    {
        foreach ($this->channelListings as $listing) {
            if ($listing->getChannel() === $channel) {
                return $listing;
            }
        }

        return null;
    }

    /** @return Collection<int, PublicationRun> */
    public function getPublicationRuns(): Collection
    {
        return $this->publicationRuns;
    }

    public function addPublicationRun(PublicationRun $run): self
    {
        if (!$this->publicationRuns->contains($run)) {
            $this->publicationRuns->add($run);
            $run->setProduct($this);
        }

        return $this;
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
