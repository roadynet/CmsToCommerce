<?php

declare(strict_types=1);

namespace App\Service\Configuration;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class ChannelCredentialManager
{
    /**
     * @var array<string, array{
     *     label: string,
     *     badge: string,
     *     description: string,
     *     file: string,
     *     safety: string,
     *     fields: list<array{
     *         env: string,
     *         label: string,
     *         type: string,
     *         required?: bool,
     *         sensitive?: bool,
     *         placeholder?: string,
     *         help?: string,
     *         default?: string,
     *         options?: list<string>
     *     }>
     * }>
     */
    private const CHANNELS = [
        'shopware' => [
            'label' => 'Shopware',
            'badge' => 'Shop',
            'description' => 'Shopware Admin API für Produktanlage, Produktupdate, Medienupload und Sichtbarkeit.',
            'file' => 'ctc-shopware.env',
            'safety' => 'Live-Sync nutzt diese Daten erst beim echten Shopware-Export.',
            'fields' => [
                ['env' => 'SHOPWARE_ADMIN_BASE_URL', 'label' => 'Admin/API Basis-URL', 'type' => 'url', 'required' => true, 'placeholder' => 'https://sw.example.de'],
                ['env' => 'SHOPWARE_BASE_URL', 'label' => 'Storefront/Base URL optional', 'type' => 'url', 'placeholder' => 'https://sw.example.de'],
                ['env' => 'SHOPWARE_ADMIN_USERNAME', 'label' => 'Admin Benutzername', 'type' => 'text', 'required' => true],
                ['env' => 'SHOPWARE_ADMIN_PASSWORD', 'label' => 'Admin Passwort', 'type' => 'password', 'required' => true, 'sensitive' => true, 'help' => 'Leer lassen, um das bestehende Passwort zu behalten.'],
                ['env' => 'SHOPWARE_SYNC_CATEGORY', 'label' => 'Ziel-Kategorie', 'type' => 'text', 'default' => 'Amazon Imports'],
            ],
        ],
        'amazon' => [
            'label' => 'Amazon SP-API',
            'badge' => 'Amazon',
            'description' => 'Seller-/Marketplace-Zugang für Product-Type-Prüfung, Listing-Preview und späteres Live-Publishing.',
            'file' => 'ctc-amazon.env',
            'safety' => 'Live-Publishing bleibt deaktiviert, solange AMAZON_ENABLE_LIVE_PUBLISH nicht aktiv gesetzt wird.',
            'fields' => [
                ['env' => 'AMAZON_REGION', 'label' => 'Region', 'type' => 'select', 'default' => 'eu', 'options' => ['eu', 'na', 'fe'], 'required' => true],
                ['env' => 'AMAZON_SELLER_ID', 'label' => 'Seller ID', 'type' => 'text', 'required' => true],
                ['env' => 'AMAZON_MARKETPLACE_ID', 'label' => 'Marketplace ID', 'type' => 'text', 'required' => true, 'placeholder' => 'A1PA6795UKMFR9'],
                ['env' => 'AMAZON_APP_ID', 'label' => 'LWA Client/App ID', 'type' => 'text', 'required' => true],
                ['env' => 'AMAZON_CLIENT_SECRET', 'label' => 'LWA Client Secret', 'type' => 'password', 'required' => true, 'sensitive' => true, 'help' => 'Leer lassen, um den bestehenden Secret zu behalten.'],
                ['env' => 'AMAZON_REFRESH_TOKEN', 'label' => 'Refresh Token', 'type' => 'password', 'required' => true, 'sensitive' => true],
                ['env' => 'AMAZON_SP_API_BASE_URL', 'label' => 'SP-API Base URL optional', 'type' => 'url', 'placeholder' => 'https://sellingpartnerapi-eu.amazon.com'],
                ['env' => 'AMAZON_LWA_BASE_URL', 'label' => 'LWA Base URL optional', 'type' => 'url', 'placeholder' => 'https://api.amazon.com'],
                ['env' => 'AMAZON_ENABLE_LIVE_PUBLISH', 'label' => 'Live-Publishing erlauben', 'type' => 'checkbox', 'default' => '0'],
            ],
        ],
        'shopify' => [
            'label' => 'Shopify',
            'badge' => 'Shop',
            'description' => 'Shopify Admin GraphQL API für productUpdate, SEO-Felder und CTC-Metafelder.',
            'file' => 'ctc-shopify.env',
            'safety' => 'Live-Write-back bleibt deaktiviert, bis SHOPIFY_ENABLE_LIVE_WRITEBACK aktiv gesetzt wird.',
            'fields' => [
                ['env' => 'SHOPIFY_SHOP_DOMAIN', 'label' => 'Shop Domain', 'type' => 'text', 'required' => true, 'placeholder' => 'mein-shop.myshopify.com'],
                ['env' => 'SHOPIFY_ADMIN_ACCESS_TOKEN', 'label' => 'Admin Access Token', 'type' => 'password', 'required' => true, 'sensitive' => true, 'placeholder' => 'shpat_...'],
                ['env' => 'SHOPIFY_ADMIN_API_VERSION', 'label' => 'Admin API Version', 'type' => 'text', 'default' => '2026-04'],
                ['env' => 'SHOPIFY_ENABLE_LIVE_WRITEBACK', 'label' => 'Live-Write-back erlauben', 'type' => 'checkbox', 'default' => '0'],
            ],
        ],
        'jtl' => [
            'label' => 'JTL',
            'badge' => 'WaWi',
            'description' => 'JTL-ERP API für Produktabruf, Medienabruf, Delta-Sync und Write-back.',
            'file' => 'ctc-jtl.env',
            'safety' => 'Write-back bleibt deaktiviert, bis JTL_ENABLE_LIVE_WRITEBACK aktiv gesetzt wird.',
            'fields' => [
                ['env' => 'JTL_API_BASE_URL', 'label' => 'API Base URL', 'type' => 'url', 'default' => 'https://api.jtl-cloud.com/erp'],
                ['env' => 'JTL_AUTH_BASE_URL', 'label' => 'Auth Base URL', 'type' => 'url', 'default' => 'https://auth.jtl-cloud.com'],
                ['env' => 'JTL_TENANT_ID', 'label' => 'Tenant ID', 'type' => 'text', 'required' => true],
                ['env' => 'JTL_CLIENT_ID', 'label' => 'Client ID', 'type' => 'text', 'required' => true],
                ['env' => 'JTL_CLIENT_SECRET', 'label' => 'Client Secret', 'type' => 'password', 'required' => true, 'sensitive' => true],
                ['env' => 'JTL_RUNAS', 'label' => 'RunAs optional', 'type' => 'text'],
                ['env' => 'JTL_COMPANY_ID', 'label' => 'Company ID optional', 'type' => 'text'],
                ['env' => 'JTL_ENABLE_LIVE_WRITEBACK', 'label' => 'Live-Write-back erlauben', 'type' => 'checkbox', 'default' => '0'],
            ],
        ],
        'plentymarkets' => [
            'label' => 'plentymarkets',
            'badge' => 'WaWi',
            'description' => 'REST-Zugang für Artikel-/Variationstexte, Medien, Preise, Bestand und Write-back.',
            'file' => 'ctc-plentymarkets.env',
            'safety' => 'Write-back bleibt deaktiviert, bis PLENTY_ENABLE_LIVE_WRITEBACK aktiv gesetzt wird.',
            'fields' => [
                ['env' => 'PLENTY_BASE_URL', 'label' => 'REST/Base URL', 'type' => 'url', 'required' => true, 'placeholder' => 'https://deinshop.plentymarkets-cloud02.com'],
                ['env' => 'PLENTY_USERNAME', 'label' => 'Benutzername', 'type' => 'text', 'required' => true],
                ['env' => 'PLENTY_PASSWORD', 'label' => 'Passwort', 'type' => 'password', 'required' => true, 'sensitive' => true],
                ['env' => 'PLENTY_DEFAULT_LANG', 'label' => 'Standardsprache', 'type' => 'text', 'default' => 'de'],
                ['env' => 'PLENTY_ENABLE_LIVE_WRITEBACK', 'label' => 'Live-Write-back erlauben', 'type' => 'checkbox', 'default' => '0'],
            ],
        ],
        'xentral' => [
            'label' => 'Xentral',
            'badge' => 'ERP',
            'description' => 'Vorbereitete Zugangsdaten für späteren Xentral-Abruf, Delta-Sync und Write-back.',
            'file' => 'ctc-xentral.env',
            'safety' => 'Der echte Xentral-Live-Connector ist vorbereitet im Portal, aber noch nicht als produktiver Write-back aktiv.',
            'fields' => [
                ['env' => 'XENTRAL_BASE_URL', 'label' => 'Xentral Base URL', 'type' => 'url', 'required' => true, 'placeholder' => 'https://xentral.example.de'],
                ['env' => 'XENTRAL_API_TOKEN', 'label' => 'API Token', 'type' => 'password', 'sensitive' => true],
                ['env' => 'XENTRAL_USERNAME', 'label' => 'Benutzername optional', 'type' => 'text'],
                ['env' => 'XENTRAL_PASSWORD', 'label' => 'Passwort optional', 'type' => 'password', 'sensitive' => true],
                ['env' => 'XENTRAL_ENABLE_LIVE_WRITEBACK', 'label' => 'Live-Write-back erlauben', 'type' => 'checkbox', 'default' => '0'],
            ],
        ],
        'sap-r3' => [
            'label' => 'SAP R/3',
            'badge' => 'ERP',
            'description' => 'Gateway-/Proxy-Zugang für IDoc-/BAPI-nahes Rückschreiben optimierter CTC-Texte.',
            'file' => 'ctc-sap-r3.env',
            'safety' => 'Live-Write-back bleibt deaktiviert, bis SAP_R3_ENABLE_LIVE_WRITEBACK aktiv gesetzt wird.',
            'fields' => [
                ['env' => 'SAP_R3_GATEWAY_URL', 'label' => 'Gateway/Proxy URL', 'type' => 'url', 'required' => true],
                ['env' => 'SAP_R3_WRITEBACK_PATH', 'label' => 'Write-back Pfad', 'type' => 'text', 'default' => '/ctc/material/writeback'],
                ['env' => 'SAP_R3_CLIENT', 'label' => 'Mandant', 'type' => 'text', 'required' => true, 'placeholder' => '100'],
                ['env' => 'SAP_R3_USERNAME', 'label' => 'Benutzername', 'type' => 'text', 'required' => true],
                ['env' => 'SAP_R3_PASSWORD', 'label' => 'Passwort', 'type' => 'password', 'required' => true, 'sensitive' => true],
                ['env' => 'SAP_R3_SYSTEM_ID', 'label' => 'System-ID optional', 'type' => 'text', 'placeholder' => 'PRD'],
                ['env' => 'SAP_R3_LANGUAGE', 'label' => 'Sprache', 'type' => 'text', 'default' => 'DE'],
                ['env' => 'SAP_R3_ENABLE_LIVE_WRITEBACK', 'label' => 'Live-Write-back erlauben', 'type' => 'checkbox', 'default' => '0'],
            ],
        ],
        'pimcore' => [
            'label' => 'Pimcore',
            'badge' => 'PIM',
            'description' => 'Pimcore API für Data Objects, localized fields, Medien und Workflow-Status.',
            'file' => 'ctc-pimcore.env',
            'safety' => 'Live-Write-back bleibt deaktiviert, bis PIMCORE_ENABLE_LIVE_WRITEBACK aktiv gesetzt wird.',
            'fields' => [
                ['env' => 'PIMCORE_BASE_URL', 'label' => 'Pimcore Base URL', 'type' => 'url', 'required' => true],
                ['env' => 'PIMCORE_API_TOKEN', 'label' => 'API Token', 'type' => 'password', 'required' => true, 'sensitive' => true],
                ['env' => 'PIMCORE_WRITEBACK_PATH', 'label' => 'Write-back Pfad', 'type' => 'text', 'default' => '/ctc/object/writeback'],
                ['env' => 'PIMCORE_DEFAULT_LANGUAGE', 'label' => 'Standardsprache', 'type' => 'text', 'default' => 'de'],
                ['env' => 'PIMCORE_ENABLE_LIVE_WRITEBACK', 'label' => 'Live-Write-back erlauben', 'type' => 'checkbox', 'default' => '0'],
            ],
        ],
    ];

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function channels(): array
    {
        $channels = [];
        foreach (array_keys(self::CHANNELS) as $slug) {
            $channels[$slug] = $this->channel($slug);
        }

        return $channels;
    }

    /**
     * @return array<string, mixed>
     */
    public function channel(string $slug): array
    {
        $definition = $this->definition($slug);
        $fileValues = $this->readFileValues($definition['file']);
        $fields = [];
        $missing = [];
        $configured = 0;

        foreach ($definition['fields'] as $field) {
            $env = $field['env'];
            $effectiveValue = $this->effectiveValue($env, $fileValues[$env] ?? '', $field['default'] ?? '');
            $hasValue = trim($effectiveValue) !== '';
            $isCheckbox = $field['type'] === 'checkbox';
            $isSensitive = (bool) ($field['sensitive'] ?? false);

            if ($hasValue && !$isCheckbox) {
                ++$configured;
            }

            if (($field['required'] ?? false) && !$hasValue) {
                $missing[] = $env;
            }

            $fields[] = [
                ...$field,
                'value' => $isSensitive ? '' : $effectiveValue,
                'masked_value' => $isSensitive ? $this->maskSecret($effectiveValue) : null,
                'has_value' => $hasValue,
                'checked' => $isCheckbox && $this->truthy($effectiveValue),
            ];
        }

        return [
            ...$definition,
            'slug' => $slug,
            'file_path' => $this->filePath($definition['file']),
            'fields' => $fields,
            'required_missing' => $missing,
            'required_missing_count' => count($missing),
            'configured_count' => $configured,
            'total_count' => count(array_filter(
                $definition['fields'],
                static fn (array $field): bool => $field['type'] !== 'checkbox',
            )),
            'ready' => $missing === [],
            'file_exists' => is_file($this->filePath($definition['file'])),
            'is_writable' => $this->isWritableTarget($definition['file']),
        ];
    }

    /**
     * @param array<string, mixed> $submitted
     *
     * @return array{path: string, changed: list<string>, kept: list<string>}
     */
    public function save(string $slug, array $submitted): array
    {
        $definition = $this->definition($slug);
        $currentValues = $this->readFileValues($definition['file']);
        $updatedValues = $currentValues;
        $changed = [];
        $kept = [];

        foreach ($definition['fields'] as $field) {
            $env = $field['env'];
            $isSensitive = (bool) ($field['sensitive'] ?? false);

            if ($field['type'] === 'checkbox') {
                $value = isset($submitted[$env]) && (string) $submitted[$env] === '1' ? '1' : '0';
            } else {
                $value = trim((string) ($submitted[$env] ?? ''));
            }

            if ($isSensitive && $value === '' && array_key_exists($env, $currentValues)) {
                $kept[] = $env;
                continue;
            }

            $updatedValues[$env] = $value;
            $changed[] = $env;
        }

        $path = $this->writeFile($definition, $updatedValues);
        foreach ($updatedValues as $name => $value) {
            $this->putRuntimeValue($name, $value);
        }

        return [
            'path' => $path,
            'changed' => $changed,
            'kept' => $kept,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function readFileValues(string $fileName): array
    {
        $path = $this->filePath($fileName);
        if (!is_file($path) || !is_readable($path)) {
            return [];
        }

        $values = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            if (str_starts_with($line, 'export ')) {
                $line = trim(substr($line, 7));
            }

            [$name, $value] = explode('=', $line, 2);
            $name = trim($name);
            $value = $this->unquote(trim($value));

            if ($name !== '') {
                $values[$name] = $value;
            }
        }

        return $values;
    }

    /**
     * @param array<string, mixed> $definition
     * @param array<string, string> $values
     */
    private function writeFile(array $definition, array $values): string
    {
        $directory = $this->privateConfigDirectory();
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('Private Konfigurationsablage konnte nicht erstellt werden.');
        }

        if (!is_writable($directory)) {
            throw new \RuntimeException('Private Konfigurationsablage ist nicht beschreibbar: '.$directory);
        }

        $knownNames = array_map(static fn (array $field): string => $field['env'], $definition['fields']);
        $lines = [
            '# CTC Zugangsdaten: '.$definition['label'],
            '# Bearbeitet im Portal am '.date('c'),
            '# Datei liegt bewusst ausserhalb des Git-Repositories.',
            '',
        ];

        foreach ($knownNames as $name) {
            $lines[] = $name.'='.$this->formatEnvValue((string) ($values[$name] ?? ''));
        }

        $extraNames = array_values(array_diff(array_keys($values), $knownNames));
        if ($extraNames !== []) {
            $lines[] = '';
            $lines[] = '# Weitere vorhandene Werte';
            foreach ($extraNames as $name) {
                $lines[] = $name.'='.$this->formatEnvValue((string) $values[$name]);
            }
        }

        $path = $this->filePath($definition['file']);
        if (file_put_contents($path, implode(PHP_EOL, $lines).PHP_EOL, LOCK_EX) === false) {
            throw new \RuntimeException('Zugangsdaten konnten nicht gespeichert werden: '.$path);
        }

        @chmod($path, 0660);

        return $path;
    }

    /**
     * @return array{
     *     label: string,
     *     badge: string,
     *     description: string,
     *     file: string,
     *     safety: string,
     *     fields: list<array<string, mixed>>
     * }
     */
    private function definition(string $slug): array
    {
        if (!isset(self::CHANNELS[$slug])) {
            throw new \InvalidArgumentException('Unbekannter Zugangsdaten-Channel: '.$slug);
        }

        return self::CHANNELS[$slug];
    }

    private function effectiveValue(string $name, string $fileValue, string $default = ''): string
    {
        $runtimeValue = $this->runtimeValue($name);
        if ($runtimeValue !== '') {
            return $runtimeValue;
        }

        if ($fileValue !== '') {
            return $fileValue;
        }

        return $default;
    }

    private function runtimeValue(string $name): string
    {
        foreach ([$_SERVER, $_ENV] as $source) {
            $value = $source[$name] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        $value = getenv($name);
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }

        return '';
    }

    private function putRuntimeValue(string $name, string $value): void
    {
        $_SERVER[$name] = $value;
        $_ENV[$name] = $value;
        putenv($name.'='.$value);
    }

    private function privateConfigDirectory(): string
    {
        return dirname($this->projectDir).DIRECTORY_SEPARATOR.'private-config';
    }

    private function filePath(string $fileName): string
    {
        return $this->privateConfigDirectory().DIRECTORY_SEPARATOR.$fileName;
    }

    private function isWritableTarget(string $fileName): bool
    {
        $path = $this->filePath($fileName);
        if (is_file($path)) {
            return is_writable($path);
        }

        $directory = $this->privateConfigDirectory();

        return is_dir($directory) ? is_writable($directory) : is_writable(dirname($directory));
    }

    private function maskSecret(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $length = strlen($value);
        if ($length <= 8) {
            return str_repeat('•', max(6, $length));
        }

        return substr($value, 0, 4).'••••'.substr($value, -4);
    }

    private function truthy(string $value): bool
    {
        return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
    }

    private function formatEnvValue(string $value): string
    {
        if ($value === '') {
            return '';
        }

        if (preg_match('/^[A-Za-z0-9_\\.\\/:@+\\-]+$/', $value) === 1) {
            return $value;
        }

        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }

    private function unquote(string $value): string
    {
        if ($value === '') {
            return '';
        }

        $first = $value[0];
        $last = $value[strlen($value) - 1];
        if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
            return stripcslashes(substr($value, 1, -1));
        }

        return $value;
    }
}
