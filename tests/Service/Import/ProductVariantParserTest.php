<?php

declare(strict_types=1);

namespace App\Tests\Service\Import;

use App\Service\Import\ProductVariantParser;
use PHPUnit\Framework\TestCase;

final class ProductVariantParserTest extends TestCase
{
    public function testParseStructuredAcceptsGermanJsonKeys(): void
    {
        $parser = new ProductVariantParser();

        $result = $parser->parseStructured([
            [
                'sku' => 'SKU-SCHWARZ-M',
                'optionen' => [
                    'Farbe' => 'Schwarz',
                    'Größe' => 'M',
                ],
                'preis' => '29,90',
                'waehrung' => 'eur',
                'bestand' => 8,
                'aktiv' => 'ja',
            ],
        ]);

        self::assertCount(1, $result);
        self::assertSame('SKU-SCHWARZ-M', $result[0]['sku']);
        self::assertSame(['Farbe' => 'Schwarz', 'Größe' => 'M'], $result[0]['options']);
        self::assertSame('29.90', $result[0]['priceGross']);
        self::assertSame('EUR', $result[0]['currency']);
        self::assertSame(8, $result[0]['stock']);
        self::assertTrue($result[0]['enabled']);
    }
}
