<?php

declare(strict_types=1);

namespace App\Tests\Integration\Amazon;

use App\Dto\ListingDraft;
use App\Entity\Product;
use App\Entity\ProductAsset;
use App\Entity\ProductVariant;
use App\Enum\AssetType;
use App\Enum\ChannelType;
use App\Enum\SyncStatus;
use App\Integration\Amazon\AmazonSpApiConnector;
use App\Service\Amazon\AmazonListingsItemPayloadBuilder;
use App\Service\Amazon\AmazonProductTypeMapper;
use App\Service\Export\ListingDataTranslator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class AmazonSpApiConnectorTest extends TestCase
{
    public function testIsConfiguredReadsExternalSecretsFile(): void
    {
        $secretsFile = tempnam(sys_get_temp_dir(), 'ctc-amazon-secrets-');
        if ($secretsFile === false) {
            self::fail('Temporäre Secrets-Datei konnte nicht erstellt werden.');
        }

        file_put_contents($secretsFile, implode("\n", [
            'AMAZON_REGION=eu',
            'AMAZON_SELLER_ID=A1SELLER123',
            'AMAZON_MARKETPLACE_ID=A1PA6795UKMFR9',
            'AMAZON_APP_ID=amzn-app-id',
            'AMAZON_CLIENT_SECRET=secret-value',
            'AMAZON_REFRESH_TOKEN=refresh-token',
        ]));

        $connector = new AmazonSpApiConnector(
            '',
            '',
            '',
            '',
            new AmazonProductTypeMapper(),
            new AmazonListingsItemPayloadBuilder(),
            new ListingDataTranslator(),
            '',
            '',
            false,
            new MockHttpClient(),
            $secretsFile,
        );

        self::assertTrue($connector->isConfigured());

        $payload = $connector->buildPayload(new Product('Testprodukt'), $this->draft());
        self::assertSame('A1SELLER123', $payload['verkaeufer_id']);
        self::assertSame('A1PA6795UKMFR9', $payload['marktplatz_id']);
        self::assertSame('https://api.amazon.com/auth/o2/token', $payload['oauth_endpoint']);
        self::assertSame('https://sellingpartnerapi-eu.amazon.com', $payload['sp_api_basis_url']);
        self::assertSame('VALIDATION_PREVIEW', $payload['listings_item_validierungsmodus']);
        self::assertSame('LISTING_PRODUCT_ONLY', $payload['listings_item_requirements']);
        self::assertSame('keywords', $payload['produkt_typ_mapping']['strategie']);
    }

    public function testPublishBuildsValidationPreviewPayload(): void
    {
        $requests = [];
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = [
                'method' => $method,
                'url' => $url,
                'headers' => $options['headers'] ?? [],
                'body' => $options['body'] ?? null,
            ];

            return match (true) {
                $url === 'https://api.amazon.com/auth/o2/token' => new MockResponse('{"access_token":"amzn-access-token"}'),
                str_starts_with($url, 'https://sellingpartnerapi-eu.amazon.com/sellers/v1/marketplaceParticipations') => new MockResponse('{"payload":[{"marketplace":{"id":"A1PA6795UKMFR9","name":"Amazon.de","countryCode":"DE"},"participation":{"isParticipating":true,"hasSuspendedListings":false}}]}'),
                str_contains($url, '/definitions/2020-09-01/productTypes?') => new MockResponse('{"productTypes":[{"name":"WATER_BOTTLE","displayName":"Water Bottle","marketplaceIds":["A1PA6795UKMFR9"]}]}'),
                str_contains($url, '/definitions/2020-09-01/productTypes/WATER_BOTTLE?') => new MockResponse('{"requirements":"LISTING","requirementsEnforced":"ENFORCED","propertyGroups":{"core":{"title":"Core","propertyNames":["condition_type","item_name","brand","bullet_point","product_description","generic_keyword","color","purchasable_offer","fulfillment_availability","main_product_image_locator","other_product_image_locator_1"]}},"schema":{"link":{"resource":"https://schemas.example/WATER_BOTTLE","checksum":"abc"}},"metaSchema":{"link":{"resource":"https://schemas.example/meta","checksum":"meta"}}}'),
                $url === 'https://schemas.example/WATER_BOTTLE' => new MockResponse('{"properties":{"attributes":{"properties":{"condition_type":{},"item_name":{},"brand":{},"bullet_point":{},"product_description":{},"generic_keyword":{},"color":{},"purchasable_offer":{},"fulfillment_availability":{},"main_product_image_locator":{},"other_product_image_locator_1":{}},"required":["condition_type","item_name","brand","purchasable_offer","fulfillment_availability"]}}}'),
                str_contains($url, '/listings/2021-08-01/items/A1SELLER123/CTC-') => new MockResponse('{"sku":"CTC-01","status":"VALID","submissionId":"preview-123","identifiers":[{"marketplaceId":"A1PA6795UKMFR9","asin":"B07TEST123"}],"issues":[]}'),
                default => new MockResponse('{"message":"Unerwarteter Test-Endpunkt"}', ['http_code' => 500]),
            };
        });

        $connector = new AmazonSpApiConnector(
            'eu',
            'A1SELLER123',
            'A1PA6795UKMFR9',
            'amzn-app-id',
            new AmazonProductTypeMapper(),
            new AmazonListingsItemPayloadBuilder(),
            new ListingDataTranslator(),
            'secret-value',
            'refresh-token',
            false,
            $httpClient,
        );

        $product = new Product('Testprodukt');
        $product->addVariant(
            (new ProductVariant('SKU-1'))
                ->setPriceGross('29.90')
                ->setCurrency('EUR')
                ->setStock(12),
        );
        $this->addImageAsset($product, 101, 1, 'front.jpg');
        $this->addImageAsset($product, 102, 2, 'detail.jpg');
        $result = $connector->publish($product, $this->draft());

        self::assertSame(SyncStatus::Succeeded, $result->status);
        self::assertStringContainsString('Amazon-Validierung erfolgreich', $result->message);
        self::assertTrue($result->payload['verbindungspruefung']['configured_marketplace_found']);
        self::assertSame('WATER_BOTTLE', $result->payload['produkt_typ_mapping']['amazon_kandidaten']['ausgewaehlter_product_type']['name']);
        self::assertSame('WATER_BOTTLE', $result->payload['produkttyp_definition']['product_type']);
        self::assertSame('LISTING', $result->payload['listings_item_payload']['body']['requirements']);
        self::assertArrayHasKey('condition_type', $result->payload['listings_item_payload']['body']['attributes']);
        self::assertArrayHasKey('item_name', $result->payload['listings_item_payload']['body']['attributes']);
        self::assertArrayHasKey('purchasable_offer', $result->payload['listings_item_payload']['body']['attributes']);
        self::assertArrayHasKey('fulfillment_availability', $result->payload['listings_item_payload']['body']['attributes']);
        self::assertArrayHasKey('main_product_image_locator', $result->payload['listings_item_payload']['body']['attributes']);
        self::assertArrayHasKey('other_product_image_locator_1', $result->payload['listings_item_payload']['body']['attributes']);
        self::assertSame('VALID', $result->payload['validierung']['status']);
        self::assertSame([], $result->payload['validierung']['fehlende_pflichtattribute_laut_amazon']);
        self::assertFalse($result->payload['live_sync']);
        self::assertSame('B07TEST123', $result->payload['erkannte_amazon_asin']);
        self::assertSame('B07TEST123', $result->externalId);
        self::assertSame('LISTING', $result->payload['listings_item_requirements']);

        self::assertSame('POST', $requests[0]['method']);
        self::assertSame('https://api.amazon.com/auth/o2/token', $requests[0]['url']);
        self::assertStringContainsString('grant_type=refresh_token', (string) $requests[0]['body']);

        self::assertSame('GET', $requests[1]['method']);
        self::assertSame('https://sellingpartnerapi-eu.amazon.com/sellers/v1/marketplaceParticipations', $requests[1]['url']);
        self::assertContains('x-amz-access-token: amzn-access-token', $requests[1]['headers']);

        self::assertSame('GET', $requests[2]['method']);
        self::assertStringContainsString('/definitions/2020-09-01/productTypes?', $requests[2]['url']);
        self::assertStringContainsString('keywords=water%20bottle%2Cinsulated%20bottle%2Cdrink%20bottle', $requests[2]['url']);

        self::assertSame('GET', $requests[3]['method']);
        self::assertStringContainsString('/definitions/2020-09-01/productTypes/WATER_BOTTLE?', $requests[3]['url']);
        self::assertStringContainsString('requirements=LISTING', $requests[3]['url']);

        self::assertSame('GET', $requests[4]['method']);
        self::assertSame('https://schemas.example/WATER_BOTTLE', $requests[4]['url']);

        self::assertSame('PUT', $requests[5]['method']);
        self::assertStringContainsString('/listings/2021-08-01/items/A1SELLER123/CTC-', $requests[5]['url']);
        self::assertStringContainsString('mode=VALIDATION_PREVIEW', $requests[5]['url']);
        self::assertStringContainsString('includedData=issues%2Cidentifiers', $requests[5]['url']);
        $putBody = json_decode((string) $requests[5]['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('WATER_BOTTLE', $putBody['productType']);
        self::assertSame('LISTING', $putBody['requirements']);
        self::assertArrayHasKey('condition_type', $putBody['attributes']);
        self::assertArrayHasKey('purchasable_offer', $putBody['attributes']);
        self::assertArrayHasKey('fulfillment_availability', $putBody['attributes']);
        self::assertArrayHasKey('main_product_image_locator', $putBody['attributes']);
        self::assertSame('http://localhost/media/product/101', $putBody['attributes']['main_product_image_locator'][0]['media_location']);
    }

    public function testPublishPerformsLiveSubmitWhenEnabled(): void
    {
        $requests = [];
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = [
                'method' => $method,
                'url' => $url,
                'headers' => $options['headers'] ?? [],
                'body' => $options['body'] ?? null,
            ];

            return match (true) {
                $url === 'https://api.amazon.com/auth/o2/token' => new MockResponse('{"access_token":"amzn-access-token"}'),
                str_starts_with($url, 'https://sellingpartnerapi-eu.amazon.com/sellers/v1/marketplaceParticipations') => new MockResponse('{"payload":[{"marketplace":{"id":"A1PA6795UKMFR9","name":"Amazon.de","countryCode":"DE"},"participation":{"isParticipating":true,"hasSuspendedListings":false}}]}'),
                str_contains($url, '/definitions/2020-09-01/productTypes?') => new MockResponse('{"productTypes":[{"name":"WATER_BOTTLE","displayName":"Water Bottle","marketplaceIds":["A1PA6795UKMFR9"]}]}'),
                str_contains($url, '/definitions/2020-09-01/productTypes/WATER_BOTTLE?') => new MockResponse('{"requirements":"LISTING","requirementsEnforced":"ENFORCED","propertyGroups":{"core":{"title":"Core","propertyNames":["condition_type","item_name","brand","bullet_point","product_description","generic_keyword","color","purchasable_offer","fulfillment_availability","main_product_image_locator"]}},"schema":{"link":{"resource":"https://schemas.example/WATER_BOTTLE","checksum":"abc"}},"metaSchema":{"link":{"resource":"https://schemas.example/meta","checksum":"meta"}}}'),
                $url === 'https://schemas.example/WATER_BOTTLE' => new MockResponse('{"properties":{"attributes":{"properties":{"condition_type":{},"item_name":{},"brand":{},"bullet_point":{},"product_description":{},"generic_keyword":{},"color":{},"purchasable_offer":{},"fulfillment_availability":{},"main_product_image_locator":{}},"required":["condition_type","item_name","brand","purchasable_offer","fulfillment_availability"]}}}'),
                str_contains($url, 'mode=VALIDATION_PREVIEW') => new MockResponse('{"sku":"CTC-01","status":"VALID","submissionId":"preview-123","identifiers":[{"marketplaceId":"A1PA6795UKMFR9","asin":"B07TEST123"}],"issues":[]}'),
                str_contains($url, '/listings/2021-08-01/items/A1SELLER123/CTC-') => new MockResponse('{"sku":"CTC-01","status":"ACCEPTED","submissionId":"live-456","issues":[]}'),
                default => new MockResponse('{"message":"Unerwarteter Test-Endpunkt"}', ['http_code' => 500]),
            };
        });

        $connector = new AmazonSpApiConnector(
            'eu',
            'A1SELLER123',
            'A1PA6795UKMFR9',
            'amzn-app-id',
            new AmazonProductTypeMapper(),
            new AmazonListingsItemPayloadBuilder(),
            new ListingDataTranslator(),
            'secret-value',
            'refresh-token',
            true,
            $httpClient,
        );

        $product = new Product('Testprodukt');
        $product->addVariant(
            (new ProductVariant('SKU-1'))
                ->setPriceGross('29.90')
                ->setCurrency('EUR')
                ->setStock(12),
        );
        $this->addImageAsset($product, 201, 1, 'front.jpg');

        $result = $connector->publish($product, $this->draft());

        self::assertSame(SyncStatus::Succeeded, $result->status);
        self::assertTrue($result->payload['live_sync']);
        self::assertSame('live-456', $result->payload['live_submit']['submission_id']);
        self::assertSame('B07TEST123', $result->externalId);
        self::assertStringContainsString('Live-Submit erfolgreich', $result->message);
        self::assertCount(7, $requests);
        self::assertSame('PUT', $requests[6]['method']);
        self::assertStringNotContainsString('mode=VALIDATION_PREVIEW', $requests[6]['url']);
        self::assertStringContainsString('includedData=issues', $requests[6]['url']);
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
            ['Doppelwandig und auslaufsicher'],
            'Robuste Trinkflasche für Alltag, Sport und Reisen.',
            [
                'product_type' => 'Trinkflasche',
                'brand' => 'North Trail',
                'category_path' => 'Outdoor/Trinkflaschen',
                'language' => 'de',
                'color' => 'Schwarz',
                'material' => 'Edelstahl',
            ],
            ['Trinkflasche', 'Edelstahl', 'Outdoor'],
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
