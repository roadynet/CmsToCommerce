<?php

declare(strict_types=1);

namespace App\Service\Integration;

use App\Enum\ExternalSystemType;

final class PlentymarketsPayloadNormalizer extends AbstractExternalSystemPayloadNormalizer
{
    public function system(): ExternalSystemType
    {
        return ExternalSystemType::Plentymarkets;
    }

    public function supports(?string $systemHint, array $payload): bool
    {
        if ($systemHint !== null && trim($systemHint) !== '' && !$this->matchesHint($systemHint, 'plentymarkets', 'plenty')) {
            return false;
        }

        return $this->matchesHint($systemHint, 'plentymarkets', 'plenty')
            || is_array($payload['variation'] ?? null)
            || is_array($payload['item'] ?? null) && is_array(($payload['item']['texts'] ?? null));
    }

    public function normalize(array $payload): array
    {
        $name = $this->stringValue(
            $this->pathValue($payload, 'item.texts.name1', 'texts.name1', 'variation.name'),
            $payload['name'] ?? null,
        );
        $brand = $this->stringValue(
            $this->pathValue($payload, 'item.manufacturer.externalName', 'manufacturer.name'),
            $payload['marke'] ?? null,
        );
        $categoryPath = $this->categoryPath(
            $this->pathValue($payload, 'categories', 'item.categories')
        );
        $description = $this->stringValue(
            $this->pathValue($payload, 'item.texts.description', 'texts.description', 'texts.shortDescription'),
            $payload['beschreibung'] ?? null,
        );
        $variants = $this->mapVariants($payload);

        return [
            'produkt_name' => $name,
            'marke' => $brand,
            'kategorie_pfad' => $categoryPath,
            'beschreibung' => $description,
            'rohtext' => $this->buildRawText($description, [
                'Marke' => $brand,
                'Kategorie' => $categoryPath,
                'Quelle' => 'plentymarkets',
            ]),
            'cms_system' => 'plentymarkets',
            'external_reference' => $this->stringValue(
                $this->pathValue($payload, 'variation.id', 'item.id'),
                $payload['id'] ?? null,
            ),
            'sprache' => $this->stringValue($payload['language'] ?? null, $payload['lang'] ?? null) ?? 'de',
            'asset_urls' => $this->assetDescriptors($this->pathValue($payload, 'images', 'item.images')),
            'variants' => $variants,
            'source_payload' => $this->preservedPayload(
                $payload,
                [
                    'system' => 'plentymarkets',
                    'name' => $name,
                    'brand' => $brand,
                    'category_path' => $categoryPath,
                    'variant_count' => count($variants),
                ],
                ['bild_urls' => $this->imageUrls($this->pathValue($payload, 'images', 'item.images'))],
            ),
        ];
    }

    public function overview(): array
    {
        return [
            'code' => $this->system()->value,
            'label' => $this->system()->label(),
            'status' => 'intake + medien bereit',
            'summary' => 'CTC kann plentymarkets-Artikeltexte, Herstellerinfos, Kategorien, Varianten und Bildquellen in den Produktstamm überführen.',
            'next_step' => 'Danach lohnt sich die Übernahme von Kanalbezügen und Status-Feedback aus plenty.',
            'example_keys' => ['item.texts.name1', 'item.manufacturer.externalName', 'variation.number', 'variations', 'item.images'],
            'intake_ready' => true,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<array<string, mixed>>
     */
    private function mapVariants(array $payload): array
    {
        $candidates = $this->listOfArrays($payload['variations'] ?? null);

        if ($candidates === []) {
            $singleVariation = $payload['variation'] ?? null;
            if (is_array($singleVariation)) {
                $candidates = [$singleVariation];
            }
        }

        $variants = [];
        foreach ($candidates as $variant) {
            $barcodeRows = $this->listOfArrays($variant['barcodes'] ?? $variant['variationBarcodes'] ?? null);
            $ean = null;
            foreach ($barcodeRows as $barcodeRow) {
                $ean = $this->stringValue($barcodeRow['code'] ?? null, $barcodeRow['barcode'] ?? null, $barcodeRow['name'] ?? null);
                if ($ean !== null) {
                    break;
                }
            }

            $variants[] = [
                'sku' => $this->stringValue($variant['number'] ?? null, $variant['sku'] ?? null, $variant['model'] ?? null),
                'ean' => $ean,
                'price' => $this->stringValue($variant['priceGross'] ?? null, $variant['price'] ?? null),
                'stock' => $variant['stock'] ?? $variant['netStock'] ?? null,
                'enabled' => $variant['isActive'] ?? $variant['active'] ?? true,
                'options' => $this->optionMap($variant['options'] ?? $variant['attributes'] ?? null),
            ];
        }

        return array_values(array_filter($variants, static fn (array $variant): bool => trim((string) ($variant['sku'] ?? '')) !== ''));
    }
}
