<?php

declare(strict_types=1);

namespace App\Integration\Jtl;

use App\Dto\ExternalWritebackResult;
use App\Entity\Product;
use App\Entity\ProductSource;
use App\Entity\ProductVariant;
use App\Enum\ChannelType;
use App\Enum\ExternalSystemType;
use App\Enum\SyncStatus;
use App\Service\Configuration\ServerSecretResolver;
use App\Service\Integration\ExternalSystemWritebackPublisher;
use App\Service\Listing\ProductListingDraftBuilder;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

final class JtlErpApiConnector implements ExternalSystemWritebackPublisher
{
    private const DEFAULT_SECRETS_FILE = 'ctc-jtl.env';
    private const FALLBACK_MIXED_SECRETS_FILE = 'ctc.env';
    private const DEFAULT_API_BASE_URL = 'https://api.jtl-cloud.com/erp';
    private const DEFAULT_AUTH_BASE_URL = 'https://auth.jtl-cloud.com';

    public function __construct(
        private readonly ProductListingDraftBuilder $productListingDraftBuilder,
        private readonly string $jtlApiBaseUrl = '',
        private readonly string $jtlAuthBaseUrl = '',
        private readonly string $jtlTenantId = '',
        private readonly string $jtlClientId = '',
        private readonly string $jtlClientSecret = '',
        private readonly bool $jtlEnableLiveWriteback = false,
        private readonly ?HttpClientInterface $httpClient = null,
        private readonly string $appExternalSecretsFile = '',
        private readonly string $jtlRunAs = '',
        private readonly string $jtlCompanyId = '',
    ) {
    }

    public function system(): ExternalSystemType
    {
        return ExternalSystemType::Jtl;
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
        $draft = $this->productListingDraftBuilder->build($product, ChannelType::Amazon);
        $config = $this->readConfig();
        $missing = $this->missingConfigFields($config);
        $targetHints = $this->targetItemHints($product);

        return [
            'basis_url' => $config['apiBaseUrl'],
            'oauth_endpoint' => $this->buildUrl($config['authBaseUrl'], '/oauth2/token'),
            'artikel_suche_endpoint' => $this->buildUrl($config['apiBaseUrl'], '/v2/items'),
            'artikel_aendern_endpoint' => $this->buildUrl($config['apiBaseUrl'], '/v2/itemdetails/change'),
            'tenant_id' => $config['tenantId'],
            'runas' => $config['runAs'] !== '' ? $config['runAs'] : null,
            'company_id' => $config['companyId'] !== '' ? $config['companyId'] : null,
            'live_writeback_aktiv' => $config['liveWritebackEnabled'],
            'schnittstelle_bereit' => $missing === [],
            'konfigurationsluecken' => $missing,
            'zielartikel_hinweise' => $targetHints,
            'request_payload' => [
                'itemId' => $targetHints['direkte_jtl_referenz'] ?: 'wird vor Live-Send aufgelöst',
                'descriptions' => [
                    'defaultDescriptions' => [[
                        'languageIso' => $this->resolveLanguageIso($product),
                        'descriptionData' => [
                            'itemName' => $draft->title,
                            'shortDescription' => $this->shortText($draft->bulletPoints),
                            'description' => $draft->description,
                            'metaDescription' => $this->buildMetaDescription($draft->description, $draft->bulletPoints),
                            'metaKeywords' => implode(', ', $draft->searchTerms),
                            'titleTag' => $draft->title,
                        ],
                    ]],
                ],
            ],
        ];
    }

    public function publish(Product $product): ExternalWritebackResult
    {
        $payloadPreview = $this->buildPayload($product);

        if (!$this->isConfigured()) {
            return new ExternalWritebackResult(
                ExternalSystemType::Jtl,
                SyncStatus::Failed,
                'JTL-Write-back ist noch nicht vollständig konfiguriert. CTC hat das Ziel-Payload vorbereitet, aber Live-Senden bleibt gesperrt.',
                $payloadPreview,
            );
        }

        if (!$this->readConfig()['liveWritebackEnabled']) {
            return new ExternalWritebackResult(
                ExternalSystemType::Jtl,
                SyncStatus::Failed,
                'JTL-Live-Write-back ist aktuell deaktiviert. Setze JTL_ENABLE_LIVE_WRITEBACK=1, wenn du den echten Rückschreibmodus aktivieren willst.',
                $payloadPreview,
            );
        }

        try {
            $config = $this->readConfig(true);
            $accessToken = $this->fetchAccessToken($config);
            $target = $this->resolveTargetItem($product, $accessToken, $config);

            if (($target['item_id'] ?? null) === null) {
                return new ExternalWritebackResult(
                    ExternalSystemType::Jtl,
                    SyncStatus::Failed,
                    'CTC konnte keinen eindeutigen JTL-Zielartikel finden. Bitte JTL-Artikel-ID als externe Referenz hinterlegen oder SKU/EAN im Produkt prüfen.',
                    [
                        ...$payloadPreview,
                        'zielartikel_aufloesung' => $target,
                    ],
                );
            }

            $requestPayload = $payloadPreview['request_payload'];
            $requestPayload['itemId'] = $target['item_id'];

            $responsePayload = $this->requestJson('PATCH', $config['apiBaseUrl'], '/v2/itemdetails/change', [
                'headers' => array_merge(
                    $this->authorizedHeaders($accessToken, $config),
                    ['Content-Type' => 'application/json']
                ),
                'json' => $requestPayload,
            ]);

            return new ExternalWritebackResult(
                ExternalSystemType::Jtl,
                SyncStatus::Succeeded,
                sprintf('JTL-Artikel %s wurde mit den optimierten CTC-Texten aktualisiert.', $target['item_id']),
                [
                    ...$payloadPreview,
                    'zielartikel_aufloesung' => $target,
                    'api_methode' => 'PATCH',
                    'api_pfad' => '/v2/itemdetails/change',
                    'request_payload' => $requestPayload,
                    'response_payload' => $responsePayload,
                ],
                (string) $target['item_id'],
            );
        } catch (Throwable $exception) {
            return new ExternalWritebackResult(
                ExternalSystemType::Jtl,
                SyncStatus::Failed,
                'JTL-Write-back fehlgeschlagen: '.$exception->getMessage(),
                $payloadPreview,
            );
        }
    }

    /**
     * @param array{
     *     apiBaseUrl: string,
     *     authBaseUrl: string,
     *     tenantId: string,
     *     clientId: string,
     *     clientSecret: string,
     *     liveWritebackEnabled: bool,
     *     runAs: string,
     *     companyId: string
     * } $config
     */
    private function fetchAccessToken(array $config): string
    {
        $response = $this->requestAbsoluteJson('POST', $this->buildUrl($config['authBaseUrl'], '/oauth2/token'), [
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'body' => http_build_query([
                'grant_type' => 'client_credentials',
                'client_id' => $config['clientId'],
                'client_secret' => $config['clientSecret'],
                'scope' => 'items.read items.write',
            ], '', '&', PHP_QUERY_RFC3986),
        ]);

        $accessToken = trim((string) ($response['access_token'] ?? ''));
        if ($accessToken === '') {
            throw new \RuntimeException('JTL OAuth-Token konnte nicht gelesen werden.');
        }

        return $accessToken;
    }

    /**
     * @param array{
     *     apiBaseUrl: string,
     *     authBaseUrl: string,
     *     tenantId: string,
     *     clientId: string,
     *     clientSecret: string,
     *     liveWritebackEnabled: bool,
     *     runAs: string,
     *     companyId: string
     * } $config
     *
     * @return array{
     *     item_id: ?string,
     *     strategie: string,
     *     direkte_jtl_referenz: ?string,
     *     suchversuche: list<array<string, mixed>>
     * }
     */
    private function resolveTargetItem(Product $product, string $accessToken, array $config): array
    {
        $directReference = $this->directJtlReference($product);
        if ($directReference !== null) {
            return [
                'item_id' => $directReference,
                'strategie' => 'direkte_jtl_referenz',
                'direkte_jtl_referenz' => $directReference,
                'suchversuche' => [],
            ];
        }

        $attempts = [];
        foreach ($this->lookupCandidates($product) as $candidate) {
            $itemsResponse = $this->requestJson('GET', $config['apiBaseUrl'], '/v2/items', [
                'headers' => $this->authorizedHeaders($accessToken, $config),
                'query' => [
                    'searchKeyWord' => $candidate['wert'],
                    'pageNumber' => 1,
                    'pageSize' => 10,
                ],
            ]);

            $items = array_values(array_filter($itemsResponse['items'] ?? [], 'is_array'));
            $matched = $this->matchItemCandidate($items, $candidate['wert']);
            $attempts[] = [
                'typ' => $candidate['typ'],
                'wert' => $candidate['wert'],
                'treffer' => count($items),
                'gematchte_item_id' => $matched['id'] ?? null,
            ];

            if (is_array($matched) && trim((string) ($matched['id'] ?? '')) !== '') {
                return [
                    'item_id' => (string) $matched['id'],
                    'strategie' => 'items_suche',
                    'direkte_jtl_referenz' => null,
                    'suchversuche' => $attempts,
                ];
            }
        }

        return [
            'item_id' => null,
            'strategie' => 'nicht_gefunden',
            'direkte_jtl_referenz' => null,
            'suchversuche' => $attempts,
        ];
    }

    /**
     * @param list<array<string, mixed>> $items
     *
     * @return array<string, mixed>|null
     */
    private function matchItemCandidate(array $items, string $candidate): ?array
    {
        $candidate = trim($candidate);
        if ($candidate === '') {
            return null;
        }

        $exactMatch = null;

        foreach ($items as $item) {
            $values = array_filter([
                trim((string) ($item['id'] ?? '')),
                trim((string) ($item['sKU'] ?? $item['sku'] ?? '')),
                trim((string) ($item['name'] ?? '')),
                trim((string) ($item['identifiers']['gtin'] ?? '')),
                trim((string) ($item['identifiers']['ownIdentifier'] ?? '')),
            ]);

            if (in_array($candidate, $values, true)) {
                $exactMatch = $item;
                break;
            }
        }

        if ($exactMatch !== null) {
            return $exactMatch;
        }

        if (count($items) === 1) {
            return $items[0];
        }

        return null;
    }

    /**
     * @return list<array{typ: string, wert: string}>
     */
    private function lookupCandidates(Product $product): array
    {
        $candidates = [];
        $seen = [];

        foreach ($product->getVariants() as $variant) {
            if (!$variant instanceof ProductVariant) {
                continue;
            }

            foreach ([
                ['typ' => 'sku', 'wert' => trim($variant->getSku())],
                ['typ' => 'ean', 'wert' => trim((string) $variant->getEan())],
            ] as $candidate) {
                if ($candidate['wert'] === '' || isset($seen[$candidate['wert']])) {
                    continue;
                }

                $seen[$candidate['wert']] = true;
                $candidates[] = $candidate;
            }
        }

        $productName = trim($product->getName());
        if ($productName !== '' && !isset($seen[$productName])) {
            $seen[$productName] = true;
            $candidates[] = ['typ' => 'name', 'wert' => $productName];
        }

        $publicId = trim((string) $product->getPublicId());
        if ($publicId !== '' && !isset($seen[$publicId])) {
            $candidates[] = ['typ' => 'ctc_public_id', 'wert' => $publicId];
        }

        return $candidates;
    }

    /**
     * @return array{
     *     direkte_jtl_referenz: ?string,
     *     suchkandidaten: list<array{typ: string, wert: string}>
     * }
     */
    private function targetItemHints(Product $product): array
    {
        return [
            'direkte_jtl_referenz' => $this->directJtlReference($product),
            'suchkandidaten' => $this->lookupCandidates($product),
        ];
    }

    private function directJtlReference(Product $product): ?string
    {
        foreach ($product->getSources() as $source) {
            if (!$source instanceof ProductSource) {
                continue;
            }

            $cmsSystem = strtolower(trim((string) $source->getCmsSystem()));
            $reference = trim((string) $source->getExternalReference());

            if ($reference !== '' && in_array($cmsSystem, ['jtl', 'jtl-wawi'], true)) {
                return $reference;
            }
        }

        return null;
    }

    private function resolveLanguageIso(Product $product): string
    {
        foreach ($product->getSources() as $source) {
            if (!$source instanceof ProductSource) {
                continue;
            }

            $language = strtoupper(trim($source->getLanguageCode()));
            if ($language !== '') {
                return substr($language, 0, 2);
            }
        }

        return 'DE';
    }

    /**
     * @return array{
     *     apiBaseUrl: string,
     *     authBaseUrl: string,
     *     tenantId: string,
     *     clientId: string,
     *     clientSecret: string,
     *     liveWritebackEnabled: bool,
     *     runAs: string,
     *     companyId: string
     * }
     */
    private function readConfig(bool $strict = false): array
    {
        $external = $this->readRuntimeSecrets($strict);

        return [
            'apiBaseUrl' => rtrim($this->firstNonEmpty(
                $external['JTL_API_BASE_URL'] ?? null,
                $this->jtlApiBaseUrl,
                self::DEFAULT_API_BASE_URL,
            ), '/'),
            'authBaseUrl' => rtrim($this->firstNonEmpty(
                $external['JTL_AUTH_BASE_URL'] ?? null,
                $this->jtlAuthBaseUrl,
                self::DEFAULT_AUTH_BASE_URL,
            ), '/'),
            'tenantId' => $this->firstNonEmpty(
                $external['JTL_TENANT_ID'] ?? null,
                $this->jtlTenantId,
            ),
            'clientId' => $this->firstNonEmpty(
                $external['JTL_CLIENT_ID'] ?? null,
                $this->jtlClientId,
            ),
            'clientSecret' => $this->firstNonEmpty(
                $external['JTL_CLIENT_SECRET'] ?? null,
                $this->jtlClientSecret,
            ),
            'liveWritebackEnabled' => $this->resolveBoolean(
                $external['JTL_ENABLE_LIVE_WRITEBACK'] ?? null,
                $this->jtlEnableLiveWriteback,
            ),
            'runAs' => $this->firstNonEmpty(
                $external['JTL_RUNAS'] ?? null,
                $this->jtlRunAs,
            ),
            'companyId' => $this->firstNonEmpty(
                $external['JTL_COMPANY_ID'] ?? null,
                $this->jtlCompanyId,
            ),
        ];
    }

    /**
     * @param array{
     *     apiBaseUrl: string,
     *     authBaseUrl: string,
     *     tenantId: string,
     *     clientId: string,
     *     clientSecret: string,
     *     liveWritebackEnabled: bool,
     *     runAs: string,
     *     companyId: string
     * } $config
     *
     * @return list<string>
     */
    private function missingConfigFields(array $config): array
    {
        $missing = [];

        if ($config['tenantId'] === '') {
            $missing[] = 'JTL_TENANT_ID';
        }

        if ($config['clientId'] === '') {
            $missing[] = 'JTL_CLIENT_ID';
        }

        if ($config['clientSecret'] === '') {
            $missing[] = 'JTL_CLIENT_SECRET';
        }

        if ($config['apiBaseUrl'] === '') {
            $missing[] = 'JTL_API_BASE_URL';
        }

        if ($config['authBaseUrl'] === '') {
            $missing[] = 'JTL_AUTH_BASE_URL';
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
            'JTL',
        );
    }

    /**
     * @return array<string, string>
     */
    private function readExternalSecrets(bool $strict = false): array
    {
        $path = $this->configuredSecretsPath();
        if ($path === null) {
            return [];
        }

        if (!is_file($path) || !is_readable($path)) {
            if ($strict) {
                throw new \RuntimeException('JTL-Secrets-Datei ist nicht lesbar. Bitte Server-Konfiguration prüfen.');
            }

            return [];
        }

        $values = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$name, $value] = explode('=', $line, 2);
            $name = trim($name);
            $value = trim(trim($value), "\"'");

            if ($name !== '') {
                $values[$name] = $value;
            }
        }

        return $values;
    }

    private function configuredSecretsPath(): ?string
    {
        $configured = trim($this->appExternalSecretsFile);
        if ($configured !== '') {
            return $configured;
        }

        $basePath = dirname(__DIR__, 4).DIRECTORY_SEPARATOR.'private-config'.DIRECTORY_SEPARATOR;
        $candidates = [
            $basePath.self::DEFAULT_SECRETS_FILE,
            $basePath.self::FALLBACK_MIXED_SECRETS_FILE,
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function requestJson(string $method, string $baseUrl, string $path, array $options): array
    {
        return $this->decodeJsonResponse(
            ($this->httpClient ?? HttpClient::create())->request($method, $this->buildUrl($baseUrl, $path), $options),
            $method,
            $path,
        );
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function requestAbsoluteJson(string $method, string $url, array $options): array
    {
        return $this->decodeJsonResponse(
            ($this->httpClient ?? HttpClient::create())->request($method, $url, $options),
            $method,
            $url,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonResponse(object $response, string $method, string $target): array
    {
        /** @var HttpClientInterface|object $response */
        $statusCode = $response->getStatusCode();
        $content = $response->getContent(false);
        $decoded = $content !== '' ? json_decode($content, true) : [];

        if ($statusCode >= 400) {
            throw new \RuntimeException($this->extractApiErrorMessage(
                is_array($decoded) ? $decoded : [],
                sprintf('HTTP %d bei %s %s.', $statusCode, $method, $target),
            ));
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array{
     *     apiBaseUrl: string,
     *     authBaseUrl: string,
     *     tenantId: string,
     *     clientId: string,
     *     clientSecret: string,
     *     liveWritebackEnabled: bool,
     *     runAs: string,
     *     companyId: string
     * } $config
     *
     * @return array<string, string>
     */
    private function authorizedHeaders(string $accessToken, array $config): array
    {
        $headers = [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer '.$accessToken,
            'x-tenant-id' => $config['tenantId'],
        ];

        if ($config['runAs'] !== '') {
            $headers['x-runas'] = $config['runAs'];
        }

        if ($config['companyId'] !== '') {
            $headers['x-companyid'] = $config['companyId'];
        }

        return $headers;
    }

    private function shortText(array $bulletPoints): string
    {
        $bulletPoints = array_values(array_filter(
            array_map(static fn (string $bullet): string => trim($bullet), $bulletPoints),
            static fn (string $bullet): bool => $bullet !== '',
        ));

        return implode(' · ', array_slice($bulletPoints, 0, 3));
    }

    private function buildMetaDescription(string $description, array $bulletPoints): string
    {
        $candidate = trim($description) !== '' ? trim($description) : $this->shortText($bulletPoints);
        if ($candidate === '') {
            return '';
        }

        return mb_substr(preg_replace('/\s+/u', ' ', $candidate) ?? $candidate, 0, 160);
    }

    private function buildUrl(string $baseUrl, string $path): string
    {
        $baseUrl = rtrim(trim($baseUrl), '/');

        return $baseUrl === '' ? '' : $baseUrl.'/'.ltrim($path, '/');
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function extractApiErrorMessage(array $payload, string $fallback): string
    {
        $detail = trim((string) ($payload['detail'] ?? $payload['message'] ?? $payload['title'] ?? ''));
        if ($detail !== '') {
            return $detail;
        }

        $errors = $payload['errors'] ?? null;
        if (is_array($errors) && isset($errors[0]) && is_array($errors[0])) {
            $detail = trim((string) ($errors[0]['detail'] ?? $errors[0]['message'] ?? $errors[0]['title'] ?? ''));
            if ($detail !== '') {
                return $detail;
            }
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
}
