<?php

declare(strict_types=1);

namespace App\Service\Product;

use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Enum\ProductStatus;
use App\Service\Import\ProductVariantParser;
use App\Service\Media\ProductMediaStorage;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\String\Slugger\SluggerInterface;

final class ProductEditorManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ProductVariantParser $variantParser,
        private readonly ProductMediaStorage $mediaStorage,
        private readonly SluggerInterface $slugger,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @param array<int, UploadedFile> $uploadedFiles
     */
    public function update(Product $product, array $input, array $uploadedFiles = []): Product
    {
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('Bitte einen Produktnamen angeben.');
        }

        $statusValue = trim((string) ($input['status'] ?? $product->getStatus()->value));

        try {
            $status = ProductStatus::from($statusValue);
        } catch (\ValueError) {
            throw new InvalidArgumentException('Der gewählte Produktstatus ist ungültig.');
        }

        $product
            ->setName($name)
            ->setSlug((string) $this->slugger->slug($name)->lower())
            ->setBrand($this->nullable($input['brand'] ?? null))
            ->setCategoryPath($this->nullable($input['category_path'] ?? null))
            ->setDescription($this->nullable($input['description'] ?? null))
            ->setStatus($status);

        foreach ($product->getVariants()->toArray() as $variant) {
            $product->removeVariant($variant);
        }

        $variantRows = $this->variantParser->parseText($this->nullable($input['variants_text'] ?? null));
        foreach ($variantRows as $row) {
            $variant = new ProductVariant($row['sku']);
            $variant
                ->setOptionSummary($row['options'])
                ->setEan($row['ean'])
                ->setPriceGross($row['priceGross'])
                ->setCurrency($row['currency'])
                ->setStock($row['stock'])
                ->setEnabled($row['enabled']);

            $product->addVariant($variant);
        }

        $position = $this->nextAssetPosition($product);
        foreach ($uploadedFiles as $uploadedFile) {
            $product->addAsset($this->mediaStorage->store($product, $uploadedFile, $position));
            ++$position;
        }

        $this->entityManager->persist($product);
        $this->entityManager->flush();

        return $product;
    }

    public function delete(Product $product): void
    {
        $this->mediaStorage->deleteProductMedia($product);
        $this->entityManager->remove($product);
        $this->entityManager->flush();
    }

    private function nextAssetPosition(Product $product): int
    {
        $highestPosition = 0;

        foreach ($product->getAssets() as $asset) {
            $highestPosition = max($highestPosition, $asset->getPosition());
        }

        return $highestPosition + 1;
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
