<?php

declare(strict_types=1);

namespace App\Tests\Service\Integration;

use App\Entity\Product;
use App\Entity\ProductSource;
use App\Enum\ExternalSystemType;
use App\Enum\SourceType;
use App\Service\Export\ListingDataTranslator;
use App\Service\Import\ProductTextNormalizer;
use App\Service\Integration\ExternalSystemWritebackPreviewRegistry;
use App\Service\Integration\JtlWritebackPreviewBuilder;
use App\Service\Integration\PlentymarketsWritebackPreviewBuilder;
use App\Service\Integration\XentralWritebackPreviewBuilder;
use App\Service\Listing\ProductListingDraftBuilder;
use PHPUnit\Framework\TestCase;

final class ExternalSystemWritebackPreviewRegistryTest extends TestCase
{
    private function registry(): ExternalSystemWritebackPreviewRegistry
    {
        $draftBuilder = new ProductListingDraftBuilder(new ProductTextNormalizer());
        $translator = new ListingDataTranslator();

        return new ExternalSystemWritebackPreviewRegistry(
            new JtlWritebackPreviewBuilder($draftBuilder, $translator),
            new PlentymarketsWritebackPreviewBuilder($draftBuilder, $translator),
            new XentralWritebackPreviewBuilder($draftBuilder, $translator),
        );
    }

    public function testBuildsJtlWritebackPreview(): void
    {
        $product = (new Product('Edelstahl Trinkflasche 750 ml'))
            ->setBrand('North Trail')
            ->setCategoryPath('Outdoor/Trinkflaschen')
            ->setDescription('Doppelwandige Edelstahl-Trinkflasche.');
        $product->addSource(
            (new ProductSource(
                SourceType::CmsImport,
                "Titel: Edelstahl Trinkflasche 750 ml\nMarke: North Trail\nMaterial: Edelstahl\nFarbe: Schwarz"
            ))->setCmsSystem('jtl')->setLanguageCode('de')
        );

        $preview = $this->registry()->build($product, ExternalSystemType::Jtl);

        self::assertSame('jtl', $preview['system']);
        self::assertSame('preview_only', $preview['modus']);
        self::assertSame('North Trail', $preview['payload']['article']['manufacturerName']);
        self::assertArrayHasKey('description', $preview['payload']['article']);
    }
}
