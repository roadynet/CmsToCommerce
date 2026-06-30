<?php

declare(strict_types=1);

namespace App\Tests\Integration\SapR3;

use App\Entity\Product;
use App\Entity\ProductSource;
use App\Enum\SourceType;
use App\Enum\SyncStatus;
use App\Integration\SapR3\SapR3GatewayConnector;
use App\Service\Export\ListingDataTranslator;
use App\Service\Import\ProductTextNormalizer;
use App\Service\Integration\SapR3WritebackPreviewBuilder;
use App\Service\Listing\ProductListingDraftBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class SapR3GatewayConnectorTest extends TestCase
{
    public function testIsConfiguredReadsExternalSecretsFile(): void
    {
        $secretsFile = tempnam(sys_get_temp_dir(), 'ctc-sap-secrets-');
        if ($secretsFile === false) {
            self::fail('Temporäre Secrets-Datei konnte nicht erstellt werden.');
        }

        file_put_contents($secretsFile, implode("\n", [
            'SAP_R3_GATEWAY_URL=https://sap-gateway.example',
            'SAP_R3_WRITEBACK_PATH=/ctc/material/writeback',
            'SAP_R3_CLIENT=100',
            'SAP_R3_USERNAME=ctc-api',
            'SAP_R3_PASSWORD=secret',
            'SAP_R3_SYSTEM_ID=PRD',
            'SAP_R3_LANGUAGE=DE',
            'SAP_R3_ENABLE_LIVE_WRITEBACK=1',
        ]));

        $connector = new SapR3GatewayConnector(
            $this->previewBuilder(),
            appExternalSecretsFile: $secretsFile,
        );

        self::assertTrue($connector->isConfigured());

        $payload = $connector->buildPayload(new Product('Testprodukt'));
        self::assertSame('https://sap-gateway.example', $payload['basis_url']);
        self::assertSame('https://sap-gateway.example/ctc/material/writeback', $payload['writeback_endpoint']);
        self::assertSame('100', $payload['sap_mandant']);
        self::assertTrue($payload['live_writeback_aktiv']);
        self::assertSame([], $payload['konfigurationsluecken']);
    }

    public function testPublishPostsPreparedIdocPayloadToGatewayProxy(): void
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
                $url === 'https://sap-gateway.example/ctc/material/writeback' => new MockResponse('{"material_number":"000000000000471100","status":"accepted"}'),
                default => new MockResponse('{"message":"Unerwarteter Test-Endpunkt"}', ['http_code' => 500]),
            };
        });

        $product = (new Product('Edelstahl Trinkflasche 750 ml'))
            ->setBrand('North Trail')
            ->setDescription('Robuste Trinkflasche für Alltag, Sport und Reisen.');
        $product->addSource(
            (new ProductSource(SourceType::CmsImport, '{"MARA":{"MATNR":"000000000000471100"}}'))
                ->setCmsSystem('sap_r3')
                ->setExternalReference('000000000000471100')
                ->setLanguageCode('de')
        );

        $connector = new SapR3GatewayConnector(
            $this->previewBuilder(),
            'https://sap-gateway.example',
            '/ctc/material/writeback',
            '100',
            'ctc-api',
            'secret',
            'PRD',
            'DE',
            true,
            $httpClient,
        );

        $result = $connector->publish($product);

        self::assertSame(SyncStatus::Succeeded, $result->status);
        self::assertSame('000000000000471100', $result->externalId);
        self::assertSame('POST', $result->payload['api_methode']);
        self::assertSame('/ctc/material/writeback', $result->payload['api_pfad']);

        self::assertSame('POST', $requests[0]['method']);
        self::assertSame('https://sap-gateway.example/ctc/material/writeback', $requests[0]['url']);

        $headers = implode("\n", array_map(static fn (mixed $header): string => (string) $header, $requests[0]['headers']));
        self::assertStringContainsString('Basic '.base64_encode('ctc-api:secret'), $headers);
        self::assertStringContainsString('X-SAP-Client: 100', $headers);

        $body = json_decode($requests[0]['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('sap_r3', $body['target_system']);
        self::assertSame('000000000000471100', $body['material_number']);
        self::assertSame('MATMAS05', $body['idoc']['basic_type']);
        self::assertArrayHasKey('ctc_listing', $body);
    }

    private function previewBuilder(): SapR3WritebackPreviewBuilder
    {
        return new SapR3WritebackPreviewBuilder(
            new ProductListingDraftBuilder(new ProductTextNormalizer()),
            new ListingDataTranslator(),
        );
    }
}
