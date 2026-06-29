<?php

declare(strict_types=1);

namespace App\Integration\Shopware;

use App\Dto\ListingDraft;
use App\Dto\SyncResult;
use App\Entity\Product;
use App\Enum\ChannelType;
use App\Enum\SyncStatus;
use App\Service\Configuration\ServerSecretResolver;
use App\Service\Export\ListingDataTranslator;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

final class ShopwareAdminApiConnector
{
    private const DEFAULT_SECRETS_FILE = 'ctc-shopware.env';
    private const LEGACY_SKILLBUILDER_SECRETS_FILE = 'skillbuilder-shopware.env';
    private const VISIBILITY_ALL = 30;

    public function __construct(
        private readonly string $shopwareBaseUrl,
        private readonly string $shopwareSyncCategory,
        private readonly ListingDataTranslator $listingDataTranslator,
        private readonly HttpClientInterface $httpClient,
        private readonly string $mediaStoragePath,
        private readonly string $appExternalSecretsFile = '',
        private readonly string $shopwareAdminBaseUrl = '',
        private readonly string $shopwareAdminUsername = '',
        private readonly string $shopwareAdminPassword = '',
    ) {
    }

    public function isConfigured(): bool
    {
        $config = $this->readConfig();

        return $config['baseUrl'] !== ''
            && $config['username'] !== ''
            && $config['password'] !== '';
    }

    /**
     * @return array<string, mixed>
     */
    public function buildPayload(Product $product, ListingDraft $draft): array
    {
        $config = $this->readConfig();
        $productNumber = $this->buildProductNumber($product);
        $stock = $this->resolveStock($product);
        $grossPrice = $this->resolveGrossPrice($product);
        $assetPreview = $this->buildAssetPreview($product);

        return [
            'basis_url' => $config['baseUrl'],
            'oauth_endpoint' => $this->buildUrl($config['baseUrl'], '/api/oauth/token'),
            'produkt_endpoint' => $this->buildUrl($config['baseUrl'], '/api/product'),
            'produkt_sichtbarkeit' => [
                'sales_channel_id' => 'wird_live_ermittelt',
                'sichtbarkeit' => self::VISIBILITY_ALL,
            ],
            'auth_modus' => 'password_grant_administration',
            'ziel_kategorie' => $this->shopwareSyncCategory !== '' ? $this->shopwareSyncCategory : null,
            'produktnummer' => $productNumber,
            'name' => $draft->title,
            'beschreibung_html' => $this->buildDescriptionHtml($draft),
            'zusatzfelder' => $this->listingDataTranslator->technicalAttributes($draft->technicalAttributes),
            'suchbegriffe' => $draft->searchTerms,
            'qualitaetswert' => $draft->qualityScore,
            'qualitaetsnote' => $draft->qualityGrade,
            'blocker' => $draft->qualityReview['blockers'],
            'bild_uploads' => $assetPreview['uploads'],
            'produkt_payload' => [
                'name' => $draft->title,
                'productNumber' => $productNumber,
                'active' => true,
                'stock' => $stock,
                'availableStock' => $stock,
                'description' => $this->buildDescriptionHtml($draft),
                'price' => [
                    [
                        'currencyId' => 'wird_live_ermittelt',
                        'gross' => $grossPrice,
                        'net' => 'wird_live_berechnet',
                        'linked' => true,
                    ],
                ],
                'categories' => $this->shopwareSyncCategory !== '' ? [['name' => $this->shopwareSyncCategory]] : [],
                'manufacturer' => $product->getBrand() !== null ? ['name' => $product->getBrand()] : null,
                'coverId' => $assetPreview['cover_id'],
                'media' => $assetPreview['product_media'],
                'visibilities' => [[
                    'salesChannelId' => 'wird_live_ermittelt',
                    'visibility' => self::VISIBILITY_ALL,
                ]],
            ],
            'schnittstelle_bereit' => $this->isConfigured(),
        ];
    }

    public function publish(Product $product, ListingDraft $draft): SyncResult
    {
        $payloadPreview = $this->buildPayload($product, $draft);

        if (!$this->isConfigured()) {
            return new SyncResult(
                ChannelType::Shopware,
                SyncStatus::Failed,
                'Shopware-Zugangsdaten fehlen noch. Export-Payload wurde vorbereitet, Live-Sync bleibt gesperrt.',
                $payloadPreview,
            );
        }

        try {
            $config = $this->readConfig(true);
            $accessToken = $this->fetchAccessToken($config);
            $productNumber = $this->buildProductNumber($product);
            $salesChannel = $this->resolveSalesChannel($accessToken, $config['baseUrl']);
            $existingProduct = $this->findOne(
                $accessToken,
                $config['baseUrl'],
                'product',
                [['type' => 'equals', 'field' => 'productNumber', 'value' => $productNumber]],
                [
                    'visibilities' => [],
                    'media' => [
                        'associations' => [
                            'media' => [],
                        ],
                    ],
                ],
            );
            $tax = $this->findOne(
                $accessToken,
                $config['baseUrl'],
                'tax',
                [['type' => 'equals', 'field' => 'taxRate', 'value' => 19]],
            ) ?? $this->first($accessToken, $config['baseUrl'], 'tax');
            $currency = $this->findOne(
                $accessToken,
                $config['baseUrl'],
                'currency',
                [['type' => 'equals', 'field' => 'isoCode', 'value' => 'EUR']],
            ) ?? $this->first($accessToken, $config['baseUrl'], 'currency');

            if ($tax === null || $currency === null) {
                throw new \RuntimeException('Shopware-Basisdaten fehlen: Steuer oder Währung nicht gefunden.');
            }

            $categoryId = $this->shopwareSyncCategory !== ''
                ? $this->ensureCategory($accessToken, $config['baseUrl'], $this->shopwareSyncCategory, $salesChannel)
                : null;
            $manufacturerId = $this->ensureManufacturer($accessToken, $config['baseUrl'], $product->getBrand());
            $grossPrice = $this->resolveGrossPrice($product);
            $stock = $this->resolveStock($product);
            $existingVisibilityId = $this->resolveExistingVisibilityId($existingProduct, (string) $salesChannel['id']);
            $visibilityId = $existingVisibilityId ?? $this->hexId();
            $assetSync = $this->syncProductAssets($product, $accessToken, $config['baseUrl'], $existingProduct);
            $productPayload = [
                'name' => $draft->title,
                'productNumber' => $productNumber,
                'active' => true,
                'stock' => $stock,
                'availableStock' => $stock,
                'taxId' => (string) $tax['id'],
                'description' => $this->buildDescriptionHtml($draft),
                'price' => [[
                    'currencyId' => (string) $currency['id'],
                    'gross' => $grossPrice,
                    'net' => $this->calculateNetPrice($grossPrice, (float) ($tax['taxRate'] ?? 0)),
                    'linked' => true,
                ]],
                'media' => $assetSync['product_media'],
                'visibilities' => [[
                    'id' => $visibilityId,
                    'salesChannelId' => (string) $salesChannel['id'],
                    'visibility' => self::VISIBILITY_ALL,
                ]],
            ];

            if ($manufacturerId !== null) {
                $productPayload['manufacturerId'] = $manufacturerId;
            }

            if ($categoryId !== null) {
                $productPayload['categories'] = [['id' => $categoryId]];
            }

            if ($assetSync['cover_id'] !== null) {
                $productPayload['coverId'] = $assetSync['cover_id'];
            }

            if ($existingProduct !== null) {
                $method = 'PATCH';
                $path = '/api/product/'.(string) $existingProduct['id'];
                $message = 'Shopware-Produkt wurde aktiv aktualisiert und im Sales Channel sichtbar gehalten.';
                $externalId = (string) $existingProduct['id'];
            } else {
                $method = 'POST';
                $path = '/api/product';
                $productPayload['id'] = $this->hexId();
                $message = 'Shopware-Produkt wurde aktiv angelegt und direkt im Sales Channel sichtbar gemacht.';
                $externalId = (string) $productPayload['id'];
            }

            $responsePayload = $this->requestJson($method, $config['baseUrl'], $path, [
                'headers' => $this->authorizedHeaders($accessToken),
                'json' => $productPayload,
            ]);

            return new SyncResult(
                ChannelType::Shopware,
                SyncStatus::Succeeded,
                $message,
                [
                    ...$payloadPreview,
                    'aufgeloeste_ids' => [
                        'produkt_id' => $externalId,
                        'tax_id' => (string) $tax['id'],
                        'currency_id' => (string) $currency['id'],
                        'category_id' => $categoryId,
                        'manufacturer_id' => $manufacturerId,
                        'sales_channel_id' => (string) $salesChannel['id'],
                        'sales_channel_visibility_id' => $visibilityId,
                    ],
                    'api_methode' => $method,
                    'api_pfad' => $path,
                    'produkt_request' => $productPayload,
                    'produkt_response' => $responsePayload,
                    'medien_sync' => $assetSync,
                ],
                $externalId,
            );
        } catch (Throwable $exception) {
            return new SyncResult(
                ChannelType::Shopware,
                SyncStatus::Failed,
                'Shopware-Sync fehlgeschlagen: '.$exception->getMessage(),
                $payloadPreview,
            );
        }
    }

    /**
     * @param array{baseUrl: string, username: string, password: string} $config
     */
    private function fetchAccessToken(array $config): string
    {
        $response = $this->requestJson('POST', $config['baseUrl'], '/api/oauth/token', [
            'headers' => [
                'Accept' => 'application/json',
            ],
            'json' => [
                'grant_type' => 'password',
                'client_id' => 'administration',
                'username' => $config['username'],
                'password' => $config['password'],
            ],
        ]);

        $accessToken = trim((string) ($response['access_token'] ?? ''));
        if ($accessToken === '') {
            throw new \RuntimeException('Shopware OAuth-Token konnte nicht gelesen werden.');
        }

        return $accessToken;
    }

    /**
     * @return array{baseUrl: string, username: string, password: string}
     */
    private function readConfig(bool $strict = false): array
    {
        $external = $this->readRuntimeSecrets($strict);

        return [
            'baseUrl' => rtrim($this->firstNonEmpty(
                $external['SHOPWARE_ADMIN_BASE_URL'] ?? null,
                $this->shopwareAdminBaseUrl,
                $this->shopwareBaseUrl,
            ), '/'),
            'username' => $this->firstNonEmpty(
                $external['SHOPWARE_ADMIN_USERNAME'] ?? null,
                $this->shopwareAdminUsername,
            ),
            'password' => $this->firstNonEmpty(
                $external['SHOPWARE_ADMIN_PASSWORD'] ?? null,
                $this->shopwareAdminPassword,
            ),
        ];
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
                'ctc.env',
                self::LEGACY_SKILLBUILDER_SECRETS_FILE,
            ],
            $strict,
            'Shopware',
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
                throw new \RuntimeException('Shopware-Secrets-Datei ist nicht lesbar. Bitte Server-Konfiguration prüfen.');
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
            $basePath.self::LEGACY_SKILLBUILDER_SECRETS_FILE,
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $filter
     *
     * @return array<string, mixed>|null
     */
    private function findOne(string $accessToken, string $baseUrl, string $entity, array $filter, array $associations = []): ?array
    {
        $data = $this->requestJson('POST', $baseUrl, '/api/search/'.$entity, [
            'headers' => $this->authorizedHeaders($accessToken),
            'json' => [
                'limit' => 1,
                'filter' => $filter,
                'associations' => $associations,
            ],
        ]);

        $first = $data['data'][0] ?? null;

        return is_array($first) ? $first : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function first(string $accessToken, string $baseUrl, string $entity): ?array
    {
        $data = $this->requestJson('POST', $baseUrl, '/api/search/'.$entity, [
            'headers' => $this->authorizedHeaders($accessToken),
            'json' => ['limit' => 1],
        ]);

        $first = $data['data'][0] ?? null;

        return is_array($first) ? $first : null;
    }

    private function ensureCategory(string $accessToken, string $baseUrl, string $name, ?array $salesChannel = null): string
    {
        $rootCategoryId = $this->resolveRootCategoryId($accessToken, $baseUrl, $salesChannel);
        $existing = $this->findOne($accessToken, $baseUrl, 'category', [
            ['type' => 'equals', 'field' => 'name', 'value' => $name],
            ['type' => 'equals', 'field' => 'parentId', 'value' => $rootCategoryId],
        ]);

        if ($existing !== null) {
            return (string) $existing['id'];
        }

        $id = $this->hexId();
        $this->requestJson('POST', $baseUrl, '/api/category', [
            'headers' => $this->authorizedHeaders($accessToken),
            'json' => [
                'id' => $id,
                'name' => $name,
                'parentId' => $rootCategoryId,
                'active' => true,
                'visible' => true,
                'type' => 'page',
            ],
        ]);

        return $id;
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveSalesChannel(string $accessToken, string $baseUrl): array
    {
        $salesChannel = $this->first($accessToken, $baseUrl, 'sales-channel');
        if ($salesChannel === null || !isset($salesChannel['id'])) {
            throw new \RuntimeException('Kein Shopware-Sales-Channel gefunden.');
        }

        return $salesChannel;
    }

    private function resolveRootCategoryId(string $accessToken, string $baseUrl, ?array $salesChannel = null): string
    {
        $salesChannel ??= $this->resolveSalesChannel($accessToken, $baseUrl);
        $navigationCategoryId = trim((string) ($salesChannel['navigationCategoryId'] ?? ''));
        if ($navigationCategoryId !== '') {
            return $navigationCategoryId;
        }

        $root = $this->findOne($accessToken, $baseUrl, 'category', [
            ['type' => 'equals', 'field' => 'name', 'value' => 'Katalog #1'],
        ]) ?? $this->first($accessToken, $baseUrl, 'category');

        if ($root === null) {
            throw new \RuntimeException('Keine Shopware-Root-Kategorie gefunden.');
        }

        return (string) $root['id'];
    }

    /**
     * @param array<string, mixed>|null $existingProduct
     */
    private function resolveExistingVisibilityId(?array $existingProduct, string $salesChannelId): ?string
    {
        if ($existingProduct === null) {
            return null;
        }

        $visibilities = $existingProduct['visibilities'] ?? [];
        if (!is_array($visibilities)) {
            return null;
        }

        foreach ($visibilities as $visibility) {
            if (!is_array($visibility)) {
                continue;
            }

            if ((string) ($visibility['salesChannelId'] ?? '') === $salesChannelId) {
                $id = trim((string) ($visibility['id'] ?? ''));

                return $id !== '' ? $id : null;
            }
        }

        return null;
    }

    private function ensureManufacturer(string $accessToken, string $baseUrl, ?string $brand): ?string
    {
        $brand = trim((string) $brand);
        if ($brand === '') {
            return null;
        }

        $existing = $this->findOne($accessToken, $baseUrl, 'product-manufacturer', [
            ['type' => 'equals', 'field' => 'name', 'value' => $brand],
        ]);
        if ($existing !== null) {
            return (string) $existing['id'];
        }

        $id = $this->hexId();
        $this->requestJson('POST', $baseUrl, '/api/product-manufacturer', [
            'headers' => $this->authorizedHeaders($accessToken),
            'json' => [
                'id' => $id,
                'name' => $brand,
            ],
        ]);

        return $id;
    }

    /**
     * @return array{
     *     product_media: list<array{id: string, mediaId: string, position: int}>,
     *     cover_id: ?string,
     *     uploads: list<array{media_id: string, product_media_id: string, original_name: string, upload_endpoint: string}>
     * }
     */
    private function buildAssetPreview(Product $product): array
    {
        $productMedia = [];
        $uploads = [];
        $coverId = null;

        foreach ($product->getAssets() as $asset) {
            if (!str_starts_with($asset->getMimeType(), 'image/')) {
                continue;
            }

            $position = $asset->getPosition() > 0 ? $asset->getPosition() : count($productMedia) + 1;
            $mediaId = $this->stableHexId('media', $product, $position);
            $productMediaId = $this->stableHexId('product-media', $product, $position);

            $productMedia[] = [
                'id' => $productMediaId,
                'mediaId' => $mediaId,
                'position' => $position,
            ];
            $uploads[] = [
                'media_id' => $mediaId,
                'product_media_id' => $productMediaId,
                'original_name' => $asset->getOriginalName(),
                'upload_endpoint' => $this->buildUrl($this->readConfig()['baseUrl'], sprintf(
                    '/api/_action/media/%s/upload?extension=%s&fileName=%s',
                    $mediaId,
                    $this->resolveAssetExtension($asset->getOriginalName(), $asset->getFilename()),
                    rawurlencode(pathinfo($asset->getOriginalName(), PATHINFO_FILENAME) ?: $asset->getFilename()),
                )),
            ];

            if ($coverId === null) {
                $coverId = $productMediaId;
            }
        }

        return [
            'product_media' => $productMedia,
            'cover_id' => $coverId,
            'uploads' => $uploads,
        ];
    }

    /**
     * @param array<string, mixed>|null $existingProduct
     *
     * @return array{
     *     product_media: list<array{id: string, mediaId: string, position: int}>,
     *     cover_id: ?string,
     *     uploads: list<array{media_id: string, product_media_id: string, original_name: string, upload_endpoint: string}>,
     *     removed_product_media_ids: list<string>
     * }
     */
    private function syncProductAssets(
        Product $product,
        string $accessToken,
        string $baseUrl,
        ?array $existingProduct = null,
    ): array {
        $preview = $this->buildAssetPreview($product);
        $desiredProductMediaIds = [];
        $removedProductMediaIds = [];

        foreach ($preview['product_media'] as $index => $productMedia) {
            $desiredProductMediaIds[] = $productMedia['id'];
            $upload = $preview['uploads'][$index] ?? null;
            if (!is_array($upload)) {
                continue;
            }

            $this->upsertMediaEntity($accessToken, $baseUrl, $upload['media_id']);
            $this->uploadAssetBinary($accessToken, $baseUrl, $upload['media_id'], $product, $productMedia['position']);
        }

        foreach ($this->resolveExistingProductMedia($existingProduct) as $existingProductMedia) {
            $existingId = trim((string) ($existingProductMedia['id'] ?? ''));
            if ($existingId === '' || in_array($existingId, $desiredProductMediaIds, true)) {
                continue;
            }

            $this->requestJson('DELETE', $baseUrl, '/api/product-media/'.$existingId, [
                'headers' => $this->authorizedHeaders($accessToken),
            ]);
            $removedProductMediaIds[] = $existingId;
        }

        if ($preview['product_media'] === []) {
            return [
                'product_media' => [],
                'cover_id' => null,
                'uploads' => [],
                'removed_product_media_ids' => $removedProductMediaIds,
            ];
        }

        $productMediaPayload = array_map(
            static fn (array $media): array => [
                'id' => $media['id'],
                'mediaId' => $media['mediaId'],
                'position' => $media['position'],
            ],
            $preview['product_media'],
        );

        return [
            'product_media' => $productMediaPayload,
            'cover_id' => $preview['cover_id'],
            'uploads' => $preview['uploads'],
            'removed_product_media_ids' => $removedProductMediaIds,
        ];
    }

    private function upsertMediaEntity(string $accessToken, string $baseUrl, string $mediaId): void
    {
        $existingMedia = $this->findOne($accessToken, $baseUrl, 'media', [
            ['type' => 'equals', 'field' => 'id', 'value' => $mediaId],
        ]);

        if ($existingMedia !== null) {
            $this->requestJson('PATCH', $baseUrl, '/api/media/'.$mediaId, [
                'headers' => $this->authorizedHeaders($accessToken),
                'json' => [
                    'private' => false,
                ],
            ]);

            return;
        }

        $this->requestJson('POST', $baseUrl, '/api/media', [
            'headers' => $this->authorizedHeaders($accessToken),
            'json' => [
                'id' => $mediaId,
                'private' => false,
            ],
        ]);
    }

    private function uploadAssetBinary(
        string $accessToken,
        string $baseUrl,
        string $mediaId,
        Product $product,
        int $position,
    ): void {
        $asset = $this->findImageAssetByPosition($product, $position);
        if ($asset === null) {
            return;
        }

        $absolutePath = rtrim($this->mediaStoragePath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$asset->getStoragePath();
        if (!is_file($absolutePath)) {
            throw new \RuntimeException(sprintf('Lokale Mediendatei "%s" wurde nicht gefunden.', $asset->getOriginalName()));
        }

        $binary = file_get_contents($absolutePath);
        if ($binary === false) {
            throw new \RuntimeException(sprintf('Lokale Mediendatei "%s" konnte nicht gelesen werden.', $asset->getOriginalName()));
        }

        $this->requestJson(
            'POST',
            $baseUrl,
            sprintf(
                '/api/_action/media/%s/upload?extension=%s&fileName=%s',
                $mediaId,
                $this->resolveAssetExtension($asset->getOriginalName(), $asset->getFilename()),
                rawurlencode(pathinfo($asset->getOriginalName(), PATHINFO_FILENAME) ?: pathinfo($asset->getFilename(), PATHINFO_FILENAME) ?: $mediaId),
            ),
            [
                'headers' => [
                    'Accept' => 'application/json',
                    'Authorization' => 'Bearer '.$accessToken,
                    'Content-Type' => $asset->getMimeType(),
                ],
                'body' => $binary,
            ],
        );

        return;

        $fileHandle = fopen($absolutePath, 'rb');
        if ($fileHandle === false) {
            throw new \RuntimeException(sprintf('Lokale Mediendatei "%s" konnte nicht geöffnet werden.', $asset->getOriginalName()));
        }

        try {
            $this->requestJson(
                'POST',
                $baseUrl,
                sprintf(
                    '/api/_action/media/%s/upload?extension=%s&fileName=%s',
                    $mediaId,
                    $this->resolveAssetExtension($asset->getOriginalName(), $asset->getFilename()),
                    rawurlencode(pathinfo($asset->getOriginalName(), PATHINFO_FILENAME) ?: pathinfo($asset->getFilename(), PATHINFO_FILENAME) ?: $mediaId),
                ),
                [
                    'headers' => [
                        'Accept' => 'application/json',
                        'Authorization' => 'Bearer '.$accessToken,
                        'Content-Type' => $asset->getMimeType(),
                    ],
                    'body' => $fileHandle,
                ],
            );
        } finally {
            fclose($fileHandle);
        }
    }

    /**
     * @param array<string, mixed>|null $existingProduct
     *
     * @return list<array<string, mixed>>
     */
    private function resolveExistingProductMedia(?array $existingProduct): array
    {
        if ($existingProduct === null) {
            return [];
        }

        $media = $existingProduct['media'] ?? [];

        return is_array($media) ? array_values(array_filter($media, 'is_array')) : [];
    }

    private function findImageAssetByPosition(Product $product, int $position): ?\App\Entity\ProductAsset
    {
        foreach ($product->getAssets() as $asset) {
            if (!str_starts_with($asset->getMimeType(), 'image/')) {
                continue;
            }

            $assetPosition = $asset->getPosition() > 0 ? $asset->getPosition() : 1;
            if ($assetPosition === $position) {
                return $asset;
            }
        }

        return null;
    }

    private function resolveAssetExtension(string $originalName, string $filename): string
    {
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if ($extension === '') {
            $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        }

        return $extension !== '' ? $extension : 'jpg';
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function requestJson(string $method, string $baseUrl, string $path, array $options): array
    {
        $response = $this->httpClient->request($method, $this->buildUrl($baseUrl, $path), $options);
        $statusCode = $response->getStatusCode();
        $content = $response->getContent(false);
        $decoded = $content !== '' ? json_decode($content, true) : [];

        if ($statusCode >= 400) {
            $message = $this->extractApiErrorMessage(
                is_array($decoded) ? $decoded : [],
                sprintf('HTTP %d bei %s %s.', $statusCode, $method, $path),
            );
            throw new \RuntimeException($message);
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
            'Authorization' => 'Bearer '.$accessToken,
        ];
    }

    private function buildProductNumber(Product $product): string
    {
        return sprintf('CTC-%s', strtoupper((string) $product->getPublicId()));
    }

    private function resolveStock(Product $product): int
    {
        $stock = null;

        foreach ($product->getVariants() as $variant) {
            $variantStock = $variant->getStock();
            if ($variantStock !== null) {
                $stock = ($stock ?? 0) + $variantStock;
            }
        }

        return max(0, $stock ?? 0);
    }

    private function resolveGrossPrice(Product $product): float
    {
        foreach ($product->getVariants() as $variant) {
            $priceGross = $variant->getPriceGross();
            if ($priceGross !== null && is_numeric($priceGross)) {
                return round((float) $priceGross, 2);
            }
        }

        return 0.0;
    }

    private function calculateNetPrice(float $grossPrice, float $taxRate): float
    {
        if ($taxRate <= 0) {
            return round($grossPrice, 2);
        }

        return round($grossPrice / (1 + ($taxRate / 100)), 2);
    }

    private function buildDescriptionHtml(ListingDraft $draft): string
    {
        $sections = [];
        $description = trim($draft->description);

        if ($description !== '') {
            $paragraphs = preg_split('/\R{2,}/u', $description) ?: [$description];
            $escapedParagraphs = array_map(
                static fn (string $paragraph): string => '<p>'.nl2br(htmlspecialchars(trim($paragraph), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')).'</p>',
                array_filter($paragraphs, static fn (string $paragraph): bool => trim($paragraph) !== ''),
            );
            $sections[] = implode('', $escapedParagraphs);
        }

        if ($draft->bulletPoints !== []) {
            $bulletMarkup = array_map(
                static fn (string $bulletPoint): string => '<li>'.htmlspecialchars($bulletPoint, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</li>',
                $draft->bulletPoints,
            );
            $sections[] = '<ul>'.implode('', $bulletMarkup).'</ul>';
        }

        return implode('', $sections);
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
        $errors = $payload['errors'] ?? null;
        if (is_array($errors) && isset($errors[0]) && is_array($errors[0])) {
            $detail = trim((string) ($errors[0]['detail'] ?? $errors[0]['title'] ?? ''));
            if ($detail !== '') {
                return $detail;
            }
        }

        $message = trim((string) ($payload['message'] ?? ''));

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

    private function stableHexId(string $scope, Product $product, int $position): string
    {
        return md5(sprintf('%s:%s:%d', $scope, (string) $product->getPublicId(), $position));
    }

    private function hexId(): string
    {
        return bin2hex(random_bytes(16));
    }
}
