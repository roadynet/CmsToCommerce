<?php

declare(strict_types=1);

namespace App\Integration\Plentymarkets;

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

final class PlentymarketsRestApiConnector implements ExternalSystemWritebackPublisher
{
    private const DEFAULT_SECRETS_FILE = 'ctc-plentymarkets.env';
    private const FALLBACK_MIXED_SECRETS_FILE = 'ctc.env';

    public function __construct(
        private readonly ProductListingDraftBuilder $productListingDraftBuilder,
        private readonly string $plentyBaseUrl = '',
        private readonly string $plentyUsername = '',
        private readonly string $plentyPassword = '',
        private readonly string $plentyDefaultLang = 'de',
        private readonly bool $plentyEnableLiveWriteback = false,
        private readonly ?HttpClientInterface $httpClient = null,
        private readonly string $appExternalSecretsFile = '',
    ) {
    }

    public function system(): ExternalSystemType
    {
        return ExternalSystemType::Plentymarkets;
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
        $targetHints = $this->targetVariationHints($product);
        $lang = $this->resolveLanguageCode($product, $config['defaultLang']);

        return [
            'basis_url' => $config['baseUrl'],
            'login_endpoint' => $this->buildUrl($config['baseUrl'], '/rest/login'),
            'varianten_suche_endpoint' => $this->buildUrl($config['baseUrl'], '/rest/items/variations'),
            'beschreibung_aendern_endpoint' => $this->buildUrl($config['baseUrl'], '/rest/items/{itemId}/variations/{variationId}/descriptions/{lang}'),
            'beschreibung_anlegen_endpoint' => $this->buildUrl($config['baseUrl'], '/rest/items/{itemId}/variations/{variationId}/descriptions'),
            'sprache' => $lang,
            'live_writeback_aktiv' => $config['liveWritebackEnabled'],
            'schnittstelle_bereit' => $missing === [],
            'konfigurationsluecken' => $missing,
            'zielartikel_hinweise' => $targetHints,
            'request_payload' => $this->buildDescriptionPayload($product, $lang),
        ];
    }

    public function publish(Product $product): ExternalWritebackResult
    {
        $payloadPreview = $this->buildPayload($product);

        if (!$this->isConfigured()) {
            return new ExternalWritebackResult(
                ExternalSystemType::Plentymarkets,
                SyncStatus::Failed,
                'plentymarkets-Write-back ist noch nicht vollstandig konfiguriert. CTC hat das Ziel-Payload vorbereitet, aber Live-Senden bleibt gesperrt.',
                $payloadPreview,
            );
        }

        if (!$this->readConfig()['liveWritebackEnabled']) {
            return new ExternalWritebackResult(
                ExternalSystemType::Plentymarkets,
                SyncStatus::Failed,
                'plentymarkets-Live-Write-back ist aktuell deaktiviert. Setze PLENTY_ENABLE_LIVE_WRITEBACK=1, wenn du den echten Ruckschreibmodus aktivieren willst.',
                $payloadPreview,
            );
        }

        try {
            $config = $this->readConfig(true);
            $accessToken = $this->fetchAccessToken($config);
            $target = $this->resolveTargetVariation($product, $accessToken, $config);

            if (($target['item_id'] ?? null) === null || ($target['variation_id'] ?? null) === null) {
                return new ExternalWritebackResult(
                    ExternalSystemType::Plentymarkets,
                    SyncStatus::Failed,
                    'CTC konnte keinen eindeutigen plentymarkets-Zielartikel finden. Bitte itemId und variationId im Import-Payload hinterlegen oder SKU/EAN im Produkt prufen.',
                    [
                        ...$payloadPreview,
                        'zielartikel_aufloesung' => $target,
                    ],
                );
            }

            $lang = (string) $payloadPreview['sprache'];
            $requestPayload = $payloadPreview['request_payload'];
            $method = 'PUT';
            $path = sprintf(
                '/rest/items/%s/variations/%s/descriptions/%s',
                $target['item_id'],
                $target['variation_id'],
                rawurlencode($lang),
            );

            try {
                $responsePayload = $this->requestJson($method, $config['baseUrl'], $path, [
                    'headers' => array_merge(
                        $this->authorizedHeaders($accessToken),
                        ['Content-Type' => 'application/json']
                    ),
                    'json' => $requestPayload,
                ]);
            } catch (\RuntimeException $exception) {
                if (!str_contains($exception->getMessage(), 'HTTP 404')) {
                    throw $exception;
                }

                $method = 'POST';
                $path = sprintf(
                    '/rest/items/%s/variations/%s/descriptions',
                    $target['item_id'],
                    $target['variation_id'],
                );
                $responsePayload = $this->requestJson($method, $config['baseUrl'], $path, [
                    'headers' => array_merge(
                        $this->authorizedHeaders($accessToken),
                        ['Content-Type' => 'application/json']
                    ),
                    'json' => $requestPayload,
                ]);
            }

            return new ExternalWritebackResult(
                ExternalSystemType::Plentymarkets,
                SyncStatus::Succeeded,
                sprintf(
                    'plentymarkets-Variation %s wurde mit den optimierten CTC-Texten aktualisiert.',
                    $target['variation_id'],
                ),
                [
                    ...$payloadPreview,
                    'zielartikel_aufloesung' => $target,
                    'api_methode' => $method,
                    'api_pfad' => $path,
                    'request_payload' => $requestPayload,
                    'response_payload' => $responsePayload,
                ],
                (string) $target['variation_id'],
            );
        } catch (Throwable $exception) {
            return new ExternalWritebackResult(
                ExternalSystemType::Plentymarkets,
                SyncStatus::Failed,
                'plentymarkets-Write-back fehlgeschlagen: '.$exception->getMessage(),
                $payloadPreview,
            );
        }
    }

    /**
     * @param array{
     *     baseUrl: string,
     *     username: string,
     *     password: string,
     *     defaultLang: string,
     *     liveWritebackEnabled: bool
     * } $config
     */
    private function fetchAccessToken(array $config): string
    {
        $response = $this->requestJson('POST', $config['baseUrl'], '/rest/login', [
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'username' => $config['username'],
                'password' => $config['password'],
            ],
        ]);

        $accessToken = trim((string) ($response['access_token'] ?? ''));
        if ($accessToken === '') {
            throw new \RuntimeException('plentymarkets Login-Token konnte nicht gelesen werden.');
        }

        return $accessToken;
    }

    /**
     * @param array{
     *     baseUrl: string,
     *     username: string,
     *     password: string,
     *     defaultLang: string,
     *     liveWritebackEnabled: bool
     * } $config
     *
     * @return array{
     *     item_id: ?string,
     *     variation_id: ?string,
     *     strategie: string,
     *     direkte_plenty_referenz: ?array{item_id: ?string, variation_id: ?string},
     *     suchversuche: list<array<string, mixed>>
     * }
     */
    private function resolveTargetVariation(Product $product, string $accessToken, array $config): array
    {
        $directReference = $this->directPlentyReference($product);
        if (($directReference['item_id'] ?? null) !== null && ($directReference['variation_id'] ?? null) !== null) {
            return [
                'item_id' => $directReference['item_id'],
                'variation_id' => $directReference['variation_id'],
                'strategie' => 'direkte_plenty_referenz',
                'direkte_plenty_referenz' => $directReference,
                'suchversuche' => [],
            ];
        }

        $attempts = [];
        if (($directReference['variation_id'] ?? null) !== null) {
            $resolved = $this->searchVariation($accessToken, $config, [
                'typ' => 'variation_id',
                'wert' => (string) $directReference['variation_id'],
                'query' => ['id' => (string) $directReference['variation_id']],
            ], $attempts);

            if ($resolved !== null) {
                return [
                    'item_id' => $resolved['item_id'],
                    'variation_id' => $resolved['variation_id'],
                    'strategie' => 'variation_id_suche',
                    'direkte_plenty_referenz' => $directReference,
                    'suchversuche' => $attempts,
                ];
            }
        }

        foreach ($this->lookupCandidates($product) as $candidate) {
            $resolved = $this->searchVariation($accessToken, $config, $candidate, $attempts);

            if ($resolved !== null) {
                return [
                    'item_id' => $resolved['item_id'],
                    'variation_id' => $resolved['variation_id'],
                    'strategie' => 'varianten_suche',
                    'direkte_plenty_referenz' => $directReference,
                    'suchversuche' => $attempts,
                ];
            }
        }

        return [
            'item_id' => null,
            'variation_id' => null,
            'strategie' => 'nicht_gefunden',
            'direkte_plenty_referenz' => $directReference,
            'suchversuche' => $attempts,
        ];
    }

    /**
     * @param array{
     *     baseUrl: string,
     *     username: string,
     *     password: string,
     *     defaultLang: string,
     *     liveWritebackEnabled: bool
     * } $config
     * @param array{typ: string, wert: string, query: array<string, scalar>} $candidate
     * @param list<array<string, mixed>> $attempts
     *
     * @return array{item_id: string, variation_id: string}|null
     */
    private function searchVariation(string $accessToken, array $config, array $candidate, array &$attempts): ?array
    {
        $query = [
            ...$candidate['query'],
            'with' => 'variationBarcodes',
            'page' => 1,
            'itemsPerPage' => 10,
        ];
        $response = $this->requestJson('GET', $config['baseUrl'], '/rest/items/variations', [
            'headers' => $this->authorizedHeaders($accessToken),
            'query' => $query,
        ]);

        $entries = $this->extractEntries($response);
        $matched = $this->matchVariationCandidate($entries, $candidate['wert']);
        $attempts[] = [
            'typ' => $candidate['typ'],
            'wert' => $candidate['wert'],
            'treffer' => count($entries),
            'gematchte_item_id' => $matched['itemId'] ?? $matched['item_id'] ?? null,
            'gematchte_variation_id' => $matched['id'] ?? null,
        ];

        if (!is_array($matched)) {
            return null;
        }

        $itemId = $this->stringValue($matched['itemId'] ?? null, $matched['item_id'] ?? null, $matched['item']['id'] ?? null);
        $variationId = $this->stringValue($matched['id'] ?? null, $matched['variationId'] ?? null);

        if ($itemId === null || $variationId === null) {
            return null;
        }

        return [
            'item_id' => $itemId,
            'variation_id' => $variationId,
        ];
    }

    /**
     * @param list<array<string, mixed>> $entries
     *
     * @return array<string, mixed>|null
     */
    private function matchVariationCandidate(array $entries, string $candidate): ?array
    {
        $candidate = trim($candidate);
        if ($candidate === '') {
            return null;
        }

        foreach ($entries as $entry) {
            $values = array_filter([
                trim((string) ($entry['id'] ?? '')),
                trim((string) ($entry['variationId'] ?? '')),
                trim((string) ($entry['number'] ?? '')),
                trim((string) ($entry['model'] ?? '')),
                trim((string) ($entry['externalId'] ?? '')),
            ]);

            foreach ($this->variationBarcodes($entry) as $barcode) {
                $values[] = $barcode;
            }

            if (in_array($candidate, $values, true)) {
                return $entry;
            }
        }

        if (count($entries) === 1) {
            return $entries[0];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $entry
     *
     * @return list<string>
     */
    private function variationBarcodes(array $entry): array
    {
        $barcodes = [];
        foreach (['barcodes', 'variationBarcodes'] as $key) {
            $rows = is_array($entry[$key] ?? null) ? $entry[$key] : [];
            foreach (array_values(array_filter($rows, 'is_array')) as $row) {
                $barcode = $this->stringValue($row['code'] ?? null, $row['barcode'] ?? null, $row['name'] ?? null);
                if ($barcode !== null) {
                    $barcodes[] = $barcode;
                }
            }
        }

        return array_values(array_unique($barcodes));
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<array<string, mixed>>
     */
    private function extractEntries(array $payload): array
    {
        foreach (['entries', 'data', 'items', 'variations'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                return array_values(array_filter($payload[$key], 'is_array'));
            }
        }

        if (array_is_list($payload)) {
            return array_values(array_filter($payload, 'is_array'));
        }

        if (isset($payload['id']) || isset($payload['variationId'])) {
            return [$payload];
        }

        return [];
    }

    /**
     * @return list<array{typ: string, wert: string, query: array<string, scalar>}>
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
                ['typ' => 'sku', 'wert' => trim($variant->getSku()), 'query' => ['number' => trim($variant->getSku())]],
                ['typ' => 'ean', 'wert' => trim((string) $variant->getEan()), 'query' => ['barcode' => trim((string) $variant->getEan())]],
            ] as $candidate) {
                if ($candidate['wert'] === '' || isset($seen[$candidate['typ'].':'.$candidate['wert']])) {
                    continue;
                }

                $seen[$candidate['typ'].':'.$candidate['wert']] = true;
                $candidates[] = $candidate;
            }
        }

        return $candidates;
    }

    /**
     * @return array{
     *     direkte_plenty_referenz: ?array{item_id: ?string, variation_id: ?string},
     *     suchkandidaten: list<array{typ: string, wert: string, query: array<string, scalar>}>
     * }
     */
    private function targetVariationHints(Product $product): array
    {
        return [
            'direkte_plenty_referenz' => $this->directPlentyReference($product),
            'suchkandidaten' => $this->lookupCandidates($product),
        ];
    }

    /**
     * @return array{item_id: ?string, variation_id: ?string}|null
     */
    private function directPlentyReference(Product $product): ?array
    {
        foreach ($product->getSources() as $source) {
            if (!$source instanceof ProductSource) {
                continue;
            }

            $cmsSystem = strtolower(trim((string) $source->getCmsSystem()));
            if (!in_array($cmsSystem, ['plentymarkets', 'plenty', 'plentyone'], true)) {
                continue;
            }

            $fromReference = $this->targetFromReference($source->getExternalReference());
            $payload = $this->decodeEmbeddedPayload($source->getRawPayload());
            $originalPayload = is_array($payload['original_payload'] ?? null) ? $payload['original_payload'] : $payload;

            $itemId = $this->stringValue(
                $fromReference['item_id'] ?? null,
                $this->pathValue($originalPayload, 'item.id', 'itemId', 'variation.itemId', 'variation.item.id'),
                $this->pathValue($payload, 'item_id', 'itemId'),
            );
            $variationId = $this->stringValue(
                $fromReference['variation_id'] ?? null,
                $this->pathValue($originalPayload, 'variation.id', 'variationId'),
                $this->pathValue($payload, 'variation_id', 'variationId'),
            );

            if ($itemId !== null || $variationId !== null) {
                return [
                    'item_id' => $itemId,
                    'variation_id' => $variationId,
                ];
            }
        }

        return null;
    }

    /**
     * @return array{item_id: ?string, variation_id: ?string}
     */
    private function targetFromReference(?string $reference): array
    {
        $reference = trim((string) $reference);
        if ($reference === '') {
            return ['item_id' => null, 'variation_id' => null];
        }

        if (str_contains($reference, ':')) {
            [$itemId, $variationId] = array_map('trim', explode(':', $reference, 2));

            return [
                'item_id' => $itemId !== '' ? $itemId : null,
                'variation_id' => $variationId !== '' ? $variationId : null,
            ];
        }

        if (str_contains($reference, '/')) {
            [$itemId, $variationId] = array_map('trim', explode('/', $reference, 2));

            return [
                'item_id' => $itemId !== '' ? $itemId : null,
                'variation_id' => $variationId !== '' ? $variationId : null,
            ];
        }

        return [
            'item_id' => null,
            'variation_id' => $reference,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeEmbeddedPayload(string $rawPayload): array
    {
        $rawPayload = trim($rawPayload);
        if ($rawPayload === '') {
            return [];
        }

        $candidates = [$rawPayload];
        $jsonStart = strrpos($rawPayload, "\n\n{");
        if ($jsonStart !== false) {
            $candidates[] = trim(substr($rawPayload, $jsonStart));
        }

        $firstBrace = strpos($rawPayload, '{');
        if ($firstBrace !== false) {
            $candidates[] = trim(substr($rawPayload, $firstBrace));
        }

        foreach ($candidates as $candidate) {
            try {
                $decoded = json_decode($candidate, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    private function resolveLanguageCode(Product $product, string $fallback): string
    {
        foreach ($product->getSources() as $source) {
            if (!$source instanceof ProductSource) {
                continue;
            }

            $language = strtolower(trim($source->getLanguageCode()));
            if ($language !== '') {
                return substr($language, 0, 2);
            }
        }

        $fallback = strtolower(trim($fallback));

        return $fallback !== '' ? substr($fallback, 0, 2) : 'de';
    }

    /**
     * @return array<string, mixed>
     */
    private function buildDescriptionPayload(Product $product, string $lang): array
    {
        $draft = $this->productListingDraftBuilder->build($product, ChannelType::Amazon);

        return [
            'lang' => $lang,
            'name' => $draft->title,
            'title' => $draft->title,
            'previewDescription' => $this->shortText($draft->bulletPoints),
            'description' => $draft->description,
            'technicalData' => $this->buildTechnicalData($draft->technicalAttributes),
            'metaDescription' => $this->buildMetaDescription($draft->description, $draft->bulletPoints),
            'metaKeywords' => implode(', ', $draft->searchTerms),
        ];
    }

    /**
     * @param array<string, scalar|array|null> $technicalAttributes
     */
    private function buildTechnicalData(array $technicalAttributes): string
    {
        $lines = [];
        foreach ($technicalAttributes as $key => $value) {
            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            $label = ucwords(str_replace('_', ' ', (string) $key));
            $text = is_array($value) ? implode(', ', array_map(static fn (mixed $entry): string => trim((string) $entry), $value)) : trim((string) $value);
            if ($text === '') {
                continue;
            }

            $lines[] = sprintf('%s: %s', $label, $text);
        }

        return implode("\n", $lines);
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

    /**
     * @return array{
     *     baseUrl: string,
     *     username: string,
     *     password: string,
     *     defaultLang: string,
     *     liveWritebackEnabled: bool
     * }
     */
    private function readConfig(bool $strict = false): array
    {
        $external = $this->readRuntimeSecrets($strict);

        return [
            'baseUrl' => $this->normalizeBaseUrl($this->firstNonEmpty(
                $external['PLENTY_BASE_URL'] ?? null,
                $external['PLENTY_REST_BASE_URL'] ?? null,
                $external['PLENTY_SYSTEM_URL'] ?? null,
                $this->plentyBaseUrl,
            )),
            'username' => $this->firstNonEmpty(
                $external['PLENTY_USERNAME'] ?? null,
                $external['PLENTY_USER'] ?? null,
                $this->plentyUsername,
            ),
            'password' => $this->firstNonEmpty(
                $external['PLENTY_PASSWORD'] ?? null,
                $this->plentyPassword,
            ),
            'defaultLang' => $this->firstNonEmpty(
                $external['PLENTY_DEFAULT_LANG'] ?? null,
                $this->plentyDefaultLang,
                'de',
            ),
            'liveWritebackEnabled' => $this->resolveBoolean(
                $external['PLENTY_ENABLE_LIVE_WRITEBACK'] ?? null,
                $this->plentyEnableLiveWriteback,
            ),
        ];
    }

    /**
     * @param array{
     *     baseUrl: string,
     *     username: string,
     *     password: string,
     *     defaultLang: string,
     *     liveWritebackEnabled: bool
     * } $config
     *
     * @return list<string>
     */
    private function missingConfigFields(array $config): array
    {
        $missing = [];

        if ($config['baseUrl'] === '') {
            $missing[] = 'PLENTY_BASE_URL';
        }

        if ($config['username'] === '') {
            $missing[] = 'PLENTY_USERNAME';
        }

        if ($config['password'] === '') {
            $missing[] = 'PLENTY_PASSWORD';
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
            'plentymarkets',
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
                throw new \RuntimeException('plentymarkets-Secrets-Datei ist nicht lesbar. Bitte Server-Konfiguration prufen.');
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
            'Authorization' => 'Bearer '.$accessToken,
        ];
    }

    private function buildUrl(string $baseUrl, string $path): string
    {
        $baseUrl = rtrim(trim($baseUrl), '/');

        return $baseUrl === '' ? '' : $baseUrl.'/'.ltrim($path, '/');
    }

    private function normalizeBaseUrl(string $baseUrl): string
    {
        $baseUrl = rtrim(trim($baseUrl), '/');
        if (str_ends_with($baseUrl, '/rest')) {
            return substr($baseUrl, 0, -5);
        }

        return $baseUrl;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function extractApiErrorMessage(array $payload, string $fallback): string
    {
        $message = trim((string) ($payload['message'] ?? $payload['error_description'] ?? $payload['error'] ?? ''));
        if ($message !== '') {
            return $message;
        }

        $errors = $payload['errors'] ?? null;
        if (is_array($errors) && isset($errors[0]) && is_array($errors[0])) {
            $message = trim((string) ($errors[0]['message'] ?? $errors[0]['detail'] ?? $errors[0]['title'] ?? ''));
            if ($message !== '') {
                return $message;
            }
        }

        return $fallback;
    }

    private function pathValue(array $payload, string ...$paths): mixed
    {
        foreach ($paths as $path) {
            $segments = explode('.', $path);
            $current = $payload;

            foreach ($segments as $segment) {
                if (!is_array($current) || !array_key_exists($segment, $current)) {
                    continue 2;
                }

                $current = $current[$segment];
            }

            return $current;
        }

        return null;
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
