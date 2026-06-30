<?php

declare(strict_types=1);

namespace App\Service\Integration;

use App\Enum\ExternalSystemType;

final class PimcorePayloadNormalizer extends AbstractExternalSystemPayloadNormalizer
{
    public function system(): ExternalSystemType
    {
        return ExternalSystemType::Pimcore;
    }

    public function supports(?string $systemHint, array $payload): bool
    {
        if ($systemHint !== null && trim($systemHint) !== '' && !$this->matchesHint($systemHint, 'pimcore', 'pim')) {
            return false;
        }

        return $this->matchesHint($systemHint, 'pimcore', 'pim')
            || strtolower((string) ($payload['system'] ?? $payload['source_system'] ?? '')) === 'pimcore'
            || is_array($payload['object'] ?? null) && (
                array_key_exists('className', (array) $payload['object'])
                || array_key_exists('o_className', (array) $payload['object'])
            )
            || is_array($payload['data'] ?? null) && array_key_exists('localizedfields', (array) $payload['data'])
            || array_key_exists('o_id', $payload)
            || array_key_exists('className', $payload);
    }

    public function normalize(array $payload): array
    {
        $object = is_array($payload['object'] ?? null) ? $payload['object'] : $payload;
        $data = is_array($object['data'] ?? null)
            ? $object['data']
            : (is_array($payload['data'] ?? null) ? $payload['data'] : $object);
        $localized = $this->localizedFields($data, $payload);
        $attributes = $this->attributes($data, $object);
        $name = $this->stringValue(
            $localized['name'] ?? null,
            $localized['title'] ?? null,
            $localized['productName'] ?? null,
            $data['name'] ?? null,
            $data['title'] ?? null,
            $object['key'] ?? null,
            $object['o_key'] ?? null,
        );
        $brand = $this->stringValue(
            $data['brand'] ?? null,
            $data['manufacturer'] ?? null,
            $data['marke'] ?? null,
            $this->pathValue($data, 'brand.name', 'manufacturer.name'),
            $localized['brand'] ?? null,
        );
        $categoryPath = $this->categoryPath(
            $data['categoryPath']
            ?? $data['categories']
            ?? $data['category']
            ?? $payload['categories']
            ?? null
        );
        $description = $this->stringValue(
            $localized['description'] ?? null,
            $localized['longDescription'] ?? null,
            $data['description'] ?? null,
            $data['longDescription'] ?? null,
            $data['beschreibung'] ?? null,
        );
        $variants = $this->mapVariants($data, $payload);
        $assetSource = $data['assets']
            ?? $data['images']
            ?? $data['gallery']
            ?? $object['assets']
            ?? $payload['assets']
            ?? $payload['images']
            ?? null;

        return [
            'produkt_name' => $name,
            'marke' => $brand,
            'kategorie_pfad' => $categoryPath,
            'beschreibung' => $description,
            'rohtext' => $this->buildRawText($description, [
                'Pimcore Objekt-ID' => $this->objectId($object),
                'Pimcore Klasse' => $this->className($object),
                'Key' => $this->stringValue($object['key'] ?? null, $object['o_key'] ?? null),
                'Marke' => $brand,
                'Kategorie' => $categoryPath,
                ...$attributes,
            ]),
            'cms_system' => 'pimcore',
            'external_reference' => $this->objectId($object),
            'sprache' => strtolower($this->stringValue($payload['language'] ?? null, $payload['lang'] ?? null) ?? 'de'),
            'asset_urls' => $this->assetDescriptors($assetSource),
            'variants' => $variants,
            'source_payload' => $this->preservedPayload(
                $payload,
                [
                    'system' => 'pimcore',
                    'object_id' => $this->objectId($object),
                    'class_name' => $this->className($object),
                    'object_key' => $this->stringValue($object['key'] ?? null, $object['o_key'] ?? null),
                    'name' => $name,
                    'brand' => $brand,
                    'category_path' => $categoryPath,
                    'variant_count' => count($variants),
                ],
                [
                    'pimcore_asset_urls' => $this->imageUrls($assetSource),
                    'pimcore_attributes' => $attributes,
                ],
            ),
        ];
    }

    public function overview(): array
    {
        return [
            'code' => $this->system()->value,
            'label' => $this->system()->label(),
            'status' => 'pim + dam intake + write-back preview bereit',
            'summary' => 'CTC kann Pimcore Data Objects, localized fields, Klassifikationsattribute, Varianten und Asset-/Gallery-URLs in den Produktstamm übernehmen.',
            'next_step' => 'Für Live-Write-back wird ein Pimcore API-/Data-Hub-/Gateway-Endpunkt angebunden; CTC erzeugt dafür bereits objektnahe Update-Payloads.',
            'example_keys' => ['object.id', 'object.className', 'data.localizedfields.de.name', 'data.attributes', 'assets'],
            'intake_ready' => true,
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function localizedFields(array $data, array $payload): array
    {
        $localized = $data['localizedfields'] ?? $data['localizedFields'] ?? $payload['localizedfields'] ?? $payload['localizedFields'] ?? null;
        if (!is_array($localized)) {
            return [];
        }

        foreach (['de', 'DE', 'de_DE', 'default'] as $languageKey) {
            if (is_array($localized[$languageKey] ?? null)) {
                return $localized[$languageKey];
            }
        }

        $first = reset($localized);

        return is_array($first) ? $first : [];
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $object
     *
     * @return array<string, string>
     */
    private function attributes(array $data, array $object): array
    {
        $source = $data['attributes']
            ?? $data['classificationStore']
            ?? $data['features']
            ?? $object['attributes']
            ?? [];

        $attributes = $this->optionMap($source);
        foreach (['material', 'color', 'farbe', 'size', 'groesse', 'weight', 'gewicht'] as $key) {
            $value = $this->stringValue($data[$key] ?? null);
            if ($value !== null && !isset($attributes[$key])) {
                $attributes[$key] = $value;
            }
        }

        return $attributes;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $payload
     *
     * @return list<array<string, mixed>>
     */
    private function mapVariants(array $data, array $payload): array
    {
        $candidates = $this->listOfArrays($data['variants'] ?? $payload['variants'] ?? $data['children'] ?? null);
        if ($candidates === []) {
            $candidates = [[
                'sku' => $data['sku'] ?? $data['articleNumber'] ?? $data['productNumber'] ?? $data['key'] ?? $payload['sku'] ?? null,
                'ean' => $data['ean'] ?? $data['gtin'] ?? $data['barcode'] ?? null,
                'price' => $data['price'] ?? $data['priceGross'] ?? null,
                'stock' => $data['stock'] ?? $data['availability'] ?? null,
                'enabled' => $data['published'] ?? $data['active'] ?? true,
                'options' => $data['variantAttributes'] ?? $data['attributes'] ?? [],
            ]];
        }

        $variants = [];
        foreach ($candidates as $variant) {
            $variants[] = [
                'sku' => $this->stringValue($variant['sku'] ?? null, $variant['articleNumber'] ?? null, $variant['productNumber'] ?? null, $variant['key'] ?? null, $variant['o_key'] ?? null),
                'ean' => $this->stringValue($variant['ean'] ?? null, $variant['gtin'] ?? null, $variant['barcode'] ?? null),
                'price' => $this->stringValue($variant['priceGross'] ?? null, $variant['price'] ?? null),
                'currency' => $this->stringValue($variant['currency'] ?? null) ?? 'EUR',
                'stock' => $variant['stock'] ?? $variant['availability'] ?? $variant['quantity'] ?? null,
                'enabled' => $variant['published'] ?? $variant['active'] ?? true,
                'options' => $this->optionMap($variant['options'] ?? $variant['attributes'] ?? $variant['variantAttributes'] ?? null),
            ];
        }

        return array_values(array_filter($variants, static fn (array $variant): bool => trim((string) ($variant['sku'] ?? '')) !== ''));
    }

    /**
     * @param array<string, mixed> $object
     */
    private function objectId(array $object): ?string
    {
        return $this->stringValue($object['id'] ?? null, $object['o_id'] ?? null, $object['objectId'] ?? null);
    }

    /**
     * @param array<string, mixed> $object
     */
    private function className(array $object): ?string
    {
        return $this->stringValue($object['className'] ?? null, $object['o_className'] ?? null, $object['class'] ?? null);
    }
}
