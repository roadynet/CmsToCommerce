<?php

declare(strict_types=1);

namespace App\Service\Integration;

use App\Enum\ExternalSystemType;

final class SapR3PayloadNormalizer extends AbstractExternalSystemPayloadNormalizer
{
    public function system(): ExternalSystemType
    {
        return ExternalSystemType::SapR3;
    }

    public function supports(?string $systemHint, array $payload): bool
    {
        if ($systemHint !== null && trim($systemHint) !== '' && !$this->matchesHint($systemHint, 'sap', 'sap-r3', 'sap_r3', 'r3', 'sap erp')) {
            return false;
        }

        return $this->matchesHint($systemHint, 'sap', 'sap-r3', 'sap_r3', 'r3', 'sap erp')
            || is_array($payload['MARA'] ?? null)
            || is_array($payload['MAKT'] ?? null)
            || is_array($payload['material'] ?? null) && array_key_exists('MATNR', (array) $payload['material'])
            || array_key_exists('MATNR', $payload)
            || is_array($payload['IDOC'] ?? null)
            || is_array($payload['E1MARAM'] ?? null);
    }

    public function normalize(array $payload): array
    {
        $material = $this->materialSegment($payload);
        $textSegment = $this->textSegment($payload);
        $sales = $this->salesSegment($payload);
        $stock = $this->stockSegment($payload);
        $price = $this->priceSegment($payload);

        $materialNumber = $this->stringValue(
            $material['MATNR'] ?? null,
            $material['matnr'] ?? null,
            $payload['MATNR'] ?? null,
            $payload['materialNumber'] ?? null,
            $payload['material_number'] ?? null,
        );
        $name = $this->stringValue(
            $textSegment['MAKTX'] ?? null,
            $textSegment['maktx'] ?? null,
            $material['MAKTX'] ?? null,
            $material['description'] ?? null,
            $payload['MAKTX'] ?? null,
            $payload['name'] ?? null,
        );
        $brand = $this->stringValue(
            $material['ZZBRAND'] ?? null,
            $material['BRAND'] ?? null,
            $material['brand'] ?? null,
            $material['MFRNR'] ?? null,
            $payload['brand'] ?? null,
            $payload['marke'] ?? null,
        );
        $categoryPath = $this->categoryPath(
            $material['categoryPath']
            ?? $payload['categoryPath']
            ?? $payload['categories']
            ?? array_filter([
                $this->stringValue($material['MATKL'] ?? null, $material['matkl'] ?? null),
                $this->stringValue($material['PRDHA'] ?? null, $material['prdha'] ?? null),
            ])
        );
        $description = $this->stringValue(
            $payload['description'] ?? null,
            $payload['beschreibung'] ?? null,
            $payload['long_text'] ?? null,
            $payload['longText'] ?? null,
            $textSegment['LANGTEXT'] ?? null,
            $textSegment['longText'] ?? null,
            $name,
        );
        $attributes = $this->sapAttributes($material, $sales);
        $variants = $this->mapVariants($payload, $material, $sales, $stock, $price);
        $assetSource = $payload['images'] ?? $payload['documents'] ?? $payload['DMS'] ?? $material['images'] ?? $material['documents'] ?? null;

        return [
            'produkt_name' => $name,
            'marke' => $brand,
            'kategorie_pfad' => $categoryPath,
            'beschreibung' => $description,
            'rohtext' => $this->buildRawText($description, [
                'SAP Materialnummer' => $materialNumber,
                'Marke/Hersteller' => $brand,
                'Warengruppe' => $this->stringValue($material['MATKL'] ?? null, $material['matkl'] ?? null),
                'Produkthierarchie' => $this->stringValue($material['PRDHA'] ?? null, $material['prdha'] ?? null),
                ...$attributes,
            ]),
            'cms_system' => 'sap_r3',
            'external_reference' => $materialNumber,
            'sprache' => strtolower($this->stringValue(
                $textSegment['SPRAS_ISO'] ?? null,
                $payload['language'] ?? null,
                $payload['lang'] ?? null,
            ) ?? 'de'),
            'asset_urls' => $this->assetDescriptors($assetSource),
            'variants' => $variants,
            'source_payload' => $this->preservedPayload(
                $payload,
                [
                    'system' => 'sap_r3',
                    'material_number' => $materialNumber,
                    'name' => $name,
                    'brand' => $brand,
                    'category_path' => $categoryPath,
                    'variant_count' => count($variants),
                ],
                [
                    'sap_segmente' => [
                        'material' => array_keys($material),
                        'text' => array_keys($textSegment),
                        'sales' => array_keys($sales),
                        'stock' => array_keys($stock),
                    ],
                    'bild_urls' => $this->imageUrls($assetSource),
                ],
            ),
        ];
    }

    public function overview(): array
    {
        return [
            'code' => $this->system()->value,
            'label' => $this->system()->label(),
            'status' => 'intake + delta + write-back preview bereit',
            'summary' => 'CTC kann SAP-R/3-Materialstamm-Daten aus MARA/MAKT/MVKE/MARD-ähnlichen Payloads, IDoc-Vorläufen oder Gateway-JSON übernehmen.',
            'next_step' => 'Für Live-Write-back wird ein SAP Gateway/RFC-/IDoc-Proxy angebunden; CTC erzeugt dafür bereits MATMAS/BAPI-nahe Payloads.',
            'example_keys' => ['MARA.MATNR', 'MAKT.MAKTX', 'MVKE.VKORG', 'MARD.LABST', 'images'],
            'intake_ready' => true,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function materialSegment(array $payload): array
    {
        foreach (['MARA', 'material', 'article', 'product', 'E1MARAM'] as $key) {
            if (is_array($payload[$key] ?? null)) {
                return $payload[$key];
            }
        }

        if (is_array($payload['IDOC']['E1MARAM'] ?? null)) {
            return $payload['IDOC']['E1MARAM'];
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function textSegment(array $payload): array
    {
        foreach (['MAKT', 'text', 'texts', 'E1MAKTM'] as $key) {
            if (is_array($payload[$key] ?? null)) {
                $segment = $payload[$key];

                return array_is_list($segment) ? (array) ($segment[0] ?? []) : $segment;
            }
        }

        if (is_array($payload['IDOC']['E1MAKTM'] ?? null)) {
            $segment = $payload['IDOC']['E1MAKTM'];

            return array_is_list($segment) ? (array) ($segment[0] ?? []) : $segment;
        }

        return [];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function salesSegment(array $payload): array
    {
        foreach (['MVKE', 'sales', 'salesData', 'E1MVKEM'] as $key) {
            if (is_array($payload[$key] ?? null)) {
                $segment = $payload[$key];

                return array_is_list($segment) ? (array) ($segment[0] ?? []) : $segment;
            }
        }

        return [];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function stockSegment(array $payload): array
    {
        foreach (['MARD', 'stock', 'inventory', 'availability'] as $key) {
            if (is_array($payload[$key] ?? null)) {
                $segment = $payload[$key];

                return array_is_list($segment) ? (array) ($segment[0] ?? []) : $segment;
            }
        }

        return [];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function priceSegment(array $payload): array
    {
        foreach (['price', 'pricing', 'condition', 'KONP'] as $key) {
            if (is_array($payload[$key] ?? null)) {
                $segment = $payload[$key];

                return array_is_list($segment) ? (array) ($segment[0] ?? []) : $segment;
            }
        }

        return [];
    }

    /**
     * @param array<string, mixed> $material
     * @param array<string, mixed> $sales
     *
     * @return array<string, string>
     */
    private function sapAttributes(array $material, array $sales): array
    {
        $attributes = [];
        foreach ([
            'Materialart' => $this->stringValue($material['MTART'] ?? null, $material['mtart'] ?? null),
            'Basismengeneinheit' => $this->stringValue($material['MEINS'] ?? null, $material['meins'] ?? null),
            'Gewicht' => $this->stringValue($material['BRGEW'] ?? null, $material['NTGEW'] ?? null),
            'Gewichtseinheit' => $this->stringValue($material['GEWEI'] ?? null),
            'Vertriebsorganisation' => $this->stringValue($sales['VKORG'] ?? null),
            'Vertriebsweg' => $this->stringValue($sales['VTWEG'] ?? null),
        ] as $label => $value) {
            if ($value !== null) {
                $attributes[$label] = $value;
            }
        }

        return $attributes;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $material
     * @param array<string, mixed> $sales
     * @param array<string, mixed> $stock
     * @param array<string, mixed> $price
     *
     * @return list<array<string, mixed>>
     */
    private function mapVariants(array $payload, array $material, array $sales, array $stock, array $price): array
    {
        $candidates = $this->listOfArrays($payload['variants'] ?? $payload['materials'] ?? null);
        if ($candidates === []) {
            $candidates = [[...$material, ...$sales, ...$stock, ...$price]];
        }

        $variants = [];
        foreach ($candidates as $variant) {
            $materialNumber = $this->stringValue($variant['MATNR'] ?? null, $variant['matnr'] ?? null, $variant['sku'] ?? null, $variant['materialNumber'] ?? null);
            $variants[] = [
                'sku' => $materialNumber,
                'ean' => $this->stringValue($variant['EAN11'] ?? null, $variant['ean'] ?? null, $variant['GTIN'] ?? null),
                'price' => $this->stringValue($variant['NETPR'] ?? null, $variant['KBETR'] ?? null, $variant['price'] ?? null),
                'currency' => $this->stringValue($variant['WAERS'] ?? null, $variant['currency'] ?? null) ?? 'EUR',
                'stock' => $variant['LABST'] ?? $variant['stock'] ?? $variant['bestand'] ?? null,
                'enabled' => !in_array(strtoupper(trim((string) ($variant['LVORM'] ?? ''))), ['X', '1', 'TRUE'], true),
                'options' => $this->optionMap($variant['options'] ?? [
                    'Werk' => $variant['WERKS'] ?? null,
                    'Lagerort' => $variant['LGORT'] ?? null,
                    'Materialart' => $variant['MTART'] ?? null,
                    'Vertrieb' => trim(sprintf('%s/%s', (string) ($variant['VKORG'] ?? ''), (string) ($variant['VTWEG'] ?? '')), '/'),
                ]),
            ];
        }

        return array_values(array_filter($variants, static fn (array $variant): bool => trim((string) ($variant['sku'] ?? '')) !== ''));
    }
}
