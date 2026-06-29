<?php

declare(strict_types=1);

namespace App\Service\Import;

use InvalidArgumentException;

final class ProductVariantParser
{
    /**
     * @return list<array{
     *     sku: string,
     *     options: array<string, string>,
     *     ean: ?string,
     *     priceGross: ?string,
     *     currency: string,
     *     stock: ?int,
     *     enabled: bool
     * }>
     */
    public function parseText(?string $input): array
    {
        $input = trim((string) $input);
        if ($input === '') {
            return [];
        }

        if (str_starts_with($input, '[')) {
            try {
                $rows = json_decode($input, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $exception) {
                throw new InvalidArgumentException('Varianten-JSON konnte nicht gelesen werden: '.$exception->getMessage(), previous: $exception);
            }

            if (!is_array($rows)) {
                throw new InvalidArgumentException('Varianten müssen als JSON-Array übergeben werden.');
            }

            return $this->parseStructured($rows);
        }

        $variants = [];
        $lines = preg_split('/\R/u', $input) ?: [];

        foreach ($lines as $index => $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $parts = array_values(array_filter(array_map('trim', preg_split('/[|;]/u', $line) ?: []), static fn (string $value): bool => $value !== ''));
            if ($parts === []) {
                continue;
            }

            $sku = array_shift($parts);
            if ($sku === null || str_contains($sku, '=')) {
                throw new InvalidArgumentException(sprintf('Variante in Zeile %d braucht zuerst eine SKU.', $index + 1));
            }

            $row = [
                'sku' => $sku,
                'options' => [],
                'ean' => null,
                'priceGross' => null,
                'currency' => 'EUR',
                'stock' => null,
                'enabled' => true,
            ];

            foreach ($parts as $part) {
                if (!str_contains($part, '=')) {
                    $row['options']['option_'.(count($row['options']) + 1)] = $part;

                    continue;
                }

                [$key, $value] = array_map('trim', explode('=', $part, 2));
                $normalizedKey = strtolower($key);

                match ($normalizedKey) {
                    'ean', 'gtin' => $row['ean'] = $value,
                    'price', 'pricegross', 'preis' => $row['priceGross'] = $this->normalizePrice($value),
                    'currency', 'waehrung', 'währung' => $row['currency'] = strtoupper($value),
                    'stock', 'bestand' => $row['stock'] = $value === '' ? null : (int) $value,
                    'enabled', 'aktiv' => $row['enabled'] = $this->toBool($value),
                    default => $row['options'][$key] = $value,
                };
            }

            $variants[] = $row;
        }

        return $variants;
    }

    /**
     * @param array<int|string, mixed> $rows
     *
     * @return list<array{
     *     sku: string,
     *     options: array<string, string>,
     *     ean: ?string,
     *     priceGross: ?string,
     *     currency: string,
     *     stock: ?int,
     *     enabled: bool
     * }>
     */
    public function parseStructured(array $rows): array
    {
        $variants = [];

        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                throw new InvalidArgumentException(sprintf('Variante %s ist kein Objekt/Array.', (string) $index));
            }

            $sku = trim((string) ($row['sku'] ?? $row['artikelnummer'] ?? ''));
            if ($sku === '') {
                throw new InvalidArgumentException(sprintf('Variante %s braucht eine SKU.', (string) $index));
            }

            $options = [];
            $rawOptions = $row['optionen'] ?? $row['options'] ?? null;
            if (is_array($rawOptions)) {
                foreach ($rawOptions as $optionKey => $optionValue) {
                    $optionKey = trim((string) $optionKey);
                    $optionValue = trim((string) $optionValue);
                    if ($optionKey !== '' && $optionValue !== '') {
                        $options[$optionKey] = $optionValue;
                    }
                }
            }

            foreach ($row as $key => $value) {
                if (in_array($key, ['sku', 'artikelnummer', 'options', 'optionen', 'ean', 'priceGross', 'price', 'preis', 'preis_brutto', 'bruttopreis', 'currency', 'waehrung', 'währung', 'stock', 'bestand', 'enabled', 'aktiv'], true)) {
                    continue;
                }

                $key = trim((string) $key);
                $value = trim((string) $value);
                if ($key !== '' && $value !== '') {
                    $options[$key] = $value;
                }
            }

            $stock = $row['bestand'] ?? $row['stock'] ?? null;
            $enabled = $row['aktiv'] ?? $row['enabled'] ?? null;

            $variants[] = [
                'sku' => $sku,
                'options' => $options,
                'ean' => $this->nullable($row['ean'] ?? null),
                'priceGross' => $this->normalizePrice($row['priceGross'] ?? $row['price'] ?? $row['preis'] ?? $row['preis_brutto'] ?? $row['bruttopreis'] ?? null),
                'currency' => strtoupper($this->nullable($row['currency'] ?? $row['waehrung'] ?? $row['währung'] ?? 'EUR') ?? 'EUR'),
                'stock' => $stock === null || $stock === '' ? null : (int) $stock,
                'enabled' => $enabled !== null ? $this->toBool((string) $enabled) : true,
            ];
        }

        return $variants;
    }

    private function normalizePrice(mixed $value): ?string
    {
        $value = $this->nullable($value);
        if ($value === null) {
            return null;
        }

        $value = preg_replace('/[^\d,.-]/', '', $value) ?? $value;
        if (str_contains($value, ',') && str_contains($value, '.')) {
            $value = str_replace('.', '', $value);
        }

        $value = str_replace(',', '.', $value);

        if (!is_numeric($value)) {
            throw new InvalidArgumentException(sprintf('Preis "%s" ist ungültig.', (string) $value));
        }

        return number_format((float) $value, 2, '.', '');
    }

    private function toBool(string $value): bool
    {
        return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'ja', 'y'], true);
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
