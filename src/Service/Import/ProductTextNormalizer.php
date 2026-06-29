<?php

declare(strict_types=1);

namespace App\Service\Import;

final class ProductTextNormalizer
{
    /**
     * @var array<string, list<string>>
     */
    private const FIELD_SYNONYMS = [
        'title' => ['titel', 'produktname', 'name', 'article name', 'product name'],
        'brand' => ['marke', 'brand', 'hersteller', 'manufacturer'],
        'model' => ['modell', 'model', 'modellnummer', 'model number'],
        'sku' => ['sku', 'artikelnummer', 'artnr', 'art-nr', 'art nr', 'item number'],
        'mpn' => ['mpn', 'herstellernummer', 'manufacturer part number'],
        'ean' => ['ean', 'gtin', 'barcode'],
        'category' => ['kategorie', 'category'],
        'product_type' => ['produktart', 'produkttyp', 'product type'],
        'color' => ['farbe', 'color'],
        'material' => ['material'],
        'size' => ['groesse', 'größe', 'size'],
        'dimensions' => ['abmessungen', 'masse', 'maße', 'dimensions'],
        'weight' => ['gewicht', 'weight'],
        'compatibility' => ['kompatibilitaet', 'kompatibilität', 'compatible with', 'compatibility'],
        'price' => ['preis', 'price'],
        'language' => ['sprache', 'language'],
        'marketplace' => ['marktplatz', 'marketplace'],
    ];

    public function normalize(string $rawText): array
    {
        $rawText = trim($rawText);

        if ($rawText === '') {
            return [
                'normalized' => [
                    'title_candidate' => null,
                    'brand' => null,
                    'model' => null,
                    'sku' => null,
                    'mpn' => null,
                    'ean' => null,
                    'category' => null,
                    'product_type' => null,
                    'color' => null,
                    'material' => null,
                    'size' => null,
                    'dimensions' => null,
                    'weight' => null,
                    'compatibility' => null,
                    'price' => null,
                    'language' => null,
                    'marketplace' => null,
                ],
                'raw' => [
                    'line_count' => 0,
                    'key_values' => [],
                    'bullet_lines' => [],
                    'unmatched_lines' => [],
                    'paragraphs' => [],
                ],
                'detected_values' => [
                    'ean_candidates' => [],
                    'dimension_candidates' => [],
                    'weight_candidates' => [],
                    'pack_candidates' => [],
                ],
                'missing_core_fields' => ['title_candidate', 'brand', 'product_type'],
                'notes' => ['Kein Quelltext vorhanden.'],
            ];
        }

        $text = str_replace(["\r\n", "\r"], "\n", $rawText);
        $lines = array_map(static fn (string $line): string => trim($line), explode("\n", $text));
        $nonEmptyLines = array_values(array_filter($lines, static fn (string $line): bool => $line !== ''));

        $keyValues = [];
        $unmatchedLines = [];
        $bulletLines = [];

        foreach ($nonEmptyLines as $line) {
            if (preg_match('/^[-*•]\s+(.+)$/u', $line, $matches) === 1) {
                $bulletLines[] = trim($matches[1]);

                continue;
            }

            if (preg_match('/^([^:=]{2,80})\s*[:=]\s*(.+)$/u', $line, $matches) === 1) {
                $rawKey = trim($matches[1]);
                $value = trim($matches[2]);
                $field = $this->canonicalField($rawKey);
                $keyValues[$field ?? $this->normalizeKey($rawKey)] = $value;

                continue;
            }

            $unmatchedLines[] = $line;
        }

        $titleCandidate = $keyValues['title'] ?? null;
        if ($titleCandidate === null) {
            foreach ($unmatchedLines as $line) {
                if (mb_strlen($line) <= 140) {
                    $titleCandidate = $line;

                    break;
                }
            }
        }

        $paragraphs = [];
        $chunks = preg_split("/\n\s*\n/u", $text) ?: [];
        foreach ($chunks as $chunk) {
            $paragraph = trim(preg_replace('/\s+/u', ' ', $chunk) ?? '');
            if ($paragraph !== '') {
                $paragraphs[] = $paragraph;
            }
        }

        $detected = [
            'ean_candidates' => $this->uniqueMatches('/\b\d{8,14}\b/u', $rawText),
            'dimension_candidates' => $this->uniqueMatches('/\b\d+(?:[.,]\d+)?\s*(?:x|×)\s*\d+(?:[.,]\d+)?(?:\s*(?:x|×)\s*\d+(?:[.,]\d+)?)?\s*(?:mm|cm|m)\b/iu', $rawText),
            'weight_candidates' => $this->uniqueMatches('/\b\d+(?:[.,]\d+)?\s*(?:mg|g|kg|ml|l)\b/iu', $rawText),
            'pack_candidates' => $this->uniqueMatches('/\b\d+\s*(?:stk|stueck|stück|pcs|pieces|pack)\b/iu', $rawText),
        ];

        if (!isset($keyValues['ean']) && $detected['ean_candidates'] !== []) {
            $keyValues['ean'] = $detected['ean_candidates'][0];
        }

        if (!isset($keyValues['dimensions']) && $detected['dimension_candidates'] !== []) {
            $keyValues['dimensions'] = $detected['dimension_candidates'][0];
        }

        if (!isset($keyValues['weight']) && $detected['weight_candidates'] !== []) {
            $keyValues['weight'] = $detected['weight_candidates'][0];
        }

        $normalized = [
            'title_candidate' => $titleCandidate,
            'brand' => $keyValues['brand'] ?? null,
            'model' => $keyValues['model'] ?? null,
            'sku' => $keyValues['sku'] ?? null,
            'mpn' => $keyValues['mpn'] ?? null,
            'ean' => $keyValues['ean'] ?? null,
            'category' => $keyValues['category'] ?? null,
            'product_type' => $keyValues['product_type'] ?? null,
            'color' => $keyValues['color'] ?? null,
            'material' => $keyValues['material'] ?? null,
            'size' => $keyValues['size'] ?? null,
            'dimensions' => $keyValues['dimensions'] ?? null,
            'weight' => $keyValues['weight'] ?? null,
            'compatibility' => $keyValues['compatibility'] ?? null,
            'price' => $keyValues['price'] ?? null,
            'language' => $keyValues['language'] ?? null,
            'marketplace' => $keyValues['marketplace'] ?? null,
        ];

        $missingCoreFields = [];
        foreach (['title_candidate', 'brand', 'product_type'] as $field) {
            if (($normalized[$field] ?? null) === null || ($normalized[$field] ?? null) === '') {
                $missingCoreFields[] = $field;
            }
        }

        $notes = [];
        if (count($nonEmptyLines) <= 2) {
            $notes[] = 'Es wurde nur sehr wenig Quelltext erkannt.';
        }

        if ($bulletLines === []) {
            $notes[] = 'Keine Bulletpoints erkannt; Freitextabschnitte werden stärker gewichtet.';
        }

        if ($unmatchedLines !== [] && count($unmatchedLines) === count($nonEmptyLines)) {
            $notes[] = 'Die Quelle wirkt weitgehend unstrukturiert; abgeleitete Felder bitte manuell prüfen.';
        }

        return [
            'normalized' => $normalized,
            'raw' => [
                'line_count' => count($nonEmptyLines),
                'key_values' => $keyValues,
                'bullet_lines' => $bulletLines,
                'unmatched_lines' => $unmatchedLines,
                'paragraphs' => $paragraphs,
            ],
            'detected_values' => $detected,
            'missing_core_fields' => $missingCoreFields,
            'notes' => $notes,
        ];
    }

    private function canonicalField(string $key): ?string
    {
        $normalized = $this->normalizeKey($key);

        foreach (self::FIELD_SYNONYMS as $canonical => $aliases) {
            if ($normalized === $canonical || in_array($normalized, $aliases, true)) {
                return $canonical;
            }
        }

        return null;
    }

    private function normalizeKey(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $transliterated = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        $value = $transliterated !== false ? $transliterated : $value;
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? $value;

        return trim($value);
    }

    /**
     * @return list<string>
     */
    private function uniqueMatches(string $pattern, string $subject): array
    {
        preg_match_all($pattern, $subject, $matches);

        return $this->unique($matches[0] ?? []);
    }

    /**
     * @param list<string> $values
     *
     * @return list<string>
     */
    private function unique(array $values): array
    {
        $seen = [];
        $result = [];

        foreach ($values as $value) {
            $compact = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
            if ($compact === '' || isset($seen[$compact])) {
                continue;
            }

            $seen[$compact] = true;
            $result[] = $compact;
        }

        return $result;
    }
}
