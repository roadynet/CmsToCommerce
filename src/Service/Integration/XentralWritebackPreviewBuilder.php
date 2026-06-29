<?php

declare(strict_types=1);

namespace App\Service\Integration;

use App\Entity\Product;
use App\Enum\ChannelType;
use App\Enum\ExternalSystemType;

final class XentralWritebackPreviewBuilder extends AbstractExternalSystemWritebackPreviewBuilder
{
    public function system(): ExternalSystemType
    {
        return ExternalSystemType::Xentral;
    }

    public function build(Product $product): array
    {
        $draft = $this->productListingDraftBuilder->build($product, ChannelType::Amazon);

        return $this->buildPreviewEnvelope($product, [
            'article' => [
                'name' => $draft->title,
                'beschreibung' => $draft->description,
                'kurztext' => $this->shortText($draft->bulletPoints),
                'hersteller' => $draft->technicalAttributes['brand'] ?? $product->getBrand(),
                'stichpunkte' => $draft->bulletPoints,
                'suchbegriffe' => $draft->searchTerms,
                'merkmale' => $this->filteredTechnicalAttributes($draft->technicalAttributes),
            ],
        ]);
    }
}
