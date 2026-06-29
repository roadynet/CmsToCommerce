<?php

declare(strict_types=1);

namespace App\Tests\Service\Amazon;

use App\Dto\ListingDraft;
use App\Entity\Product;
use App\Entity\ProductAsset;
use App\Entity\ProductVariant;
use App\Enum\AssetType;
use App\Enum\ChannelType;
use App\Service\Amazon\AmazonListingsItemPayloadBuilder;
use PHPUnit\Framework\TestCase;

final class AmazonListingsItemPayloadBuilderTest extends TestCase
{
    public function testBuildMapsKnownSchemaAttributesFromDraft(): void
    {
        $builder = new AmazonListingsItemPayloadBuilder();
        $product = (new Product('Edelstahl Trinkflasche 750 ml'))->setBrand('North Trail');
        $product->addVariant(
            (new ProductVariant('SKU-1'))
                ->setPriceGross('29.90')
                ->setCurrency('EUR')
                ->setStock(14),
        );
        $this->addImageAsset($product, 301, 1, 'front.jpg');
        $this->addImageAsset($product, 302, 2, 'detail.jpg');

        $result = $builder->build(
            $product,
            $this->draft(),
            'A1PA6795UKMFR9',
            'de_DE',
            'WATER_BOTTLE',
            [
                'properties' => [
                    'attributes' => [
                        'properties' => [
                            'condition_type' => [],
                            'item_name' => [],
                            'brand' => [],
                            'manufacturer' => [],
                            'product_description' => [],
                            'bullet_point' => [],
                            'generic_keyword' => [],
                            'color' => [],
                            'purchasable_offer' => [],
                            'fulfillment_availability' => [],
                            'main_product_image_locator' => [],
                            'other_product_image_locator_1' => [],
                        ],
                        'required' => ['condition_type', 'item_name', 'brand', 'purchasable_offer', 'fulfillment_availability'],
                    ],
                ],
            ],
            'LISTING',
        );

        self::assertSame('WATER_BOTTLE', $result['body']['productType']);
        self::assertSame('LISTING', $result['body']['requirements']);
        self::assertArrayHasKey('condition_type', $result['body']['attributes']);
        self::assertArrayHasKey('item_name', $result['body']['attributes']);
        self::assertArrayHasKey('brand', $result['body']['attributes']);
        self::assertArrayHasKey('bullet_point', $result['body']['attributes']);
        self::assertArrayHasKey('generic_keyword', $result['body']['attributes']);
        self::assertArrayHasKey('color', $result['body']['attributes']);
        self::assertArrayHasKey('purchasable_offer', $result['body']['attributes']);
        self::assertArrayHasKey('fulfillment_availability', $result['body']['attributes']);
        self::assertArrayHasKey('main_product_image_locator', $result['body']['attributes']);
        self::assertArrayHasKey('other_product_image_locator_1', $result['body']['attributes']);
        self::assertSame(29.9, $result['body']['attributes']['purchasable_offer'][0]['our_price'][0]['schedule'][0]['value_with_tax']);
        self::assertSame(14, $result['body']['attributes']['fulfillment_availability'][0]['quantity']);
        self::assertSame('http://localhost/media/product/301', $result['body']['attributes']['main_product_image_locator'][0]['media_location']);
        self::assertSame('http://localhost/media/product/302', $result['body']['attributes']['other_product_image_locator_1'][0]['media_location']);
        self::assertSame([], $result['lokal_fehlende_pflichtattribute']);
        self::assertContains('material', $result['ausgelassene_kandidaten']);
    }

    private function addImageAsset(Product $product, int $id, int $position, string $name): void
    {
        $asset = (new ProductAsset(AssetType::Image, $name, $name, 'image/jpeg', 'demo/'.$name))
            ->setPosition($position);

        $reflection = new \ReflectionProperty($asset, 'id');
        $reflection->setValue($asset, $id);

        $product->addAsset($asset);
    }

    private function draft(): ListingDraft
    {
        return new ListingDraft(
            ChannelType::Amazon,
            'North Trail Edelstahl Trinkflasche 750 ml',
            ['Doppelwandig und auslaufsicher', 'Für Alltag und Sport'],
            'Robuste Trinkflasche aus Edelstahl für Alltag, Sport und Reisen.',
            [
                'product_type' => 'Trinkflasche',
                'brand' => 'North Trail',
                'category_path' => 'Outdoor/Trinkflaschen',
                'language' => 'de',
                'color' => 'Schwarz',
                'material' => 'Edelstahl',
                'size' => '750 ml',
            ],
            ['Trinkflasche', 'Edelstahl', 'Outdoor'],
            88,
            'B',
            [
                'observed_facts' => [],
                'inferred_facts' => [],
                'missing_or_unverified' => [],
                'conflicts' => [],
            ],
            [
                'sequence' => [],
                'improvement_notes' => [],
            ],
            [
                'strengths' => [],
                'blockers' => [],
                'fixes_to_reach_a_level' => [],
                'confidence_note' => 'ok',
            ],
        );
    }
}
