<?php

declare(strict_types=1);

namespace App\Service\Integration;

use App\Entity\Product;
use App\Enum\ChannelType;
use App\Service\Export\ListingDataTranslator;
use App\Service\Listing\ProductListingDraftBuilder;

abstract class AbstractExternalSystemWritebackPreviewBuilder implements ExternalSystemWritebackPreviewBuilder
{
    public function __construct(
        protected readonly ProductListingDraftBuilder $productListingDraftBuilder,
        protected readonly ListingDataTranslator $listingDataTranslator,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildPreviewEnvelope(Product $product, array $payload): array
    {
        $draft = $this->productListingDraftBuilder->build($product, ChannelType::Amazon);

        return [
            'system' => $this->system()->value,
            'system_label' => $this->system()->label(),
            'modus' => 'preview_only',
            'ctc_quelle' => 'listing_draft_amazon',
            'produkt' => [
                'id' => $product->getId(),
                'public_id' => (string) $product->getPublicId(),
                'name' => $product->getName(),
            ],
            'ctc_listing' => [
                'titel' => $draft->title,
                'bulletpoints' => $draft->bulletPoints,
                'beschreibung' => $draft->description,
                'suchbegriffe' => $draft->searchTerms,
                'technische_merkmale' => $this->listingDataTranslator->technicalAttributes($draft->technicalAttributes),
                'qualitaet' => [
                    'punktzahl' => $draft->qualityScore,
                    'note' => $draft->qualityGrade,
                    'hinweis' => $draft->qualityReview['confidence_note'] ?? null,
                ],
            ],
            'payload' => $payload,
        ];
    }

    protected function shortText(array $bulletPoints): string
    {
        $bulletPoints = array_values(array_filter(
            array_map(static fn (string $bullet): string => trim($bullet), $bulletPoints),
            static fn (string $bullet): bool => $bullet !== '',
        ));

        return implode(' · ', array_slice($bulletPoints, 0, 3));
    }

    /**
     * @param array<string, scalar|array|null> $technicalAttributes
     *
     * @return array<string, scalar|array|null>
     */
    protected function filteredTechnicalAttributes(array $technicalAttributes): array
    {
        return array_filter(
            $this->listingDataTranslator->technicalAttributes($technicalAttributes),
            static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== [],
        );
    }
}
