<?php

declare(strict_types=1);

namespace App\Service\Amazon;

use App\Dto\ListingDraft;
use App\Entity\Product;
use App\Entity\ProductAsset;
use App\Entity\ProductVariant;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class AmazonListingsItemPayloadBuilder
{
    public function __construct(
        private readonly ?UrlGeneratorInterface $urlGenerator = null,
    ) {
    }

    /**
     * @param array<string, mixed> $productTypeSchema
     *
     * @return array{
     *     body: array<string, mixed>,
     *     gemappte_attribute: array<string, array{quelle: string, typ: string, elemente: int}>,
     *     schema_attribute_anzahl: int,
     *     schema_attribute_vorschau: list<string>,
     *     schema_pflichtattribute: list<string>,
     *     lokal_fehlende_pflichtattribute: list<string>,
     *     ausgelassene_kandidaten: list<string>
     * }
     */
    public function build(
        Product $product,
        ListingDraft $draft,
        string $marketplaceId,
        string $locale,
        string $productType,
        array $productTypeSchema,
        string $requirements = 'LISTING_PRODUCT_ONLY',
    ): array {
        $schemaAttributes = $this->extractSchemaAttributes($productTypeSchema);
        $requiredAttributes = $this->extractRequiredAttributes($productTypeSchema);
        $candidates = $this->buildCandidates($product, $draft, $marketplaceId, $locale);

        $mappedAttributes = [];
        $mappedMetadata = [];
        $skippedCandidates = [];

        foreach ($candidates as $attributeName => $candidate) {
            if ($schemaAttributes !== [] && !in_array($attributeName, $schemaAttributes, true)) {
                $skippedCandidates[] = $attributeName;
                continue;
            }

            $mappedAttributes[$attributeName] = $candidate['payload'];
            $mappedMetadata[$attributeName] = [
                'quelle' => $candidate['source'],
                'typ' => $candidate['type'],
                'elemente' => is_countable($candidate['payload']) ? count($candidate['payload']) : 1,
            ];
        }

        $missingRequired = array_values(array_filter(
            $requiredAttributes,
            static fn (string $attributeName): bool => !array_key_exists($attributeName, $mappedAttributes),
        ));

        return [
            'body' => [
                'productType' => $productType,
                'requirements' => $requirements,
                'attributes' => $mappedAttributes,
            ],
            'gemappte_attribute' => $mappedMetadata,
            'schema_attribute_anzahl' => count($schemaAttributes),
            'schema_attribute_vorschau' => array_slice($schemaAttributes, 0, 40),
            'schema_pflichtattribute' => $requiredAttributes,
            'lokal_fehlende_pflichtattribute' => $missingRequired,
            'ausgelassene_kandidaten' => array_values(array_unique($skippedCandidates)),
        ];
    }

    /**
     * @return array<string, array{payload: array<int, array<string, mixed>>, source: string, type: string}>
     */
    private function buildCandidates(Product $product, ListingDraft $draft, string $marketplaceId, string $locale): array
    {
        $technical = $draft->technicalAttributes;
        $offerData = $this->resolveOfferData($product);
        $candidates = [];

        $candidates['condition_type'] = [
            'payload' => $this->simpleAttribute('new_new', $marketplaceId),
            'source' => 'CTC Standardwert für Neuware',
            'type' => 'enum',
        ];

        $title = trim($draft->title);
        if ($title !== '') {
            $candidates['item_name'] = [
                'payload' => $this->localizedAttribute($title, $marketplaceId, $locale),
                'source' => 'Listing-Titel aus CTC',
                'type' => 'text',
            ];
        }

        $brand = trim((string) ($technical['brand'] ?? $product->getBrand() ?? ''));
        if ($brand !== '') {
            $candidates['brand'] = [
                'payload' => $this->simpleAttribute($brand, $marketplaceId),
                'source' => 'Marke aus Produktstamm/Import',
                'type' => 'text',
            ];
            $candidates['manufacturer'] = [
                'payload' => $this->simpleAttribute($brand, $marketplaceId),
                'source' => 'Marke als Hersteller',
                'type' => 'text',
            ];
        }

        $description = trim($draft->description);
        if ($description !== '') {
            $candidates['product_description'] = [
                'payload' => $this->localizedAttribute($description, $marketplaceId, $locale),
                'source' => 'CTC Produktbeschreibung',
                'type' => 'rich_text',
            ];
        }

        if ($draft->bulletPoints !== []) {
            $candidates['bullet_point'] = [
                'payload' => array_map(
                    fn (string $bullet): array => $this->localizedAttributeValue($bullet, $marketplaceId, $locale),
                    array_values(array_slice(array_filter(
                        array_map(static fn (string $bullet): string => trim($bullet), $draft->bulletPoints),
                        static fn (string $bullet): bool => $bullet !== '',
                    ), 0, 5)),
                ),
                'source' => 'CTC Bulletpoints',
                'type' => 'text_list',
            ];
        }

        $genericKeywords = $this->genericKeywordValues($draft->searchTerms);
        if ($genericKeywords !== []) {
            $candidates['generic_keyword'] = [
                'payload' => array_map(
                    fn (string $keyword): array => ['value' => $keyword, 'marketplace_id' => $marketplaceId],
                    $genericKeywords,
                ),
                'source' => 'CTC Suchbegriffe',
                'type' => 'keyword_list',
            ];
        }

        if ($offerData['price'] !== null && $offerData['currency'] !== null) {
            $candidates['purchasable_offer'] = [
                'payload' => [[
                    'marketplace_id' => $marketplaceId,
                    'currency' => $offerData['currency'],
                    'audience' => 'ALL',
                    'our_price' => [[
                        'schedule' => [[
                            'value_with_tax' => $offerData['price'],
                        ]],
                    ]],
                ]],
                'source' => 'Preis aus aktiver Variante',
                'type' => 'offer',
            ];
        }

        if ($offerData['stock'] !== null) {
            $candidates['fulfillment_availability'] = [
                'payload' => [[
                    'fulfillment_channel_code' => 'DEFAULT',
                    'quantity' => max(0, $offerData['stock']),
                ]],
                'source' => 'Bestand aus aktiver Variante',
                'type' => 'inventory',
            ];
        }

        foreach ($this->buildImageCandidates($product, $marketplaceId) as $attributeName => $candidate) {
            $candidates[$attributeName] = $candidate;
        }

        foreach ([
            'color' => ['source' => 'Farbe aus Import/Produktstamm', 'type' => 'text'],
            'color_name' => ['source' => 'Farbe aus Import/Produktstamm', 'type' => 'text'],
            'material' => ['source' => 'Material aus Import/Produktstamm', 'type' => 'text'],
            'material_type' => ['source' => 'Material aus Import/Produktstamm', 'type' => 'text'],
            'size' => ['source' => 'Größe aus Import/Produktstamm', 'type' => 'text'],
            'size_name' => ['source' => 'Größe aus Import/Produktstamm', 'type' => 'text'],
            'model_name' => ['source' => 'Modell aus Import/Produktstamm', 'type' => 'text'],
            'model_number' => ['source' => 'Modell aus Import/Produktstamm', 'type' => 'text'],
        ] as $attributeName => $meta) {
            $value = $this->resolveAttributeValue($attributeName, $technical);
            if ($value === null) {
                continue;
            }

            $candidates[$attributeName] = [
                'payload' => $this->localizedAttribute($value, $marketplaceId, $locale),
                'source' => $meta['source'],
                'type' => $meta['type'],
            ];
        }

        return array_filter(
            $candidates,
            static fn (array $candidate): bool => $candidate['payload'] !== [],
        );
    }

    /**
     * @return array<string, array{payload: array<int, array<string, mixed>>, source: string, type: string}>
     */
    private function buildImageCandidates(Product $product, string $marketplaceId): array
    {
        $images = $this->resolveImageUrls($product, $marketplaceId);
        if ($images === []) {
            return [];
        }

        $candidates = [
            'main_product_image_locator' => [
                'payload' => [['media_location' => $images[0]]],
                'source' => 'CTC Hauptbild',
                'type' => 'image_locator',
            ],
        ];

        foreach (array_slice($images, 1, 8) as $index => $url) {
            $candidates['other_product_image_locator_'.($index + 1)] = [
                'payload' => [['media_location' => $url]],
                'source' => 'CTC Zusatzbild',
                'type' => 'image_locator',
            ];
        }

        return $candidates;
    }

    /**
     * @return list<string>
     */
    private function resolveImageUrls(Product $product, string $marketplaceId): array
    {
        $urls = [];

        /** @var ProductAsset $asset */
        foreach ($product->getAssets() as $asset) {
            if (!str_starts_with($asset->getMimeType(), 'image/')) {
                continue;
            }

            $url = $this->buildImageUrl($asset);
            if ($url === null) {
                continue;
            }

            $urls[] = $url;
        }

        return array_values(array_unique(array_filter($urls, static fn (string $url): bool => $url !== '')));
    }

    private function buildImageUrl(ProductAsset $asset): ?string
    {
        $assetId = $asset->getId();
        if ($assetId === null) {
            return null;
        }

        if ($this->urlGenerator !== null) {
            return $this->urlGenerator->generate('app_product_asset_show', ['id' => $assetId], UrlGeneratorInterface::ABSOLUTE_URL);
        }

        return 'http://localhost/media/product/'.$assetId;
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return list<string>
     */
    private function extractSchemaAttributes(array $schema): array
    {
        $attributes = $schema['properties']['attributes']['properties'] ?? null;
        if (!is_array($attributes)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $key): string => (string) $key, array_keys($attributes)),
            static fn (string $key): bool => $key !== '',
        ));
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return list<string>
     */
    private function extractRequiredAttributes(array $schema): array
    {
        $required = $schema['properties']['attributes']['required'] ?? [];
        if (!is_array($required)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $value): string => (string) $value, $required),
            static fn (string $value): bool => $value !== '',
        ));
    }

    /**
     * @return list<array{value: string, marketplace_id: string}>
     */
    private function simpleAttribute(string $value, string $marketplaceId): array
    {
        return [[
            'value' => $value,
            'marketplace_id' => $marketplaceId,
        ]];
    }

    /**
     * @return list<array{value: string, language_tag: string, marketplace_id: string}>
     */
    private function localizedAttribute(string $value, string $marketplaceId, string $locale): array
    {
        return [$this->localizedAttributeValue($value, $marketplaceId, $locale)];
    }

    /**
     * @return array{value: string, language_tag: string, marketplace_id: string}
     */
    private function localizedAttributeValue(string $value, string $marketplaceId, string $locale): array
    {
        return [
            'value' => $value,
            'language_tag' => $locale,
            'marketplace_id' => $marketplaceId,
        ];
    }

    /**
     * @param list<string> $searchTerms
     *
     * @return list<string>
     */
    private function genericKeywordValues(array $searchTerms): array
    {
        $terms = [];
        foreach ($searchTerms as $term) {
            $term = trim($term);
            if ($term === '') {
                continue;
            }

            $terms[mb_strtolower($term)] = $term;
        }

        $chunks = [];
        $buffer = '';
        foreach (array_values($terms) as $term) {
            $candidate = $buffer === '' ? $term : $buffer.' '.$term;
            if (mb_strlen($candidate) > 200) {
                if ($buffer !== '') {
                    $chunks[] = $buffer;
                }
                $buffer = $term;
                continue;
            }

            $buffer = $candidate;
        }

        if ($buffer !== '') {
            $chunks[] = $buffer;
        }

        return array_slice($chunks, 0, 5);
    }

    /**
     * @param array<string, scalar|array|null> $technical
     */
    private function resolveAttributeValue(string $attributeName, array $technical): ?string
    {
        return match ($attributeName) {
            'color', 'color_name' => $this->stringOrNull($technical['color'] ?? null),
            'material', 'material_type' => $this->stringOrNull($technical['material'] ?? null),
            'size', 'size_name' => $this->stringOrNull($technical['size'] ?? null),
            'model_name', 'model_number' => $this->stringOrNull($technical['model'] ?? null),
            default => null,
        };
    }

    /**
     * @return array{price: ?float, currency: ?string, stock: ?int}
     */
    private function resolveOfferData(Product $product): array
    {
        $price = null;
        $currency = null;
        $stock = null;

        /** @var ProductVariant $variant */
        foreach ($product->getVariants() as $variant) {
            if (!$variant->isEnabled()) {
                continue;
            }

            if ($price === null && $variant->getPriceGross() !== null && is_numeric($variant->getPriceGross())) {
                $price = round((float) $variant->getPriceGross(), 2);
                $currency = $variant->getCurrency() !== '' ? $variant->getCurrency() : 'EUR';
            }

            if ($variant->getStock() !== null) {
                $stock = ($stock ?? 0) + $variant->getStock();
            }
        }

        return [
            'price' => $price,
            'currency' => $currency,
            'stock' => $stock,
        ];
    }

    private function stringOrNull(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
