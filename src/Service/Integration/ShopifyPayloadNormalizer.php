<?php

declare(strict_types=1);

namespace App\Service\Integration;

use App\Enum\ExternalSystemType;

final class ShopifyPayloadNormalizer extends AbstractExternalSystemPayloadNormalizer
{
    public function system(): ExternalSystemType
    {
        return ExternalSystemType::Shopify;
    }

    public function supports(?string $systemHint, array $payload): bool
    {
        if ($systemHint !== null && trim($systemHint) !== '' && !$this->matchesHint($systemHint, 'shopify', 'shopify-admin')) {
            return false;
        }

        return $this->matchesHint($systemHint, 'shopify', 'shopify-admin')
            || strtolower((string) ($payload['system'] ?? $payload['source_system'] ?? '')) === 'shopify'
            || is_array($payload['product'] ?? null) && (
                array_key_exists('admin_graphql_api_id', (array) $payload['product'])
                || array_key_exists('body_html', (array) $payload['product'])
                || array_key_exists('vendor', (array) $payload['product'])
            )
            || array_key_exists('admin_graphql_api_id', $payload) && array_key_exists('variants', $payload);
    }

    public function normalize(array $payload): array
    {
        $product = is_array($payload['product'] ?? null) ? $payload['product'] : $payload;
        $title = $this->stringValue($product['title'] ?? null, $product['name'] ?? null);
        $brand = $this->stringValue($product['vendor'] ?? null, $product['brand'] ?? null);
        $categoryPath = $this->categoryPath($product['product_type'] ?? $product['category'] ?? $product['collections'] ?? $payload['collections'] ?? null);
        $description = $this->stringValue($product['body_html'] ?? null, $product['descriptionHtml'] ?? null, $product['description'] ?? null);
        $variants = $this->mapVariants($product);
        $assetSource = $product['images'] ?? $product['media'] ?? $payload['images'] ?? null;

        return [
            'produkt_name' => $title,
            'marke' => $brand,
            'kategorie_pfad' => $categoryPath,
            'beschreibung' => $this->htmlToText($description),
            'rohtext' => $this->buildRawText($this->htmlToText($description), [
                'Shopify Produkt-ID' => $this->shopifyProductReference($product),
                'Vendor' => $brand,
                'Product Type' => $this->stringValue($product['product_type'] ?? null),
                'Status' => $this->stringValue($product['status'] ?? null),
                'Tags' => $this->tagString($product['tags'] ?? null),
            ]),
            'cms_system' => 'shopify',
            'external_reference' => $this->shopifyProductReference($product),
            'sprache' => strtolower($this->stringValue($payload['language'] ?? null, $payload['lang'] ?? null) ?? 'de'),
            'asset_urls' => $this->assetDescriptors($assetSource),
            'variants' => $variants,
            'source_payload' => $this->preservedPayload(
                $payload,
                [
                    'system' => 'shopify',
                    'product_id' => $this->shopifyProductReference($product),
                    'title' => $title,
                    'vendor' => $brand,
                    'product_type' => $this->stringValue($product['product_type'] ?? null),
                    'variant_count' => count($variants),
                ],
                [
                    'shopify_graphql_id' => $this->stringValue($product['admin_graphql_api_id'] ?? null, $product['id'] ?? null),
                    'shopify_asset_urls' => $this->imageUrls($assetSource),
                ],
            ),
        ];
    }

    public function overview(): array
    {
        return [
            'code' => $this->system()->value,
            'label' => $this->system()->label(),
            'status' => 'admin-api intake + write-back preview bereit',
            'summary' => 'CTC kann Shopify-Produkte, Varianten, Preise, Bestände, Tags, Vendor/Product-Type und Bildquellen in den Produktstamm übernehmen.',
            'next_step' => 'Für Live-Write-back wird ein Shopify Admin API Token gesetzt; CTC erzeugt bereits GraphQL-Payloads für Produkttexte und SEO-Felder.',
            'example_keys' => ['product.id', 'product.admin_graphql_api_id', 'product.title', 'product.variants', 'product.images'],
            'intake_ready' => true,
        ];
    }

    /**
     * @param array<string, mixed> $product
     *
     * @return list<array<string, mixed>>
     */
    private function mapVariants(array $product): array
    {
        $candidates = $this->listOfArrays($product['variants'] ?? null);
        if ($candidates === []) {
            $candidates = [[
                'sku' => $product['sku'] ?? null,
                'barcode' => $product['barcode'] ?? null,
                'price' => $product['price'] ?? null,
                'inventory_quantity' => $product['inventory_quantity'] ?? null,
                'title' => $product['title'] ?? null,
            ]];
        }

        $variants = [];
        foreach ($candidates as $variant) {
            $options = [];
            foreach (['option1', 'option2', 'option3'] as $optionKey) {
                $value = $this->stringValue($variant[$optionKey] ?? null);
                if ($value !== null && strtolower($value) !== 'default title') {
                    $options[$optionKey] = $value;
                }
            }

            $variants[] = [
                'sku' => $this->stringValue($variant['sku'] ?? null, $variant['inventory_item_id'] ?? null, $variant['id'] ?? null),
                'ean' => $this->stringValue($variant['barcode'] ?? null, $variant['ean'] ?? null),
                'price' => $this->stringValue($variant['price'] ?? null, $variant['compare_at_price'] ?? null),
                'currency' => $this->stringValue($variant['currency'] ?? null) ?? 'EUR',
                'stock' => $variant['inventory_quantity'] ?? $variant['stock'] ?? null,
                'enabled' => ($variant['available'] ?? true) !== false,
                'options' => $options !== [] ? $options : $this->optionMap($variant['options'] ?? null),
            ];
        }

        return array_values(array_filter($variants, static fn (array $variant): bool => trim((string) ($variant['sku'] ?? '')) !== ''));
    }

    /**
     * @param array<string, mixed> $product
     */
    private function shopifyProductReference(array $product): ?string
    {
        return $this->stringValue($product['admin_graphql_api_id'] ?? null, $product['id'] ?? null);
    }

    private function htmlToText(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return $text !== '' ? preg_replace('/\s+/u', ' ', $text) : null;
    }

    private function tagString(mixed $tags): ?string
    {
        if (is_array($tags)) {
            $tags = implode(', ', array_map(static fn (mixed $tag): string => trim((string) $tag), $tags));
        }

        return $this->stringValue($tags);
    }
}
