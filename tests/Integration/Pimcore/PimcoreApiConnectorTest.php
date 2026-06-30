<?php

declare(strict_types=1);

namespace App\Tests\Integration\Pimcore;

use App\Entity\Product;
use App\Entity\ProductSource;
use App\Enum\SourceType;
use App\Enum\SyncStatus;
use App\Integration\Pimcore\PimcoreApiConnector;
use App\Service\Export\ListingDataTranslator;
use App\Service\Import\ProductTextNormalizer;
use App\Service\Integration\PimcoreWritebackPreviewBuilder;
use App\Service\Listing\ProductListingDraftBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class PimcoreApiConnectorTest extends TestCase
{
    public function testIsConfiguredReadsExternalSecretsFile(): void
    {
        $secretsFile = tempnam(sys_get_temp_dir(), 'ctc-pimcore-secrets-');
        if ($secretsFile === false) {
            self::fail('Temporäre Secrets-Datei konnte nicht erstellt werden.');
        }

        file_put_contents($secretsFile, implode("\n", [
            'PIMCORE_BASE_URL=https://pimcore.example',
            'PIMCORE_API_TOKEN=dummy-token',
            'PIMCORE_WRITEBACK_PATH=/ctc/object/writeback',
            'PIMCORE_DEFAULT_LANGUAGE=de',
            'PIMCORE_ENABLE_LIVE_WRITEBACK=1',
        ]));

        $connector = new PimcoreApiConnector(
            $this->previewBuilder(),
            appExternalSecretsFile: $secretsFile,
        );

        self::assertTrue($connector->isConfigured());

        $payload = $connector->buildPayload(new Product('Testprodukt'));
        self::assertSame('https://pimcore.example', $payload['basis_url']);
        self::assertSame('https://pimcore.example/ctc/object/writeback', $payload['writeback_endpoint']);
        self::assertTrue($payload['live_writeback_aktiv']);
        self::assertSame([], $payload['konfigurationsluecken']);
    }

    public function testPublishPostsPreparedObjectPayload(): void
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
                $url === 'https://pimcore.example/ctc/object/writeback' => new MockResponse('{"object_id":"471100","status":"accepted"}'),
                default => new MockResponse('{"message":"Unerwarteter Test-Endpunkt"}', ['http_code' => 500]),
            };
        });

        $product = (new Product('Edelstahl Trinkflasche 750 ml'))
            ->setBrand('North Trail')
            ->setDescription('Robuste Trinkflasche für Alltag, Sport und Reisen.');
        $product->addSource(
            (new ProductSource(SourceType::CmsImport, '{"object":{"id":471100,"key":"edelstahl-trinkflasche","className":"Product"}}'))
                ->setCmsSystem('pimcore')
                ->setExternalReference('471100')
                ->setLanguageCode('de')
        );

        $connector = new PimcoreApiConnector(
            $this->previewBuilder(),
            'https://pimcore.example',
            'dummy-token',
            '/ctc/object/writeback',
            'de',
            true,
            $httpClient,
        );

        $result = $connector->publish($product);

        self::assertSame(SyncStatus::Succeeded, $result->status);
        self::assertSame('471100', $result->externalId);
        self::assertSame('POST', $result->payload['api_methode']);
        self::assertSame('/ctc/object/writeback', $result->payload['api_pfad']);

        self::assertSame('POST', $requests[0]['method']);
        self::assertSame('https://pimcore.example/ctc/object/writeback', $requests[0]['url']);
        self::assertContains('Authorization: Bearer dummy-token', $requests[0]['headers']);

        $body = json_decode($requests[0]['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('pimcore', $body['target_system']);
        self::assertSame('471100', $body['object']['id']);
        self::assertSame('Product', $body['object']['className']);
        self::assertArrayHasKey('ctcOptimizedTitle', $body['data']);
        self::assertArrayHasKey('ctc_listing', $body);
    }

    private function previewBuilder(): PimcoreWritebackPreviewBuilder
    {
        return new PimcoreWritebackPreviewBuilder(
            new ProductListingDraftBuilder(new ProductTextNormalizer()),
            new ListingDataTranslator(),
        );
    }
}
