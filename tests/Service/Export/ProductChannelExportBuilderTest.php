<?php

declare(strict_types=1);

namespace App\Tests\Service\Export;

use App\Entity\Product;
use App\Entity\ProductAsset;
use App\Entity\ProductSource;
use App\Enum\AssetType;
use App\Enum\ChannelType;
use App\Enum\SourceType;
use App\Integration\Amazon\AmazonSpApiConnector;
use App\Service\Amazon\AmazonListingsItemPayloadBuilder;
use App\Service\Amazon\AmazonProductTypeMapper;
use App\Integration\Shopware\ShopwareAdminApiConnector;
use App\Service\Export\ProductChannelExportBuilder;
use App\Service\Export\ListingDataTranslator;
use App\Service\Import\ProductTextNormalizer;
use App\Service\Listing\ProductListingDraftBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;

final class ProductChannelExportBuilderTest extends TestCase
{
    public function testBuildUsesGermanExportKeys(): void
    {
        $product = (new Product('Thermo Travel Bottle'))->setBrand('North Trail')->setCategoryPath('Outdoor/Drinkware');
        $product->setDescription('Reusable insulated bottle.');
        $product->addSource(new ProductSource(SourceType::CmsImport, "Brand: North Trail\nProduct Type: Insulated bottle\nMaterial: Stainless steel\nColor: Black"));
        $product->addAsset(new ProductAsset(AssetType::Image, 'front.jpg', 'front.jpg', 'image/jpeg', 'demo/front.jpg'));

        $translator = new ListingDataTranslator();

        $builder = new ProductChannelExportBuilder(
            new ProductListingDraftBuilder(new ProductTextNormalizer()),
            new AmazonSpApiConnector('eu', 'seller', 'market', 'app', new AmazonProductTypeMapper(), new AmazonListingsItemPayloadBuilder(), $translator, '', '', false),
            new ShopwareAdminApiConnector('https://shop.example', 'Standard', $translator, new MockHttpClient(), sys_get_temp_dir()),
            $translator,
        );

        $export = $builder->build($product, ChannelType::Amazon);

        self::assertArrayHasKey('produkt_uebersicht', $export);
        self::assertArrayHasKey('produktseite', $export);
        self::assertArrayHasKey('technische_merkmale', $export['produktseite']);
        self::assertArrayHasKey('produktart', $export['produktseite']['technische_merkmale']);
        self::assertArrayHasKey('kategorie_pfad', $export['produktseite']['technische_merkmale']);
        self::assertArrayNotHasKey('product_type', $export['produktseite']['technische_merkmale']);
        self::assertSame('Hauptbild', $export['produktseite']['bildreihenfolge'][0]['rolle']);
        self::assertArrayHasKey('originaldateiname', $export['produktseite']['bildreihenfolge'][0]);
        self::assertArrayHasKey('verkaeufer_id', $export['export']['amazon']);
        self::assertArrayNotHasKey('seller_id', $export['export']['amazon']);
        self::assertArrayHasKey('produktart', $export['export']['amazon']['merkmale']);
        self::assertArrayNotHasKey('product_type', $export['export']['amazon']['merkmale']);
        self::assertArrayHasKey('produkt_typ_mapping', $export['export']['amazon']);
        self::assertArrayHasKey('strategie', $export['export']['amazon']['produkt_typ_mapping']);
        self::assertSame('password_grant_administration', $export['export']['shopware']['auth_modus']);
        self::assertStringContainsString('/api/product', $export['export']['shopware']['produkt_endpoint']);
        self::assertTrue($export['export']['shopware']['produkt_payload']['active']);
        self::assertSame(30, $export['export']['shopware']['produkt_payload']['visibilities'][0]['visibility']);
        self::assertArrayHasKey('bild_uploads', $export['export']['shopware']);
    }
}
