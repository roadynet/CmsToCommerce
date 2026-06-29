<?php

declare(strict_types=1);

namespace App\Service\Integration;

abstract class AbstractExternalSystemPayloadNormalizer implements ExternalSystemPayloadNormalizer
{
    protected function matchesHint(?string $systemHint, string ...$aliases): bool
    {
        $systemHint = strtolower(trim((string) $systemHint));
        if ($systemHint === '') {
            return false;
        }

        foreach ($aliases as $alias) {
            if ($systemHint === strtolower($alias)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function pathValue(array $payload, string ...$paths): mixed
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

    protected function stringValue(mixed ...$values): ?string
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

    /**
     * @return list<array<string, mixed>>
     */
    protected function listOfArrays(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_array'));
    }

    protected function categoryPath(mixed $value): ?string
    {
        if (is_string($value)) {
            $value = trim($value);

            return $value !== '' ? $value : null;
        }

        if (!is_array($value)) {
            return null;
        }

        $parts = [];
        foreach ($value as $entry) {
            if (is_array($entry)) {
                $part = $this->stringValue($entry['name'] ?? null, $entry['label'] ?? null, $entry['path'] ?? null);
            } else {
                $part = $this->stringValue($entry);
            }

            if ($part !== null) {
                $parts[] = $part;
            }
        }

        $parts = array_values(array_unique(array_filter($parts, static fn (string $part): bool => $part !== '')));

        return $parts !== [] ? implode('/', $parts) : null;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $mapping
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    protected function preservedPayload(array $payload, array $mapping, array $extra = []): array
    {
        return [
            'normalisiert_aus' => $mapping,
            ...$extra,
            'original_payload' => $payload,
        ];
    }

    /**
     * @param array<string, mixed> $facts
     */
    protected function buildRawText(?string $description, array $facts = []): string
    {
        $lines = [];

        if ($description !== null) {
            $lines[] = trim($description);
        }

        foreach ($facts as $label => $value) {
            $value = is_array($value) ? implode(', ', array_map(static fn (mixed $entry): string => trim((string) $entry), $value)) : trim((string) $value);
            if ($value === '') {
                continue;
            }

            $lines[] = sprintf('%s: %s', $label, $value);
        }

        return trim(implode("\n", $lines));
    }

    /**
     * @return array<string, string>
     */
    protected function optionMap(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $options = [];
        foreach ($value as $optionKey => $optionValue) {
            if (is_array($optionValue)) {
                $key = $this->stringValue($optionValue['name'] ?? null, $optionValue['label'] ?? null, is_string($optionKey) ? $optionKey : null);
                $entryValue = $this->stringValue($optionValue['value'] ?? null, $optionValue['labelValue'] ?? null, $optionValue['nameValue'] ?? null);
            } else {
                $key = is_string($optionKey) ? trim($optionKey) : null;
                $entryValue = $this->stringValue($optionValue);
            }

            if ($key !== null && $entryValue !== null) {
                $options[$key] = $entryValue;
            }
        }

        return $options;
    }

    /**
     * @return list<string>
     */
    protected function imageUrls(mixed $value): array
    {
        $urls = [];

        foreach ($this->listOfArrays($value) as $image) {
            $url = $this->stringValue($image['url'] ?? null, $image['src'] ?? null, $image['location'] ?? null, $image['path'] ?? null);
            if ($url !== null) {
                $urls[] = $url;
            }
        }

        return array_values(array_unique($urls));
    }

    /**
     * @return list<array{url: string, name?: string, alt?: string}>
     */
    protected function assetDescriptors(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $descriptors = [];

        foreach ($value as $entry) {
            if (is_string($entry)) {
                $url = trim($entry);
                if ($url === '') {
                    continue;
                }

                $descriptors[$url] = ['url' => $url];
                continue;
            }

            if (!is_array($entry)) {
                continue;
            }

            $url = $this->stringValue($entry['url'] ?? null, $entry['src'] ?? null, $entry['location'] ?? null, $entry['path'] ?? null, $entry['downloadUrl'] ?? null);
            if ($url === null) {
                continue;
            }

            $descriptor = ['url' => $url];
            $name = $this->stringValue($entry['name'] ?? null, $entry['filename'] ?? null, $entry['originalName'] ?? null, $entry['basename'] ?? null);
            $alt = $this->stringValue($entry['alt'] ?? null, $entry['altText'] ?? null, $entry['title'] ?? null, $entry['label'] ?? null);

            if ($name !== null) {
                $descriptor['name'] = $name;
            }

            if ($alt !== null) {
                $descriptor['alt'] = $alt;
            }

            $descriptors[$url] = $descriptor;
        }

        return array_values($descriptors);
    }
}
