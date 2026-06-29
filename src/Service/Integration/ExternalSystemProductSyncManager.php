<?php

declare(strict_types=1);

namespace App\Service\Integration;

use App\Dto\ExternalSystemSyncResult;
use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Enum\ProductStatus;
use App\Repository\ProductRepository;
use App\Service\Import\ProductVariantParser;
use App\Service\Media\ProductMediaStorage;
use App\Service\Product\ProductIntakeManager;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Symfony\Component\String\Slugger\SluggerInterface;

final class ExternalSystemProductSyncManager
{
    public function __construct(
        private readonly ProductRepository $productRepository,
        private readonly ProductIntakeManager $productIntakeManager,
        private readonly ProductVariantParser $productVariantParser,
        private readonly ProductMediaStorage $productMediaStorage,
        private readonly EntityManagerInterface $entityManager,
        private readonly SluggerInterface $slugger,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function sync(array $payload, bool $deltaOnly = false): ExternalSystemSyncResult
    {
        $product = $this->locateExistingProduct($payload);

        if ($product === null) {
            if ($deltaOnly) {
                throw new InvalidArgumentException('Delta-Sync braucht ein bestehendes Produkt. Bitte zuerst einen vollständigen Intake ausführen.');
            }

            $product = $this->productIntakeManager->createFromApiPayload($payload);
            [$mediaAdded, $warnings] = $this->syncRemoteAssets($product, $payload['asset_urls'] ?? []);

            if ($mediaAdded > 0) {
                $this->entityManager->persist($product);
                $this->entityManager->flush();
            }

            return new ExternalSystemSyncResult($product, true, false, $mediaAdded, 0, count($product->getVariants()), $warnings);
        }

        if ($deltaOnly) {
            [$variantsUpdated, $variantsCreated] = $this->applyVariantDelta($product, $payload);
        } else {
            [$variantsUpdated, $variantsCreated] = $this->mergeProductData($product, $payload);
        }

        $product->addSource($this->productIntakeManager->createSourceFromApiPayload($payload));
        [$mediaAdded, $warnings] = $this->syncRemoteAssets($product, $payload['asset_urls'] ?? []);

        $this->entityManager->persist($product);
        $this->entityManager->flush();

        return new ExternalSystemSyncResult($product, false, $deltaOnly, $mediaAdded, $variantsUpdated, $variantsCreated, $warnings);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function locateExistingProduct(array $payload): ?Product
    {
        $cmsSystem = $this->nullable($payload['cms_system'] ?? null);
        $externalReference = $this->nullable($payload['external_reference'] ?? $payload['externe_referenz'] ?? null);

        if ($externalReference !== null) {
            $existing = $this->productRepository->findOneBySourceReference($cmsSystem, $externalReference);
            if ($existing !== null) {
                return $existing;
            }
        }

        $variantRows = isset($payload['variants']) && is_array($payload['variants'])
            ? $this->productVariantParser->parseStructured($payload['variants'])
            : [];

        foreach ($variantRows as $row) {
            $sku = trim((string) ($row['sku'] ?? ''));
            if ($sku === '') {
                continue;
            }

            $existing = $this->productRepository->findOneByVariantSku($sku);
            if ($existing !== null) {
                return $existing;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{0: int, 1: int}
     */
    private function mergeProductData(Product $product, array $payload): array
    {
        $name = $this->nullable($payload['produkt_name'] ?? $payload['name'] ?? null);
        if ($name !== null && $name !== $product->getName()) {
            $product
                ->setName($name)
                ->setSlug((string) $this->slugger->slug($name)->lower());
        }

        $brand = $this->nullable($payload['marke'] ?? $payload['brand'] ?? null);
        if ($brand !== null) {
            $product->setBrand($brand);
        }

        $categoryPath = $this->nullable($payload['kategorie_pfad'] ?? $payload['category_path'] ?? null);
        if ($categoryPath !== null) {
            $product->setCategoryPath($categoryPath);
        }

        $description = $this->nullable($payload['beschreibung'] ?? $payload['description'] ?? null);
        if ($description !== null) {
            $product->setDescription($description);
        }

        if ($product->getStatus() === ProductStatus::Draft) {
            $product->setStatus(ProductStatus::Imported);
        }

        return $this->mergeVariants($product, $payload, false);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{0: int, 1: int}
     */
    private function applyVariantDelta(Product $product, array $payload): array
    {
        return $this->mergeVariants($product, $payload, true);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{0: int, 1: int}
     */
    private function mergeVariants(Product $product, array $payload, bool $deltaOnly): array
    {
        $variantRows = isset($payload['variants']) && is_array($payload['variants'])
            ? $this->productVariantParser->parseStructured($payload['variants'])
            : [];

        if ($variantRows === []) {
            return [0, 0];
        }

        $existingBySku = [];
        foreach ($product->getVariants() as $existingVariant) {
            $existingBySku[$existingVariant->getSku()] = $existingVariant;
        }

        $updated = 0;
        $created = 0;

        foreach ($variantRows as $row) {
            $sku = trim((string) ($row['sku'] ?? ''));
            if ($sku === '') {
                continue;
            }

            $variant = $existingBySku[$sku] ?? null;
            if ($variant === null) {
                $variant = new ProductVariant($sku);
                $product->addVariant($variant);
                ++$created;
            } else {
                ++$updated;
            }

            if (!$deltaOnly || $row['options'] !== []) {
                $variant->setOptionSummary($row['options']);
            }

            if (!$deltaOnly || $row['ean'] !== null) {
                $variant->setEan($row['ean']);
            }

            if ($row['priceGross'] !== null) {
                $variant->setPriceGross($row['priceGross']);
            }

            if (($row['currency'] ?? '') !== '') {
                $variant->setCurrency($row['currency']);
            }

            if ($row['stock'] !== null) {
                $variant->setStock($row['stock']);
            }

            $variant->setEnabled((bool) ($row['enabled'] ?? true));
        }

        return [$updated, $created];
    }

    /**
     * @param mixed $assets
     *
     * @return array{0: int, 1: list<string>}
     */
    private function syncRemoteAssets(Product $product, mixed $assets): array
    {
        if (!is_array($assets)) {
            return [0, []];
        }

        $existingNames = [];
        foreach ($product->getAssets() as $asset) {
            $existingNames[strtolower($asset->getOriginalName())] = true;
        }

        $position = $this->nextAssetPosition($product);
        $added = 0;
        $warnings = [];

        foreach ($assets as $assetDescriptor) {
            if (!is_array($assetDescriptor)) {
                continue;
            }

            $url = $this->nullable($assetDescriptor['url'] ?? null);
            if ($url === null) {
                continue;
            }

            $originalName = $this->nullable($assetDescriptor['name'] ?? null) ?? $this->basenameFromUrl($url);
            $dedupeKey = strtolower($originalName);
            if ($dedupeKey !== '' && isset($existingNames[$dedupeKey])) {
                continue;
            }

            try {
                $asset = $this->productMediaStorage->storeRemoteUrl(
                    $product,
                    $url,
                    $position,
                    $originalName,
                    $this->nullable($assetDescriptor['alt'] ?? null),
                );
                $product->addAsset($asset);
                if ($dedupeKey !== '') {
                    $existingNames[$dedupeKey] = true;
                }
                ++$position;
                ++$added;
            } catch (\Throwable $exception) {
                $warnings[] = sprintf('Medium "%s" konnte nicht geladen werden: %s', $url, $exception->getMessage());
            }
        }

        return [$added, $warnings];
    }

    private function nextAssetPosition(Product $product): int
    {
        $highestPosition = 0;

        foreach ($product->getAssets() as $asset) {
            $highestPosition = max($highestPosition, $asset->getPosition());
        }

        return $highestPosition + 1;
    }

    private function basenameFromUrl(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $basename = trim(basename($path));

        return $basename !== '' ? $basename : 'remote-asset';
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
