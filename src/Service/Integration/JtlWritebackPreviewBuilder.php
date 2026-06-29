<?php

declare(strict_types=1);

namespace App\Service\Integration;

use App\Entity\Product;
use App\Enum\ChannelType;
use App\Enum\ExternalSystemType;

final class JtlWritebackPreviewBuilder extends AbstractExternalSystemWritebackPreviewBuilder
{
    public function system(): ExternalSystemType
    {
        return ExternalSystemType::Jtl;
    }

    public function build(Product $product): array
    {
        $draft = $this->productListingDraftBuilder->build($product, ChannelType::Amazon);

        return $this->buildPreviewEnvelope($product, [
            'article' => [
                'name' => $draft->title,
                'description' => $draft->description,
                'shortDescription' => $this->shortText($draft->bulletPoints),
                'manufacturerName' => $draft->technicalAttributes['brand'] ?? $product->getBrand(),
                'metaKeywords' => $draft->searchTerms,
                'attributes' => $this->filteredTechnicalAttributes($draft->technicalAttributes),
            ],
        ]);
    }
}
