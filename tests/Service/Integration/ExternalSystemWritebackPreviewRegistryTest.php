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
use App\Service\Integration\SapR3WritebackPreviewBuilder;
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
            new SapR3WritebackPreviewBuilder($draftBuilder, $translator),
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

    public function testBuildsSapR3WritebackPreview(): void
    {
        $product = (new Product('Edelstahl Trinkflasche 750 ml'))
            ->setBrand('North Trail')
            ->setCategoryPath('Outdoor/Trinkflaschen')
            ->setDescription('Doppelwandige Edelstahl-Trinkflasche.');
        $product->addSource(
            (new ProductSource(
                SourceType::CmsImport,
                '{"MARA":{"MATNR":"000000000000471100"}}'
            ))->setCmsSystem('sap_r3')->setExternalReference('000000000000471100')->setLanguageCode('de')
        );

        $preview = $this->registry()->build($product, ExternalSystemType::SapR3);

        self::assertSame('sap_r3', $preview['system']);
        self::assertSame('MATMAS05', $preview['payload']['idoc']['basic_type']);
        self::assertSame('000000000000471100', $preview['payload']['idoc']['segments']['E1MARAM']['MATNR']);
        self::assertSame('BAPI_MATERIAL_SAVEDATA', $preview['payload']['bapi']['name']);
        self::assertArrayHasKey('ZCTC_LISTING_TEXT', $preview['payload']['idoc']['segments']);
    }
}
