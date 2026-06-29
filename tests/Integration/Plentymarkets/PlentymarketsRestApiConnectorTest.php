<?php

declare(strict_types=1);

namespace App\Tests\Integration\Plentymarkets;

use App\Entity\Product;
use App\Entity\ProductSource;
use App\Entity\ProductVariant;
use App\Enum\SourceType;
use App\Enum\SyncStatus;
use App\Integration\Plentymarkets\PlentymarketsRestApiConnector;
use App\Service\Import\ProductTextNormalizer;
use App\Service\Listing\ProductListingDraftBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class PlentymarketsRestApiConnectorTest extends TestCase
{
    public function testIsConfiguredReadsExternalSecretsFile(): void
    {
        $secretsFile = tempnam(sys_get_temp_dir(), 'ctc-plenty-secrets-');
        if ($secretsFile === false) {
            self::fail('Temporare Secrets-Datei konnte nicht erstellt werden.');
        }

        file_put_contents($secretsFile, implode("\n", [
            'PLENTY_BASE_URL=https://plenty.example/rest',
            'PLENTY_USERNAME=api-user',
            'PLENTY_PASSWORD=secret',
            'PLENTY_DEFAULT_LANG=de',
            'PLENTY_ENABLE_LIVE_WRITEBACK=1',
        ]));

        $connector = new PlentymarketsRestApiConnector(
            new ProductListingDraftBuilder(new ProductTextNormalizer()),
            appExternalSecretsFile: $secretsFile,
        );

        self::assertTrue($connector->isConfigured());

        $payload = $connector->buildPayload(new Product('Testprodukt'));
        self::assertSame('https://plenty.example', $payload['basis_url']);
        self::assertSame('https://plenty.example/rest/login', $payload['login_endpoint']);
        self::assertTrue($payload['live_writeback_aktiv']);
        self::assertSame([], $payload['konfigurationsluecken']);
    }

    public function testPublishUsesDirectItemAndVariationReferenceWhenAvailable(): void
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
                $url === 'https://plenty.example/rest/login' => new MockResponse('{"access_token":"plenty-token"}'),
                $url === 'https://plenty.example/rest/items/154332/variations/1136/descriptions/de' => new MockResponse('{"id":991,"variationId":1136,"lang":"de"}'),
                default => new MockResponse('{"message":"Unerwarteter Test-Endpunkt"}', ['http_code' => 500]),
            };
        });

        $product = (new Product('Edelstahl Trinkflasche 750 ml'))
            ->setBrand('North Trail')
            ->setDescription('Robuste Trinkflasche fur Alltag, Sport und Reisen.');
        $product->addSource(
            (new ProductSource(SourceType::CmsImport, '{"item":{"id":154332},"variation":{"id":1136}}'))
                ->setCmsSystem('plentymarkets')
                ->setExternalReference('154332:1136')
                ->setLanguageCode('de')
        );

        $connector = $this->connector($httpClient);
        $result = $connector->publish($product);

        self::assertSame(SyncStatus::Succeeded, $result->status);
        self::assertSame('1136', $result->externalId);
        self::assertSame('direkte_plenty_referenz', $result->payload['zielartikel_aufloesung']['strategie']);
        self::assertSame('PUT', $result->payload['api_methode']);
        self::assertSame('/rest/items/154332/variations/1136/descriptions/de', $result->payload['api_pfad']);

        self::assertSame('POST', $requests[0]['method']);
        self::assertSame('https://plenty.example/rest/login', $requests[0]['url']);
        self::assertStringContainsString('"username":"api-user"', $requests[0]['body']);
        self::assertStringContainsString('"password":"secret"', $requests[0]['body']);

        self::assertSame('PUT', $requests[1]['method']);
        self::assertContains('Authorization: Bearer plenty-token', $requests[1]['headers']);
        $putBody = json_decode($requests[1]['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('de', $putBody['lang']);
        self::assertSame('North Trail Edelstahl Trinkflasche 750 ml', $putBody['name']);
        self::assertSame('North Trail Edelstahl Trinkflasche 750 ml', $putBody['title']);
        self::assertStringContainsString('Robuste Trinkflasche fur Alltag, Sport und Reisen.', $putBody['description']);
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
                $url === 'https://plenty.example/rest/login' => new MockResponse('{"access_token":"plenty-token"}'),
                str_starts_with($url, 'https://plenty.example/rest/items/variations?') => new MockResponse('{"entries":[{"id":1136,"itemId":154332,"number":"NT-750-BLK","variationBarcodes":[{"code":"4259001100011"}]}]}'),
                $url === 'https://plenty.example/rest/items/154332/variations/1136/descriptions/de' => new MockResponse('{"id":991,"variationId":1136,"lang":"de"}'),
                default => new MockResponse('{"message":"Unerwarteter Test-Endpunkt"}', ['http_code' => 500]),
            };
        });

        $product = (new Product('Edelstahl Trinkflasche 750 ml'))
            ->setBrand('North Trail')
            ->setDescription('Robuste Trinkflasche fur Alltag, Sport und Reisen.');
        $product->addVariant(
            (new ProductVariant('NT-750-BLK'))
                ->setEan('4259001100011')
                ->setPriceGross('29.90')
                ->setStock(12)
        );

        $connector = $this->connector($httpClient);
        $result = $connector->publish($product);

        self::assertSame(SyncStatus::Succeeded, $result->status);
        self::assertSame('1136', $result->externalId);
        self::assertSame('varianten_suche', $result->payload['zielartikel_aufloesung']['strategie']);
        self::assertSame('154332', $result->payload['zielartikel_aufloesung']['item_id']);
        self::assertSame('1136', $result->payload['zielartikel_aufloesung']['variation_id']);

        self::assertSame('GET', $requests[1]['method']);
        self::assertStringContainsString('/rest/items/variations?', $requests[1]['url']);
        self::assertStringContainsString('number=NT-750-BLK', $requests[1]['url']);
        self::assertStringContainsString('with=variationBarcodes', $requests[1]['url']);

        self::assertSame('PUT', $requests[2]['method']);
        self::assertSame('https://plenty.example/rest/items/154332/variations/1136/descriptions/de', $requests[2]['url']);
    }

    private function connector(MockHttpClient $httpClient): PlentymarketsRestApiConnector
    {
        return new PlentymarketsRestApiConnector(
            new ProductListingDraftBuilder(new ProductTextNormalizer()),
            'https://plenty.example/rest',
            'api-user',
            'secret',
            'de',
            true,
            $httpClient,
        );
    }
}
