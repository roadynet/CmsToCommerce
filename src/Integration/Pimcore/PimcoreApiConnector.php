<?php

declare(strict_types=1);

namespace App\Integration\Pimcore;

use App\Dto\ExternalWritebackResult;
use App\Entity\Product;
use App\Entity\ProductSource;
use App\Entity\ProductVariant;
use App\Enum\ExternalSystemType;
use App\Enum\SyncStatus;
use App\Service\Configuration\ServerSecretResolver;
use App\Service\Integration\ExternalSystemWritebackPublisher;
use App\Service\Integration\PimcoreWritebackPreviewBuilder;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

final class PimcoreApiConnector implements ExternalSystemWritebackPublisher
{
    private const DEFAULT_SECRETS_FILE = 'ctc-pimcore.env';
    private const FALLBACK_MIXED_SECRETS_FILE = 'ctc.env';

    public function __construct(
        private readonly PimcoreWritebackPreviewBuilder $pimcoreWritebackPreviewBuilder,
        private readonly string $pimcoreBaseUrl = '',
        private readonly string $pimcoreApiToken = '',
        private readonly string $pimcoreWritebackPath = '/ctc/object/writeback',
        private readonly string $pimcoreDefaultLanguage = 'de',
        private readonly bool $pimcoreEnableLiveWriteback = false,
        private readonly ?HttpClientInterface $httpClient = null,
        private readonly string $appExternalSecretsFile = '',
    ) {
    }

    public function system(): ExternalSystemType
    {
        return ExternalSystemType::Pimcore;
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
        $preview = $this->pimcoreWritebackPreviewBuilder->build($product);
        $target = $this->targetObjectHints($product);

        return [
            'basis_url' => $config['baseUrl'],
            'writeback_endpoint' => $this->buildUrl($config['baseUrl'], $config['writebackPath']),
            'sprache' => $config['defaultLanguage'],
            'live_writeback_aktiv' => $config['liveWritebackEnabled'],
            'schnittstelle_bereit' => $missing === [],
            'konfigurationsluecken' => $missing,
            'zielobjekt_hinweise' => $target,
            'request_payload' => [
                'source' => 'ctc',
                'target_system' => 'pimcore',
                'language' => $config['defaultLanguage'],
                'object' => [
                    'id' => $target['direkte_pimcore_referenz'] ?: $preview['payload']['object']['id'],
                    'key' => $target['object_key'] ?: $preview['payload']['object']['key'],
                    'className' => $target['class_name'] ?: $preview['payload']['object']['className'],
                ],
                'data' => $preview['payload']['data'],
                'localizedfields' => $preview['payload']['localizedfields'],
                'workflow' => [
                    ...$preview['payload']['workflow'],
                    'live_writeback' => $config['liveWritebackEnabled'],
                ],
                'ctc_listing' => $preview['ctc_listing'],
            ],
        ];
    }

    public function publish(Product $product): ExternalWritebackResult
    {
        $payloadPreview = $this->buildPayload($product);

        if (!$this->isConfigured()) {
            return new ExternalWritebackResult(
                ExternalSystemType::Pimcore,
                SyncStatus::Failed,
                'Pimcore-Write-back ist noch nicht vollständig konfiguriert. CTC hat das Objekt-Payload vorbereitet, aber Live-Senden bleibt gesperrt.',
                $payloadPreview,
            );
        }

        if (!$this->readConfig()['liveWritebackEnabled']) {
            return new ExternalWritebackResult(
                ExternalSystemType::Pimcore,
                SyncStatus::Failed,
                'Pimcore-Live-Write-back ist aktuell deaktiviert. Setze PIMCORE_ENABLE_LIVE_WRITEBACK=1, wenn der echte Pimcore-Endpunkt schreiben darf.',
                $payloadPreview,
            );
        }

        try {
            $config = $this->readConfig(true);
            $responsePayload = $this->requestJson('POST', $this->buildUrl($config['baseUrl'], $config['writebackPath']), [
                'headers' => $this->authorizedHeaders($config),
                'json' => $payloadPreview['request_payload'],
            ]);

            $externalId = $this->stringValue(
                $responsePayload['object_id'] ?? null,
                $responsePayload['id'] ?? null,
                $payloadPreview['request_payload']['object']['id'] ?? null,
            );

            return new ExternalWritebackResult(
                ExternalSystemType::Pimcore,
                SyncStatus::Succeeded,
                sprintf('Pimcore-Endpunkt hat den CTC-Write-back für Objekt %s angenommen.', $externalId ?: 'ohne Objekt-ID'),
                [
                    ...$payloadPreview,
                    'api_methode' => 'POST',
                    'api_pfad' => $config['writebackPath'],
                    'response_payload' => $responsePayload,
                ],
                $externalId,
            );
        } catch (Throwable $exception) {
            return new ExternalWritebackResult(
                ExternalSystemType::Pimcore,
                SyncStatus::Failed,
                'Pimcore-Write-back fehlgeschlagen: '.$exception->getMessage(),
                $payloadPreview,
            );
        }
    }

    /**
     * @return array{
     *     direkte_pimcore_referenz: ?string,
     *     object_key: ?string,
     *     class_name: ?string,
     *     suchkandidaten: list<array{typ: string, wert: string}>
     * }
     */
    private function targetObjectHints(Product $product): array
    {
        $directReference = null;
        $objectKey = null;
        $className = null;

        foreach ($product->getSources() as $source) {
            if (!$source instanceof ProductSource) {
                continue;
            }

            $cmsSystem = strtolower(trim((string) $source->getCmsSystem()));
            if (!in_array($cmsSystem, ['pimcore', 'pim'], true)) {
                continue;
            }

            $directReference = $this->stringValue($source->getExternalReference());
            $payload = $this->decodeEmbeddedPayload($source->getRawPayload());
            $originalPayload = is_array($payload['original_payload'] ?? null) ? $payload['original_payload'] : $payload;
            $object = is_array($originalPayload['object'] ?? null) ? $originalPayload['object'] : $originalPayload;
            $objectKey = $this->stringValue($object['key'] ?? null, $object['o_key'] ?? null);
            $className = $this->stringValue($object['className'] ?? null, $object['o_className'] ?? null);
        }

        $candidates = [];
        foreach ($product->getVariants() as $variant) {
            if (!$variant instanceof ProductVariant) {
                continue;
            }

            $sku = trim($variant->getSku());
            if ($sku !== '') {
                $candidates[] = ['typ' => 'sku', 'wert' => $sku];
            }
        }

        if ($product->getName() !== '') {
            $candidates[] = ['typ' => 'name', 'wert' => $product->getName()];
        }

        return [
            'direkte_pimcore_referenz' => $directReference,
            'object_key' => $objectKey,
            'class_name' => $className,
            'suchkandidaten' => $this->uniqueCandidates($candidates),
        ];
    }

    /**
     * @return array{
     *     baseUrl: string,
     *     apiToken: string,
     *     writebackPath: string,
     *     defaultLanguage: string,
     *     liveWritebackEnabled: bool
     * }
     */
    private function readConfig(bool $strict = false): array
    {
        $external = $this->readRuntimeSecrets($strict);

        return [
            'baseUrl' => rtrim($this->firstNonEmpty(
                $external['PIMCORE_BASE_URL'] ?? null,
                $external['PIMCORE_API_BASE_URL'] ?? null,
                $this->pimcoreBaseUrl,
            ), '/'),
            'apiToken' => $this->firstNonEmpty(
                $external['PIMCORE_API_TOKEN'] ?? null,
                $external['PIMCORE_ACCESS_TOKEN'] ?? null,
                $this->pimcoreApiToken,
            ),
            'writebackPath' => $this->normalizePath($this->firstNonEmpty(
                $external['PIMCORE_WRITEBACK_PATH'] ?? null,
                $this->pimcoreWritebackPath,
                '/ctc/object/writeback',
            )),
            'defaultLanguage' => strtolower($this->firstNonEmpty(
                $external['PIMCORE_DEFAULT_LANGUAGE'] ?? null,
                $this->pimcoreDefaultLanguage,
                'de',
            )),
            'liveWritebackEnabled' => $this->resolveBoolean(
                $external['PIMCORE_ENABLE_LIVE_WRITEBACK'] ?? null,
                $this->pimcoreEnableLiveWriteback,
            ),
        ];
    }

    /**
     * @param array{baseUrl: string, apiToken: string, writebackPath: string, defaultLanguage: string, liveWritebackEnabled: bool} $config
     *
     * @return list<string>
     */
    private function missingConfigFields(array $config): array
    {
        $missing = [];

        if ($config['baseUrl'] === '') {
            $missing[] = 'PIMCORE_BASE_URL';
        }

        if ($config['apiToken'] === '') {
            $missing[] = 'PIMCORE_API_TOKEN';
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
            'Pimcore',
        );
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
     * @param array{apiToken: string} $config
     *
     * @return array<string, string>
     */
    private function authorizedHeaders(array $config): array
    {
        return [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer '.$config['apiToken'],
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

    private function buildUrl(string $baseUrl, string $path): string
    {
        $baseUrl = rtrim(trim($baseUrl), '/');

        return $baseUrl === '' ? '' : $baseUrl.'/'.ltrim($path, '/');
    }

    private function normalizePath(string $path): string
    {
        $path = trim($path);

        return $path === '' ? '/ctc/object/writeback' : '/'.ltrim($path, '/');
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
