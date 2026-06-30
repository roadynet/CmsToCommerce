<?php

declare(strict_types=1);

namespace App\Service\Integration;

use App\Entity\Product;
use App\Entity\ProductSource;
use App\Enum\ChannelType;
use App\Enum\ExternalSystemType;

final class ShopifyWritebackPreviewBuilder extends AbstractExternalSystemWritebackPreviewBuilder
{
    public function system(): ExternalSystemType
    {
        return ExternalSystemType::Shopify;
    }

    public function build(Product $product): array
    {
        $draft = $this->productListingDraftBuilder->build($product, ChannelType::Amazon);
        $target = $this->targetProduct($product);

        return $this->buildPreviewEnvelope($product, [
            'shopify_hinweis' => 'Shopify wird über die Admin GraphQL API angebunden. CTC erzeugt eine sichere Preview für productUpdate und CTC-Metafelder.',
            'admin_api' => [
                'transport' => 'GraphQL',
                'mutation' => 'productUpdate',
                'product_id' => $target['product_gid'],
                'product_numeric_id' => $target['product_id'],
            ],
            'graphql' => [
                'query' => <<<'GRAPHQL'
mutation CtcProductWriteback($input: ProductInput!) {
  productUpdate(input: $input) {
    product {
      id
      title
      handle
    }
    userErrors {
      field
      message
    }
  }
}
GRAPHQL,
                'variables' => [
                    'input' => [
                        'id' => $target['product_gid'] ?: 'gid://shopify/Product/wird_vor_live_writeback_aufgeloest',
                        'title' => $draft->title,
                        'descriptionHtml' => $this->descriptionHtml($draft->description),
                        'vendor' => $draft->technicalAttributes['brand'] ?? $product->getBrand(),
                        'tags' => array_values(array_unique(array_filter([
                            'ctc-optimiert',
                            'amazon-ready',
                            ...$draft->searchTerms,
                        ]))),
                        'seo' => [
                            'title' => $draft->title,
                            'description' => $this->metaDescription($draft->description, $draft->bulletPoints),
                        ],
                        'metafields' => [
                            [
                                'namespace' => 'ctc',
                                'key' => 'quality_score',
                                'type' => 'number_integer',
                                'value' => (string) $draft->qualityScore,
                            ],
                            [
                                'namespace' => 'ctc',
                                'key' => 'quality_grade',
                                'type' => 'single_line_text_field',
                                'value' => $draft->qualityGrade,
                            ],
                        ],
                    ],
                ],
            ],
            'target' => $target,
        ]);
    }

    /**
     * @return array{product_id: ?string, product_gid: ?string, handle: ?string, language: string}
     */
    private function targetProduct(Product $product): array
    {
        foreach ($product->getSources() as $source) {
            if (!$source instanceof ProductSource) {
                continue;
            }

            $cmsSystem = strtolower(trim((string) $source->getCmsSystem()));
            if (!in_array($cmsSystem, ['shopify', 'shopify-admin'], true)) {
                continue;
            }

            $payload = $this->decodePayload($source->getRawPayload());
            $originalPayload = is_array($payload['original_payload'] ?? null) ? $payload['original_payload'] : $payload;
            $productPayload = is_array($originalPayload['product'] ?? null) ? $originalPayload['product'] : $originalPayload;
            $reference = $this->stringValue($source->getExternalReference(), $productPayload['admin_graphql_api_id'] ?? null, $productPayload['id'] ?? null);

            return [
                'product_id' => $this->numericProductId($reference),
                'product_gid' => $this->graphqlProductId($reference),
                'handle' => $this->stringValue($productPayload['handle'] ?? null),
                'language' => strtolower($this->stringValue($source->getLanguageCode(), 'de') ?? 'de'),
            ];
        }

        return [
            'product_id' => null,
            'product_gid' => null,
            'handle' => $product->getSlug(),
            'language' => 'de',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodePayload(string $rawPayload): array
    {
        $rawPayload = trim($rawPayload);
        if ($rawPayload === '') {
            return [];
        }

        $firstBrace = strpos($rawPayload, '{');
        $candidate = $firstBrace === false ? $rawPayload : substr($rawPayload, $firstBrace);

        try {
            $decoded = json_decode($candidate, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    private function graphqlProductId(?string $reference): ?string
    {
        $reference = trim((string) $reference);
        if ($reference === '') {
            return null;
        }

        if (str_starts_with($reference, 'gid://shopify/Product/')) {
            return $reference;
        }

        if (ctype_digit($reference)) {
            return 'gid://shopify/Product/'.$reference;
        }

        return null;
    }

    private function numericProductId(?string $reference): ?string
    {
        $reference = trim((string) $reference);
        if ($reference === '') {
            return null;
        }

        if (ctype_digit($reference)) {
            return $reference;
        }

        if (preg_match('~/Product/(\d+)$~', $reference, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    private function descriptionHtml(string $description): string
    {
        $paragraphs = array_values(array_filter(array_map('trim', preg_split('/\R{2,}/u', $description) ?: [])));
        if ($paragraphs === []) {
            $paragraphs = [trim($description)];
        }

        return implode('', array_map(
            static fn (string $paragraph): string => '<p>'.htmlspecialchars($paragraph, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</p>',
            $paragraphs,
        ));
    }

    private function metaDescription(string $description, array $bulletPoints): string
    {
        $candidate = trim($description) !== '' ? trim($description) : $this->shortText($bulletPoints);
        if ($candidate === '') {
            return '';
        }

        return mb_substr(preg_replace('/\s+/u', ' ', $candidate) ?? $candidate, 0, 160);
    }

    private function stringValue(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            if (is_array($value)) {
                continue;
            }

            $value = trim((string) $value);
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }
}
