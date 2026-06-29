<?php

declare(strict_types=1);

namespace App\Service\Configuration;

/**
 * Resolves sensitive runtime configuration without requiring committed secrets.
 *
 * Priority:
 * 1. Global server/environment variables, e.g. Apache SetEnv, hosting panel envs, PHP-FPM env.
 * 2. A private external env-style file outside the repository.
 * 3. Harmless application defaults injected from the committed .env file by Symfony.
 */
final class ServerSecretResolver
{
    /**
     * @param list<string> $fallbackFileNames
     *
     * @return array<string, string>
     */
    public static function resolve(
        string $configuredSecretsFile = '',
        array $fallbackFileNames = [],
        bool $strict = false,
        string $label = 'Secrets',
    ): array {
        $values = [];
        $path = self::configuredSecretsPath($configuredSecretsFile, $fallbackFileNames);

        if ($path !== null) {
            $values = self::readEnvFile($path, $strict, $label);
        }

        return array_replace($values, self::serverValues());
    }

    /**
     * @param list<string> $fallbackFileNames
     */
    private static function configuredSecretsPath(string $configuredSecretsFile, array $fallbackFileNames): ?string
    {
        $configured = self::firstNonEmpty(
            self::serverValue('APP_EXTERNAL_SECRETS_FILE'),
            $configuredSecretsFile,
        );

        if ($configured !== '') {
            return $configured;
        }

        $basePath = dirname(__DIR__, 4).DIRECTORY_SEPARATOR.'private-config'.DIRECTORY_SEPARATOR;
        foreach (array_unique($fallbackFileNames) as $fileName) {
            $candidate = $basePath.$fileName;
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    private static function readEnvFile(string $path, bool $strict, string $label): array
    {
        if (!is_file($path) || !is_readable($path)) {
            if ($strict) {
                throw new \RuntimeException($label.'-Secrets-Datei ist nicht lesbar. Bitte Server-Konfiguration prüfen.');
            }

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
            $value = trim(trim($value), "\"'");

            if ($name !== '' && $value !== '') {
                $values[$name] = $value;
            }
        }

        return $values;
    }

    /**
     * @return array<string, string>
     */
    private static function serverValues(): array
    {
        $values = [];
        foreach (self::runtimeSecretKeys() as $name) {
            $value = self::serverValue($name);
            if ($value !== null) {
                $values[$name] = $value;
            }
        }

        return $values;
    }

    /**
     * @return list<string>
     */
    private static function runtimeSecretKeys(): array
    {
        $value = self::serverValue('CTC_RUNTIME_SECRET_KEYS');
        if ($value === null || $value === '') {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (string $name): string => trim($name),
            explode(',', $value),
        )));
    }

    private static function serverValue(string $name): ?string
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

    private static function firstNonEmpty(?string ...$values): string
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
