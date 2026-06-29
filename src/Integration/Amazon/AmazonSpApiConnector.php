<?php

declare(strict_types=1);

namespace App\Integration\Amazon;

use App\Dto\ListingDraft;
use App\Dto\SyncResult;
use App\Entity\Product;
use App\Enum\ChannelType;
use App\Enum\SyncStatus;
use App\Service\Amazon\AmazonListingsItemPayloadBuilder;
use App\Service\Amazon\AmazonProductTypeMapper;
use App\Service\Configuration\ServerSecretResolver;
use App\Service\Export\ListingDataTranslator;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

final class AmazonSpApiConnector
{
    private const DEFAULT_SECRETS_FILE = 'ctc-amazon.env';
    private const FALLBACK_MIXED_SECRETS_FILE = 'ctc.env';
    private const DEFAULT_LWA_BASE_URL = 'https://api.amazon.com';

    /**
     * @var array<string, string>
     */
    private const REGION_ENDPOINTS = [
        'eu' => 'https://sellingpartnerapi-eu.amazon.com',
        'na' => 'https://sellingpartnerapi-na.amazon.com',
        'fe' => 'https://sellingpartnerapi-fe.amazon.com',
    ];

    public function __construct(
        private readonly string $amazonRegion,
        private readonly string $amazonSellerId,
        private readonly string $amazonMarketplaceId,
        private readonly string $amazonAppId,
        private readonly AmazonProductTypeMapper $productTypeMapper,
        private readonly AmazonListingsItemPayloadBuilder $listingsItemPayloadBuilder,
        private readonly ListingDataTranslator $listingDataTranslator,
        private readonly string $amazonClientSecret = '',
        private readonly string $amazonRefreshToken = '',
        private readonly bool $amazonEnableLivePublish = false,
        private readonly ?HttpClientInterface $httpClient = null,
        private readonly string $appExternalSecretsFile = '',
        private readonly string $amazonSpApiBaseUrl = '',
        private readonly string $amazonLwaBaseUrl = '',
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->missingConfigFields($this->readConfig()) === [];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildPayload(Product $product, ListingDraft $draft): array
    {
        $config = $this->readConfig();
        $sku = $this->buildSellerSku($product);
        $missing = $this->missingConfigFields($config);
        $productTypeMapping = $this->productTypeMapper->map($product, $draft);
        $requirements = $this->resolveRequirements($product);

        return [
            'verkaeufer_id' => $config['sellerId'],
            'marktplatz_id' => $config['marketplaceId'],
            'region' => $config['region'],
            'oauth_endpoint' => $this->buildUrl($config['lwaBaseUrl'], '/auth/o2/token'),
            'sp_api_basis_url' => $config['apiBaseUrl'],
            'marketplace_probe_endpoint' => $this->buildUrl($config['apiBaseUrl'], '/sellers/v1/marketplaceParticipations'),
            'produkttyp_suche_endpoint' => $this->buildUrl($config['apiBaseUrl'], '/definitions/2020-09-01/productTypes'),
            'produkttyp_schema_endpoint_muster' => $this->buildUrl($config['apiBaseUrl'], '/definitions/2020-09-01/productTypes/{productType}'),
            'listings_item_validierungsmodus' => 'VALIDATION_PREVIEW',
            'listings_item_requirements' => $requirements,
            'listings_item_requirements_begruendung' => $requirements === 'LISTING'
                ? 'Preis und Bestand sind vorhanden, deshalb kann CTC ein vollständiges Listing-Preview inklusive Offer-Daten bauen.'
                : 'Es fehlen Preis oder Bestand. CTC validiert daher zunächst nur die Produktdaten.',
            'live_publish_aktiv' => $this->amazonEnableLivePublish,
            'live_publish_moeglich' => $this->amazonEnableLivePublish && $requirements === 'LISTING',
            'live_publish_hinweis' => $this->amazonEnableLivePublish
                ? ($requirements === 'LISTING'
                    ? 'Live-Publishing ist aktiviert. Nach erfolgreicher Preview sendet CTC zusätzlich den echten Amazon-Submit.'
                    : 'Live-Publishing ist aktiviert, aber ohne Preis und Bestand bleibt CTC aus Sicherheitsgründen beim Produktdaten-Preview.')
                : 'Live-Publishing ist deaktiviert. CTC arbeitet nur mit VALIDATION_PREVIEW.',
            'listings_items_endpoint' => $config['sellerId'] !== ''
                ? $this->buildUrl($config['apiBaseUrl'], '/listings/2021-08-01/items/'.$config['sellerId'].'/'.$sku)
                : null,
            'sku' => $sku,
            'externe_referenz' => (string) $product->getPublicId(),
            'titel' => $draft->title,
            'bulletpoints' => $draft->bulletPoints,
            'beschreibung' => $draft->description,
            'suchbegriffe' => $draft->searchTerms,
            'merkmale' => $this->listingDataTranslator->technicalAttributes($draft->technicalAttributes),
            'qualitaetswert' => $draft->qualityScore,
            'qualitaetsnote' => $draft->qualityGrade,
            'blocker' => $draft->qualityReview['blockers'],
            'produkt_typ_hinweis' => $draft->technicalAttributes['product_type'] ?? null,
            'produkt_typ_mapping' => $productTypeMapping,
            'konfigurationsluecken' => $missing,
            'app_id_konfiguriert' => $config['appId'] !== '',
            'schnittstelle_bereit' => $missing === [],
        ];
    }

    public function publish(Product $product, ListingDraft $draft): SyncResult
    {
        $payloadPreview = $this->buildPayload($product, $draft);

        if (!$this->isConfigured()) {
            return new SyncResult(
                ChannelType::Amazon,
                SyncStatus::Failed,
                'Amazon SP-API ist noch nicht vollständig konfiguriert. Es fehlen noch Basisdaten für OAuth oder Marketplace-Zugriff.',
                $payloadPreview,
            );
        }

        try {
            $config = $this->readConfig(true);
            $accessToken = $this->fetchAccessToken($config);
            $probe = $this->probeMarketplaceAccess($accessToken, $config);

            if (!$probe['configured_marketplace_found']) {
                throw new \RuntimeException(sprintf(
                    'Die konfigurierte Marketplace-ID "%s" wurde im Seller-Zugriff nicht gefunden.',
                    $config['marketplaceId'],
                ));
            }

            if ($probe['is_participating'] !== true) {
                throw new \RuntimeException(sprintf(
                    'Der Seller ist für den Marketplace "%s" aktuell nicht aktiv freigeschaltet.',
                    $config['marketplaceId'],
                ));
            }

            $productTypeMapping = $this->productTypeMapper->map($product, $draft);
            $requirements = $this->resolveRequirements($product);
            $productTypeSearch = $this->searchProductTypes($accessToken, $config, $productTypeMapping);
            $selectedProductType = (string) ($productTypeSearch['ausgewaehlter_product_type']['name'] ?? '');

            if ($selectedProductType === '') {
                return new SyncResult(
                    ChannelType::Amazon,
                    SyncStatus::Failed,
                    $this->buildSearchFailureMessage($draft),
                    [
                        ...$payloadPreview,
                        'verbindungspruefung' => $probe,
                        'produkt_typ_mapping' => [
                            ...$payloadPreview['produkt_typ_mapping'],
                            'amazon_kandidaten' => $productTypeSearch,
                        ],
                    ],
                );
            }

            $locale = (string) ($productTypeMapping['locale'] ?? 'DEFAULT');
            $definitionSummary = $this->fetchProductTypeDefinitionSummary($accessToken, $config, $selectedProductType, $locale, $requirements);
            $productTypeSchema = $this->fetchProductTypeSchema((string) ($definitionSummary['schema']['link'] ?? ''));
            $listingsItemPreview = $this->listingsItemPayloadBuilder->build(
                $product,
                $draft,
                $config['marketplaceId'],
                $locale,
                $selectedProductType,
                $productTypeSchema,
                $requirements,
            );
            $validationPreview = $this->validateListingsItemPreview($accessToken, $config, $product, $listingsItemPreview['body'], $locale);
            $resolvedExternalId = $this->resolveExternalIdFromIdentifiers($validationPreview['identifiers'] ?? []);

            $payload = [
                ...$payloadPreview,
                'live_sync' => false,
                'validierungsmodus' => 'VALIDATION_PREVIEW',
                'listings_item_requirements' => $requirements,
                'verbindungspruefung' => $probe,
                'produkt_typ_mapping' => [
                    ...$payloadPreview['produkt_typ_mapping'],
                    'amazon_kandidaten' => $productTypeSearch,
                ],
                'produkttyp_definition' => $definitionSummary,
                'produkttyp_schema' => [
                    'schema_attribute_anzahl' => $listingsItemPreview['schema_attribute_anzahl'],
                    'schema_attribute_vorschau' => $listingsItemPreview['schema_attribute_vorschau'],
                    'schema_pflichtattribute' => $listingsItemPreview['schema_pflichtattribute'],
                ],
                'listings_item_payload' => $listingsItemPreview,
                'validierung' => $validationPreview,
                'erkannte_amazon_asin' => $resolvedExternalId,
            ];

            $validationStatus = strtoupper((string) ($validationPreview['status'] ?? ''));
            $validationSucceeded = in_array($validationStatus, ['VALID', 'ACCEPTED'], true);

            if (!$validationSucceeded) {
                return new SyncResult(
                    ChannelType::Amazon,
                    SyncStatus::Failed,
                    $this->buildValidationMessage($selectedProductType, $requirements, $validationPreview),
                    $payload,
                );
            }

            if (!$this->shouldSubmitLive($requirements)) {
                return new SyncResult(
                    ChannelType::Amazon,
                    SyncStatus::Succeeded,
                    $this->buildValidationMessage($selectedProductType, $requirements, $validationPreview),
                    $payload,
                    $resolvedExternalId,
                );
            }

            $liveSubmission = $this->submitListingsItemLive($accessToken, $config, $product, $listingsItemPreview['body'], $locale);
            $liveStatus = strtoupper((string) ($liveSubmission['status'] ?? ''));
            $payload['live_sync'] = true;
            $payload['live_submit'] = $liveSubmission;

            return new SyncResult(
                ChannelType::Amazon,
                in_array($liveStatus, ['VALID', 'ACCEPTED'], true) ? SyncStatus::Succeeded : SyncStatus::Failed,
                $this->buildLiveSubmitMessage($selectedProductType, $liveSubmission),
                $payload,
                $this->resolveExternalIdFromIdentifiers($validationPreview['identifiers'] ?? []),
            );
        } catch (Throwable $exception) {
            return new SyncResult(
                ChannelType::Amazon,
                SyncStatus::Failed,
                'Amazon SP-API Prüfung fehlgeschlagen: '.$exception->getMessage(),
                $payloadPreview,
            );
        }
    }

    /**
     * @param array{
     *     region: string,
     *     sellerId: string,
     *     marketplaceId: string,
     *     appId: string,
     *     clientSecret: string,
     *     refreshToken: string,
     *     apiBaseUrl: string,
     *     lwaBaseUrl: string
     * } $config
     */
    private function fetchAccessToken(array $config): string
    {
        $response = $this->requestJson('POST', $config['lwaBaseUrl'], '/auth/o2/token', [
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'body' => http_build_query([
                'grant_type' => 'refresh_token',
                'refresh_token' => $config['refreshToken'],
                'client_id' => $config['appId'],
                'client_secret' => $config['clientSecret'],
            ], '', '&', PHP_QUERY_RFC3986),
        ]);

        $accessToken = trim((string) ($response['access_token'] ?? ''));
        if ($accessToken === '') {
            throw new \RuntimeException('Amazon OAuth-Token konnte nicht gelesen werden.');
        }

        return $accessToken;
    }

    /**
     * @param array{
     *     region: string,
     *     sellerId: string,
     *     marketplaceId: string,
     *     appId: string,
     *     clientSecret: string,
     *     refreshToken: string,
     *     apiBaseUrl: string,
     *     lwaBaseUrl: string
     * } $config
     *
     * @return array<string, mixed>
     */
    private function probeMarketplaceAccess(string $accessToken, array $config): array
    {
        $response = $this->requestJson('GET', $config['apiBaseUrl'], '/sellers/v1/marketplaceParticipations', [
            'headers' => $this->authorizedHeaders($accessToken),
        ]);

        $participations = array_values(array_filter($response['payload'] ?? [], 'is_array'));
        $configuredMarketplace = null;

        foreach ($participations as $participation) {
            $marketplace = is_array($participation['marketplace'] ?? null) ? $participation['marketplace'] : [];
            if (($marketplace['id'] ?? null) === $config['marketplaceId']) {
                $configuredMarketplace = $participation;
                break;
            }
        }

        return [
            'oauth_token_erhalten' => true,
            'sp_api_basis_url' => $config['apiBaseUrl'],
            'configured_marketplace_found' => $configuredMarketplace !== null,
            'marketplace_id' => $config['marketplaceId'],
            'marketplace_name' => $configuredMarketplace['marketplace']['name'] ?? null,
            'country_code' => $configuredMarketplace['marketplace']['countryCode'] ?? null,
            'is_participating' => $configuredMarketplace !== null
                ? (bool) ($configuredMarketplace['participation']['isParticipating'] ?? false)
                : false,
            'has_suspended_listings' => $configuredMarketplace !== null && array_key_exists('hasSuspendedListings', $configuredMarketplace['participation'] ?? [])
                ? (bool) $configuredMarketplace['participation']['hasSuspendedListings']
                : null,
            'marketplace_count' => count($participations),
            'marketplaces' => array_map(
                static fn (array $participation): array => [
                    'id' => $participation['marketplace']['id'] ?? null,
                    'name' => $participation['marketplace']['name'] ?? null,
                    'country_code' => $participation['marketplace']['countryCode'] ?? null,
                    'is_participating' => (bool) ($participation['participation']['isParticipating'] ?? false),
                ],
                $participations,
            ),
        ];
    }

    /**
     * @param array{
     *     region: string,
     *     sellerId: string,
     *     marketplaceId: string,
     *     appId: string,
     *     clientSecret: string,
     *     refreshToken: string,
     *     apiBaseUrl: string,
     *     lwaBaseUrl: string
     * } $config
     * @param array{
     *     strategie: string,
     *     suchbegriffe: list<string>,
     *     artikelname: string,
     *     locale: string
     * } $mapping
     *
     * @return array{
     *     suche_mit: string,
     *     request_parameter: array<string, string>,
     *     treffer_anzahl: int,
     *     ausgewaehlter_product_type: ?array{name: string, display_name: ?string, marketplace_ids: list<string>},
     *     kandidaten: list<array{name: string, display_name: ?string, marketplace_ids: list<string>}>
     * }
     */
    private function searchProductTypes(string $accessToken, array $config, array $mapping): array
    {
        $query = [
            'marketplaceIds' => $config['marketplaceId'],
            'locale' => (string) $mapping['locale'],
            'searchLocale' => (string) $mapping['locale'],
        ];

        if (($mapping['strategie'] ?? 'item_name') === 'keywords' && ($mapping['suchbegriffe'] ?? []) !== []) {
            $query['keywords'] = implode(',', array_values(array_unique($mapping['suchbegriffe'])));
            $searchMode = 'keywords';
        } else {
            $query['itemName'] = (string) $mapping['artikelname'];
            $searchMode = 'item_name';
        }

        $response = $this->requestJson('GET', $config['apiBaseUrl'], '/definitions/2020-09-01/productTypes', [
            'headers' => $this->authorizedHeaders($accessToken),
            'query' => $query,
        ]);

        $candidates = [];
        foreach (array_values(array_filter($response['productTypes'] ?? [], 'is_array')) as $productType) {
            $candidates[] = [
                'name' => (string) ($productType['name'] ?? ''),
                'display_name' => isset($productType['displayName']) ? (string) $productType['displayName'] : null,
                'marketplace_ids' => array_values(array_map(
                    static fn (mixed $value): string => (string) $value,
                    array_filter($productType['marketplaceIds'] ?? [], static fn (mixed $value): bool => is_scalar($value) && (string) $value !== ''),
                )),
            ];
        }

        $candidates = array_values(array_filter(
            $candidates,
            static fn (array $candidate): bool => $candidate['name'] !== '',
        ));

        return [
            'suche_mit' => $searchMode,
            'request_parameter' => $query,
            'treffer_anzahl' => count($candidates),
            'ausgewaehlter_product_type' => $candidates[0] ?? null,
            'kandidaten' => array_slice($candidates, 0, 10),
        ];
    }

    /**
     * @param array{
     *     region: string,
     *     sellerId: string,
     *     marketplaceId: string,
     *     appId: string,
     *     clientSecret: string,
     *     refreshToken: string,
     *     apiBaseUrl: string,
     *     lwaBaseUrl: string
     * } $config
     *
     * @return array<string, mixed>
     */
    private function fetchProductTypeDefinitionSummary(
        string $accessToken,
        array $config,
        string $productType,
        string $locale,
        string $requirements,
    ): array {
        $response = $this->requestJson('GET', $config['apiBaseUrl'], '/definitions/2020-09-01/productTypes/'.$productType, [
            'headers' => $this->authorizedHeaders($accessToken),
            'query' => [
                'sellerId' => $config['sellerId'],
                'marketplaceIds' => $config['marketplaceId'],
                'locale' => $locale,
                'requirements' => $requirements,
                'requirementsEnforced' => 'ENFORCED',
            ],
        ]);

        $propertyGroups = [];
        foreach (array_filter($response['propertyGroups'] ?? [], 'is_array') as $groupKey => $group) {
            $propertyNames = array_values(array_map(
                static fn (mixed $value): string => (string) $value,
                array_filter($group['propertyNames'] ?? [], static fn (mixed $value): bool => is_scalar($value) && (string) $value !== ''),
            ));

            $propertyGroups[] = [
                'gruppe' => (string) $groupKey,
                'titel' => isset($group['title']) ? (string) $group['title'] : null,
                'eigenschaften_anzahl' => count($propertyNames),
                'eigenschaften_vorschau' => array_slice($propertyNames, 0, 8),
            ];
        }

        return [
            'product_type' => $productType,
            'requirements' => $response['requirements'] ?? null,
            'requirements_enforced' => $response['requirementsEnforced'] ?? null,
            'property_group_count' => count($propertyGroups),
            'property_groups' => $propertyGroups,
            'schema' => [
                'link' => $response['schema']['link']['resource'] ?? null,
                'checksum' => $response['schema']['link']['checksum'] ?? null,
            ],
            'meta_schema' => [
                'link' => $response['metaSchema']['link']['resource'] ?? null,
                'checksum' => $response['metaSchema']['link']['checksum'] ?? null,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchProductTypeSchema(string $schemaUrl): array
    {
        $schemaUrl = trim($schemaUrl);
        if ($schemaUrl === '') {
            throw new \RuntimeException('Amazon-Produkttyp-Schema-Link fehlt.');
        }

        return $this->requestAbsoluteJson('GET', $schemaUrl, [
            'headers' => [
                'Accept' => 'application/json',
            ],
        ]);
    }

    /**
     * @param array{
     *     region: string,
     *     sellerId: string,
     *     marketplaceId: string,
     *     appId: string,
     *     clientSecret: string,
     *     refreshToken: string,
     *     apiBaseUrl: string,
     *     lwaBaseUrl: string
     * } $config
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function validateListingsItemPreview(
        string $accessToken,
        array $config,
        Product $product,
        array $body,
        string $locale,
    ): array {
        $response = $this->requestJson('PUT', $config['apiBaseUrl'], '/listings/2021-08-01/items/'.$config['sellerId'].'/'.$this->buildSellerSku($product), [
            'headers' => array_merge($this->authorizedHeaders($accessToken), [
                'Content-Type' => 'application/json',
            ]),
            'query' => [
                'marketplaceIds' => $config['marketplaceId'],
                'includedData' => 'issues,identifiers',
                'mode' => 'VALIDATION_PREVIEW',
                'issueLocale' => $locale,
            ],
            'json' => $body,
        ]);

        $issues = array_values(array_filter($response['issues'] ?? [], 'is_array'));

        return [
            'status' => $response['status'] ?? null,
            'submission_id' => $response['submissionId'] ?? null,
            'identifiers' => $response['identifiers'] ?? [],
            'issues' => $issues,
            'fehlende_pflichtattribute_laut_amazon' => $this->extractMissingAttributesFromIssues($issues),
        ];
    }

    /**
     * @param array{
     *     region: string,
     *     sellerId: string,
     *     marketplaceId: string,
     *     appId: string,
     *     clientSecret: string,
     *     refreshToken: string,
     *     apiBaseUrl: string,
     *     lwaBaseUrl: string
     * } $config
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function submitListingsItemLive(
        string $accessToken,
        array $config,
        Product $product,
        array $body,
        string $locale,
    ): array {
        $response = $this->requestJson('PUT', $config['apiBaseUrl'], '/listings/2021-08-01/items/'.$config['sellerId'].'/'.$this->buildSellerSku($product), [
            'headers' => array_merge($this->authorizedHeaders($accessToken), [
                'Content-Type' => 'application/json',
            ]),
            'query' => [
                'marketplaceIds' => $config['marketplaceId'],
                'includedData' => 'issues',
                'issueLocale' => $locale,
            ],
            'json' => $body,
        ]);

        return [
            'status' => $response['status'] ?? null,
            'submission_id' => $response['submissionId'] ?? null,
            'issues' => array_values(array_filter($response['issues'] ?? [], 'is_array')),
        ];
    }

    /**
     * @return array{
     *     region: string,
     *     sellerId: string,
     *     marketplaceId: string,
     *     appId: string,
     *     clientSecret: string,
     *     refreshToken: string,
     *     apiBaseUrl: string,
     *     lwaBaseUrl: string
     * }
     */
    private function readConfig(bool $strict = false): array
    {
        $external = $this->readRuntimeSecrets($strict);
        $region = strtolower($this->firstNonEmpty(
            $external['AMAZON_REGION'] ?? null,
            $this->amazonRegion,
            'eu',
        ));

        return [
            'region' => $region,
            'sellerId' => $this->firstNonEmpty(
                $external['AMAZON_SELLER_ID'] ?? null,
                $this->amazonSellerId,
            ),
            'marketplaceId' => $this->firstNonEmpty(
                $external['AMAZON_MARKETPLACE_ID'] ?? null,
                $this->amazonMarketplaceId,
            ),
            'appId' => $this->firstNonEmpty(
                $external['AMAZON_APP_ID'] ?? null,
                $this->amazonAppId,
            ),
            'clientSecret' => $this->firstNonEmpty(
                $external['AMAZON_CLIENT_SECRET'] ?? null,
                $this->amazonClientSecret,
            ),
            'refreshToken' => $this->firstNonEmpty(
                $external['AMAZON_REFRESH_TOKEN'] ?? null,
                $this->amazonRefreshToken,
            ),
            'apiBaseUrl' => rtrim($this->firstNonEmpty(
                $external['AMAZON_SP_API_BASE_URL'] ?? null,
                $this->amazonSpApiBaseUrl,
                self::REGION_ENDPOINTS[$region] ?? '',
            ), '/'),
            'lwaBaseUrl' => rtrim($this->firstNonEmpty(
                $external['AMAZON_LWA_BASE_URL'] ?? null,
                $this->amazonLwaBaseUrl,
                self::DEFAULT_LWA_BASE_URL,
            ), '/'),
        ];
    }

    /**
     * @param array{
     *     region: string,
     *     sellerId: string,
     *     marketplaceId: string,
     *     appId: string,
     *     clientSecret: string,
     *     refreshToken: string,
     *     apiBaseUrl: string,
     *     lwaBaseUrl: string
     * } $config
     *
     * @return list<string>
     */
    private function missingConfigFields(array $config): array
    {
        $missing = [];

        if ($config['sellerId'] === '') {
            $missing[] = 'AMAZON_SELLER_ID';
        }

        if ($config['marketplaceId'] === '') {
            $missing[] = 'AMAZON_MARKETPLACE_ID';
        }

        if ($config['appId'] === '') {
            $missing[] = 'AMAZON_APP_ID';
        }

        if ($config['clientSecret'] === '') {
            $missing[] = 'AMAZON_CLIENT_SECRET';
        }

        if ($config['refreshToken'] === '') {
            $missing[] = 'AMAZON_REFRESH_TOKEN';
        }

        if ($config['apiBaseUrl'] === '') {
            $missing[] = 'AMAZON_REGION_ODER_AMAZON_SP_API_BASE_URL';
        }

        if ($config['lwaBaseUrl'] === '') {
            $missing[] = 'AMAZON_LWA_BASE_URL';
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
            'Amazon',
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
                throw new \RuntimeException('Amazon-Secrets-Datei ist nicht lesbar. Bitte Server-Konfiguration prüfen.');
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
     * @return array<string, string>
     */
    private function authorizedHeaders(string $accessToken): array
    {
        return [
            'Accept' => 'application/json',
            'x-amz-access-token' => $accessToken,
            'x-amz-date' => gmdate('Ymd\THis\Z'),
            'user-agent' => 'cms-to-commerce-hub/1.0 (Language=PHP)',
        ];
    }

    private function buildUrl(string $baseUrl, string $path): string
    {
        $baseUrl = rtrim(trim($baseUrl), '/');

        return $baseUrl === '' ? '' : $baseUrl.'/'.ltrim($path, '/');
    }

    private function buildSellerSku(Product $product): string
    {
        return sprintf('CTC-%s', strtoupper((string) $product->getPublicId()));
    }

    private function resolveRequirements(Product $product): string
    {
        $hasPrice = false;
        $hasStock = false;

        foreach ($product->getVariants() as $variant) {
            if (!$variant->isEnabled()) {
                continue;
            }

            if (!$hasPrice && $variant->getPriceGross() !== null && is_numeric($variant->getPriceGross()) && (float) $variant->getPriceGross() > 0) {
                $hasPrice = true;
            }

            if (!$hasStock && $variant->getStock() !== null) {
                $hasStock = true;
            }

            if ($hasPrice && $hasStock) {
                return 'LISTING';
            }
        }

        return 'LISTING_PRODUCT_ONLY';
    }

    private function shouldSubmitLive(string $requirements): bool
    {
        return $this->amazonEnableLivePublish && $requirements === 'LISTING';
    }

    private function buildSearchFailureMessage(ListingDraft $draft): string
    {
        $productTypeHint = trim((string) ($draft->technicalAttributes['product_type'] ?? ''));

        return $productTypeHint !== ''
            ? sprintf(
                'Amazon SP-API Verbindung ist live, aber für die CTC-Produktart "%s" wurden noch keine Amazon-Product-Types gefunden. Wir müssen die Suchbegriffe oder Kategorie weiter schärfen.',
                $productTypeHint,
            )
            : 'Amazon SP-API Verbindung ist live, aber es wurden noch keine Amazon-Product-Types gefunden. Wir müssen die Produktart oder Kategorie im CTC-Produkt schärfen.';
    }

    /**
     * @param array<string, mixed> $validationPreview
     */
    private function buildValidationMessage(string $selectedProductType, string $requirements, array $validationPreview): string
    {
        $status = strtoupper((string) ($validationPreview['status'] ?? ''));
        $missing = $validationPreview['fehlende_pflichtattribute_laut_amazon'] ?? [];
        $scopeLabel = $requirements === 'LISTING' ? 'vollständige Listing-Daten' : 'Produktdaten';

        if (in_array($status, ['VALID', 'ACCEPTED'], true)) {
            return sprintf(
                'Amazon-Validierung erfolgreich. Das CTC-Payload für %s im Product Type "%s" ist im Modus VALIDATION_PREVIEW strukturell gültig.',
                $scopeLabel,
                $selectedProductType,
            );
        }

        if ($missing !== []) {
            return sprintf(
                'Amazon-Validierung meldet noch fehlende Pflichtattribute für %s im Product Type "%s": %s.',
                $scopeLabel,
                $selectedProductType,
                implode(', ', $missing),
            );
        }

        return sprintf(
            'Amazon-Validierung für %s im Product Type "%s" hat noch Schema- oder Inhaltsfehler zurückgegeben.',
            $scopeLabel,
            $selectedProductType,
        );
    }

    /**
     * @param array<string, mixed> $liveSubmission
     */
    private function buildLiveSubmitMessage(string $selectedProductType, array $liveSubmission): string
    {
        $status = strtoupper((string) ($liveSubmission['status'] ?? ''));

        if (in_array($status, ['VALID', 'ACCEPTED'], true)) {
            return sprintf(
                'Amazon-Live-Submit erfolgreich angestoßen. Das Listing für Product Type "%s" wurde nach erfolgreicher Preview an Amazon übergeben.',
                $selectedProductType,
            );
        }

        return sprintf(
            'Amazon-Live-Submit für Product Type "%s" wurde nicht akzeptiert.',
            $selectedProductType,
        );
    }

    /**
     * @param list<array<string, mixed>> $issues
     *
     * @return list<string>
     */
    private function extractMissingAttributesFromIssues(array $issues): array
    {
        $missing = [];

        foreach ($issues as $issue) {
            $categories = array_map(
                static fn (mixed $value): string => (string) $value,
                array_filter($issue['categories'] ?? [], static fn (mixed $value): bool => is_scalar($value)),
            );

            if (!in_array('MISSING_ATTRIBUTE', $categories, true)) {
                continue;
            }

            foreach (array_filter($issue['attributeNames'] ?? [], static fn (mixed $value): bool => is_scalar($value)) as $attributeName) {
                $missing[] = (string) $attributeName;
            }
        }

        return array_values(array_unique(array_filter($missing, static fn (string $value): bool => $value !== '')));
    }

    /**
     * @param list<array<string, mixed>> $identifiers
     */
    private function resolveExternalIdFromIdentifiers(array $identifiers): ?string
    {
        foreach ($identifiers as $identifier) {
            $asin = trim((string) ($identifier['asin'] ?? ''));
            if ($asin !== '') {
                return $asin;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function extractApiErrorMessage(array $payload, string $fallback): string
    {
        $errors = $payload['errors'] ?? null;
        if (is_array($errors) && isset($errors[0]) && is_array($errors[0])) {
            $detail = trim((string) ($errors[0]['details'] ?? $errors[0]['message'] ?? ''));
            if ($detail !== '') {
                return $detail;
            }
        }

        $message = trim((string) ($payload['message'] ?? $payload['error_description'] ?? ''));

        return $message !== '' ? $message : $fallback;
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
}
