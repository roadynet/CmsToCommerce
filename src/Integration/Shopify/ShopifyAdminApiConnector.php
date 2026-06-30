<?php

declare(strict_types=1);

namespace App\Integration\Shopify;

use App\Dto\ExternalWritebackResult;
use App\Entity\Product;
use App\Entity\ProductSource;
use App\Entity\ProductVariant;
use App\Enum\ExternalSystemType;
use App\Enum\SyncStatus;
use App\Service\Configuration\ServerSecretResolver;
use App\Service\Integration\ExternalSystemWritebackPublisher;
use App\Service\Integration\ShopifyWritebackPreviewBuilder;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

final class ShopifyAdminApiConnector implements ExternalSystemWritebackPublisher
{
    private const DEFAULT_SECRETS_FILE = 'ctc-shopify.env';
    private const FALLBACK_MIXED_SECRETS_FILE = 'ctc.env';

    public function __construct(
        private readonly ShopifyWritebackPreviewBuilder $shopifyWritebackPreviewBuilder,
        private readonly string $shopifyShopDomain = '',
        private readonly string $shopifyAdminAccessToken = '',
        private readonly string $shopifyAdminApiVersion = '2026-04',
        private readonly bool $shopifyEnableLiveWriteback = false,
        private readonly ?HttpClientInterface $httpClient = null,
        private readonly string $appExternalSecretsFile = '',
    ) {
    }

    public function system(): ExternalSystemType
    {
        return ExternalSystemType::Shopify;
    }

    public function isConfigured(): bool
    {
        return $this->missingConfigFields($this->readConfig()) === [];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildPayload(Product $product): array
    {
        $config = $this->readConfig();
        $missing = $this->missingConfigFields($config);
        $preview = $this->shopifyWritebackPreviewBuilder->build($product);
        $target = $this->targetProductHints($product);

        return [
            'shop_domain' => $config['shopDomain'],
            'admin_graphql_endpoint' => $this->graphqlEndpoint($config),
            'admin_api_version' => $config['apiVersion'],
            'live_writeback_aktiv' => $config['liveWritebackEnabled'],
            'schnittstelle_bereit' => $missing === [],
            'konfigurationsluecken' => $missing,
            'zielprodukt_hinweise' => $target,
            'request_payload' => [
                'query' => $preview['payload']['graphql']['query'],
                'variables' => [
                    'input' => [
                        ...$preview['payload']['graphql']['variables']['input'],
                        'id' => $target['direkte_shopify_gid'] ?: $preview['payload']['graphql']['variables']['input']['id'],
                    ],
                ],
            ],
        ];
    }

    public function publish(Product $product): ExternalWritebackResult
    {
        $payloadPreview = $this->buildPayload($product);

        if (!$this->isConfigured()) {
            return new ExternalWritebackResult(
                ExternalSystemType::Shopify,
                SyncStatus::Failed,
                'Shopify-Write-back ist noch nicht vollständig konfiguriert. CTC hat das Admin-GraphQL-Payload vorbereitet, aber Live-Senden bleibt gesperrt.',
                $payloadPreview,
            );
        }

        if (!$this->readConfig()['liveWritebackEnabled']) {
            return new ExternalWritebackResult(
                ExternalSystemType::Shopify,
                SyncStatus::Failed,
                'Shopify-Live-Write-back ist aktuell deaktiviert. Setze SHOPIFY_ENABLE_LIVE_WRITEBACK=1, wenn der echte Shopify-Shop schreiben darf.',
                $payloadPreview,
            );
        }

        $productId = trim((string) ($payloadPreview['request_payload']['variables']['input']['id'] ?? ''));
        if ($productId === '' || $productId === 'gid://shopify/Product/wird_vor_live_writeback_aufgeloest') {
            return new ExternalWritebackResult(
                ExternalSystemType::Shopify,
                SyncStatus::Failed,
                'CTC konnte kein eindeutiges Shopify-Produktziel bestimmen. Bitte Shopify Product GID oder numerische Produkt-ID als externe Referenz hinterlegen.',
                $payloadPreview,
            );
        }

        try {
            $config = $this->readConfig(true);
            $responsePayload = $this->requestJson('POST', $this->graphqlEndpoint($config), [
                'headers' => $this->authorizedHeaders($config),
                'json' => $payloadPreview['request_payload'],
            ]);
            $errors = $this->extractUserErrors($responsePayload);
            if ($errors !== []) {
                return new ExternalWritebackResult(
                    ExternalSystemType::Shopify,
                    SyncStatus::Failed,
                    'Shopify meldet Validierungsfehler: '.implode(' | ', $errors),
                    [
                        ...$payloadPreview,
                        'api_methode' => 'POST',
                        'api_pfad' => '/admin/api/'.$config['apiVersion'].'/graphql.json',
                        'response_payload' => $responsePayload,
                    ],
                    $productId,
                );
            }

            $externalId = $this->stringValue($responsePayload['data']['productUpdate']['product']['id'] ?? null, $productId);

            return new ExternalWritebackResult(
                ExternalSystemType::Shopify,
                SyncStatus::Succeeded,
                sprintf('Shopify-Produkt %s wurde mit den optimierten CTC-Texten aktualisiert.', $externalId ?: $productId),
                [
                    ...$payloadPreview,
                    'api_methode' => 'POST',
                    'api_pfad' => '/admin/api/'.$config['apiVersion'].'/graphql.json',
                    'response_payload' => $responsePayload,
                ],
                $externalId,
            );
        } catch (Throwable $exception) {
            return new ExternalWritebackResult(
                ExternalSystemType::Shopify,
                SyncStatus::Failed,
                'Shopify-Write-back fehlgeschlagen: '.$exception->getMessage(),
                $payloadPreview,
            );
        }
    }

    /**
     * @return array{
     *     direkte_shopify_referenz: ?string,
     *     direkte_shopify_gid: ?string,
     *     suchkandidaten: list<array{typ: string, wert: string}>
     * }
     */
    private function targetProductHints(Product $product): array
    {
        $directReference = $this->directShopifyReference($product);
        $candidates = [];

        foreach ($product->getVariants() as $variant) {
            if (!$variant instanceof ProductVariant) {
                continue;
            }

            $sku = trim($variant->getSku());
            if ($sku !== '') {
                $candidates[] = ['typ' => 'sku', 'wert' => $sku];
            }

            $ean = trim((string) $variant->getEan());
            if ($ean !== '') {
                $candidates[] = ['typ' => 'barcode', 'wert' => $ean];
            }
        }

        return [
            'direkte_shopify_referenz' => $directReference,
            'direkte_shopify_gid' => $this->graphqlProductId($directReference),
            'suchkandidaten' => $this->uniqueCandidates($candidates),
        ];
    }

    private function directShopifyReference(Product $product): ?string
    {
        foreach ($product->getSources() as $source) {
            if (!$source instanceof ProductSource) {
                continue;
            }

            $cmsSystem = strtolower(trim((string) $source->getCmsSystem()));
            if (!in_array($cmsSystem, ['shopify', 'shopify-admin'], true)) {
                continue;
            }

            $reference = trim((string) $source->getExternalReference());
            if ($reference !== '') {
                return $reference;
            }
        }

        return null;
    }

    /**
     * @return array{
     *     shopDomain: string,
     *     accessToken: string,
     *     apiVersion: string,
     *     liveWritebackEnabled: bool
     * }
     */
    private function readConfig(bool $strict = false): array
    {
        $external = $this->readRuntimeSecrets($strict);

        return [
            'shopDomain' => $this->normalizeShopDomain($this->firstNonEmpty(
                $external['SHOPIFY_SHOP_DOMAIN'] ?? null,
                $external['SHOPIFY_ADMIN_BASE_URL'] ?? null,
                $this->shopifyShopDomain,
            )),
            'accessToken' => $this->firstNonEmpty(
                $external['SHOPIFY_ADMIN_ACCESS_TOKEN'] ?? null,
                $external['SHOPIFY_ACCESS_TOKEN'] ?? null,
                $this->shopifyAdminAccessToken,
            ),
            'apiVersion' => $this->firstNonEmpty(
                $external['SHOPIFY_ADMIN_API_VERSION'] ?? null,
                $this->shopifyAdminApiVersion,
                '2026-04',
            ),
            'liveWritebackEnabled' => $this->resolveBoolean(
                $external['SHOPIFY_ENABLE_LIVE_WRITEBACK'] ?? null,
                $this->shopifyEnableLiveWriteback,
            ),
        ];
    }

    /**
     * @param array{shopDomain: string, accessToken: string, apiVersion: string, liveWritebackEnabled: bool} $config
     *
     * @return list<string>
     */
    private function missingConfigFields(array $config): array
    {
        $missing = [];

        if ($config['shopDomain'] === '') {
            $missing[] = 'SHOPIFY_SHOP_DOMAIN';
        }

        if ($config['accessToken'] === '') {
            $missing[] = 'SHOPIFY_ADMIN_ACCESS_TOKEN';
        }

        return $missing;
    }

    /**
     * @return array<string, string>
     */
    private function readRuntimeSecrets(bool $strict = false): array
    {
        return ServerSecretResolver::resolve(
            $this->appExternalSecretsFile,
            [
                self::DEFAULT_SECRETS_FILE,
                self::FALLBACK_MIXED_SECRETS_FILE,
                'ctc-shopware.env',
            ],
            $strict,
            'Shopify',
        );
    }

    /**
     * @param array{shopDomain: string, apiVersion: string} $config
     */
    private function graphqlEndpoint(array $config): string
    {
        return sprintf('https://%s/admin/api/%s/graphql.json', $config['shopDomain'], $config['apiVersion']);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function requestJson(string $method, string $url, array $options): array
    {
        $response = ($this->httpClient ?? HttpClient::create())->request($method, $url, $options);
        $statusCode = $response->getStatusCode();
        $content = $response->getContent(false);
        $decoded = $content !== '' ? json_decode($content, true) : [];

        if ($statusCode >= 400) {
            throw new \RuntimeException($this->extractApiErrorMessage(
                is_array($decoded) ? $decoded : [],
                sprintf('HTTP %d bei %s %s.', $statusCode, $method, $url),
            ));
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array{accessToken: string} $config
     *
     * @return array<string, string>
     */
    private function authorizedHeaders(array $config): array
    {
        return [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'X-Shopify-Access-Token' => $config['accessToken'],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<string>
     */
    private function extractUserErrors(array $payload): array
    {
        $errors = $payload['data']['productUpdate']['userErrors'] ?? [];
        if (!is_array($errors)) {
            return [];
        }

        $messages = [];
        foreach ($errors as $error) {
            if (!is_array($error)) {
                continue;
            }

            $message = trim((string) ($error['message'] ?? ''));
            if ($message !== '') {
                $messages[] = $message;
            }
        }

        return $messages;
    }

    /**
     * @param list<array{typ: string, wert: string}> $candidates
     *
     * @return list<array{typ: string, wert: string}>
     */
    private function uniqueCandidates(array $candidates): array
    {
        $seen = [];
        $unique = [];

        foreach ($candidates as $candidate) {
            $key = $candidate['typ'].':'.$candidate['wert'];
            if ($candidate['wert'] === '' || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $unique[] = $candidate;
        }

        return $unique;
    }

    private function normalizeShopDomain(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $host = parse_url($value, PHP_URL_HOST);
        if (is_string($host) && $host !== '') {
            return strtolower($host);
        }

        return strtolower(trim($value, '/'));
    }

    private function graphqlProductId(?string $reference): ?string
    {
        $reference = trim((string) $reference);
        if ($reference === '') {
            return null;
        }

        if (str_starts_with($reference, 'gid://shopify/Product/')) {
            return $reference;
        }

        if (ctype_digit($reference)) {
            return 'gid://shopify/Product/'.$reference;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function extractApiErrorMessage(array $payload, string $fallback): string
    {
        $message = trim((string) ($payload['errors'][0]['message'] ?? $payload['message'] ?? $payload['error_description'] ?? $payload['error'] ?? ''));
        if ($message !== '') {
            return $message;
        }

        return $fallback;
    }

    private function firstNonEmpty(?string ...$values): string
    {
        foreach ($values as $value) {
            $value = trim((string) $value);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function resolveBoolean(?string $value, bool $fallback): bool
    {
        $value = strtolower(trim((string) $value));
        if ($value === '') {
            return $fallback;
        }

        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }

    private function stringValue(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            if (is_array($value)) {
                continue;
            }

            $value = trim((string) $value);
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }
}
