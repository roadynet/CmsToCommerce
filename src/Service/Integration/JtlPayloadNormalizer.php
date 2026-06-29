<?php

declare(strict_types=1);

namespace App\Service\Integration;

use App\Enum\ExternalSystemType;

final class JtlPayloadNormalizer extends AbstractExternalSystemPayloadNormalizer
{
    public function system(): ExternalSystemType
    {
        return ExternalSystemType::Jtl;
    }

    public function supports(?string $systemHint, array $payload): bool
    {
        if ($systemHint !== null && trim($systemHint) !== '' && !$this->matchesHint($systemHint, 'jtl', 'jtl-wawi')) {
            return false;
        }

        return $this->matchesHint($systemHint, 'jtl', 'jtl-wawi')
            || is_array($payload['article'] ?? null)
            || is_array($payload['variations'] ?? null) && array_key_exists('manufacturerName', (array) ($payload['article'] ?? []));
    }

    public function normalize(array $payload): array
    {
        $name = $this->stringValue(
            $this->pathValue($payload, 'article.name', 'article.articleName', 'product.name'),
            $payload['name'] ?? null,
            $payload['bezeichnung'] ?? null,
        );
        $brand = $this->stringValue(
            $this->pathValue($payload, 'article.manufacturerName', 'article.brand', 'manufacturer.name'),
            $payload['marke'] ?? null,
        );
        $categoryPath = $this->categoryPath(
            $this->pathValue($payload, 'article.categoryPath', 'article.categories', 'categories')
        );
        $description = $this->stringValue(
            $this->pathValue($payload, 'article.description', 'article.shortDescription', 'description'),
            $payload['beschreibung'] ?? null,
        );
        $attributes = $this->optionMap($this->pathValue($payload, 'article.attributes', 'attributes'));
        $variants = $this->mapVariants($this->pathValue($payload, 'variants', 'article.variations', 'variations'));

        return [
            'produkt_name' => $name,
            'marke' => $brand,
            'kategorie_pfad' => $categoryPath,
            'beschreibung' => $description,
            'rohtext' => $this->buildRawText($description, [
                'Marke' => $brand,
                'Kategorie' => $categoryPath,
                ...$attributes,
            ]),
            'cms_system' => 'jtl',
            'external_reference' => $this->stringValue(
                $this->pathValue($payload, 'article.id', 'article.articleId'),
                $payload['id'] ?? null,
            ),
            'sprache' => $this->stringValue(
                $payload['language'] ?? null,
                $this->pathValue($payload, 'article.language'),
            ) ?? 'de',
            'asset_urls' => $this->assetDescriptors($this->pathValue($payload, 'images', 'article.images')),
            'variants' => $variants,
            'source_payload' => $this->preservedPayload(
                $payload,
                [
                    'system' => 'jtl',
                    'name' => $name,
                    'brand' => $brand,
                    'category_path' => $categoryPath,
                    'variant_count' => count($variants),
                ],
                ['bild_urls' => $this->imageUrls($this->pathValue($payload, 'images', 'article.images'))],
            ),
        ];
    }

    public function overview(): array
    {
        return [
            'code' => $this->system()->value,
            'label' => $this->system()->label(),
            'status' => 'intake + medien bereit',
            'summary' => 'CTC erkennt JTL-Artikel, Varianten, Kategorien und externe Bildquellen bereits beim API-Eingang.',
            'next_step' => 'Als Nächstes können wir direkte JTL-API-Abrufe und Zeitsteuerung ergänzen.',
            'example_keys' => ['article.name', 'article.manufacturerName', 'article.categoryPath', 'variants', 'images'],
            'intake_ready' => true,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function mapVariants(mixed $value): array
    {
        $variants = [];

        foreach ($this->listOfArrays($value) as $variant) {
            $variants[] = [
                'sku' => $this->stringValue($variant['sku'] ?? null, $variant['articleNumber'] ?? null, $variant['number'] ?? null),
                'ean' => $this->stringValue($variant['ean'] ?? null, $variant['gtin'] ?? null, $variant['barcode'] ?? null),
                'price' => $this->stringValue($variant['priceGross'] ?? null, $variant['salesPriceGross'] ?? null, $variant['price'] ?? null),
                'stock' => $variant['stock'] ?? $variant['quantity'] ?? null,
                'enabled' => $variant['enabled'] ?? $variant['active'] ?? true,
                'options' => $this->optionMap($variant['options'] ?? $variant['attributes'] ?? null),
            ];
        }

        return array_values(array_filter($variants, static fn (array $variant): bool => trim((string) ($variant['sku'] ?? '')) !== ''));
    }
}
