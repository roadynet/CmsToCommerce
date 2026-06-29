<?php

declare(strict_types=1);

namespace App\Service\Integration;

use App\Entity\Product;
use App\Enum\ChannelType;
use App\Enum\ExternalSystemType;

final class PlentymarketsWritebackPreviewBuilder extends AbstractExternalSystemWritebackPreviewBuilder
{
    public function system(): ExternalSystemType
    {
        return ExternalSystemType::Plentymarkets;
    }

    public function build(Product $product): array
    {
        $draft = $this->productListingDraftBuilder->build($product, ChannelType::Amazon);

        return $this->buildPreviewEnvelope($product, [
            'item' => [
                'texts' => [
                    'name1' => $draft->title,
                    'description' => $draft->description,
                    'shortDescription' => $this->shortText($draft->bulletPoints),
                ],
                'manufacturer' => [
                    'externalName' => $draft->technicalAttributes['brand'] ?? $product->getBrand(),
                ],
                'keywords' => $draft->searchTerms,
                'freeTexts' => $this->filteredTechnicalAttributes($draft->technicalAttributes),
            ],
        ]);
    }
}
