<?php

declare(strict_types=1);

namespace App\Service\Integration;

use App\Entity\Product;
use App\Enum\ExternalSystemType;

final class ExternalSystemWritebackPreviewRegistry
{
    /**
     * @var array<string, ExternalSystemWritebackPreviewBuilder>
     */
    private array $builders = [];

    public function __construct(
        JtlWritebackPreviewBuilder $jtlWritebackPreviewBuilder,
        PlentymarketsWritebackPreviewBuilder $plentymarketsWritebackPreviewBuilder,
        XentralWritebackPreviewBuilder $xentralWritebackPreviewBuilder,
        SapR3WritebackPreviewBuilder $sapR3WritebackPreviewBuilder,
        PimcoreWritebackPreviewBuilder $pimcoreWritebackPreviewBuilder,
        ShopifyWritebackPreviewBuilder $shopifyWritebackPreviewBuilder,
    ) {
        foreach ([
            $jtlWritebackPreviewBuilder,
            $plentymarketsWritebackPreviewBuilder,
            $xentralWritebackPreviewBuilder,
            $sapR3WritebackPreviewBuilder,
            $pimcoreWritebackPreviewBuilder,
            $shopifyWritebackPreviewBuilder,
        ] as $builder) {
            $this->builders[$builder->system()->value] = $builder;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function build(Product $product, ExternalSystemType $system): array
    {
        $builder = $this->builders[$system->value] ?? null;
        if ($builder === null) {
            throw new \InvalidArgumentException(sprintf('Für %s gibt es noch keine Write-back-Preview.', $system->label()));
        }

        return $builder->build($product);
    }
}
