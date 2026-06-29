<?php

declare(strict_types=1);

namespace App\Tests\Service\Import;

use App\Service\Import\ProductTextNormalizer;
use PHPUnit\Framework\TestCase;

final class ProductTextNormalizerTest extends TestCase
{
    public function testNormalizesSemiStructuredText(): void
    {
        $normalizer = new ProductTextNormalizer();

        $result = $normalizer->normalize(<<<TEXT
        Brand: North Trail
        Product Type: Thermo bottle
        Material: Stainless steel
        Color: Black
        EAN: 1234567890123
        - Keeps drinks warm
        - BPA-free lid
        TEXT);

        self::assertSame('North Trail', $result['normalized']['brand']);
        self::assertSame('Thermo bottle', $result['normalized']['product_type']);
        self::assertSame('1234567890123', $result['normalized']['ean']);
        self::assertCount(2, $result['raw']['bullet_lines']);
        self::assertContains('title_candidate', $result['missing_core_fields']);
    }
}
