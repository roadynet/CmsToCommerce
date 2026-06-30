<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shopify;

use App\Entity\Product;
use App\Entity\ProductSource;
use App\Enum\SourceType;
use App\Enum\SyncStatus;
use App\Integration\Shopify\ShopifyAdminApiConnector;
use App\Service\Export\ListingDataTranslator;
use App\Service\Import\ProductTextNormalizer;
use App\Service\Integration\ShopifyWritebackPreviewBuilder;
use App\Service\Listing\ProductListingDraftBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ShopifyAdminApiConnectorTest extends TestCase
{
    public function testIsConfiguredReadsExternalSecretsFile(): void
    {
        $secretsFile = tempnam(sys_get_temp_dir(), 'ctc-shopify-secrets-');
        if ($secretsFile === false) {
            self::fail('Temporäre Secrets-Datei konnte nicht erstellt werden.');
        }

        file_put_contents($secretsFile, implode("\n", [
            'SHOPIFY_SHOP_DOMAIN=ctc-demo.myshopify.com',
            'SHOPIFY_ADMIN_ACCESS_TOKEN=shpat_dummy',
            'SHOPIFY_ADMIN_API_VERSION=2026-04',
            'SHOPIFY_ENABLE_LIVE_WRITEBACK=1',
        ]));

        $connector = new ShopifyAdminApiConnector(
            $this->previewBuilder(),
            appExternalSecretsFile: $secretsFile,
        );

        self::assertTrue($connector->isConfigured());

        $payload = $connector->buildPayload(new Product('Testprodukt'));
        self::assertSame('ctc-demo.myshopify.com', $payload['shop_domain']);
        self::assertSame('https://ctc-demo.myshopify.com/admin/api/2026-04/graphql.json', $payload['admin_graphql_endpoint']);
        self::assertTrue($payload['live_writeback_aktiv']);
        self::assertSame([], $payload['konfigurationsluecken']);
    }

    public function testPublishPostsProductUpdateMutation(): void
    {
        $requests = [];
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = [
                'method' => $method,
                'url' => $url,
                'headers' => $options['headers'] ?? [],
                'body' => (string) ($options['body'] ?? ''),
            ];

            return match (true) {
                $url === 'https://ctc-demo.myshopify.com/admin/api/2026-04/graphql.json' => new MockResponse('{"data":{"productUpdate":{"product":{"id":"gid://shopify/Product/471100","title":"North Trail Edelstahl Trinkflasche 750 ml","handle":"edelstahl-trinkflasche"},"userErrors":[]}}}'),
                default => new MockResponse('{"errors":[{"message":"Unerwarteter Test-Endpunkt"}]}', ['http_code' => 500]),
            };
        });

        $product = (new Product('Edelstahl Trinkflasche 750 ml'))
            ->setBrand('North Trail')
            ->setDescription('Robuste Trinkflasche für Alltag, Sport und Reisen.');
        $product->addSource(
            (new ProductSource(SourceType::CmsImport, '{"product":{"id":471100,"admin_graphql_api_id":"gid://shopify/Product/471100","handle":"edelstahl-trinkflasche"}}'))
                ->setCmsSystem('shopify')
                ->setExternalReference('gid://shopify/Product/471100')
                ->setLanguageCode('de')
        );

        $connector = new ShopifyAdminApiConnector(
            $this->previewBuilder(),
            'ctc-demo.myshopify.com',
            'shpat_dummy',
            '2026-04',
            true,
            $httpClient,
        );

        $result = $connector->publish($product);

        self::assertSame(SyncStatus::Succeeded, $result->status);
        self::assertSame('gid://shopify/Product/471100', $result->externalId);
        self::assertSame('POST', $result->payload['api_methode']);
        self::assertSame('/admin/api/2026-04/graphql.json', $result->payload['api_pfad']);

        self::assertSame('POST', $requests[0]['method']);
        self::assertSame('https://ctc-demo.myshopify.com/admin/api/2026-04/graphql.json', $requests[0]['url']);
        self::assertContains('X-Shopify-Access-Token: shpat_dummy', $requests[0]['headers']);

        $body = json_decode($requests[0]['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertStringContainsString('productUpdate', $body['query']);
        self::assertSame('gid://shopify/Product/471100', $body['variables']['input']['id']);
        self::assertArrayHasKey('descriptionHtml', $body['variables']['input']);
        self::assertArrayHasKey('seo', $body['variables']['input']);
        self::assertArrayHasKey('metafields', $body['variables']['input']);
    }

    private function previewBuilder(): ShopifyWritebackPreviewBuilder
    {
        return new ShopifyWritebackPreviewBuilder(
            new ProductListingDraftBuilder(new ProductTextNormalizer()),
            new ListingDataTranslator(),
        );
    }
}
