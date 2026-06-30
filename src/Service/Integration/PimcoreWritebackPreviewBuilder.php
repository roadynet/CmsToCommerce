<?php

declare(strict_types=1);

namespace App\Service\Integration;

use App\Entity\Product;
use App\Entity\ProductSource;
use App\Enum\ChannelType;
use App\Enum\ExternalSystemType;

final class PimcoreWritebackPreviewBuilder extends AbstractExternalSystemWritebackPreviewBuilder
{
    public function system(): ExternalSystemType
    {
        return ExternalSystemType::Pimcore;
    }

    public function build(Product $product): array
    {
        $draft = $this->productListingDraftBuilder->build($product, ChannelType::Amazon);
        $target = $this->targetObject($product);

        return $this->buildPreviewEnvelope($product, [
            'pimcore_hinweis' => 'Pimcore wird in CTC als PIM/DAM-Ziel behandelt. Das Payload ist für einen Pimcore API-, Data-Hub- oder Gateway-Endpunkt vorbereitet.',
            'object' => [
                'id' => $target['object_id'],
                'key' => $target['object_key'],
                'className' => $target['class_name'] ?? 'Product',
            ],
            'data' => [
                'ctcOptimizedTitle' => $draft->title,
                'ctcShortDescription' => $this->shortText($draft->bulletPoints),
                'ctcDescription' => $draft->description,
                'ctcBulletpoints' => $draft->bulletPoints,
                'ctcKeywords' => $draft->searchTerms,
                'ctcAttributes' => $this->filteredTechnicalAttributes($draft->technicalAttributes),
                'ctcQualityScore' => $draft->qualityScore,
                'ctcQualityGrade' => $draft->qualityGrade,
            ],
            'localizedfields' => [
                $target['language'] => [
                    'ctcOptimizedTitle' => $draft->title,
                    'ctcShortDescription' => $this->shortText($draft->bulletPoints),
                    'ctcDescription' => $draft->description,
                ],
            ],
            'workflow' => [
                'status' => 'ctc_review_ready',
                'live_writeback' => false,
            ],
        ]);
    }

    /**
     * @return array{object_id: ?string, object_key: ?string, class_name: ?string, language: string}
     */
    private function targetObject(Product $product): array
    {
        foreach ($product->getSources() as $source) {
            if (!$source instanceof ProductSource) {
                continue;
            }

            $cmsSystem = strtolower(trim((string) $source->getCmsSystem()));
            if (!in_array($cmsSystem, ['pimcore', 'pim'], true)) {
                continue;
            }

            $payload = $this->decodePayload($source->getRawPayload());
            $originalPayload = is_array($payload['original_payload'] ?? null) ? $payload['original_payload'] : $payload;
            $object = is_array($originalPayload['object'] ?? null) ? $originalPayload['object'] : $originalPayload;

            return [
                'object_id' => $this->stringValue($source->getExternalReference(), $object['id'] ?? null, $object['o_id'] ?? null, $object['objectId'] ?? null),
                'object_key' => $this->stringValue($object['key'] ?? null, $object['o_key'] ?? null, $product->getSlug()),
                'class_name' => $this->stringValue($object['className'] ?? null, $object['o_className'] ?? null, 'Product'),
                'language' => strtolower($this->stringValue($source->getLanguageCode(), 'de') ?? 'de'),
            ];
        }

        return [
            'object_id' => null,
            'object_key' => $product->getSlug(),
            'class_name' => 'Product',
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
