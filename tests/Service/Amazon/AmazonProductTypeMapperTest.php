<?php

declare(strict_types=1);

namespace App\Tests\Service\Amazon;

use App\Dto\ListingDraft;
use App\Entity\Product;
use App\Enum\ChannelType;
use App\Service\Amazon\AmazonProductTypeMapper;
use PHPUnit\Framework\TestCase;

final class AmazonProductTypeMapperTest extends TestCase
{
    public function testMapsDrinkBottleToKeywordSearch(): void
    {
        $mapper = new AmazonProductTypeMapper();
        $product = new Product('Edelstahl Trinkflasche 750 ml');

        $mapping = $mapper->map($product, $this->draft(
            'Trinkflasche',
            'Outdoor/Trinkflaschen',
            'North Trail Edelstahl Trinkflasche 750 ml',
            'Robuste Trinkflasche für Alltag, Sport und Reisen.',
        ));

        self::assertSame('drinkware_bottle', $mapping['regel']);
        self::assertSame('keywords', $mapping['strategie']);
        self::assertSame(['water bottle', 'insulated bottle', 'drink bottle'], $mapping['suchbegriffe']);
        self::assertSame('de_DE', $mapping['locale']);
        self::assertSame('hoch', $mapping['vertrauen']);
    }

    public function testFallsBackToItemNameWhenNoRuleMatches(): void
    {
        $mapper = new AmazonProductTypeMapper();
        $product = new Product('Modulares Organisationssystem');

        $mapping = $mapper->map($product, $this->draft(
            'Organizer',
            'Büro/Organisation',
            'Modulares Organisationssystem für Schreibtisch und Ablage',
            'Flexible Fächer für Dokumente, Zubehör und Arbeitsplatzorganisation.',
        ));

        self::assertSame('fallback_freisuche', $mapping['regel']);
        self::assertSame('item_name', $mapping['strategie']);
        self::assertContains('Organizer', $mapping['suchbegriffe']);
        self::assertNotSame('', $mapping['artikelname']);
    }

    private function draft(string $productType, string $categoryPath, string $title, string $description): ListingDraft
    {
        return new ListingDraft(
            ChannelType::Amazon,
            $title,
            ['Doppelwandig und auslaufsicher'],
            $description,
            [
                'product_type' => $productType,
                'category_path' => $categoryPath,
                'language' => 'de',
            ],
            ['test'],
            80,
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
