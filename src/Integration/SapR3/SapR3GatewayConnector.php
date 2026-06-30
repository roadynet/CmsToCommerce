<?php

declare(strict_types=1);

namespace App\Integration\SapR3;

use App\Dto\ExternalWritebackResult;
use App\Entity\Product;
use App\Entity\ProductSource;
use App\Entity\ProductVariant;
use App\Enum\ExternalSystemType;
use App\Enum\SyncStatus;
use App\Service\Configuration\ServerSecretResolver;
use App\Service\Integration\ExternalSystemWritebackPublisher;
use App\Service\Integration\SapR3WritebackPreviewBuilder;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

final class SapR3GatewayConnector implements ExternalSystemWritebackPublisher
{
    private const DEFAULT_SECRETS_FILE = 'ctc-sap-r3.env';
    private const FALLBACK_MIXED_SECRETS_FILE = 'ctc.env';

    public function __construct(
        private readonly SapR3WritebackPreviewBuilder $sapR3WritebackPreviewBuilder,
        private readonly string $sapR3GatewayUrl = '',
        private readonly string $sapR3WritebackPath = '/ctc/material/writeback',
        private readonly string $sapR3Client = '',
        private readonly string $sapR3Username = '',
        private readonly string $sapR3Password = '',
        private readonly string $sapR3SystemId = '',
        private readonly string $sapR3Language = 'DE',
        private readonly bool $sapR3EnableLiveWriteback = false,
        private readonly ?HttpClientInterface $httpClient = null,
        private readonly string $appExternalSecretsFile = '',
    ) {
    }

    public function system(): ExternalSystemType
    {
        return ExternalSystemType::SapR3;
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
        $preview = $this->sapR3WritebackPreviewBuilder->build($product);
        $target = $this->targetMaterialHints($product);

        return [
            'basis_url' => $config['gatewayUrl'],
            'writeback_endpoint' => $this->buildUrl($config['gatewayUrl'], $config['writebackPath']),
            'sap_mandant' => $config['client'] !== '' ? $config['client'] : null,
            'sap_system_id' => $config['systemId'] !== '' ? $config['systemId'] : null,
            'sprache' => $config['language'],
            'live_writeback_aktiv' => $config['liveWritebackEnabled'],
            'schnittstelle_bereit' => $missing === [],
            'konfigurationsluecken' => $missing,
            'zielmaterial_hinweise' => $target,
            'request_payload' => [
                'source' => 'ctc',
                'target_system' => 'sap_r3',
                'client' => $config['client'] !== '' ? $config['client'] : null,
                'system_id' => $config['systemId'] !== '' ? $config['systemId'] : null,
                'language' => $config['language'],
                'material_number' => $target['direkte_sap_referenz'] ?: $target['primaere_sku'] ?: $preview['payload']['materialnummer'],
                'writeback_mode' => 'idoc_or_bapi_proxy',
                'idoc' => $preview['payload']['idoc'],
                'bapi' => $preview['payload']['bapi'],
                'ctc_listing' => $preview['ctc_listing'],
            ],
        ];
    }

    public function publish(Product $product): ExternalWritebackResult
    {
        $payloadPreview = $this->buildPayload($product);

        if (!$this->isConfigured()) {
            return new ExternalWritebackResult(
                ExternalSystemType::SapR3,
                SyncStatus::Failed,
                'SAP-R/3-Write-back ist noch nicht vollständig konfiguriert. CTC hat das Gateway-/IDoc-/BAPI-Payload vorbereitet, aber Live-Senden bleibt gesperrt.',
                $payloadPreview,
            );
        }

        if (!$this->readConfig()['liveWritebackEnabled']) {
            return new ExternalWritebackResult(
                ExternalSystemType::SapR3,
                SyncStatus::Failed,
                'SAP-R/3-Live-Write-back ist aktuell deaktiviert. Setze SAP_R3_ENABLE_LIVE_WRITEBACK=1, wenn der echte Gateway-Proxy senden darf.',
                $payloadPreview,
            );
        }

        try {
            $config = $this->readConfig(true);
            $responsePayload = $this->requestJson('POST', $this->buildUrl($config['gatewayUrl'], $config['writebackPath']), [
                'headers' => $this->authorizedHeaders($config),
                'json' => $payloadPreview['request_payload'],
            ]);

            $externalId = $this->stringValue(
                $responsePayload['material_number'] ?? null,
                $responsePayload['MATNR'] ?? null,
                $payloadPreview['request_payload']['material_number'] ?? null,
            );

            return new ExternalWritebackResult(
                ExternalSystemType::SapR3,
                SyncStatus::Succeeded,
                sprintf('SAP-R/3-Gateway hat den CTC-Write-back für Material %s angenommen.', $externalId ?: 'ohne Materialnummer'),
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
                ExternalSystemType::SapR3,
                SyncStatus::Failed,
                'SAP-R/3-Write-back fehlgeschlagen: '.$exception->getMessage(),
                $payloadPreview,
            );
        }
    }

    /**
     * @return array{
     *     direkte_sap_referenz: ?string,
     *     primaere_sku: ?string,
     *     suchkandidaten: list<array{typ: string, wert: string}>
     * }
     */
    private function targetMaterialHints(Product $product): array
    {
        $candidates = [];
        $primarySku = null;

        foreach ($product->getVariants() as $variant) {
            if (!$variant instanceof ProductVariant) {
                continue;
            }

            $sku = trim($variant->getSku());
            if ($sku !== '') {
                $primarySku ??= $sku;
                $candidates[] = ['typ' => 'matnr_sku', 'wert' => $sku];
            }

            $ean = trim((string) $variant->getEan());
            if ($ean !== '') {
                $candidates[] = ['typ' => 'ean11', 'wert' => $ean];
            }
        }

        return [
            'direkte_sap_referenz' => $this->directSapReference($product),
            'primaere_sku' => $primarySku,
            'suchkandidaten' => $this->uniqueCandidates($candidates),
        ];
    }

    private function directSapReference(Product $product): ?string
    {
        foreach ($product->getSources() as $source) {
            if (!$source instanceof ProductSource) {
                continue;
            }

            $cmsSystem = strtolower(trim((string) $source->getCmsSystem()));
            $reference = trim((string) $source->getExternalReference());

            if ($reference !== '' && in_array($cmsSystem, ['sap_r3', 'sap-r3', 'sap', 'r3'], true)) {
                return $reference;
            }
        }

        return null;
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

    /**
     * @return array{
     *     gatewayUrl: string,
     *     writebackPath: string,
     *     client: string,
     *     username: string,
     *     password: string,
     *     systemId: string,
     *     language: string,
     *     liveWritebackEnabled: bool
     * }
     */
    private function readConfig(bool $strict = false): array
    {
        $external = $this->readRuntimeSecrets($strict);

        return [
            'gatewayUrl' => rtrim($this->firstNonEmpty(
                $external['SAP_R3_GATEWAY_URL'] ?? null,
                $external['SAP_GATEWAY_URL'] ?? null,
                $this->sapR3GatewayUrl,
            ), '/'),
            'writebackPath' => $this->normalizePath($this->firstNonEmpty(
                $external['SAP_R3_WRITEBACK_PATH'] ?? null,
                $this->sapR3WritebackPath,
                '/ctc/material/writeback',
            )),
            'client' => $this->firstNonEmpty(
                $external['SAP_R3_CLIENT'] ?? null,
                $external['SAP_CLIENT'] ?? null,
                $this->sapR3Client,
            ),
            'username' => $this->firstNonEmpty(
                $external['SAP_R3_USERNAME'] ?? null,
                $external['SAP_USERNAME'] ?? null,
                $this->sapR3Username,
            ),
            'password' => $this->firstNonEmpty(
                $external['SAP_R3_PASSWORD'] ?? null,
                $external['SAP_PASSWORD'] ?? null,
                $this->sapR3Password,
            ),
            'systemId' => $this->firstNonEmpty(
                $external['SAP_R3_SYSTEM_ID'] ?? null,
                $external['SAP_SYSTEM_ID'] ?? null,
                $this->sapR3SystemId,
            ),
            'language' => strtoupper($this->firstNonEmpty(
                $external['SAP_R3_LANGUAGE'] ?? null,
                $this->sapR3Language,
                'DE',
            )),
            'liveWritebackEnabled' => $this->resolveBoolean(
                $external['SAP_R3_ENABLE_LIVE_WRITEBACK'] ?? null,
                $this->sapR3EnableLiveWriteback,
            ),
        ];
    }

    /**
     * @param array{
     *     gatewayUrl: string,
     *     writebackPath: string,
     *     client: string,
     *     username: string,
     *     password: string,
     *     systemId: string,
     *     language: string,
     *     liveWritebackEnabled: bool
     * } $config
     *
     * @return list<string>
     */
    private function missingConfigFields(array $config): array
    {
        $missing = [];

        if ($config['gatewayUrl'] === '') {
            $missing[] = 'SAP_R3_GATEWAY_URL';
        }

        if ($config['client'] === '') {
            $missing[] = 'SAP_R3_CLIENT';
        }

        if ($config['username'] === '') {
            $missing[] = 'SAP_R3_USERNAME';
        }

        if ($config['password'] === '') {
            $missing[] = 'SAP_R3_PASSWORD';
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
            'SAP R/3',
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
     * @param array{
     *     client: string,
     *     username: string,
     *     password: string,
     *     systemId: string
     * } $config
     *
     * @return array<string, string>
     */
    private function authorizedHeaders(array $config): array
    {
        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'Authorization' => 'Basic '.base64_encode($config['username'].':'.$config['password']),
        ];

        if ($config['client'] !== '') {
            $headers['X-SAP-Client'] = $config['client'];
        }

        if ($config['systemId'] !== '') {
            $headers['X-SAP-System'] = $config['systemId'];
        }

        return $headers;
    }

    private function buildUrl(string $baseUrl, string $path): string
    {
        $baseUrl = rtrim(trim($baseUrl), '/');

        return $baseUrl === '' ? '' : $baseUrl.'/'.ltrim($path, '/');
    }

    private function normalizePath(string $path): string
    {
        $path = trim($path);

        return $path === '' ? '/ctc/material/writeback' : '/'.ltrim($path, '/');
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
