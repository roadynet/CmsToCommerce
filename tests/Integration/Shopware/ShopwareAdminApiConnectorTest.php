<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shopware;

use App\Dto\ListingDraft;
use App\Entity\Product;
use App\Entity\ProductAsset;
use App\Entity\ProductVariant;
use App\Enum\AssetType;
use App\Enum\ChannelType;
use App\Enum\SyncStatus;
use App\Integration\Shopware\ShopwareAdminApiConnector;
use App\Service\Export\ListingDataTranslator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Uid\Ulid;

final class ShopwareAdminApiConnectorTest extends TestCase
{
    public function testPublishUsesSkillbuilderStylePasswordGrantAndUploadsProductImages(): void
    {
        $product = (new Product('Aurora LED Tischleuchte'))
            ->setBrand('Acme')
            ->setDescription('Schlichte Tischleuchte mit warmweißem Licht.');

        $variant = (new ProductVariant('AURORA-001'))
            ->setPriceGross('29.90')
            ->setStock(5);
        $product->addVariant($variant);

        $mediaRoot = sys_get_temp_dir().'/ctc-shopware-media-'.bin2hex(random_bytes(4));
        mkdir($mediaRoot.'/demo', 0777, true);
        file_put_contents(
            $mediaRoot.'/demo/front.png',
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wn3lWQAAAAASUVORK5CYII=', true) ?: ''
        );

        $asset = new ProductAsset(AssetType::Image, 'front.png', 'front.png', 'image/png', 'demo/front.png');
        $asset->setPosition(1);
        $product->addAsset($asset);

        $draft = new ListingDraft(
            ChannelType::Shopware,
            'Aurora LED Tischleuchte',
            ['Warmweißes Licht', 'Kompaktes Format'],
            'Schlichte Tischleuchte mit warmweißem Licht.',
            [
                'brand' => 'Acme',
                'category_path' => 'Wohnen > Licht',
                'product_type' => 'Tischleuchte',
            ],
            ['aurora', 'tischleuchte', 'led'],
            92,
            'A',
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
                'confidence_note' => 'Test',
            ],
        );

        $requests = [];
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = [
                'method' => $method,
                'url' => $url,
                'body' => (string) ($options['body'] ?? ''),
            ];

            return match (true) {
                str_ends_with($url, '/api/oauth/token') => new MockResponse('{"access_token":"test-token"}'),
                str_ends_with($url, '/api/search/sales-channel') => new MockResponse('{"data":[{"id":"sales-channel-1","navigationCategoryId":"root-category-1"}]}'),
                str_ends_with($url, '/api/search/product') => new MockResponse('{"data":[]}'),
                str_ends_with($url, '/api/search/tax') => new MockResponse('{"data":[{"id":"tax-1","taxRate":19}]}'),
                str_ends_with($url, '/api/search/currency') => new MockResponse('{"data":[{"id":"currency-1","isoCode":"EUR"}]}'),
                str_ends_with($url, '/api/search/category') => new MockResponse('{"data":[{"id":"category-1","name":"Amazon Imports"}]}'),
                str_ends_with($url, '/api/search/product-manufacturer') => new MockResponse('{"data":[{"id":"manufacturer-1","name":"Acme"}]}'),
                str_ends_with($url, '/api/search/media') => new MockResponse('{"data":[]}'),
                str_contains($url, '/api/_action/media/') && str_contains($url, '/upload?') => new MockResponse('', ['http_code' => 204]),
                str_ends_with($url, '/api/media') => new MockResponse('{}', ['http_code' => 204]),
                str_ends_with($url, '/api/product') => new MockResponse('{}', ['http_code' => 204]),
                default => new MockResponse('{"errors":[{"detail":"Unerwarteter Test-Endpunkt"}]}', ['http_code' => 500]),
            };
        });

        $connector = new ShopwareAdminApiConnector(
            '',
            'Amazon Imports',
            new ListingDataTranslator(),
            $httpClient,
            $mediaRoot,
            '',
            'https://shop.example',
            'admin-user',
            'admin-password',
        );

        $result = $connector->publish($product, $draft);

        self::assertSame(SyncStatus::Succeeded, $result->status);
        self::assertNotNull($result->externalId);
        self::assertSame('POST', $requests[0]['method']);
        self::assertSame('https://shop.example/api/oauth/token', $requests[0]['url']);
        self::assertStringContainsString('"grant_type":"password"', $requests[0]['body']);
        self::assertStringContainsString('"client_id":"administration"', $requests[0]['body']);
        self::assertStringContainsString('"username":"admin-user"', $requests[0]['body']);
        self::assertStringContainsString('"password":"admin-password"', $requests[0]['body']);
        self::assertNotEmpty(array_filter($requests, static fn (array $request): bool => str_ends_with($request['url'], '/api/media')));
        self::assertNotEmpty(array_filter($requests, static fn (array $request): bool => str_contains($request['url'], '/api/_action/media/') && $request['body'] !== ''));
        self::assertSame('/api/product', $result->payload['api_pfad']);
        self::assertSame('POST', $result->payload['api_methode']);
        self::assertSame('tax-1', $result->payload['aufgeloeste_ids']['tax_id']);
        self::assertSame('currency-1', $result->payload['aufgeloeste_ids']['currency_id']);
        self::assertSame('category-1', $result->payload['aufgeloeste_ids']['category_id']);
        self::assertSame('manufacturer-1', $result->payload['aufgeloeste_ids']['manufacturer_id']);
        self::assertSame('sales-channel-1', $result->payload['aufgeloeste_ids']['sales_channel_id']);
        self::assertSame('Aurora LED Tischleuchte', $result->payload['produkt_request']['name']);
        self::assertSame('CTC-'.strtoupper((string) $product->getPublicId()), $result->payload['produkt_request']['productNumber']);
        self::assertTrue($result->payload['produkt_request']['active']);
        self::assertSame('sales-channel-1', $result->payload['produkt_request']['visibilities'][0]['salesChannelId']);
        self::assertSame(30, $result->payload['produkt_request']['visibilities'][0]['visibility']);
        self::assertArrayHasKey('coverId', $result->payload['produkt_request']);
        self::assertCount(1, $result->payload['produkt_request']['media']);
    }

    public function testIsConfiguredReadsExternalSecretsFile(): void
    {
        $secretsFile = tempnam(sys_get_temp_dir(), 'ctc-shopware-secrets-');
        if ($secretsFile === false) {
            self::fail('Temporäre Secrets-Datei konnte nicht erstellt werden.');
        }

        file_put_contents($secretsFile, implode("\n", [
            'SHOPWARE_ADMIN_BASE_URL=https://sw.mcmonaco.de',
            'SHOPWARE_ADMIN_USERNAME=admin@example.invalid',
            'SHOPWARE_ADMIN_PASSWORD=secret',
        ]));

        $connector = new ShopwareAdminApiConnector(
            '',
            'Amazon Imports',
            new ListingDataTranslator(),
            new MockHttpClient(),
            sys_get_temp_dir(),
            $secretsFile,
        );

        self::assertTrue($connector->isConfigured());
        self::assertSame('https://sw.mcmonaco.de', $connector->buildPayload(new Product('Testprodukt'), $this->draft())['basis_url']);
    }

    public function testBuildPayloadUsesUniqueFullUlidForProductNumber(): void
    {
        $first = new Product('Produkt Eins');
        $second = new Product('Produkt Zwei');

        $this->assignPublicId($first, '01J0ABCDE0TSV4RRFFQ69G5FAV');
        $this->assignPublicId($second, '01J0ABCDE0ZZZ4RRFFQ69G5FAA');

        $connector = new ShopwareAdminApiConnector(
            'https://shop.example',
            'Amazon Imports',
            new ListingDataTranslator(),
            new MockHttpClient(),
            sys_get_temp_dir(),
        );

        $firstNumber = $connector->buildPayload($first, $this->draft())['produktnummer'];
        $secondNumber = $connector->buildPayload($second, $this->draft())['produktnummer'];

        self::assertSame('CTC-01J0ABCDE0TSV4RRFFQ69G5FAV', $firstNumber);
        self::assertSame('CTC-01J0ABCDE0ZZZ4RRFFQ69G5FAA', $secondNumber);
        self::assertNotSame($firstNumber, $secondNumber);
    }

    private function draft(): ListingDraft
    {
        return new ListingDraft(
            ChannelType::Shopware,
            'Testprodukt',
            ['Punkt 1'],
            'Beschreibung',
            [],
            [],
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

    private function assignPublicId(Product $product, string $ulid): void
    {
        $reflection = new \ReflectionProperty($product, 'publicId');
        $reflection->setAccessible(true);
        $reflection->setValue($product, new Ulid($ulid));
    }
}
