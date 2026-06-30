<?php

declare(strict_types=1);

namespace App\Tests\Service\Integration;

use App\Service\Integration\ExternalSystemIntakeRegistry;
use App\Service\Integration\GenericPayloadNormalizer;
use App\Service\Integration\JtlPayloadNormalizer;
use App\Service\Integration\PlentymarketsPayloadNormalizer;
use App\Service\Integration\SapR3PayloadNormalizer;
use App\Service\Integration\XentralPayloadNormalizer;
use PHPUnit\Framework\TestCase;

final class ExternalSystemIntakeRegistryTest extends TestCase
{
    private function registry(): ExternalSystemIntakeRegistry
    {
        return new ExternalSystemIntakeRegistry(
            new JtlPayloadNormalizer(),
            new PlentymarketsPayloadNormalizer(),
            new XentralPayloadNormalizer(),
            new SapR3PayloadNormalizer(),
            new GenericPayloadNormalizer(),
        );
    }

    public function testNormalizesJtlPayload(): void
    {
        $normalized = $this->registry()->normalize([
            'article' => [
                'id' => '4711',
                'name' => 'Edelstahl Trinkflasche 750 ml',
                'manufacturerName' => 'North Trail',
                'categoryPath' => 'Outdoor/Trinkflaschen',
                'description' => 'Robuste Flasche.',
                'attributes' => [
                    'Material' => 'Edelstahl',
                    'Farbe' => 'Schwarz',
                ],
            ],
            'variants' => [[
                'sku' => 'NT-750-BLK',
                'ean' => '4259001100011',
                'priceGross' => '29.90',
                'stock' => 24,
                'options' => [
                    'Größe' => '750 ml',
                ],
            ]],
        ], 'jtl');

        self::assertSame('jtl', $normalized['_ctc_system_code']);
        self::assertSame('Edelstahl Trinkflasche 750 ml', $normalized['produkt_name']);
        self::assertSame('North Trail', $normalized['marke']);
        self::assertSame('Outdoor/Trinkflaschen', $normalized['kategorie_pfad']);
        self::assertCount(1, $normalized['variants']);
        self::assertSame('NT-750-BLK', $normalized['variants'][0]['sku']);
        self::assertArrayHasKey('asset_urls', $normalized);
    }

    public function testNormalizesPlentymarketsPayload(): void
    {
        $normalized = $this->registry()->normalize([
            'item' => [
                'id' => 900,
                'texts' => [
                    'name1' => 'Bambus Schneidebrett',
                    'description' => 'Massives Schneidebrett mit Saftrille.',
                ],
                'manufacturer' => [
                    'externalName' => 'Casa Verde',
                ],
            ],
            'variation' => [
                'id' => 901,
                'number' => 'CV-BOARD-01',
                'price' => '24.90',
                'stock' => 8,
                'barcodes' => [[
                    'code' => '4259001100028',
                ]],
            ],
            'categories' => [
                ['name' => 'Küche'],
                ['name' => 'Schneidebretter'],
            ],
        ], 'plentymarkets');

        self::assertSame('plentymarkets', $normalized['_ctc_system_code']);
        self::assertSame('Bambus Schneidebrett', $normalized['produkt_name']);
        self::assertSame('Casa Verde', $normalized['marke']);
        self::assertSame('Küche/Schneidebretter', $normalized['kategorie_pfad']);
        self::assertSame('CV-BOARD-01', $normalized['variants'][0]['sku']);
    }

    public function testNormalizesXentralPayload(): void
    {
        $normalized = $this->registry()->normalize([
            'article' => [
                'id' => '55',
                'name' => 'LED Schreibtischlampe',
                'hersteller' => 'Lumo Desk',
                'beschreibung' => 'Dimmbare LED-Lampe mit USB-C.',
                'nummer' => 'LD-USB-C',
                'preis' => '59.90',
                'lager' => 14,
                'ean' => '4259001100035',
            ],
        ], 'xentral');

        self::assertSame('xentral', $normalized['_ctc_system_code']);
        self::assertSame('LED Schreibtischlampe', $normalized['produkt_name']);
        self::assertSame('Lumo Desk', $normalized['marke']);
        self::assertCount(1, $normalized['variants']);
        self::assertSame('LD-USB-C', $normalized['variants'][0]['sku']);
    }

    public function testNormalizesSapR3Payload(): void
    {
        $normalized = $this->registry()->normalize([
            'MARA' => [
                'MATNR' => '000000000000471100',
                'MATKL' => 'OUTDOOR',
                'MTART' => 'FERT',
                'MEINS' => 'ST',
                'ZZBRAND' => 'North Trail',
                'EAN11' => '4259001100011',
            ],
            'MAKT' => [
                'SPRAS_ISO' => 'DE',
                'MAKTX' => 'Edelstahl Trinkflasche 750 ml',
                'LANGTEXT' => 'Doppelwandige Edelstahl-Trinkflasche.',
            ],
            'MARD' => [
                'WERKS' => '1000',
                'LGORT' => '0001',
                'LABST' => 24,
            ],
            'price' => [
                'NETPR' => '29.90',
                'WAERS' => 'EUR',
            ],
            'images' => [[
                'url' => 'https://example.com/sap/flasche.jpg',
                'filename' => 'flasche.jpg',
            ]],
        ], 'sap_r3');

        self::assertSame('sap_r3', $normalized['_ctc_system_code']);
        self::assertSame('Edelstahl Trinkflasche 750 ml', $normalized['produkt_name']);
        self::assertSame('North Trail', $normalized['marke']);
        self::assertSame('OUTDOOR', $normalized['kategorie_pfad']);
        self::assertSame('000000000000471100', $normalized['external_reference']);
        self::assertSame('000000000000471100', $normalized['variants'][0]['sku']);
        self::assertSame('4259001100011', $normalized['variants'][0]['ean']);
        self::assertSame('29.90', $normalized['variants'][0]['price']);
        self::assertSame(24, $normalized['variants'][0]['stock']);
        self::assertSame('flasche.jpg', $normalized['asset_urls'][0]['name']);
    }

    public function testFallsBackToGenericPayload(): void
    {
        $normalized = $this->registry()->normalize([
            'produkt_name' => 'Generisches Produkt',
            'marke' => 'Acme',
            'source_system' => 'custom-pim',
        ], 'custom-pim');

        self::assertSame('generic', $normalized['_ctc_system_code']);
        self::assertSame('custom-pim', $normalized['cms_system']);
        self::assertSame('Generisches Produkt', $normalized['produkt_name']);
    }
}
