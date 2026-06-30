<?php

declare(strict_types=1);

/**
 * Loads production secrets from global server variables or private env files
 * before Symfony Runtime reads APP_ENV, APP_SECRET, DATABASE_URL and friends.
 *
 * Existing server variables always win. Private files are only a fallback and
 * are expected next to the project folder, e.g.:
 *
 *   P:\projects\private-config\ctc.env
 *   /www/htdocs/w0195fef/projects/private-config/ctc.env
 */

$projectDir = dirname(__DIR__);
$privateConfigDir = dirname($projectDir).DIRECTORY_SEPARATOR.'private-config';

$runtimeSecretKeys = ctc_relevant_server_env_keys();
$configuredSecretsFile = ctc_server_env_value('APP_EXTERNAL_SECRETS_FILE');
$candidateFiles = array_values(array_filter([
    $configuredSecretsFile,
    $privateConfigDir.DIRECTORY_SEPARATOR.'ctc.env',
    $privateConfigDir.DIRECTORY_SEPARATOR.'ctc-shopware.env',
    $privateConfigDir.DIRECTORY_SEPARATOR.'ctc-amazon.env',
    $privateConfigDir.DIRECTORY_SEPARATOR.'ctc-jtl.env',
    $privateConfigDir.DIRECTORY_SEPARATOR.'ctc-plentymarkets.env',
    $privateConfigDir.DIRECTORY_SEPARATOR.'ctc-xentral.env',
    $privateConfigDir.DIRECTORY_SEPARATOR.'ctc-sap-r3.env',
    $privateConfigDir.DIRECTORY_SEPARATOR.'ctc-pimcore.env',
    $privateConfigDir.DIRECTORY_SEPARATOR.'ctc-shopify.env',
    $privateConfigDir.DIRECTORY_SEPARATOR.'skillbuilder-shopware.env',
]));

$fileValues = [];
foreach (array_unique($candidateFiles) as $candidateFile) {
    if (!is_file($candidateFile) || !is_readable($candidateFile)) {
        continue;
    }

    $fileValues = array_replace($fileValues, ctc_parse_env_file($candidateFile));
}

$runtimeSecretKeys = array_values(array_unique(array_merge($runtimeSecretKeys, array_keys($fileValues))));
ctc_put_server_env_value('CTC_RUNTIME_SECRET_KEYS', implode(',', $runtimeSecretKeys));

foreach ($fileValues as $name => $value) {
    if (ctc_server_env_value($name) !== null) {
        continue;
    }

    ctc_put_server_env_value($name, $value);
}

/**
 * @return array<string, string>
 */
function ctc_parse_env_file(string $path): array
{
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
        $value = trim(trim($value), "\"'");

        if ($name !== '' && $value !== '') {
            $values[$name] = $value;
        }
    }

    return $values;
}

function ctc_server_env_value(string $name): ?string
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

    return null;
}

function ctc_put_server_env_value(string $name, string $value): void
{
    $_SERVER[$name] = $value;
    $_ENV[$name] = $value;
    putenv($name.'='.$value);
}

/**
 * @return list<string>
 */
function ctc_relevant_server_env_keys(): array
{
    $keys = [];
    $sources = [
        getenv() ?: [],
        $_ENV,
        $_SERVER,
    ];

    foreach ($sources as $source) {
        foreach ($source as $name => $value) {
            if (!is_string($name) || !is_scalar($value) || trim((string) $value) === '') {
                continue;
            }

            if (ctc_is_relevant_runtime_secret_key($name)) {
                $keys[] = $name;
            }
        }
    }

    return array_values(array_unique($keys));
}

function ctc_is_relevant_runtime_secret_key(string $name): bool
{
    if (in_array($name, [
        'APP_ENV',
        'APP_DEBUG',
        'APP_SECRET',
        'APP_SHARE_DIR',
        'APP_PLATFORM_NAME',
        'APP_EXTERNAL_SECRETS_FILE',
        'APP_ADMIN_PASSWORD_HASH',
        'APP_IMPORT_API_TOKEN',
        'DATABASE_URL',
        'DEFAULT_URI',
        'MAILER_DSN',
        'MESSENGER_TRANSPORT_DSN',
        'CMS_DEFAULT_LANGUAGE',
    ], true)) {
        return true;
    }

    foreach (['SHOPWARE_', 'AMAZON_', 'JTL_', 'PLENTY_', 'XENTRAL_', 'SAP_R3_', 'SAP_', 'PIMCORE_', 'SHOPIFY_'] as $prefix) {
        if (str_starts_with($name, $prefix)) {
            return true;
        }
    }

    return false;
}
