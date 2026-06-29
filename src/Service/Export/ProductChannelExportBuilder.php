<?php

declare(strict_types=1);

namespace App\Service\Export;

use App\Entity\Product;
use App\Enum\ChannelType;
use App\Integration\Amazon\AmazonSpApiConnector;
use App\Integration\Shopware\ShopwareAdminApiConnector;
use App\Service\Listing\ProductListingDraftBuilder;

final class ProductChannelExportBuilder
{
    public function __construct(
        private readonly ProductListingDraftBuilder $draftBuilder,
        private readonly AmazonSpApiConnector $amazonConnector,
        private readonly ShopwareAdminApiConnector $shopwareConnector,
        private readonly ListingDataTranslator $listingDataTranslator,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(Product $product, ChannelType $channel): array
    {
        $draft = $this->draftBuilder->build($product, $channel);

        return [
            'produkt_uebersicht' => [
                'produktart' => $draft->technicalAttributes['product_type'] ?? null,
                'ziel_marktplatz' => $channel->value,
                'sprache' => $draft->technicalAttributes['language'] ?? 'de',
                'kategorie' => $draft->technicalAttributes['category_path'] ?? null,
                'marke' => $draft->technicalAttributes['brand'] ?? null,
                'varianten_modell' => $draft->technicalAttributes['variant_model'] ?? null,
                'hinweis' => $draft->qualityReview['confidence_note'],
            ],
            'quellenpruefung' => $this->listingDataTranslator->sourceAudit($draft->sourceAudit),
            'produktseite' => [
                'titel' => $draft->title,
                'bulletpoints' => $draft->bulletPoints,
                'beschreibung' => $draft->description,
                'technische_merkmale' => $this->listingDataTranslator->technicalAttributes($draft->technicalAttributes),
                'suchbegriffe' => $draft->searchTerms,
                'bildreihenfolge' => $this->listingDataTranslator->imageGuidance($draft->imageGuidance)['bildreihenfolge'],
                'bild_hinweise' => $this->listingDataTranslator->imageGuidance($draft->imageGuidance)['bild_hinweise'],
            ],
            'qualitaetspruefung' => [
                'punktzahl' => $draft->qualityScore,
                'note' => $draft->qualityGrade,
                ...$this->listingDataTranslator->qualityReview($draft->qualityReview),
            ],
            'export' => [
                'amazon' => $this->amazonConnector->buildPayload($product, $draft),
                'shopware' => $this->shopwareConnector->buildPayload($product, $draft),
            ],
        ];
    }
}
