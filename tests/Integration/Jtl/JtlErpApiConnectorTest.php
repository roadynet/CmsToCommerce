<?php

declare(strict_types=1);

namespace App\Tests\Integration\Jtl;

use App\Entity\Product;
use App\Entity\ProductSource;
use App\Entity\ProductVariant;
use App\Enum\SourceType;
use App\Enum\SyncStatus;
use App\Integration\Jtl\JtlErpApiConnector;
use App\Service\Import\ProductTextNormalizer;
use App\Service\Listing\ProductListingDraftBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class JtlErpApiConnectorTest extends TestCase
{
    public function testIsConfiguredReadsExternalSecretsFile(): void
    {
        $secretsFile = tempnam(sys_get_temp_dir(), 'ctc-jtl-secrets-');
        if ($secretsFile === false) {
            self::fail('Temporäre Secrets-Datei konnte nicht erstellt werden.');
        }

        file_put_contents($secretsFile, implode("\n", [
            'JTL_API_BASE_URL=https://api.jtl-cloud.com/erp',
            'JTL_AUTH_BASE_URL=https://auth.jtl-cloud.com',
            'JTL_TENANT_ID=tenant-123',
            'JTL_CLIENT_ID=client-123',
            'JTL_CLIENT_SECRET=secret-123',
            'JTL_ENABLE_LIVE_WRITEBACK=1',
        ]));

        $connector = new JtlErpApiConnector(
            new ProductListingDraftBuilder(new ProductTextNormalizer()),
            appExternalSecretsFile: $secretsFile,
        );

        self::assertTrue($connector->isConfigured());

        $payload = $connector->buildPayload(new Product('Testprodukt'));
        self::assertSame('https://api.jtl-cloud.com/erp', $payload['basis_url']);
        self::assertSame('https://auth.jtl-cloud.com/oauth2/token', $payload['oauth_endpoint']);
        self::assertTrue($payload['live_writeback_aktiv']);
        self::assertSame([], $payload['konfigurationsluecken']);
    }

    public function testPublishUsesDirectJtlReferenceWhenAvailable(): void
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
                $url === 'https://auth.jtl-cloud.com/oauth2/token' => new MockResponse('{"access_token":"jtl-access-token"}'),
                $url === 'https://api.jtl-cloud.com/erp/v2/itemdetails/change' => new MockResponse('{}'),
                default => new MockResponse('{"message":"Unerwarteter Test-Endpunkt"}', ['http_code' => 500]),
            };
        });

        $product = (new Product('North Trail Edelstahl Trinkflasche 750 ml'))
            ->setBrand('North Trail')
            ->setCategoryPath('Outdoor/Trinkflaschen')
            ->setDescription('Robuste Trinkflasche für Alltag, Sport und Reisen.');
        $product->addSource(
            (new ProductSource(SourceType::CmsImport, '{"article":{"id":"3f0c46ca-fb82-4c91-8d33-111111111111"}}'))
                ->setCmsSystem('jtl')
                ->setExternalReference('3f0c46ca-fb82-4c91-8d33-111111111111')
                ->setLanguageCode('de')
        );

        $connector = new JtlErpApiConnector(
            new ProductListingDraftBuilder(new ProductTextNormalizer()),
            'https://api.jtl-cloud.com/erp',
            'https://auth.jtl-cloud.com',
            'tenant-123',
            'client-123',
            'secret-123',
            true,
            $httpClient,
        );

        $result = $connector->publish($product);

        self::assertSame(SyncStatus::Succeeded, $result->status);
        self::assertSame('3f0c46ca-fb82-4c91-8d33-111111111111', $result->externalId);
        self::assertStringContainsString('aktualisiert', $result->message);
        self::assertSame('PATCH', $result->payload['api_methode']);
        self::assertSame('/v2/itemdetails/change', $result->payload['api_pfad']);
        self::assertSame('direkte_jtl_referenz', $result->payload['zielartikel_aufloesung']['strategie']);
        self::assertSame('3f0c46ca-fb82-4c91-8d33-111111111111', $result->payload['request_payload']['itemId']);

        self::assertSame('POST', $requests[0]['method']);
        self::assertSame('https://auth.jtl-cloud.com/oauth2/token', $requests[0]['url']);
        self::assertStringContainsString('grant_type=client_credentials', $requests[0]['body']);
        self::assertStringContainsString('scope=items.read%20items.write', $requests[0]['body']);

        self::assertSame('PATCH', $requests[1]['method']);
        self::assertSame('https://api.jtl-cloud.com/erp/v2/itemdetails/change', $requests[1]['url']);
        self::assertContains('Authorization: Bearer jtl-access-token', $requests[1]['headers']);
        self::assertContains('x-tenant-id: tenant-123', $requests[1]['headers']);
        $patchBody = json_decode($requests[1]['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('3f0c46ca-fb82-4c91-8d33-111111111111', $patchBody['itemId']);
        self::assertSame('DE', $patchBody['descriptions']['defaultDescriptions'][0]['languageIso']);
        self::assertSame('North Trail North Trail Edelstahl Trinkflasche 750 ml', $patchBody['descriptions']['defaultDescriptions'][0]['descriptionData']['itemName']);
    }

    public function testPublishResolvesTargetBySkuWhenNoDirectReferenceExists(): void
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
                $url === 'https://auth.jtl-cloud.com/oauth2/token' => new MockResponse('{"access_token":"jtl-access-token"}'),
                str_contains($url, '/v2/items?') => new MockResponse('{"items":[{"id":"1e0c46ca-fb82-4c91-8d33-222222222222","sKU":"NT-750-BLK","identifiers":{"gtin":"4259001100011","ownIdentifier":""},"name":"North Trail Edelstahl Trinkflasche 750 ml"}],"totalItems":1,"pageNumber":1,"pageSize":10}'),
                $url === 'https://api.jtl-cloud.com/erp/v2/itemdetails/change' => new MockResponse('{}'),
                default => new MockResponse('{"message":"Unerwarteter Test-Endpunkt"}', ['http_code' => 500]),
            };
        });

        $product = (new Product('North Trail Edelstahl Trinkflasche 750 ml'))
            ->setBrand('North Trail')
            ->setDescription('Robuste Trinkflasche für Alltag, Sport und Reisen.');
        $product->addVariant(
            (new ProductVariant('NT-750-BLK'))
                ->setEan('4259001100011')
                ->setPriceGross('29.90')
                ->setStock(12)
        );

        $connector = new JtlErpApiConnector(
            new ProductListingDraftBuilder(new ProductTextNormalizer()),
            'https://api.jtl-cloud.com/erp',
            'https://auth.jtl-cloud.com',
            'tenant-123',
            'client-123',
            'secret-123',
            true,
            $httpClient,
        );

        $result = $connector->publish($product);

        self::assertSame(SyncStatus::Succeeded, $result->status);
        self::assertSame('1e0c46ca-fb82-4c91-8d33-222222222222', $result->externalId);
        self::assertSame('items_suche', $result->payload['zielartikel_aufloesung']['strategie']);
        self::assertSame('1e0c46ca-fb82-4c91-8d33-222222222222', $result->payload['request_payload']['itemId']);

        self::assertSame('GET', $requests[1]['method']);
        self::assertStringContainsString('/v2/items?', $requests[1]['url']);
        self::assertStringContainsString('searchKeyWord=NT-750-BLK', $requests[1]['url']);

        $patchBody = json_decode($requests[2]['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('1e0c46ca-fb82-4c91-8d33-222222222222', $patchBody['itemId']);
    }
}
