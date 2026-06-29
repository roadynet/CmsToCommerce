<?php

declare(strict_types=1);

namespace App\Service\Integration;

use App\Enum\ExternalSystemType;

final class XentralPayloadNormalizer extends AbstractExternalSystemPayloadNormalizer
{
    public function system(): ExternalSystemType
    {
        return ExternalSystemType::Xentral;
    }

    public function supports(?string $systemHint, array $payload): bool
    {
        if ($systemHint !== null && trim($systemHint) !== '' && !$this->matchesHint($systemHint, 'xentral')) {
            return false;
        }

        return $this->matchesHint($systemHint, 'xentral')
            || is_array($payload['article'] ?? null) && array_key_exists('nummer', (array) $payload['article'])
            || is_array($payload['artikel'] ?? null);
    }

    public function normalize(array $payload): array
    {
        $article = is_array($payload['article'] ?? null) ? $payload['article'] : (is_array($payload['artikel'] ?? null) ? $payload['artikel'] : []);
        $name = $this->stringValue($article['name'] ?? null, $article['bezeichnung'] ?? null, $payload['name'] ?? null);
        $brand = $this->stringValue($article['hersteller'] ?? null, $article['marke'] ?? null, $payload['marke'] ?? null);
        $categoryPath = $this->categoryPath($article['kategorien'] ?? $payload['categories'] ?? null);
        $description = $this->stringValue($article['beschreibung'] ?? null, $article['description'] ?? null, $payload['beschreibung'] ?? null);
        $variants = $this->mapVariants($payload, $article);

        return [
            'produkt_name' => $name,
            'marke' => $brand,
            'kategorie_pfad' => $categoryPath,
            'beschreibung' => $description,
            'rohtext' => $this->buildRawText($description, [
                'Marke' => $brand,
                'Kategorie' => $categoryPath,
                'Artikelnummer' => $this->stringValue($article['nummer'] ?? null, $article['sku'] ?? null),
            ]),
            'cms_system' => 'xentral',
            'external_reference' => $this->stringValue($article['id'] ?? null, $payload['id'] ?? null),
            'sprache' => $this->stringValue($payload['language'] ?? null, $payload['lang'] ?? null) ?? 'de',
            'asset_urls' => $this->assetDescriptors($article['bilder'] ?? $payload['images'] ?? null),
            'variants' => $variants,
            'source_payload' => $this->preservedPayload(
                $payload,
                [
                    'system' => 'xentral',
                    'name' => $name,
                    'brand' => $brand,
                    'category_path' => $categoryPath,
                    'variant_count' => count($variants),
                ],
                ['bild_urls' => $this->imageUrls($article['bilder'] ?? $payload['images'] ?? null)],
            ),
        ];
    }

    public function overview(): array
    {
        return [
            'code' => $this->system()->value,
            'label' => $this->system()->label(),
            'status' => 'intake + medien bereit',
            'summary' => 'CTC kann Xentral-Artikel, Lager-/Preisinfos, Varianten und externe Bildquellen bereits in das eigene Produktmodell überführen.',
            'next_step' => 'Danach bieten sich Prozessstatus, Freigaben und direkte Xentral-Abrufe an.',
            'example_keys' => ['article.name', 'article.nummer', 'article.beschreibung', 'variants', 'article.bilder'],
            'intake_ready' => true,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $article
     *
     * @return list<array<string, mixed>>
     */
    private function mapVariants(array $payload, array $article): array
    {
        $candidates = $this->listOfArrays($payload['variants'] ?? $article['varianten'] ?? null);
        if ($candidates === []) {
            $candidates = [[
                'sku' => $article['nummer'] ?? $article['sku'] ?? null,
                'ean' => $article['ean'] ?? null,
                'price' => $article['preis'] ?? $article['price'] ?? null,
                'stock' => $article['lager'] ?? $article['stock'] ?? null,
                'enabled' => $article['aktiv'] ?? true,
            ]];
        }

        $variants = [];
        foreach ($candidates as $variant) {
            $variants[] = [
                'sku' => $this->stringValue($variant['sku'] ?? null, $variant['nummer'] ?? null, $variant['articleNumber'] ?? null),
                'ean' => $this->stringValue($variant['ean'] ?? null, $variant['barcode'] ?? null),
                'price' => $this->stringValue($variant['priceGross'] ?? null, $variant['price'] ?? null, $variant['preis'] ?? null),
                'stock' => $variant['stock'] ?? $variant['lager'] ?? $variant['bestand'] ?? null,
                'enabled' => $variant['enabled'] ?? $variant['aktiv'] ?? true,
                'options' => $this->optionMap($variant['options'] ?? $variant['optionen'] ?? null),
            ];
        }

        return array_values(array_filter($variants, static fn (array $variant): bool => trim((string) ($variant['sku'] ?? '')) !== ''));
    }
}
