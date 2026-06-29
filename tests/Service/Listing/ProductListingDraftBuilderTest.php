<?php

declare(strict_types=1);

namespace App\Tests\Service\Listing;

use App\Entity\Product;
use App\Entity\ProductAsset;
use App\Entity\ProductSource;
use App\Enum\AssetType;
use App\Enum\ChannelType;
use App\Enum\SourceType;
use App\Service\Import\ProductTextNormalizer;
use App\Service\Listing\ProductListingDraftBuilder;
use PHPUnit\Framework\TestCase;

final class ProductListingDraftBuilderTest extends TestCase
{
    public function testBuildsDraftWithSignalsFromProductData(): void
    {
        $product = (new Product('Thermo Travel Bottle'))->setBrand('North Trail')->setCategoryPath('Outdoor/Drinkware');
        $product->setDescription('Reusable insulated bottle.');
        $product->addSource(new ProductSource(SourceType::CmsImport, "Brand: North Trail\nProduct Type: Insulated bottle\nMaterial: Stainless steel\nColor: Black"));
        $product->addAsset(new ProductAsset(AssetType::Image, 'front.jpg', 'front.jpg', 'image/jpeg', 'demo/front.jpg'));

        $builder = new ProductListingDraftBuilder(new ProductTextNormalizer());
        $draft = $builder->build($product, ChannelType::Amazon);

        self::assertSame(ChannelType::Amazon, $draft->channel);
        self::assertStringContainsString('North Trail', $draft->title);
        self::assertGreaterThanOrEqual(80, $draft->qualityScore);
        self::assertContains('Outdoor/Drinkware', $draft->searchTerms);
        self::assertSame('A', $draft->qualityGrade);
        self::assertNotEmpty($draft->qualityReview['strengths']);
        self::assertStringNotContainsString('Entwurf', $draft->description);
        self::assertStringContainsString('Reusable insulated bottle.', $draft->description);
    }

    public function testBuildDescriptionSkipsStructuredMetadataParagraphs(): void
    {
        $product = (new Product('Edelstahl Trinkflasche 750 ml'))->setBrand('North Trail')->setCategoryPath('Outdoor/Drinkware');
        $product->setDescription("Titel: Edelstahl Trinkflasche 750 ml Marke: North Trail Produktart: Trinkflasche Material: Edelstahl Farbe: Schwarz Preis: 29,90\n\nDoppelwandige Edelstahl-Trinkflasche für heiße und kalte Getränke.");
        $product->addSource(new ProductSource(SourceType::CmsImport, "Brand: North Trail\nProduct Type: Trinkflasche\nMaterial: Edelstahl\nColor: Schwarz\nSize: 750 ml\nDimensions: 28 x 7 cm\nWeight: 320 g\nCompatibility: Alltag, Sport, Reisen"));
        $product->addAsset(new ProductAsset(AssetType::Image, 'front.jpg', 'front.jpg', 'image/jpeg', 'demo/front.jpg'));

        $builder = new ProductListingDraftBuilder(new ProductTextNormalizer());
        $draft = $builder->build($product, ChannelType::Shopware);

        self::assertStringNotContainsString('Titel:', $draft->description);
        self::assertStringNotContainsString('Listing-Entwurf', $draft->description);
        self::assertStringContainsString('Doppelwandige Edelstahl-Trinkflasche', $draft->description);
        self::assertStringContainsString('Abmessungen: 28 x 7 cm.', $draft->description);
    }
}
